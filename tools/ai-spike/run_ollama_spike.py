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


PROMPT = r"""Analiza la imagen del documento y extrae únicamente información que sea visible.
Devuelve un único objeto JSON válido, sin Markdown ni texto adicional, usando exactamente
la estructura obligatoria indicada abajo y conservando todas sus propiedades.

Reglas:
- Cada dato es un objeto con "value" y "confidence".
- "confidence" es un número entre 0.0 y 1.0; expresa confianza de lectura, no validación.
- Si un dato no aparece, no se lee claramente o no puede identificarse sin adivinar,
  usa "value": null y "confidence": 0.
- No inventes, completes, calcules ni derives valores.
- Los importes y porcentajes son cadenas decimales con punto y exactamente dos decimales,
  sin símbolos de moneda, separadores de miles ni el signo % (por ejemplo "48204.21", "7.00").
- year y week son enteros; no interpretes week como semana ISO.
- Las fechas visibles usan YYYY-MM-DD. No deduzcas fechas a partir de year o week.
- Incluye un elemento en details por cada movimiento visible y conserva el orden visual.
- affiliation y branch pertenecen a cada movimiento y deben aparecer dentro de cada elemento
  de details, no dentro de record.
- No extraigas ni inventes operation_number. El orden se deriva de la posición en details.
- source_commission, source_return_amount y source_totals son únicamente transcripciones
  de lo impreso. No calcules valores faltantes ni los presentes como valores oficiales.
- Si una propiedad no se muestra o no puede leerse, consérvala con value null y confidence 0.
- source_totals siempre debe contener income, commission y return_amount.
- details debe contener al menos un elemento; no agregues movimientos que no sean visibles.

Estructura obligatoria:
{
  "record": {
    "company_name": {"value": null, "confidence": 0},
    "year": {"value": null, "confidence": 0},
    "week": {"value": null, "confidence": 0},
    "week_start_date": {"value": null, "confidence": 0},
    "week_end_date": {"value": null, "confidence": 0}
  },
  "details": [
    {
      "affiliation": {"value": null, "confidence": 0},
      "branch": {"value": null, "confidence": 0},
      "income": {"value": null, "confidence": 0},
      "commission_percentage": {"value": null, "confidence": 0},
      "source_commission": {"value": null, "confidence": 0},
      "source_return_amount": {"value": null, "confidence": 0}
    }
  ],
  "source_totals": {
    "income": {"value": null, "confidence": 0},
    "commission": {"value": null, "confidence": 0},
    "return_amount": {"value": null, "confidence": 0}
  }
}"""

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
        "format": "json",
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
    print(f"Contrato v2: {'VÁLIDO' if not issues else 'REVISAR'}")
    for issue in issues:
        print(f"- {issue}")
    print("Respuesta:")
    print(json.dumps(extraction, ensure_ascii=False, indent=2))
    return 0 if not issues else 1


if __name__ == "__main__":
    raise SystemExit(main())
