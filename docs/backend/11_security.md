# Backend Security

## Baseline

- Keep `APP_DEBUG=false` outside local development.
- Never commit `.env` or secret values.
- Hash passwords through Laravel's supported mechanisms.
- Validate all input with Form Requests or equivalent domain validation.
- Use Policies for resource authorization.
- Protect private files behind authorized Laravel responses.
- Do not expose PostgreSQL, private storage, or Ollama to browsers.
- Do not log passwords, tokens, encryption keys, or sensitive provider payloads.
- Add authorization tests for every mutation.

## Upload Security

Validate MIME type, file size, generated storage names, and record association. Do not trust the original filename or client-provided content type as the only security check.

## AI Security

Laravel is the only component allowed to call Ollama. External responses must be treated as untrusted input, validated against the extraction contract, and preserved for diagnosis without allowing them to bypass business validation.

## Auditability

Meaningful changes to records and delivery preparation require user, reason, before value, after value, and timestamp information as defined by the global data model.
