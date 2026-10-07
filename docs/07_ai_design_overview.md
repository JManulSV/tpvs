# Fase 5 Summary

This file is a short implementation-oriented summary of the AI Design phase.

## Core Contract

- AI reads the image and returns structured JSON.
- Backend validates structure and business rules.
- Human confirms the record.
- Only `CONFIRMED` is official.

## Key Components

- `AIService`
- `OllamaAIService`
- `ExtractionValidator`
- `BusinessValidator`
- `RecordExtractionService`
- `AIProcessing`

## Key Rules

- Do not invent values.
- Keep all expected JSON properties.
- Use `null` and `confidence = 0` when a field is unreadable.
- Preserve the visual order of movements.
- Do not auto-confirm AI output.
- Do not calculate official business truth in the AI layer.

## Main Flow

```text
Image
  -> AIService
  -> ExtractionValidator
  -> BusinessValidator
  -> RecordExtractionService
  -> Record + RecordDetails
  -> Human review
  -> CONFIRMED
```
