#!/usr/bin/env python3
"""Run one image through the Ollama vision model for the TPVS AI spike."""

import argparse
import base64
import json
import re
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any


PROMPT_VERSION = "v3"
PROMPT = r"""You are a document transcription system. Inspect the supplied image and extract only
information that is visibly printed in it. Return the extracted fields in the JSON object
constrained by the supplied JSON Schema. Do not return Markdown, comments, or surrounding text.

Follow these rules exactly:
1. Transcribe; do not infer, calculate, repair, or complete missing document data.
2. Include every property required by the schema. If a field is absent, unreadable, ambiguous,
   or would require guessing, set value to null and confidence to 0.
3. Confidence is your reading confidence from 0 to 1, not business validation. Do not use 1.0
   automatically. Use a value that reflects how clearly the characters are visible.
4. Keep year and week as JSON integers. Do not infer dates from the week number, and do not
   reinterpret the business week as an ISO week. Copy complete visible dates as YYYY-MM-DD.
5. Return money and commission percentages as strings with exactly two decimal places, a dot as
   the decimal separator, and no grouping separators, currency symbol, or percent sign.
   Examples: printed "$1,234.56" -> "1234.56"; printed "1.234,56" -> "1234.56";
   printed "8%" -> "8.00"; printed "6.5%" -> "6.50". These are formatting conversions only.
6. If separators are malformed or the intended numeric value is ambiguous, do not guess: return
   null with confidence 0 for that field. In particular, do not reinterpret strings such as
   "55.963.03" unless the image makes the intended decimal unambiguous.
7. The record object contains document-level company and period fields only. Affiliation and
   branch belong to each individual movement in details and must never be moved to record.
8. Include exactly one details item for each visible movement, in visual reading order. Do not
   merge rows or create movements from totals. Array position represents movement order; do not
   extract operation_number.
9. source_commission, source_return_amount, and source_totals are transcriptions of printed
   source amounts only. Never calculate them. If the document does not print a per-movement
   return amount, use null even if it can be calculated from income and commission.
10. source_totals must contain income, commission, and return_amount. Use null for totals not
    visibly printed or not readable.

Now inspect the supplied image and transcribe its actual contents. The JSON Schema is the output
contract. Do not use any values from this instruction text as evidence about the image."""

FIELD_NAMES = ("value", "confidence")
DECIMAL_PATTERN = re.compile(r"^-?\d+\.\d{2}$")
ROOT_FIELDS = {"record", "details", "source_totals"}
RECORD_FIELDS = {
    "company_name", "year", "week", "week_start_date", "week_end_date"
}
DETAIL_FIELDS = {
    "affiliation", "branch", "income", "commission_percentage",
    "source_commission", "source_return_amount",
}
TOTAL_FIELDS = {"income", "commission", "return_amount"}
DECIMAL_FIELDS = {
    "income", "commission_percentage", "source_commission",
    "source_return_amount", "commission", "return_amount",
}
DATE_FIELDS = {"week_start_date", "week_end_date"}


def field_schema(value_schema: dict[str, Any]) -> dict[str, Any]:
    return {
        "type": "object",
        "properties": {
            "value": value_schema,
            "confidence": {"type": "number", "minimum": 0, "maximum": 1},
        },
        "required": ["value", "confidence"],
        "additionalProperties": False,
    }


DECIMAL_VALUE_SCHEMA = {
    "anyOf": [
        {"type": "string", "pattern": r"^-?\d+\.\d{2}$"},
        {"type": "null"},
    ]
}
STRING_VALUE_SCHEMA = {"anyOf": [{"type": "string"}, {"type": "null"}]}
INTEGER_VALUE_SCHEMA = {
    "anyOf": [{"type": "integer"}, {"type": "null"}]
}
DATE_VALUE_SCHEMA = {
    "anyOf": [
        {"type": "string", "pattern": r"^\d{4}-\d{2}-\d{2}$"},
        {"type": "null"},
    ]
}


def object_schema(fields: dict[str, dict[str, Any]]) -> dict[str, Any]:
    return {
        "type": "object",
        "properties": {name: field_schema(schema) for name, schema in fields.items()},
        "required": list(fields),
        "additionalProperties": False,
    }


EXTRACTION_SCHEMA = {
    "$schema": "http://json-schema.org/draft-07/schema#",
    "type": "object",
    "properties": {
        "record": object_schema({
            "company_name": STRING_VALUE_SCHEMA,
            "year": INTEGER_VALUE_SCHEMA,
            "week": INTEGER_VALUE_SCHEMA,
            "week_start_date": DATE_VALUE_SCHEMA,
            "week_end_date": DATE_VALUE_SCHEMA,
        }),
        "details": {
            "type": "array",
            "minItems": 1,
            "items": object_schema({
                "affiliation": STRING_VALUE_SCHEMA,
                "branch": STRING_VALUE_SCHEMA,
                "income": DECIMAL_VALUE_SCHEMA,
                "commission_percentage": DECIMAL_VALUE_SCHEMA,
                "source_commission": DECIMAL_VALUE_SCHEMA,
                "source_return_amount": DECIMAL_VALUE_SCHEMA,
            }),
        },
        "source_totals": object_schema({
            "income": DECIMAL_VALUE_SCHEMA,
            "commission": DECIMAL_VALUE_SCHEMA,
            "return_amount": DECIMAL_VALUE_SCHEMA,
        }),
    },
    "required": ["record", "details", "source_totals"],
    "additionalProperties": False,
}


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("image", type=Path, help="Path to one representative image")
    parser.add_argument(
        "--url", default="http://localhost:11434", help="Ollama base URL"
    )
    parser.add_argument("--model", default="qwen2.5vl:3b", help="Ollama model tag")
    parser.add_argument("--timeout", type=int, default=360, help="HTTP timeout in seconds")
    return parser.parse_args()


def contract_issues(data: Any) -> list[str]:
    issues: list[str] = []
    if not isinstance(data, dict):
        return ["La raíz de la respuesta no es un objeto JSON."]

    extra_root = set(data) - ROOT_FIELDS
    missing_root = ROOT_FIELDS - set(data)
    if missing_root:
        issues.append(f"Faltan propiedades raíz: {', '.join(sorted(missing_root))}.")
    if extra_root:
        issues.append(f"Propiedades raíz inesperadas: {', '.join(sorted(extra_root))}.")

    record = data.get("record")
    if not isinstance(record, dict):
        issues.append("record debe ser un objeto.")
    else:
        issues.extend(validate_fields(record, RECORD_FIELDS, "record"))

    details = data.get("details")
    if not isinstance(details, list) or not details:
        issues.append("details debe ser un array con al menos un movimiento.")
    else:
        for index, detail in enumerate(details, start=1):
            if not isinstance(detail, dict):
                issues.append(f"details[{index - 1}] debe ser un objeto.")
                continue
            issues.extend(validate_fields(detail, DETAIL_FIELDS, f"details[{index - 1}]"))

    totals = data.get("source_totals")
    if not isinstance(totals, dict):
        issues.append("source_totals debe ser un objeto.")
    else:
        issues.extend(validate_fields(totals, TOTAL_FIELDS, "source_totals"))
    return issues


def validate_fields(obj: dict[str, Any], expected: set[str], path: str) -> list[str]:
    issues: list[str] = []
    missing = expected - set(obj)
    extra = set(obj) - expected
    if missing:
        issues.append(f"{path}: faltan campos: {', '.join(sorted(missing))}.")
    if extra:
        issues.append(f"{path}: campos inesperados: {', '.join(sorted(extra))}.")

    for name in expected & set(obj):
        field = obj[name]
        field_path = f"{path}.{name}"
        if not isinstance(field, dict):
            issues.append(f"{field_path} debe contener value y confidence.")
            continue
        if set(field) != set(FIELD_NAMES):
            issues.append(f"{field_path} debe contener exactamente value y confidence.")
            continue

        value = field["value"]
        confidence = field["confidence"]
        if not isinstance(confidence, (int, float)) or isinstance(confidence, bool):
            issues.append(f"{field_path}.confidence debe ser numérico.")
        elif not 0 <= confidence <= 1:
            issues.append(f"{field_path}.confidence debe estar entre 0 y 1.")

        if value is None:
            if confidence != 0:
                issues.append(f"{field_path}: value null requiere confidence 0.")
        elif name in DECIMAL_FIELDS:
            if not isinstance(value, str) or not DECIMAL_PATTERN.fullmatch(value):
                issues.append(f"{field_path}.value debe ser cadena decimal con dos decimales.")
        elif name in {"year", "week"}:
            if not isinstance(value, int) or isinstance(value, bool):
                issues.append(f"{field_path}.value debe ser entero o null.")
        elif name in DATE_FIELDS:
            if not isinstance(value, str) or not re.fullmatch(r"\d{4}-\d{2}-\d{2}", value):
                issues.append(f"{field_path}.value debe usar YYYY-MM-DD o null.")
        elif not isinstance(value, str):
            issues.append(f"{field_path}.value debe ser texto o null.")
    return issues


def main() -> int:
    args = parse_args()
    if not args.image.is_file():
        print(f"No existe la imagen: {args.image}", file=sys.stderr)
        return 2

    image = base64.b64encode(args.image.read_bytes()).decode("ascii")
    payload = {
        "model": args.model,
        "prompt": PROMPT,
        "images": [image],
        "stream": False,
        "format": EXTRACTION_SCHEMA,
    }
    request = urllib.request.Request(
        f"{args.url.rstrip('/')}/api/generate",
        data=json.dumps(payload).encode("utf-8"),
        headers={"Content-Type": "application/json"},
        method="POST",
    )

    started_at = time.perf_counter()
    try:
        with urllib.request.urlopen(request, timeout=args.timeout) as response:
            result = json.load(response)
    except urllib.error.HTTPError as error:
        details = error.read().decode("utf-8", errors="replace")
        print(f"Ollama respondió HTTP {error.code}: {details}", file=sys.stderr)
        return 1
    except (urllib.error.URLError, TimeoutError) as error:
        print(f"No se pudo completar la petición a Ollama: {error}", file=sys.stderr)
        return 1
    elapsed_seconds = time.perf_counter() - started_at

    print(f"Imagen: {args.image}")
    print(f"Modelo: {args.model}")
    print(f"Tiempo total cliente: {elapsed_seconds:.2f} segundos")
    if "total_duration" in result:
        print(f"Tiempo reportado por Ollama: {result['total_duration'] / 1e9:.2f} segundos")

    response_text = result.get("response", "")
    try:
        extraction = json.loads(response_text)
    except json.JSONDecodeError as error:
        print(f"Respuesta del modelo no es JSON parseable: {error}")
        print(response_text)
        return 1

    issues = contract_issues(extraction)
    print(f"Contrato {PROMPT_VERSION}: {'VÁLIDO' if not issues else 'REVISAR'}")
    for issue in issues:
        print(f"- {issue}")
    print("Respuesta:")
    print(json.dumps(extraction, ensure_ascii=False, indent=2))
    return 0 if not issues else 1


if __name__ == "__main__":
    raise SystemExit(main())
