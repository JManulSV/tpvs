# Queues, Jobs, and Integrations

## Current Status

The SPEC 08 Laravel AI processing job and Ollama provider are implemented and covered by backend tests. Runtime processing uses the asynchronous database queue; `QUEUE_CONNECTION=sync` must not be used for image extraction.

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

## SPEC 08 Queue Policy

- `ProcessImageJob` is dispatched after the Record/Image/AIProcessing database transaction commits. Dispatch failure after commit is recorded durably as `Record.ERROR` and `AIProcessing.ERROR`.
- The job claims and locks its aggregate before provider work. Duplicate or stale jobs must not call Ollama if the processing row is already completed, errored for a manual cycle, or otherwise no longer eligible.
- The initial processing cycle permits up to three provider calls total, but only retries transient connection errors, timeouts, and HTTP 5xx. Structural extraction failures and non-transient HTTP 4xx are terminal.
- A manual retry is explicitly marked and makes one provider call only; it has no automatic retries. It is allowed only from `ERROR` and reuses the same AIProcessing row.
- A usable extraction stores the normalized proposal and issues and transitions to `Record.NEEDS_REVIEW` / `AIProcessing.COMPLETED`. A terminal failure transitions both models to `ERROR`. No AI job transitions a Record to `CONFIRMED`, and this workflow writes no `History` rows.
- Coordinate timeouts as HTTP client <= 300 seconds, worker timeout 330 seconds, and database queue `retry_after` 360 seconds. The worker's global `--tries` must not contradict the job's retry policy.

The database connection defaults `DB_QUEUE_RETRY_AFTER` to `360`. The regular Compose worker uses `--timeout=330` and leaves retries to the job's `$tries` policy (three attempts for the initial cycle and one for manual retry). The local Composer development command uses the same 330-second worker timeout.
