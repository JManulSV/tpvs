# Roadmap

Which module is built, by which SPEC, and what remains for the MVP. Detailed scope, decisions, and checkpoint evidence live in each SPEC under `specs/`. New ideas that have no SPEC yet go in [`BACKLOG.md`](BACKLOG.md).

A module is complete only when its code, tests, and checkpoint are verified — never because its documentation exists.

## Module Status

| Phase | Module | SPEC | Status |
| --- | --- | --- | --- |
| 8.2 | Infrastructure (Laravel, Next.js, PostgreSQL, Docker, queue worker) | — | Complete |
| 8.3 | Database schema and relationships | — | Complete |
| 8.4 | Authentication and roles | 01, 02, 17 | Complete |
| 8.5 | Companies | 03, 23 | Complete |
| 8.6 | Manual records | 04, 07 | Complete |
| 8.7 | Private images | 05 | Complete |
| 8.8 | AI spike (model chosen: `qwen2.5vl:3b`, see [`ai-spike/benchmark-v4.md`](ai-spike/benchmark-v4.md)) | 06 | Complete |
| 8.9 | Asynchronous AI pipeline | 08 | Complete |
| 8.10 | Review, confirmation, cancellation | 09 | Complete |
| 8.11 | Record history | 10 | Complete |
| 8.12 | Delivery preparation and cash adjustment | 11, 14, 22 | Complete |
| 8.13 | Word delivery document | 12, 14 | Complete |
| 8.14 | Reports and Excel | 13, 24 | Complete |
| 8.15 | Frontend design system and screens | 15, 16, 18–24 | Complete |
| — | Backend refactor (actions, `Money`, domain errors; no behavior change) | 25 | Complete |
| 8.16 | End-to-end integration | — | Pending |
| 8.17 | Release testing | — | Pending |
| 8.18 | Office LAN deployment | — | Pending |

## Pending Phases

### 8.16 — End-to-End Integration

Verify the whole journey with persisted data and role enforcement:

```text
Supplier image (WhatsApp, manual) -> Upload -> AI -> Review -> Confirm
  -> Prepare delivery -> Word -> Reports -> Excel
```

WhatsApp stays a manual source; no direct integration is added in the MVP.

### 8.17 — Release Testing

Normal flows: readable image, multiple movements, review and confirmation, valid delivery, Word, Excel.

Failure and authorization flows: unreadable image, invalid AI JSON, unknown company, missing field, incorrect commission, incorrect return, mismatched cash, `VIEWER` attempting a mutation, canceled record excluded from reports, confirmed record edited and returned to review, delivery prepared with an older amount, missing image blocking Word.

Unresolved failures block release.

### 8.18 — Deployment

Deploy on the office LAN. The application server hosts Next.js, Laravel, PostgreSQL, the queue worker, and private storage; the AI PC hosts Ollama and the vision model, reached by Laravel over the LAN.

**Checkpoint:** a real workflow completed from another office workstation.

## Definition of Done

Every module has:

- scope matching its SPEC;
- validated inputs and explicit error behavior;
- authorization tests for allowed and forbidden roles;
- tests appropriate to its risk, run against `tpvs_test`;
- database transactions around multi-entity workflows;
- API behavior and errors documented in [`backend/api-contract.md`](backend/api-contract.md);
- a successful checkpoint, with its SPEC status updated afterwards.

No module may bypass backend validation, role authorization, the human confirmation rule, or confirmed-only official reporting.
