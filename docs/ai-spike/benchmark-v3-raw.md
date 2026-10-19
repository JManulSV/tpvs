# AI Spike — Benchmark v3 Raw Results and Diagnostics

This file contains synthetic diagnostics and controlled failures first, followed by the ten representative-image v3 runs added after the images were made available. Summary metrics and assessment are in [`benchmark-v3.md`](benchmark-v3.md).

## Runtime discovery

```text
Container: ollama
Image: ollama/ollama:latest
Status: running
Published port: 127.0.0.1:11434 -> 11434/tcp
Installed model: qwen2.5vl:3b (3.2 GB)
Docker memory limit: none (HostConfig.Memory = 0)
```

## Synthetic image check 1 — prompt still contained an example

Input: temporary blank 1x1 PNG at `/tmp/opencode/ai-spike-diagnostic.png`; not a benchmark sample.

```text
Tiempo total cliente: 96.04 segundos
Tiempo reportado por Ollama: 96.03 segundos
Contrato v3: VÁLIDO
```

The model returned the illustrative `EXAMPLE CO` / 2026 / week 40 values from the prompt. This output exposed example anchoring. The prompt example was removed before conducting the next diagnostic. The inference result is not treated as a test sample or accuracy observation.

## Synthetic image check 2 — final prompt without example

Input: same temporary blank 1x1 PNG; not a benchmark sample.

```text
Tiempo total cliente: 52.53 segundos
Tiempo reportado por Ollama: 52.52 segundos
Contrato v3: REVISAR
- details[0].income: value null requiere confidence 0.
- details[0].source_return_amount: value null requiere confidence 0.
- details[1].income: value null requiere confidence 0.
- details[1].source_return_amount: value null requiere confidence 0.
- source_totals.income: value null requiere confidence 0.
- source_totals.return_amount: value null requiere confidence 0.
```

The model no longer copied the removed example, but it still assigned non-zero confidence to null fields. Local validation detected these violations. All returned values were nonsensical for the blank input, as expected for a non-representative image; do not use this output for extraction accuracy.

## Controlled failures

### Missing input file

Command:

```bash
python3 -B tools/ai-spike/run_ollama_spike.py test-images/no-existe.jpeg
```

Observed:

```text
exit: 2
No existe la imagen: test-images/no-existe.jpeg
```

### Ollama unavailable at the configured URL

Command:

```bash
python3 -B tools/ai-spike/run_ollama_spike.py /tmp/opencode/ai-spike-diagnostic.png --url http://127.0.0.1:1
```

Observed:

```text
exit: 1
No se pudo completar la petición a Ollama: <urlopen error [Errno 111] Connection refused>
```

### Model not installed

Command:

```bash
python3 -B tools/ai-spike/run_ollama_spike.py /tmp/opencode/ai-spike-diagnostic.png --model tpvs-model-does-not-exist:0
```

Observed:

```text
exit: 1
Ollama respondió HTTP 404: {"error":"model 'tpvs-model-does-not-exist:0' not found"}
```

## Resource snapshots

One-time `docker stats --no-stream` observations:

```text
ollama 96.82% 754.9MiB / 14.86GiB 4.96%
ollama 567.98% 3.92GiB / 14.86GiB 26.38%
ollama 0.06% 3.973GiB / 14.86GiB 26.74%
```

These were snapshots at different moments around the synthetic diagnostics, not a continuous sample or reliable peak measurement. Do not interpret CPU above 100% as an error: Docker reports aggregate use across CPU cores. Poll `docker stats` during representative image requests in the actual target Debian server to collect useful resource data.

## Representative image runs

Test1.1:
Imagen: test-images/prueba1.jpeg
Modelo: qwen2.5vl:3b
Tiempo total cliente: 79.52 segundos
Tiempo reportado por Ollama: 79.51 segundos
Contrato v3: REVISAR
- record.week_start_date.value debe usar YYYY-MM-DD o null.
- record.week_end_date.value debe usar YYYY-MM-DD o null.
- details[0].commission_percentage.value debe ser cadena decimal con dos decimales.
- details[0].income.value debe ser cadena decimal con dos decimales.
- details[0].source_return_amount: value null requiere confidence 0.
- details[1].commission_percentage.value debe ser cadena decimal con dos decimales.
- details[1].income.value debe ser cadena decimal con dos decimales.
- details[1].source_return_amount: value null requiere confidence 0.
- source_totals.income.value debe ser cadena decimal con dos decimales.
Respuesta:
{
  "record": {
    "company_name": {
      "value": "Mi empresa",
      "confidence": 1
    },
    "year": {
      "value": 2026,
      "confidence": 1
    },
    "week": {
      "value": 36,
      "confidence": 1
    },
    "week_start_date": {
      "value": "07/09/2026",
      "confidence": 0.9
    },
    "week_end_date": {
      "value": "13/09/2026",
      "confidence": 0.9
    }
  },
  "details": [
    {
      "affiliation": {
        "value": "AFIL.-1234",
        "confidence": 1
      },
      "branch": {
        "value": "Mi sucursal",
        "confidence": 1
      },
      "income": {
        "value": "1,000.00",
        "confidence": 1
      },
      "commission_percentage": {
        "value": "8%",
        "confidence": 1
      },
      "source_commission": {
        "value": "80.00",
        "confidence": 1
      },
      "source_return_amount": {
        "value": null,
        "confidence": 0.5
      }
    },
    {
      "affiliation": {
        "value": "AFIL.-1234",
        "confidence": 1
      },
      "branch": {
        "value": "Mi sucursal",
        "confidence": 1
      },
      "income": {
        "value": "1,000.00",
        "confidence": 1
      },
      "commission_percentage": {
        "value": "8%",
        "confidence": 1
      },
      "source_commission": {
        "value": "80.00",
        "confidence": 1
      },
      "source_return_amount": {
        "value": null,
        "confidence": 0.5
      }
    }
  ],
  "source_totals": {
    "income": {
      "value": "1,000.00",
      "confidence": 1
    },
    "commission": {
      "value": "80.00",
      "confidence": 1
    },
    "return_amount": {
      "value": "920.00",
      "confidence": 1
    }
  }
}

Test1.2:
Imagen: test-images/prueba1.jpeg
Modelo: qwen2.5vl:3b
Tiempo total cliente: 37.01 segundos
Tiempo reportado por Ollama: 37.00 segundos
Contrato v3: REVISAR
- record.week_start_date.value debe usar YYYY-MM-DD o null.
- record.week_end_date.value debe usar YYYY-MM-DD o null.
- details[0].commission_percentage.value debe ser cadena decimal con dos decimales.
- details[0].income.value debe ser cadena decimal con dos decimales.
- source_totals.income.value debe ser cadena decimal con dos decimales.
Respuesta:
{
  "record": {
    "company_name": {
      "value": "Mi empresa",
      "confidence": 1
    },
    "year": {
      "value": 2026,
      "confidence": 1
    },
    "week": {
      "value": 36,
      "confidence": 1
    },
    "week_start_date": {
      "value": "07/09/2026",
      "confidence": 0.9
    },
    "week_end_date": {
      "value": "13/09/2026",
      "confidence": 0.9
    }
  },
  "details": [
    {
      "affiliation": {
        "value": "AFIL.-1234",
        "confidence": 1
      },
      "branch": {
        "value": "Mi sucursal",
        "confidence": 1
      },
      "income": {
        "value": "1,000.00",
        "confidence": 1
      },
      "commission_percentage": {
        "value": "8%",
        "confidence": 1
      },
      "source_commission": {
        "value": "80.00",
        "confidence": 1
      },
      "source_return_amount": {
        "value": "920.00",
        "confidence": 1
      }
    }
  ],
  "source_totals": {
    "income": {
      "value": "1,000.00",
      "confidence": 1
    },
    "commission": {
      "value": "80.00",
      "confidence": 1
    },
    "return_amount": {
      "value": "920.00",
      "confidence": 1
    }
  }
}

Test2.1:
Imagen: test-images/prueba2.jpeg
Modelo: qwen2.5vl:3b
Tiempo total cliente: 82.07 segundos
Tiempo reportado por Ollama: 82.06 segundos
Contrato v3: REVISAR
- record.week_start_date.value debe usar YYYY-MM-DD o null.
- record.week_end_date.value debe usar YYYY-MM-DD o null.
- details[0].commission_percentage.value debe ser cadena decimal con dos decimales.
- details[0].source_return_amount: value null requiere confidence 0.
- details[0].source_commission.value debe ser cadena decimal con dos decimales.
- details[0].income.value debe ser cadena decimal con dos decimales.
- details[1].commission_percentage.value debe ser cadena decimal con dos decimales.
- details[1].source_return_amount: value null requiere confidence 0.
- details[1].source_commission.value debe ser cadena decimal con dos decimales.
- details[1].income.value debe ser cadena decimal con dos decimales.
- source_totals.return_amount.value debe ser cadena decimal con dos decimales.
- source_totals.commission.value debe ser cadena decimal con dos decimales.
- source_totals.income.value debe ser cadena decimal con dos decimales.
Respuesta:
{
  "record": {
    "company_name": {
      "value": "EMPRESA: EVENTOS",
      "confidence": 1
    },
    "year": {
      "value": 2026,
      "confidence": 1
    },
    "week": {
      "value": 40,
      "confidence": 1
    },
    "week_start_date": {
      "value": "5 de Octubre",
      "confidence": 0.9
    },
    "week_end_date": {
      "value": "11 de Octubre",
      "confidence": 0.9
    }
  },
  "details": [
    {
      "affiliation": {
        "value": "AFIL.-009060269",
        "confidence": 1
      },
      "branch": {
        "value": "CASA TRAPICHE",
        "confidence": 1
      },
      "income": {
        "value": "48.204,21",
        "confidence": 1
      },
      "commission_percentage": {
        "value": "7%",
        "confidence": 1
      },
      "source_commission": {
        "value": "3.374,29",
        "confidence": 1
      },
      "source_return_amount": {
        "value": null,
        "confidence": 0.5
      }
    },
    {
      "affiliation": {
        "value": "AFIL.-009634394",
        "confidence": 1
      },
      "branch": {
        "value": "CASA TRAPICHE",
        "confidence": 1
      },
      "income": {
        "value": "28.384,35",
        "confidence": 1
      },
      "commission_percentage": {
        "value": "7%",
        "confidence": 1
      },
      "source_commission": {
        "value": "1.986,90",
        "confidence": 1
      },
      "source_return_amount": {
        "value": null,
        "confidence": 0.5
      }
    }
  ],
  "source_totals": {
    "income": {
      "value": "76.588,56",
      "confidence": 1
    },
    "commission": {
      "value": "5.361,20",
      "confidence": 1
    },
    "return_amount": {
      "value": "71.227,36",
      "confidence": 1
    }
  }
}

Test2.2:
Imagen: test-images/prueba2.jpeg
Modelo: qwen2.5vl:3b
Tiempo total cliente: 55.02 segundos
Tiempo reportado por Ollama: 55.01 segundos
Contrato v3: REVISAR
- record.week_start_date.value debe usar YYYY-MM-DD o null.
- record.week_end_date.value debe usar YYYY-MM-DD o null.
- details[0].commission_percentage.value debe ser cadena decimal con dos decimales.
- details[0].income.value debe ser cadena decimal con dos decimales.
- details[0].source_commission.value debe ser cadena decimal con dos decimales.
- details[0].source_return_amount: value null requiere confidence 0.
- details[1].commission_percentage.value debe ser cadena decimal con dos decimales.
- details[1].income.value debe ser cadena decimal con dos decimales.
- details[1].source_commission.value debe ser cadena decimal con dos decimales.
- details[1].source_return_amount: value null requiere confidence 0.
- source_totals.return_amount.value debe ser cadena decimal con dos decimales.
- source_totals.commission.value debe ser cadena decimal con dos decimales.
- source_totals.income.value debe ser cadena decimal con dos decimales.
Respuesta:
{
  "record": {
    "company_name": {
      "value": "EMPRESA: EVENTOS",
      "confidence": 1
    },
    "year": {
      "value": 2026,
      "confidence": 1
    },
    "week": {
      "value": 40,
      "confidence": 1
    },
    "week_start_date": {
      "value": "5 de Octubre",
      "confidence": 1
    },
    "week_end_date": {
      "value": "11 de Octubre",
      "confidence": 1
    }
  },
  "details": [
    {
      "affiliation": {
        "value": "AFIL.-009060269",
        "confidence": 1
      },
      "branch": {
        "value": "CASA TRAPICHE",
        "confidence": 1
      },
      "income": {
        "value": "48.204,21",
        "confidence": 0.9
      },
      "commission_percentage": {
        "value": "7%",
        "confidence": 0.9
      },
      "source_commission": {
        "value": "3.374,29",
        "confidence": 0.9
      },
      "source_return_amount": {
        "value": null,
        "confidence": 0.9
      }
    },
    {
      "affiliation": {
        "value": "AFIL.-009634394",
        "confidence": 1
      },
      "branch": {
        "value": "CASA TRAPICHE",
        "confidence": 1
      },
      "income": {
        "value": "28.384,35",
        "confidence": 0.9
      },
      "commission_percentage": {
        "value": "7%",
        "confidence": 0.9
      },
      "source_commission": {
        "value": "1.986,90",
        "confidence": 0.9
      },
      "source_return_amount": {
        "value": null,
        "confidence": 0.9
      }
    }
  ],
  "source_totals": {
    "income": {
      "value": "76.588,56",
      "confidence": 0.9
    },
    "commission": {
      "value": "5.361,20",
      "confidence": 0.9
    },
    "return_amount": {
      "value": "71.227,36",
      "confidence": 0.9
    }
  }
}

Test 3.1:
Imagen: test-images/prueba3.jpeg
Modelo: qwen2.5vl:3b
Tiempo total cliente: 83.13 segundos
Tiempo reportado por Ollama: 83.12 segundos
Contrato v3: REVISAR
- details[0].commission_percentage.value debe ser cadena decimal con dos decimales.
- details[0].income.value debe ser cadena decimal con dos decimales.
- details[0].source_return_amount: value null requiere confidence 0.
- details[1].commission_percentage.value debe ser cadena decimal con dos decimales.
- details[1].income.value debe ser cadena decimal con dos decimales.
- details[1].source_return_amount: value null requiere confidence 0.
- source_totals.income.value debe ser cadena decimal con dos decimales.
- source_totals.return_amount.value debe ser cadena decimal con dos decimales.
Respuesta:
{
  "record": {
    "company_name": {
      "value": "LUUM",
      "confidence": 1
    },
    "year": {
      "value": 2026,
      "confidence": 1
    },
    "week": {
      "value": 10,
      "confidence": 1
    },
    "week_start_date": {
      "value": "2026-03-09",
      "confidence": 1
    },
    "week_end_date": {
      "value": "2026-03-15",
      "confidence": 1
    }
  },
  "details": [
    {
      "affiliation": {
        "value": "AFIL.- 009538185",
        "confidence": 1
      },
      "branch": {
        "value": "JAMAICA",
        "confidence": 1
      },
      "income": {
        "value": "6,073.00",
        "confidence": 1
      },
      "commission_percentage": {
        "value": "6.5%",
        "confidence": 1
      },
      "source_commission": {
        "value": "394.75",
        "confidence": 0.9
      },
      "source_return_amount": {
        "value": null,
        "confidence": 0.9
      }
    },
    {
      "affiliation": {
        "value": "TOTAL",
        "confidence": 0.9
      },
      "branch": {
        "value": "INGRESOS",
        "confidence": 0.9
      },
      "income": {
        "value": "6,073.00",
        "confidence": 1
      },
      "commission_percentage": {
        "value": "6.5%",
        "confidence": 1
      },
      "source_commission": {
        "value": "394.75",
        "confidence": 0.9
      },
      "source_return_amount": {
        "value": null,
        "confidence": 0.9
      }
    }
  ],
  "source_totals": {
    "income": {
      "value": "6,073.00",
      "confidence": 1
    },
    "commission": {
      "value": "394.75",
      "confidence": 1
    },
    "return_amount": {
      "value": "5,678.26",
      "confidence": 1
    }
  }
}

Test 3.2:
Imagen: test-images/prueba3.jpeg
Modelo: qwen2.5vl:3b
Tiempo total cliente: 53.21 segundos
Tiempo reportado por Ollama: 53.20 segundos
Contrato v3: REVISAR
- details[0].income.value debe ser cadena decimal con dos decimales.
- details[0].source_return_amount: value null requiere confidence 0.
- details[0].commission_percentage.value debe ser cadena decimal con dos decimales.
- details[1].income.value debe ser cadena decimal con dos decimales.
- details[1].source_return_amount: value null requiere confidence 0.
- details[1].commission_percentage.value debe ser cadena decimal con dos decimales.
- source_totals.income.value debe ser cadena decimal con dos decimales.
- source_totals.return_amount.value debe ser cadena decimal con dos decimales.
Respuesta:
{
  "record": {
    "company_name": {
      "value": "LUUM",
      "confidence": 1
    },
    "year": {
      "value": 2026,
      "confidence": 1
    },
    "week": {
      "value": 10,
      "confidence": 1
    },
    "week_start_date": {
      "value": "2026-03-09",
      "confidence": 0.9
    },
    "week_end_date": {
      "value": "2026-03-15",
      "confidence": 0.9
    }
  },
  "details": [
    {
      "affiliation": {
        "value": "AFIL.- 009538185",
        "confidence": 1
      },
      "branch": {
        "value": "JAMAICA",
        "confidence": 1
      },
      "income": {
        "value": "6,073.00",
        "confidence": 1
      },
      "commission_percentage": {
        "value": "6.5%",
        "confidence": 1
      },
      "source_commission": {
        "value": "394.75",
        "confidence": 1
      },
      "source_return_amount": {
        "value": null,
        "confidence": 0.5
      }
    },
    {
      "affiliation": {
        "value": "AFIL.- 009538185",
        "confidence": 1
      },
      "branch": {
        "value": "JAMAICA",
        "confidence": 1
      },
      "income": {
        "value": "6,073.00",
        "confidence": 1
      },
      "commission_percentage": {
        "value": "6.5%",
        "confidence": 1
      },
      "source_commission": {
        "value": "394.75",
        "confidence": 1
      },
      "source_return_amount": {
        "value": null,
        "confidence": 0.5
      }
    }
  ],
  "source_totals": {
    "income": {
      "value": "6,073.00",
      "confidence": 1
    },
    "commission": {
      "value": "394.75",
      "confidence": 1
    },
    "return_amount": {
      "value": "5,678.26",
      "confidence": 1
    }
  }
}

Test 4.1
Imagen: test-images/prueba4.jpeg
Modelo: qwen2.5vl:3b
Tiempo total cliente: 80.51 segundos
Tiempo reportado por Ollama: 80.50 segundos
Contrato v3: REVISAR
- record.week_start_date.value debe usar YYYY-MM-DD o null.
- record.week_end_date.value debe usar YYYY-MM-DD o null.
- details[0].income.value debe ser cadena decimal con dos decimales.
- details[0].commission_percentage.value debe ser cadena decimal con dos decimales.
- details[0].source_return_amount: value null requiere confidence 0.
- details[1].branch: value null requiere confidence 0.
- details[1].income.value debe ser cadena decimal con dos decimales.
- details[1].commission_percentage.value debe ser cadena decimal con dos decimales.
- details[1].source_return_amount: value null requiere confidence 0.
- details[1].affiliation: value null requiere confidence 0.
- source_totals.return_amount.value debe ser cadena decimal con dos decimales.
- source_totals.income.value debe ser cadena decimal con dos decimales.
Respuesta:
{
  "record": {
    "company_name": {
      "value": "Defentia",
      "confidence": 1
    },
    "year": {
      "value": 2026,
      "confidence": 1
    },
    "week": {
      "value": 36,
      "confidence": 1
    },
    "week_start_date": {
      "value": "01/09/2026",
      "confidence": 0.9
    },
    "week_end_date": {
      "value": "15/09/2026",
      "confidence": 0.9
    }
  },
  "details": [
    {
      "affiliation": {
        "value": "AFIL.-1234567",
        "confidence": 1
      },
      "branch": {
        "value": "INNOVA",
        "confidence": 1
      },
      "income": {
        "value": "2,000.00",
        "confidence": 1
      },
      "commission_percentage": {
        "value": "8%",
        "confidence": 1
      },
      "source_commission": {
        "value": "160.00",
        "confidence": 1
      },
      "source_return_amount": {
        "value": null,
        "confidence": 0.5
      }
    },
    {
      "affiliation": {
        "value": null,
        "confidence": 0.5
      },
      "branch": {
        "value": null,
        "confidence": 0.5
      },
      "income": {
        "value": "2,000.00",
        "confidence": 1
      },
      "commission_percentage": {
        "value": "8%",
        "confidence": 1
      },
      "source_commission": {
        "value": "160.00",
        "confidence": 1
      },
      "source_return_amount": {
        "value": null,
        "confidence": 0.5
      }
    }
  ],
  "source_totals": {
    "income": {
      "value": "2,000.00",
      "confidence": 1
    },
    "commission": {
      "value": "160.00",
      "confidence": 1
    },
    "return_amount": {
      "value": "1,840.00",
      "confidence": 1
    }
  }
}

Test 4.2

Imagen: test-images/prueba4.jpeg
Modelo: qwen2.5vl:3b
Tiempo total cliente: 48.75 segundos
Tiempo reportado por Ollama: 48.74 segundos
Contrato v3: REVISAR
- record.week_end_date.value debe usar YYYY-MM-DD o null.
- record.week_start_date.value debe usar YYYY-MM-DD o null.
- details[0].income.value debe ser cadena decimal con dos decimales.
- details[0].commission_percentage.value debe ser cadena decimal con dos decimales.
- details[1].income.value debe ser cadena decimal con dos decimales.
- details[1].commission_percentage.value debe ser cadena decimal con dos decimales.
- source_totals.return_amount.value debe ser cadena decimal con dos decimales.
- source_totals.income.value debe ser cadena decimal con dos decimales.
Respuesta:
{
  "record": {
    "company_name": {
      "value": "Defentia",
      "confidence": 1
    },
    "year": {
      "value": 2026,
      "confidence": 1
    },
    "week": {
      "value": 36,
      "confidence": 1
    },
    "week_start_date": {
      "value": "01",
      "confidence": 1
    },
    "week_end_date": {
      "value": "15",
      "confidence": 1
    }
  },
  "details": [
    {
      "affiliation": {
        "value": "AFIL.-1234567",
        "confidence": 1
      },
      "branch": {
        "value": "INNOVA",
        "confidence": 1
      },
      "income": {
        "value": "2,000.00",
        "confidence": 1
      },
      "commission_percentage": {
        "value": "8%",
        "confidence": 1
      },
      "source_commission": {
        "value": "160.00",
        "confidence": 1
      },
      "source_return_amount": {
        "value": null,
        "confidence": 0
      }
    },
    {
      "affiliation": {
        "value": null,
        "confidence": 0
      },
      "branch": {
        "value": null,
        "confidence": 0
      },
      "income": {
        "value": "2,000.00",
        "confidence": 1
      },
      "commission_percentage": {
        "value": "8%",
        "confidence": 1
      },
      "source_commission": {
        "value": "160.00",
        "confidence": 1
      },
      "source_return_amount": {
        "value": null,
        "confidence": 0
      }
    }
  ],
  "source_totals": {
    "income": {
      "value": "2,000.00",
      "confidence": 1
    },
    "commission": {
      "value": "160.00",
      "confidence": 1
    },
    "return_amount": {
      "value": "1,840.00",
      "confidence": 1
    }
  }
}

Test 5.1 
Imagen: test-images/prueba5.jpeg
Modelo: qwen2.5vl:3b
Tiempo total cliente: 84.95 segundos
Tiempo reportado por Ollama: 84.95 segundos
Contrato v3: REVISAR
- record.week_start_date.value debe usar YYYY-MM-DD o null.
- record.week_end_date.value debe usar YYYY-MM-DD o null.
- details[0].commission_percentage.value debe ser cadena decimal con dos decimales.
- details[0].income.value debe ser cadena decimal con dos decimales.
- details[0].source_commission.value debe ser cadena decimal con dos decimales.
- details[1].commission_percentage.value debe ser cadena decimal con dos decimales.
- details[1].income.value debe ser cadena decimal con dos decimales.
- details[1].source_commission.value debe ser cadena decimal con dos decimales.
- source_totals.commission.value debe ser cadena decimal con dos decimales.
- source_totals.return_amount.value debe ser cadena decimal con dos decimales.
- source_totals.income.value debe ser cadena decimal con dos decimales.
Respuesta:
{
  "record": {
    "company_name": {
      "value": "LUUM",
      "confidence": 1
    },
    "year": {
      "value": 2026,
      "confidence": 1
    },
    "week": {
      "value": 39,
      "confidence": 1
    },
    "week_start_date": {
      "value": "28 de Septiembre",
      "confidence": 0.9
    },
    "week_end_date": {
      "value": "4 de Octubre",
      "confidence": 0.9
    }
  },
  "details": [
    {
      "affiliation": {
        "value": "AFIL.-9574699",
        "confidence": 1
      },
      "branch": {
        "value": "NEJAYOTE MOLINO",
        "confidence": 1
      },
      "income": {
        "value": "55.963,03",
        "confidence": 1
      },
      "commission_percentage": {
        "value": "6%",
        "confidence": 1
      },
      "source_commission": {
        "value": "3.357,78",
        "confidence": 1
      },
      "source_return_amount": {
        "value": null,
        "confidence": 0
      }
    },
    {
      "affiliation": {
        "value": "AFIL.-9757895",
        "confidence": 1
      },
      "branch": {
        "value": "NEJAYOTE MOLINO",
        "confidence": 1
      },
      "income": {
        "value": "44.971,67",
        "confidence": 1
      },
      "commission_percentage": {
        "value": "6%",
        "confidence": 1
      },
      "source_commission": {
        "value": "2.698,30",
        "confidence": 1
      },
      "source_return_amount": {
        "value": null,
        "confidence": 0
      }
    }
  ],
  "source_totals": {
    "income": {
      "value": "100.934,70",
      "confidence": 1
    },
    "commission": {
      "value": "6.056,08",
      "confidence": 1
    },
    "return_amount": {
      "value": "94.878,62",
      "confidence": 1
    }
  }
}

Test 5.2

Imagen: test-images/prueba5.jpeg
Modelo: qwen2.5vl:3b
Tiempo total cliente: 52.76 segundos
Tiempo reportado por Ollama: 52.75 segundos
Contrato v3: REVISAR
- record.week_start_date.value debe usar YYYY-MM-DD o null.
- record.week_end_date.value debe usar YYYY-MM-DD o null.
- details[0].income.value debe ser cadena decimal con dos decimales.
- details[0].source_commission.value debe ser cadena decimal con dos decimales.
- details[0].commission_percentage.value debe ser cadena decimal con dos decimales.
- details[1].income.value debe ser cadena decimal con dos decimales.
- details[1].source_commission.value debe ser cadena decimal con dos decimales.
- details[1].commission_percentage.value debe ser cadena decimal con dos decimales.
- source_totals.income.value debe ser cadena decimal con dos decimales.
- source_totals.return_amount.value debe ser cadena decimal con dos decimales.
- source_totals.commission.value debe ser cadena decimal con dos decimales.
Respuesta:
{
  "record": {
    "company_name": {
      "value": "LUUM",
      "confidence": 1
    },
    "year": {
      "value": 2026,
      "confidence": 1
    },
    "week": {
      "value": 39,
      "confidence": 1
    },
    "week_start_date": {
      "value": "28 de Septiembre",
      "confidence": 0.9
    },
    "week_end_date": {
      "value": "4 de Octubre",
      "confidence": 0.9
    }
  },
  "details": [
    {
      "affiliation": {
        "value": "AFIL.-9574699",
        "confidence": 1
      },
      "branch": {
        "value": "NEJAYOTE MOLINO",
        "confidence": 1
      },
      "income": {
        "value": "55.963,03",
        "confidence": 1
      },
      "commission_percentage": {
        "value": "6%",
        "confidence": 1
      },
      "source_commission": {
        "value": "3.357,78",
        "confidence": 1
      },
      "source_return_amount": {
        "value": null,
        "confidence": 0
      }
    },
    {
      "affiliation": {
        "value": "AFIL.-9757895",
        "confidence": 1
      },
      "branch": {
        "value": "NEJAYOTE MOLINO",
        "confidence": 1
      },
      "income": {
        "value": "44.971,67",
        "confidence": 1
      },
      "commission_percentage": {
        "value": "6%",
        "confidence": 1
      },
      "source_commission": {
        "value": "2.698,30",
        "confidence": 1
      },
      "source_return_amount": {
        "value": null,
        "confidence": 0
      }
    }
  ],
  "source_totals": {
    "income": {
      "value": "100.934,70",
      "confidence": 1
    },
    "commission": {
      "value": "6.056,08",
      "confidence": 1
    },
    "return_amount": {
      "value": "94.878,62",
      "confidence": 1
    }
  }
}
