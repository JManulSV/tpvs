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

For Record images, accept only non-empty JPEG, PNG, or WebP content up to 10 MiB. Detect MIME from bytes; do not trust the original filename, extension, or client-provided content type as the only security check. Sanitize the original filename as metadata and use a server-generated UUID storage key.

Store files on a dedicated private local disk, separate from public storage and `FILESYSTEM_DISK` selection. Never create public links for this disk. Do not return physical paths, storage keys, or public URLs to clients. Serve bytes only from authenticated Laravel routes authorized against the parent Record; use `Content-Disposition: inline` and `Cache-Control: private, no-store`.

Only `EDITOR` may mutate image associations; authenticated `EDITOR` and `VIEWER` may view images on non-canceled Records. Reject access and upload/replacement for canceled Records. Reject replacement while an Image has AIProcessing attempts so processing provenance remains intact. Preserve CSRF validation on the upload route.

Coordinate filesystem and database changes explicitly: write a new random-key file first, update metadata transactionally under a Record lock, remove the new file if the database transaction fails, and delete the previous file only after commit. Log post-commit cleanup failure without exposing storage details to clients. Do not log image contents or sensitive multipart payloads.

## AI Security

Laravel is the only component allowed to call Ollama. External responses must be treated as untrusted input, validated against the extraction contract, and preserved for diagnosis without allowing them to bypass business validation.

## Auditability

Meaningful changes to records and delivery preparation require user, reason, before value, after value, and timestamp information as defined by the global data model.
