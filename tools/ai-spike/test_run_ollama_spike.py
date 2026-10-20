import contextlib
import io
import json
import sys
import tempfile
import unittest
from unittest.mock import patch

import run_ollama_spike as runner


def extracted_document() -> dict:
    def field(value=None, confidence=0):
        return {"value": value, "confidence": confidence}

    return {
        "record": {
            "company_name": field(),
            "year": field(),
            "week": field(),
            "week_start_date": field(),
            "week_end_date": field(),
        },
        "details": [{
            "affiliation": field(),
            "branch": field(),
            "income": field(),
            "commission_percentage": field(),
            "source_commission": field(),
            "source_return_amount": field(),
        }],
        "source_totals": {
            "income": field(),
            "commission": field(),
            "return_amount": field(),
        },
    }


class NumericNormalizationTests(unittest.TestCase):
    def test_explicit_formats_normalize_and_ambiguous_formats_are_rejected(self):
        accepted = {
            ("5%", True): "5.00",
            ("6.5%", True): "6.50",
            ("$1,234.56", False): "1234.56",
            ("1.234,56", False): "1234.56",
            ("1234.5", False): "1234.50",
        }
        for (text, is_percentage), expected in accepted.items():
            with self.subTest(text=text):
                self.assertEqual(runner.normalize_numeric_text(text, is_percentage), expected)

        for text in ("1,200", "1.200", "55.963.03"):
            with self.subTest(text=text):
                self.assertIsNone(runner.normalize_numeric_text(text, False))

    def test_null_is_accepted_and_does_not_increment_counter(self):
        document = extracted_document()
        normalized, issues, count = runner.normalize_and_validate_values(document)
        self.assertEqual(count, 0)
        self.assertTrue(any("valor faltante" in issue for issue in issues))
        self.assertEqual(normalized["details"][0]["income"]["value"], None)

    def test_nonconvertible_numbers_keep_raw_document_and_are_counted(self):
        document = extracted_document()
        field = document["details"][0]["income"]
        field.update(value="1,200", confidence=0.9)

        normalized, issues, count = runner.normalize_and_validate_values(document)

        self.assertEqual(count, 1)
        self.assertEqual(document["details"][0]["income"]["value"], "1,200")
        self.assertIsNone(normalized["details"][0]["income"]["value"])
        self.assertTrue(issues)


class StructureAndClassificationTests(unittest.TestCase):
    def test_structural_wrapper_and_confidence_errors_are_separate(self):
        document = extracted_document()
        self.assertEqual(runner.structural_issues(document), [])

        document["unexpected"] = True
        self.assertTrue(runner.structural_issues(document))

        document = extracted_document()
        document["record"]["year"]["confidence"] = 1.01
        self.assertTrue(runner.structural_issues(document))

        document = extracted_document()
        document["record"]["year"].update(value=None, confidence=0.5)
        self.assertTrue(runner.structural_issues(document))

        document = extracted_document()
        del document["source_totals"]["commission"]
        self.assertTrue(runner.structural_issues(document))

    def test_four_unconvertible_values_are_usable_and_five_are_not(self):
        document = extracted_document()
        numeric_fields = [
            document["details"][0]["income"],
            document["details"][0]["commission_percentage"],
            document["details"][0]["source_commission"],
            document["source_totals"]["income"],
            document["source_totals"]["commission"],
        ]
        for field in numeric_fields:
            field.update(value="1,200", confidence=0.8)

        document["source_totals"]["commission"].update(value=None, confidence=0)
        _, _, four_count = runner.normalize_and_validate_values(document)
        self.assertEqual(four_count, 4)
        self.assertLess(four_count, 5)

        document["source_totals"]["commission"].update(value="1,200", confidence=0.8)
        _, _, five_count = runner.normalize_and_validate_values(document)
        self.assertEqual(five_count, 5)
        self.assertGreaterEqual(five_count, 5)

    def test_invalid_iso_date_becomes_null_without_numeric_error(self):
        document = extracted_document()
        document["record"]["week_start_date"].update(
            value="2026-02-30", confidence=0.9
        )

        normalized, issues, count = runner.normalize_and_validate_values(document)

        self.assertIsNone(normalized["record"]["week_start_date"]["value"])
        self.assertTrue(issues)
        self.assertEqual(count, 0)


class RunnerOutputTests(unittest.TestCase):
    def invoke_runner(self, document):
        response = io.BytesIO(json.dumps({
            "response": json.dumps(document),
            "total_duration": 1250000000,
        }).encode())
        captured_request = {}

        def fake_urlopen(request, timeout):
            captured_request["payload"] = json.loads(request.data)
            captured_request["timeout"] = timeout
            return response

        output = io.StringIO()
        with tempfile.NamedTemporaryFile() as image, \
                patch.object(sys, "argv", ["run_ollama_spike.py", image.name, "--model", "test:model"]), \
                patch.object(runner.urllib.request, "urlopen", side_effect=fake_urlopen), \
                contextlib.redirect_stdout(output):
            exit_code = runner.main()

        return exit_code, output.getvalue(), captured_request

    def test_runner_reports_comparable_metadata_and_normalized_output(self):
        document = extracted_document()
        document["details"][0]["commission_percentage"].update(
            value="5%", confidence=0.95
        )
        exit_code, output, request = self.invoke_runner(document)

        self.assertEqual(exit_code, 0)
        self.assertIn("Modelo: test:model", output)
        self.assertIn(f"Versión del prompt: {runner.PROMPT_VERSION}", output)
        self.assertIn("Tiempo de solicitud:", output)
        self.assertIn("Validez estructural: VÁLIDA", output)
        self.assertIn('"value": "5.00"', output)
        self.assertEqual(request["payload"]["prompt"], runner.PROMPT)
        self.assertEqual(request["payload"]["format"], runner.EXTRACTION_SCHEMA)

    def test_runner_returns_distinct_structural_and_unusable_codes(self):
        invalid = {"record": {}}
        exit_code, output, _ = self.invoke_runner(invalid)
        self.assertEqual(exit_code, 2)
        self.assertIn("Validez estructural: ERROR", output)
        self.assertIn("Valores numéricos no convertibles: 0", output)

        document = extracted_document()
        for field in (
            document["details"][0]["income"],
            document["details"][0]["commission_percentage"],
            document["details"][0]["source_commission"],
            document["details"][0]["source_return_amount"],
            document["source_totals"]["income"],
        ):
            field.update(value="1,200", confidence=0.8)
        exit_code, output, _ = self.invoke_runner(document)
        self.assertEqual(exit_code, 3)
        self.assertIn("Valores numéricos no convertibles: 5", output)
        self.assertIn("NO UTILIZABLE", output)


if __name__ == "__main__":
    unittest.main()
