#!/usr/bin/env python3
"""Run one image through the Ollama vision model for the TPVS AI spike."""

import argparse
import base64
import copy
import json
import math
import re
import sys
import time
import urllib.error
import urllib.request
from datetime import date
from decimal import Decimal
from pathlib import Path
from typing import Any


PROMPT_VERSION = "v4"
PROMPT = r"""You are a document transcription system. Inspect the supplied image and extract only
information that is visibly printed in it. Return one JSON object matching the supplied JSON
Schema. Do not return Markdown, comments, or surrounding text.

Follow these rules exactly:
1. Transcribe; do not infer, calculate, repair, or complete missing document data.
2. Include every property required by the schema. If a field is absent or unreadable, set value to
   null and confidence to 0. A null value must always have confidence 0.
3. Confidence is your reading confidence from 0 to 1, not business validation. Do not use 1.0
   automatically. Use a value that reflects how clearly the characters are visible.
4. Keep year and week as JSON integers. Do not infer dates from the week number, and do not
   reinterpret the business week as an ISO week. Copy complete visible dates as YYYY-MM-DD.
5. For monetary amounts and commission percentages, transcribe the printed characters faithfully
   as a string, including any visible currency symbol, grouping or decimal separators, and percent
   sign. Do not reformat, calculate, or guess what an ambiguous separator means. The application
   will normalize only unambiguous numeric formats. If the characters themselves cannot be read,
   use null with confidence 0.
6. The record object contains document-level company and period fields only. Affiliation and
   branch belong to each individual movement in details and must never be moved to record.
7. Include exactly one details item for each visible movement, in visual reading order. Do not
   merge rows or create movements from totals. Array position represents movement order; do not
   extract operation_number.
8. source_commission, source_return_amount, and source_totals are transcriptions of printed
   source amounts only. Never calculate them. If the document does not print a per-movement
   return amount, use null even if it can be calculated from income and commission.
9. source_totals must contain income, commission, and return_amount. Use null for totals not
   visibly printed or not readable. Keep totals separate from movement rows.

Now inspect the supplied image and transcribe its actual contents. The JSON Schema is the output
contract. Do not use any values from this instruction text as evidence about the image."""

FIELD_NAMES = ("value", "confidence")
DECIMAL_PATTERN = re.compile(r"^-?\d+\.\d{2}$")
CANONICAL_NUMBER_PATTERN = re.compile(r"^-?\d+(?:\.\d{1,2})?$")
NUMERIC_TEXT_PATTERN = re.compile(r"^-?[\d.,]+$")
NUMERIC_CANDIDATE_PATTERN = re.compile(r"^[\d.,\s$€£¥₹%+-]+$")
US_GROUPED_NUMBER_PATTERN = re.compile(r"^-?\d{1,3}(?:,\d{3})+\.\d{1,2}$")
EU_GROUPED_NUMBER_PATTERN = re.compile(r"^-?\d{1,3}(?:\.\d{3})+,\d{1,2}$")
PERCENT_PATTERN = re.compile(r"^(-?\d+(?:\.\d{1,2})?)%$")
CURRENCY_SYMBOL_PATTERN = re.compile(r"^[\$€£¥₹]\s*")
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
PERCENT_FIELDS = {"commission_percentage"}
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


DECIMAL_VALUE_SCHEMA = {"anyOf": [{"type": "string"}, {"type": "null"}]}
STRING_VALUE_SCHEMA = {"anyOf": [{"type": "string"}, {"type": "null"}]}
INTEGER_VALUE_SCHEMA = {
    "anyOf": [
        {"type": "integer"},
        {"type": "string"},
        {"type": "null"},
    ]
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


def structural_issues(data: Any) -> list[str]:
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
        elif not math.isfinite(confidence) or not 0 <= confidence <= 1:
            issues.append(f"{field_path}.confidence debe estar entre 0 y 1.")

        if value is None and confidence != 0:
            issues.append(f"{field_path}: value null requiere confidence 0.")
    return issues


def canonical_decimal(value: Decimal) -> str:
    return f"{value:.2f}"


def normalize_numeric_text(value: str, is_percentage: bool) -> str | None:
    """Normalize only explicit numeric formats; ambiguous separators stay invalid."""
    text = value.strip()
    if is_percentage:
        match = PERCENT_PATTERN.fullmatch(text)
        if match:
            return canonical_decimal(Decimal(match.group(1)))
        if CANONICAL_NUMBER_PATTERN.fullmatch(text):
            return canonical_decimal(Decimal(text))
        return None

    # A currency symbol is optional, but stripping it must leave a complete valid pattern.
    text = CURRENCY_SYMBOL_PATTERN.sub("", text, count=1).strip()

    if CANONICAL_NUMBER_PATTERN.fullmatch(text):
        numeric_text = text
    elif US_GROUPED_NUMBER_PATTERN.fullmatch(text):
        numeric_text = text.replace(",", "")
    elif EU_GROUPED_NUMBER_PATTERN.fullmatch(text):
        numeric_text = text.replace(".", "").replace(",", ".")
    else:
        return None
    return canonical_decimal(Decimal(numeric_text))


def normalize_numeric_values(data: Any) -> tuple[Any, list[str], int]:
    """Return a normalized copy, field issues, and count of unconvertible numeric strings."""
    normalized = copy.deepcopy(data)
    issues: list[str] = []
    unconvertible_count = 0

    if not isinstance(normalized, dict):
        return normalized, issues, unconvertible_count

    record = normalized.get("record")
    if isinstance(record, dict):
        for field_name in {"year", "week"}:
            field = record.get(field_name)
            if not isinstance(field, dict) or set(field) != set(FIELD_NAMES):
                continue
            value = field["value"]
            if value is None or (isinstance(value, int) and not isinstance(value, bool)):
                continue
            if isinstance(value, str) and re.fullmatch(r"-?\d+", value.strip()):
                field["value"] = int(value.strip())
            elif isinstance(value, str) and NUMERIC_TEXT_PATTERN.fullmatch(value.strip()):
                field["value"] = None
                field["confidence"] = 0
                unconvertible_count += 1
                issues.append(
                    f"record.{field_name}: valor numérico no convertible {value!r}; "
                    "se conserva en la respuesta cruda."
                )
            elif isinstance(value, str):
                issues.append(f"record.{field_name}.value tiene tipo inesperado.")
            else:
                issues.append(f"record.{field_name}.value tiene tipo inesperado.")

    containers: list[tuple[str, Any, set[str]]] = [
        ("source_totals", normalized.get("source_totals"), TOTAL_FIELDS),
    ]
    details = normalized.get("details")
    if isinstance(details, list):
        containers.extend(
            (f"details[{index}]", detail, DECIMAL_FIELDS)
            for index, detail in enumerate(details)
        )

    for container_path, container, field_names in containers:
        if not isinstance(container, dict):
            continue
        for field_name in field_names:
            field = container.get(field_name)
            if not isinstance(field, dict) or set(field) != set(FIELD_NAMES):
                continue
            value = field["value"]
            if value is None:
                continue
            if not isinstance(value, str):
                issues.append(f"{container_path}.{field_name}.value tiene tipo inesperado.")
                continue

            canonical = normalize_numeric_text(
                value, field_name in PERCENT_FIELDS
            )
            if canonical is None:
                field["value"] = None
                field["confidence"] = 0
                if NUMERIC_CANDIDATE_PATTERN.fullmatch(value):
                    unconvertible_count += 1
                    issues.append(
                        f"{container_path}.{field_name}: valor numérico no convertible "
                        f"{value!r}; se conserva en la respuesta cruda."
                    )
                else:
                    issues.append(
                        f"{container_path}.{field_name}.value tiene tipo o formato inesperado."
                    )
            else:
                field["value"] = canonical

    return normalized, issues, unconvertible_count


def normalize_and_validate_values(data: Any) -> tuple[Any, list[str], int]:
    normalized, issues, unconvertible_count = normalize_numeric_values(data)

    def inspect_field(field: dict[str, Any], name: str, path: str) -> None:
        value = field["value"]
        if name in DATE_FIELDS and value is not None:
            if not isinstance(value, str) or not re.fullmatch(r"\d{4}-\d{2}-\d{2}", value):
                field["value"] = None
                field["confidence"] = 0
                issues.append(f"{path}.value debe ser una fecha ISO YYYY-MM-DD; se deja como null.")
            else:
                try:
                    date.fromisoformat(value)
                except ValueError:
                    field["value"] = None
                    field["confidence"] = 0
                    issues.append(f"{path}.value no es una fecha real; se deja como null.")
        elif name not in DECIMAL_FIELDS and name not in {"year", "week"} and value is not None:
            if not isinstance(value, str):
                issues.append(f"{path}.value tiene tipo inesperado.")
        confidence = field["confidence"]
        if field["value"] is None:
            issues.append(f"{path}: valor faltante; requiere revisión.")
            if confidence < 0.5:
                issues.append(f"{path}: confianza baja ({confidence}); requiere revisión.")
            return
        if name in DECIMAL_FIELDS:
            if not isinstance(field["value"], str) or not DECIMAL_PATTERN.fullmatch(field["value"]):
                issues.append(f"{path}.value no tiene formato decimal canónico.")
        elif name in {"year", "week"}:
            if not isinstance(field["value"], int) or isinstance(field["value"], bool):
                issues.append(f"{path}.value debe ser entero o null.")
        elif name in DATE_FIELDS:
            pass
        elif not isinstance(field["value"], str):
            issues.append(f"{path}.value debe ser texto o null.")

        if confidence < 0.5:
            issues.append(f"{path}: confianza baja ({confidence}); requiere revisión.")

    if not isinstance(normalized, dict):
        return normalized, issues, unconvertible_count
    for container_name, names in (("record", RECORD_FIELDS), ("source_totals", TOTAL_FIELDS)):
        container = normalized.get(container_name)
        if isinstance(container, dict):
            for name in names & set(container):
                field = container[name]
                if isinstance(field, dict) and set(field) == set(FIELD_NAMES):
                    inspect_field(field, name, f"{container_name}.{name}")
    details = normalized.get("details")
    if isinstance(details, list):
        for index, detail in enumerate(details):
            if isinstance(detail, dict):
                for name in DETAIL_FIELDS & set(detail):
                    field = detail[name]
                    if isinstance(field, dict) and set(field) == set(FIELD_NAMES):
                        inspect_field(field, name, f"details[{index}].{name}")
    return normalized, issues, unconvertible_count


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
    except json.JSONDecodeError as error:
        elapsed_seconds = time.perf_counter() - started_at
        print(f"Respuesta HTTP de Ollama no es JSON válido: {error}", file=sys.stderr)
        print(f"Tiempo de solicitud: {elapsed_seconds:.2f} segundos")
        return 1
    except urllib.error.HTTPError as error:
        elapsed_seconds = time.perf_counter() - started_at
        details = error.read().decode("utf-8", errors="replace")
        print(f"Ollama respondió HTTP {error.code}: {details}", file=sys.stderr)
        print(f"Tiempo de solicitud: {elapsed_seconds:.2f} segundos")
        return 1
    except (urllib.error.URLError, TimeoutError) as error:
        elapsed_seconds = time.perf_counter() - started_at
        print(f"No se pudo completar la petición a Ollama: {error}", file=sys.stderr)
        print(f"Tiempo de solicitud: {elapsed_seconds:.2f} segundos")
        return 1
    elapsed_seconds = time.perf_counter() - started_at

    if not isinstance(result, dict) or not isinstance(result.get("response"), str):
        print("Respuesta HTTP de Ollama sin campo response de texto.", file=sys.stderr)
        print(f"Tiempo de solicitud: {elapsed_seconds:.2f} segundos")
        return 1

    print(f"Imagen: {args.image}")
    print(f"Modelo: {args.model}")
    print(f"Versión del prompt: {PROMPT_VERSION}")
    print(f"Tiempo de solicitud: {elapsed_seconds:.2f} segundos")
    if "total_duration" in result:
        print(f"Tiempo reportado por Ollama: {result['total_duration'] / 1e9:.2f} segundos")

    response_text = result.get("response", "")
    try:
        extraction = json.loads(response_text)
    except json.JSONDecodeError as error:
        print("Validez estructural: ERROR (JSON no parseable)")
        print("Valores numéricos no convertibles: 0")
        print(f"Respuesta del modelo no es JSON parseable: {error}")
        print("Respuesta cruda del modelo:")
        print(response_text)
        return 2

    structure_errors = structural_issues(extraction)
    if structure_errors:
        print("Validez estructural: ERROR")
        print("Valores numéricos no convertibles: 0")
        print("Issues:")
        for issue in structure_errors:
            print(f"- {issue}")
        print("Respuesta cruda del modelo:")
        print(response_text)
        print("JSON parseado:")
        print(json.dumps(extraction, ensure_ascii=False, indent=2))
        return 2

    normalized, value_issues, unconvertible_count = normalize_and_validate_values(extraction)
    usable = unconvertible_count < 5
    print("Validez estructural: VÁLIDA")
    print(f"Valores numéricos no convertibles: {unconvertible_count}")
    print(f"Extracción: {'UTILIZABLE PARA REVISIÓN' if usable else 'NO UTILIZABLE'}")
    print("Issues:")
    if not value_issues:
        print("- Ninguno")
    for issue in value_issues:
        print(f"- {issue}")
    print("Respuesta cruda del modelo:")
    print(response_text)
    print("JSON crudo parseado:")
    print(json.dumps(extraction, ensure_ascii=False, indent=2))
    print("Respuesta normalizada:")
    print(json.dumps(normalized, ensure_ascii=False, indent=2))
    return 0 if usable else 3


if __name__ == "__main__":
    raise SystemExit(main())
