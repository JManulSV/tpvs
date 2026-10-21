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

For the SPEC 08 pipeline, one logical `AIProcessing` row belongs to each Image. It stores provider, model, latest raw result (backend-private), normalized result, status, timestamps, and safe failure information. A new manual retry replaces the current logical result/error; per-call history is out of scope for SPEC 08. Technical failures and usable results requiring review are different outcomes.

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

## SPEC 08 Queue Policy (Planned)

- `ProcessImageJob` is dispatched after the Record/Image/AIProcessing database transaction commits. Dispatch failure after commit is recorded durably as `Record.ERROR` and `AIProcessing.ERROR`.
- The job claims and locks its aggregate before provider work. Duplicate or stale jobs must not call Ollama if the processing row is already completed, errored for a manual cycle, or otherwise no longer eligible.
- The initial processing cycle permits up to three provider calls total, but only retries transient connection errors, timeouts, and HTTP 5xx. Structural extraction failures and non-transient HTTP 4xx are terminal.
- A manual retry is explicitly marked and makes one provider call only; it has no automatic retries. It is allowed only from `ERROR` and reuses the same AIProcessing row.
- A usable extraction stores the normalized proposal and issues and transitions to `Record.NEEDS_REVIEW` / `AIProcessing.COMPLETED`. A terminal failure transitions both models to `ERROR`. No AI job transitions a Record to `CONFIRMED`, and this workflow writes no `History` rows.
- Coordinate timeouts as HTTP client <= 300 seconds, worker timeout 330 seconds, and database queue `retry_after` 360 seconds. The worker's global `--tries` must not contradict the job's retry policy.
