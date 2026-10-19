# AI Spike — Benchmark v2

## Status and scope

Second extraction-contract round for roadmap phase 8.8. Five images were each processed twice with the same Ollama model and v2 prompt. The user has checked and corrected the expected values against the source images. Memory/resource usage was not measured, so the hardware feasibility assessment remains incomplete.

## Environment

| Item | Value |
| --- | --- |
| Runtime | Ollama |
| Model | `qwen2.5vl:3b` |
| Hardware | Debian server, Intel Core i5-12400, 16 GB RAM, no dedicated GPU |
| Samples | 5 document images |
| Repetitions | 2 per image (10 runs) |
| Maximum acceptable processing time | 5 minutes per image |
| Prompt/contract | Spanish [`prompt-v2.txt`](prompt-v2.txt); Ollama `format: "json"` plus local contract checks |

Full expected responses and raw model outputs are recorded in [`benchmark-v2-raw.txt`](benchmark-v2-raw.txt). Expected responses were reshaped from the v1 references and corrected against the original images, including the dates in sample 1 and sample 3 income/return amounts. Formatting-only normalization in the expected JSON converts values to the v2 canonical decimal strings. This round used prompt v2; the executable runner now uses prompt v3.

## Latency

| Image | Repetition 1 | Repetition 2 | Mean |
| --- | ---: | ---: | ---: |
| `prueba1.jpeg` | 42.87 s | 34.17 s | 38.52 s |
| `prueba2.jpeg` | 86.14 s | 50.49 s | 68.32 s |
| `prueba3.jpeg` | 72.45 s | 35.16 s | 53.81 s |
| `prueba4.jpeg` | 72.07 s | 36.12 s | 54.10 s |
| `prueba5.jpeg` | 51.84 s | 90.35 s | 71.10 s |
| **All runs** |  |  | **57.17 s average** |

The median across all ten runs is **51.17 seconds**; the range is **34.17–90.35 seconds**. All recorded runs finished below the five-minute target. Client and Ollama timings were nearly identical in the captured output. No memory, CPU-load, or temperature readings were recorded.

## Contract adherence

| Image | Valid responses | Repetitions | Main observed issue |
| --- | ---: | ---: | --- |
| `prueba1.jpeg` | 0 | 2 | Income includes thousands separator (`"1,000.00"`); both runs return incorrect start and end dates versus the verified reference. |
| `prueba2.jpeg` | 2 | 2 | Both outputs passed structural/type checks and match the verified reference. |
| `prueba3.jpeg` | 0 | 2 | Values match the verified reference, but income/return use thousands separators and percentage includes `%`. |
| `prueba4.jpeg` | 0 | 2 | Values match the verified reference, but amounts use thousands separators and percentage includes `%`. |
| `prueba5.jpeg` | 0 | 2 | Dot used as thousands separator (`"55.963.03"`); null return fields have confidence `0.99` instead of `0`. |
| **Total** | **2** | **10** | **20% contract-valid responses** |

“Contract valid” means the runner accepted the JSON structure and basic types. It does not measure semantic extraction correctness; that is reviewed separately below against the corrected references.

### Semantic comparison against corrected references

The corrected references allow a separate review of what the model read versus how it serialized the values:

- `prueba1`: company, week, and movement values agree, but both runs misread the period dates. The income is also not in canonical format.
- `prueba2`: both runs match the corrected reference and pass the contract validator.
- `prueba3`: extracted values, including the now-confirmed `6073.00` income and `5678.26` return, agree with the corrected reference after interpreting the thousands separator and percent sign. These are transcribed source values, not backend-calculated official amounts.
- `prueba4`: the general and movement values agree after interpreting the thousands separator and percent sign, except the model returned `source_return_amount` as `1840.00` while the corrected reference is `null`. If the image does not print a per-movement return amount, this is an unsupported inference and must be treated as an extraction error.
- `prueba5`: monetary values appear to agree when the repeated dots are interpreted as a formatting error, but the output is malformed for the contract and null values have invalid confidence. This must not be silently interpreted by production code without an explicitly tested normalization rule.

Thus, semantic reading is encouraging on samples 2–5, with a clear date-reading failure on sample 1. This is a qualitative comparison, not a formal accuracy percentage: values requiring locale interpretation have not been counted as canonical matches. Exact contract adherence remains 2/10.

## Findings

- **Performance:** Strong for this workload so far. The slowest observed run, 90.35 seconds, is well below the agreed five-minute ceiling on the CPU-only test server.
- **Repeatability:** Each pair returned the same broad structure and generally the same extracted values. Latency varied substantially between repetitions, from roughly 34 to 90 seconds.
- **Formatting remains the main weakness:** The model still emits locale-specific thousands separators and percent symbols despite the prompt example and normalization instruction. These responses are correctly rejected by the contract checker.
- **Missing-value confidence needs improvement:** In the fifth sample, `source_return_amount` is null while confidence is `0.99`; contract requires null with confidence zero.
- **Reading errors remain possible:** Both sample 1 runs return `2026-07-07` and `2026-09-13`; the user-corrected reference is `2026-10-07` through `2026-10-13`. This is a genuine date extraction error, not a reference mistake.
- **Confidence is not accuracy:** The model frequently reports confidence near `1.0`, including on values that fail format checks or may not match the reference.
- **Resource feasibility is incomplete:** RAM/GPU use, CPU utilization and temperature were not captured. The server has no dedicated GPU.

## Assessment

**Decision: optimize and run a focused follow-up before the phase 8.8 go/no-go checkpoint.** The latency target is met comfortably, and the new per-movement structure works for the tested multi-movement output. However, only 2 of 10 responses passed the v2 contract checker, so the extraction contract is not yet reliably met. Test a prompt adjustment that explicitly distinguishes the document's number formats, requests canonical decimal output, and emphasizes exact date transcription. Keep the prompt fixed during each follow-up round and record it as a new prompt version.

Do not silently normalize ambiguous numeric strings in the application. A string such as `"55.963.03"` is malformed and should remain a review/validation issue unless a deterministic, unambiguous normalization rule is separately approved and tested.

## Follow-up actions

1. Capture host RAM and CPU use during cold and warm runs; note whether the model was already loaded.
2. Test prompt v3 on the same five images twice with unchanged configuration, preserving raw outputs and timings.
3. Compare v3 with v2 on formatting, date transcription, null/confidence consistency, and semantic accuracy.
4. Decide whether to proceed with this model, optimize further, or compare another vision model after checking contract adherence, semantic accuracy, latency and resource usage together.

The proposed v2 extraction shape differs from the current application/domain model in where `affiliation` and `branch` live and in omitting AI-extracted `operation_number`. Resolve that alignment separately before implementing phase 8.9.
