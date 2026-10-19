# AI Spike — Benchmark v1

## Status

Initial exploratory run for roadmap phase 8.8. Five images were tested once each using Ollama and `qwen2.5vl:3b`. This is preliminary evidence, not the completed 8.8 checkpoint.

## Setup

| Item | Value |
| --- | --- |
| Runtime | Ollama |
| Model | `qwen2.5vl:3b` |
| Test hardware | Debian server, Intel Core i5-12400, 16 GB RAM, no dedicated GPU |
| Samples | 5 representative document images |
| Repetitions | 1 per image |
| Agreed maximum processing time | 5 minutes per image |

The original prompt, expected outputs, and model responses are preserved in [the raw v1 transcript](benchmark-v1-raw.txt). The expected outputs have not been independently verified against the source images and must not yet be treated as benchmark ground truth.

## Recorded latency

| Sample | Movements noted | Ollama-reported time | Under 5-minute target |
| --- | ---: | ---: | --- |
| 1 | 1 | 35.97 s | Yes |
| 2 | 2 | 46.11 s | Yes |
| 3 | 1 | Not recorded | Not measured |
| 4 | 1 | 61.06 s | Yes |
| 5 | 2 | 80.22 s | Yes |

The four recorded times range from 35.97 to 80.22 seconds. Client-side end-to-end timing and memory/hardware utilization were not captured in this round.

## Observations

- The model generally identified visible fields and returned multiple movements in the examples tested.
- It repeatedly failed the requested canonical number formatting: outputs included locale-specific decimal separators, thousands separators, currency symbols, and `%` signs.
- It returned confidence `1` even for malformed or incorrect values. Confidence must not be interpreted as measured accuracy.
- At least one date was returned incomplete or incorrect (`"01"` where a full date was expected).
- Some expected outputs in the raw transcript are invalid JSON, use confidence values of `100` despite the prompt's 0–1 range, or contain values that need checking against their images.
- Sample 1's expected totals require verification: expected extraction must transcribe printed totals only, not calculate totals that are absent from the image.
- The initial extraction contract placed `affiliation` and `branch` at record level and included `operation_number`. Review of the samples led to a revised v2 direction: affiliation and branch belong to each movement; omit operation number from AI extraction; derive movement order from the `details` array position.

## Assessment

**Preliminary result: promising; continue the spike with a revised contract and prompt.** Recorded latency is comfortably below the agreed five-minute limit, but repeatability, accuracy against verified references, contract adherence, and resource use have not yet been established. Do not use this v1 run alone to mark phase 8.8 complete or to make production integration decisions.

## Next round

1. Verify each sample's reference values directly against the image.
2. Use the revised v2 extraction structure and prompt in `tools/ai-spike/run_ollama_spike.py`.
3. Run every image at least twice with unchanged configuration.
4. Record client and Ollama durations, JSON/contract validity, field-level correctness, movement count/order, repeatability, and memory/hardware use.
5. Document a go/optimize/change decision after the measurements.

Example invocation from the repository root:

```bash
python3 tools/ai-spike/run_ollama_spike.py test-images/sample-01.jpeg
```

Images belong in the ignored `test-images/` directory and should not be committed.
