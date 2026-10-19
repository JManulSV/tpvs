# AI Spike — Benchmark v3

## Status and decision

The five representative images were each processed twice with the English v3 prompt and `qwen2.5vl:3b`. All ten runs finished below the agreed five-minute limit, but **0/10 responses passed local contract validation**. The structured-output setting did not reliably enforce the requested date and decimal formats, and the model sometimes produced extra movement rows.

**Decision: OPTIMIZE before production integration.** The model remains promising on latency and can read many of the visible values, but v3 does not yet reliably satisfy the extraction contract. Do not begin the production extraction pipeline on the assumption that the JSON Schema alone guarantees valid data.

## Environment

| Item | Value |
| --- | --- |
| Runtime | Ollama in Docker (`ollama/ollama:latest`) |
| Model | `qwen2.5vl:3b` |
| Hardware reported for target host | Debian, Intel Core i5-12400, 16 GB RAM, no dedicated GPU |
| Samples | 5 representative document images |
| Repetitions | 2 per image; 10 total |
| Time limit | 5 minutes per image |
| Prompt | English v3 in `tools/ai-spike/run_ollama_spike.py`, with Ollama JSON Schema format and local validation |

Expected values are in [`benchmark-v2-raw.txt`](benchmark-v2-raw.txt), corrected against the images by the user. Raw v3 outputs and controlled-failure diagnostics are in [`benchmark-v3-raw.md`](benchmark-v3-raw.md).

## Latency

| Image | Run 1 | Run 2 | Mean |
| --- | ---: | ---: | ---: |
| `prueba1.jpeg` | 79.52 s | 37.01 s | 58.27 s |
| `prueba2.jpeg` | 82.07 s | 55.02 s | 68.55 s |
| `prueba3.jpeg` | 83.13 s | 53.21 s | 68.17 s |
| `prueba4.jpeg` | 80.51 s | 48.75 s | 64.63 s |
| `prueba5.jpeg` | 84.95 s | 52.76 s | 68.86 s |
| **All 10 runs** |  |  | **65.69 s average** |

Median: **67.27 s**. Range: **37.01–84.95 s**. All ten runs met the five-minute processing target. The second run was faster for every image, consistent with model warm-up/cache effects, though the benchmark did not independently control model residency.

## Contract adherence and observed extraction issues

| Image | Contract-valid runs | Main observations compared with the corrected reference |
| --- | ---: | --- |
| `prueba1.jpeg` | 0/2 | Each run returned two copies of the single expected movement. Dates are `DD/MM/YYYY`, income has a grouping comma, and percentage includes `%`. One run has null returns with confidence `0.5`. |
| `prueba2.jpeg` | 0/2 | Correct two movement rows, but dates are natural-language strings and money uses European separators. Printed totals differ from the reference by one cent (`5361.20` vs `5361.19`; `71227.36` vs `71227.37`). |
| `prueba3.jpeg` | 0/2 | The image has one movement, but both outputs include a second row representing `TOTAL INGRESOS` as if it were a movement. Values use grouping commas/percent signs; missing per-detail returns have non-zero confidence. |
| `prueba4.jpeg` | 0/2 | Each run returned two rows for the one expected movement. Dates are localized or incomplete; one run returns null affiliation/branch with confidence `0.5`. |
| `prueba5.jpeg` | 0/2 | Movement count is two, but dates are natural-language strings and numbers use European separators. |
| **Total** | **0/10** | None met the strict v3 structure/type/format checks. |

The runner's local validation correctly rejected every response. Ollama's JSON Schema output helped produce parseable JSON with the expected broad object shape, but did not ensure the date and decimal string patterns in the returned values. The two-row minimum in details also allows summary rows to be represented as detail items. Structural/format validity is separate from semantic correctness; the table notes observed semantic errors without claiming a field-level accuracy percentage.

## Resource measurements

During the five representative image tests, the user sampled `docker stats` approximately every two seconds. Per-image observed maxima were:

| Image | Highest sampled CPU | Highest sampled container memory |
| --- | ---: | ---: |
| `prueba1.jpeg` | 602.07% | 3.967 GiB |
| `prueba2.jpeg` | 602.58% | 4.067 GiB |
| `prueba3.jpeg` | 602.07% | 4.183 GiB |
| `prueba4.jpeg` | 601.72% | 4.281 GiB |
| `prueba5.jpeg` | 602.49% | 4.381 GiB |
| **Observed maximum** | **602.58%** | **4.381 GiB** |

The readings are sampled maxima, not exact instantaneous peaks. Docker CPU percentages are aggregate multi-core use, so values near 600% indicate use of about six logical CPUs at that instant, not an error. Container memory stayed below 30% of the Docker-reported 14.86 GiB host memory during the observations. Earlier synthetic-image snapshots are retained in the raw report but are not used for this representative workload assessment.

## Controlled failures

The runner's controlled failures behaved as expected (details and exact output in the raw report):

- Missing local image: exits `2` with a clear file-not-found message before making a request.
- Ollama connection refused: exits `1` with a concise connection error.
- Model not found: exits `1` and surfaces Ollama's HTTP 404 response.

These checks cover the standalone spike runner only. Queue retries and Laravel `ERROR` state behavior are part of phase 8.9.

## Assessment and next work

The v3 prompt/schema change **did not resolve format adherence** and exposed additional multiple-row classification errors. The latency remains acceptable for the expected low-volume asynchronous workflow. Keep the current model as a candidate while optimizing extraction, not as a production-ready provider yet.

The technical spike checkpoint is complete with an **OPTIMIZE** decision: the time/resource envelope is acceptable for the observed low-volume workload, but the candidate does not meet output-contract quality for production. Optimize and validate the extraction before relying on it in the production pipeline. Remaining candidate work:

1. Capture RAM and CPU during at least one representative image inference, preferably polling `docker stats` at a short interval to record an observed peak; record cold/warm state.
2. Investigate why summary/total rows are emitted as detail movements; refine the prompt/schema or adjust the model candidate, then run a focused comparison on samples 1 and 3 plus one multi-movement sample.
3. Preserve the current v3 raw output and record any follow-up prompt/model as a new version rather than overwriting it.
4. Record any follow-up prompt/model benchmark as a new version and use its accuracy, contract adherence, repeatability, latency, and resource measurements to decide production suitability during phase 8.9 preparation.

Do not silently coerce ambiguous numeric strings. Clearly unambiguous transformations such as `8%` to `8.00` may be considered later as a separately tested backend normalization rule, while locale-ambiguous or malformed values must remain review issues. The extraction shape also still needs alignment with the existing application/domain model before phase 8.9.
