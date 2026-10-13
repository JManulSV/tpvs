# Backend Security

## Baseline

- Keep `APP_DEBUG=false` outside local development.
- Never commit `.env` or secret values.
- Hash passwords through Laravel's supported mechanisms.
- Use Laravel Sanctum stateful SPA authentication with a database-backed Laravel session; do not issue JWTs or personal access tokens for the browser flow.
- Protect the session in an `HttpOnly`, `SameSite=Lax` cookie named `tpvs_session`; use a browser-session cookie and a 120-minute inactivity timeout, and mark it `Secure` under HTTPS.
- Enforce CSRF validation on state-changing requests. The same-origin BFF must forward the session cookie, CSRF cookie/header, and required origin headers.
- Return `401` for unauthenticated requests and `403` for authenticated users forbidden by role.
- Validate all input with Form Requests or equivalent domain validation.
- Use Policies for resource authorization.
- Protect private files behind authorized Laravel responses.
- Do not expose PostgreSQL, private storage, or Ollama to browsers.
- Do not log passwords, tokens, encryption keys, or sensitive provider payloads.
- Add authorization tests for every mutation.

Authentication endpoints and central role middleware are implemented. The Next.js BFF integration remains pending and must preserve Laravel cookies, CSRF headers, statuses, and no-store behavior before operational use.

## Upload Security

Validate MIME type, file size, generated storage names, and record association. Do not trust the original filename or client-provided content type as the only security check.

## AI Security

Laravel is the only component allowed to call Ollama. External responses must be treated as untrusted input, validated against the extraction contract, and preserved for diagnosis without allowing them to bypass business validation.

## Auditability

Meaningful changes to records and delivery preparation require user, reason, before value, after value, and timestamp information as defined by the global data model.
