# AI Spike — Benchmark v4 / SPEC 06

## Decision

**Recommended candidate for SPEC 08: `qwen2.5vl:3b`.** It had the highest exact visible-field agreement with the corrected references (138/154 non-null reference fields across ten inferences), followed by `gemma3:4b` (128/154) and `minicpm-v:8b` (94/154). This follows the SPEC 06 priority order: visible-field transcription first, movement count/order second, structural validity third, latency fourth. There is no minimum accuracy gate.

This is a relative candidate selection, not a claim that any model is production-ready without review. All ten `qwen2.5vl:3b` responses were structurally invalid under the required null/confidence rule, and it returned the expected number of movement rows in only four of ten runs. SPEC 08 must retain the extraction evidence and make the result available for human review. `gemma3:4b` is the structural-validity alternative if later implementation findings change the selection priorities.

## Controlled setup

| Item | Value |
| --- | --- |
| Runtime | Ollama API `0.40.5` at `http://localhost:11434` |
| Prompt | English v4, [`prompt-v4.txt`](prompt-v4.txt), unchanged for all candidates |
| Output format | Same `EXTRACTION_SCHEMA`, Ollama structured output, local normalizer and validator |
| Samples | `test-images/prueba1.jpeg` … `test-images/prueba5.jpeg` (local ignored files; not committed) |
| Models | `gemma3:4b`, `qwen2.5vl:3b`, `minicpm-v:8b` |
| Repetitions | Two per image/model; 30 total requests |
| Execution order | Round 1: gemma3, qwen2.5vl, minicpm-v. Round 2: minicpm-v, qwen2.5vl, gemma3. Images 1–5 within each group. Strictly sequential. |
| References | Corrected expected values in [`benchmark-v2-raw.txt`](benchmark-v2-raw.txt) |

The final controlled run used one frozen runner version for all 30 calls. Raw outputs were kept outside the repository at `/tmp/opencode/spec-06-benchmark-final/` and are not versioned. An earlier exploratory set is also local at `/tmp/opencode/spec-06-benchmark/`; it is excluded because a validator change happened during that run.

## Aggregate results

| Model | Exact visible fields | Correct movement count | Structurally valid | Usable for review | Mean latency | Median latency |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| `qwen2.5vl:3b` | **138/154** | 4/10 | 0/10 | 0/10 | **91.9 s** | 89.3 s |
| `gemma3:4b` | 128/154 | **6/10** | **10/10** | 9/10 | 109.2 s | 90.0 s |
| `minicpm-v:8b` | 94/154 | 3/10 | 10/10 | 6/10 | 178.7 s | 169.6 s |

All measured request latencies were below five minutes; ranges were 83.1–107.3 s for Qwen, 51.1–202.7 s for Gemma, and 89.8–261.9 s for MiniCPM. The runner returned a structural error for Qwen before value normalization, so its usable count is zero by the structural contract, not by the five-value threshold. For Gemma, nine outputs were usable for review; its remaining output had five or more unconvertible numeric values.

## Per-model, per-image comparison

“Field matches” compares non-null corrected reference values at their expected locations after only the approved deterministic numeric normalization. Movement count is correct when the returned `details` length matches the reference. Structural validity uses the runner's structural result; latencies are the mean of the two serial runs.

| Model | Image | Field matches | Movement count correct | Structural | Mean latency |
| --- | --- | ---: | ---: | ---: | ---: |
| `qwen2.5vl:3b` | `prueba1.jpeg` | 22/28 | 0/2 | 0/2 | 106.7 s |
|  | `prueba2.jpeg` | 30/36 | 2/2 | 0/2 | 89.2 s |
|  | `prueba3.jpeg` | 24/28 | 0/2 | 0/2 | 89.4 s |
|  | `prueba4.jpeg` | 26/26 | 0/2 | 0/2 | 84.9 s |
|  | `prueba5.jpeg` | 36/36 | 2/2 | 0/2 | 89.2 s |
| `gemma3:4b` | `prueba1.jpeg` | 21/28 | 2/2 | 2/2 | 72.6 s |
|  | `prueba2.jpeg` | 24/36 | 0/2 | 2/2 | 183.3 s |
|  | `prueba3.jpeg` | 21/28 | 2/2 | 2/2 | 75.9 s |
|  | `prueba4.jpeg` | 26/26 | 2/2 | 2/2 | 62.9 s |
|  | `prueba5.jpeg` | 36/36 | 0/2 | 2/2 | 151.5 s |
| `minicpm-v:8b` | `prueba1.jpeg` | 9/28 | 1/2 | 2/2 | 142.6 s |
|  | `prueba2.jpeg` | 19/36 | 0/2 | 2/2 | 212.8 s |
|  | `prueba3.jpeg` | 17/28 | 2/2 | 2/2 | 103.0 s |
|  | `prueba4.jpeg` | 23/26 | 0/2 | 2/2 | 212.0 s |
|  | `prueba5.jpeg` | 26/36 | 0/2 | 2/2 | 223.4 s |

## Observations

- Qwen had the strongest aggregate transcription agreement and the shortest, most consistent latency. All its outputs violated the `null` plus confidence `0` requirement, so none passed structural validation. Its outputs also included duplicate or additional movement rows in samples 1, 3, and 4.
- Gemma passed structural validation in all runs and had the best movement-count agreement. It added three or more movement rows on both runs for samples 2 and 5, despite otherwise matching many reference values.
- MiniCPM passed structural validation in all ten runs but had the lowest field agreement, the fewest correct movement counts, and the highest latency. Four outputs reached the non-usable five-value threshold.
- Semantic correctness and output-contract validity are distinct. Confidence values are not treated as accuracy; no model is automatically confirmed by these results.
- The per-model field score is a location-by-location comparison against non-null reference values; extra rows are captured separately by the movement-count metric and are not credited as correct reference fields.

## Recommendation for SPEC 08

Use `qwen2.5vl:3b` as the initial candidate because the spec gives visible-field transcription first priority, then movement count/order, structure, and latency. Carry forward the requirement to preserve raw model output and normalized evidence, classify structural failures distinctly, and present usable partial extractions for human review. Do not apply semantic corrections to movement rows or claim the benchmark establishes a minimum accuracy level.

The controlled experiment is complete and does not block SPEC 07 or SPEC 08. Production provider behavior and persistence remain within SPEC 08.
