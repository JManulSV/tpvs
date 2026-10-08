# Queues, Jobs, and Integrations

## Current Status

Laravel queue configuration exists, but the TPVS AI processing jobs and provider integration are not implemented. `QUEUE_CONNECTION=sync` is acceptable only for the current foundation.

## Queue Rules

- Long-running or retryable work must run in a queue.
- Jobs must define timeout and retry behavior intentionally.
- Failed jobs must remain diagnosable.
- Jobs must be idempotent or protect against duplicate effects.
- Database state changes should be coordinated with transaction boundaries.

## AI Boundary

The browser never calls Ollama. Laravel is the only application component that calls the AI host, preferably through a queued job and an injectable provider interface.

Every attempt must preserve provider, model, raw result, normalized result, status, and failure information. Technical failures and usable results requiring review are different outcomes.

## Planned Flow

```text
ProcessImageJob
  -> AI provider interface
  -> Ollama provider
  -> extraction contract validation
  -> business validation
  -> NEEDS_REVIEW or ERROR
```

AI output never confirms a record.
