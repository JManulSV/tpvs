# API, BFF, and Sessions

## Status

The frontend has a same-origin catch-all Route Handler at `src/app/api/[...path]/route.ts` that proxies requests to Laravel using server-only `LARAVEL_API_URL`. The Axios client targets `/api`. Authentication and the business API are planned; the current proxy must not be treated as proof that those endpoints exist.

## Request Boundary

```text
Browser feature service
  -> /api/... on the Next.js origin
  -> Next.js BFF Route Handler
  -> Laravel /api/...
```

- Browser code calls only same-origin `/api/...` paths.
- `LARAVEL_API_URL` is server-only and must not be renamed to a `NEXT_PUBLIC_*` variable.
- Laravel is the authority for endpoint validation, authorization, business errors, and state changes.
- Do not expose PostgreSQL, private file paths, or Ollama directly to browser code.
- The BFF should forward requests and responses deliberately; it should not silently redefine backend semantics.

## Proxy Contract

When changing or extending the proxy:

- encode path segments and preserve the query string;
- allow only the HTTP methods and headers required by the API;
- preserve upstream status and relevant headers, including cookies where the session design requires them;
- use `cache: "no-store"` for authenticated or operational data unless a reviewed caching policy says otherwise;
- support request/response bodies without assuming every payload is JSON;
- account for file upload size, download content disposition, byte ranges, and streaming behavior for private images and generated documents;
- return a stable gateway error on backend unavailability without exposing the upstream URL or exception details;
- do not log credentials, cookies, or sensitive payloads.

The current allowlist of forwarded headers is an implementation detail. Review it against the Laravel contract whenever adding authentication, CSRF, uploads, conditional requests, or downloads.

## Authentication and Cookie Decisions

Authentication is not implemented yet. Before implementing it, reconcile [`../04_technical_architecture.md`](../04_technical_architecture.md) and [`../11_technical_roadmap.md`](../11_technical_roadmap.md) into one explicit session contract covering:

- whether Laravel issues a JWT or another session credential;
- which component stores the credential and how it remains HttpOnly;
- cookie name, `Secure`, `SameSite`, path, expiration, and rotation behavior;
- whether Next.js acts as a transparent cookie proxy or owns a server-side session boundary;
- logout, expired sessions, and invalid-session responses;
- CSRF protection for state-changing requests and how any CSRF token/header traverses the BFF;
- server-rendered requests versus browser-initiated requests.

Do not put access tokens in local storage, JavaScript-readable persistent storage, URLs, or client-visible environment variables. Do not invent a second cookie/session strategy in a feature implementation. The documented backend authentication contract and the tested BFF behavior must agree.

## Errors

- Preserve Laravel validation responses so forms can associate field errors with inputs.
- Preserve HTTP statuses for authentication, authorization, missing resources, conflicts, and validation failures.
- Map transport failures to a user-safe message while retaining enough server-side diagnostics for operators.
- Use stable business error codes from Laravel where available; do not infer business state from message text.
- Never treat a hidden UI action as a replacement for handling `401` or `403` from the backend.

## Caching and Security

- Do not cache authenticated records, private images, or sensitive reports in a shared cache.
- Review Next.js fetch/cache behavior and TanStack Query cache lifetime for role changes and logout; clear user-scoped client cache when identity changes or the session ends.
- Do not allow a request path or query value to select an arbitrary upstream host. The upstream base URL must remain trusted server configuration.
- Ensure response headers do not disclose private storage paths or internal topology.

## API Contract References

Use [`../backend/03_api_contract.md`](../backend/03_api_contract.md) for implemented and planned Laravel behavior and [`../04_technical_architecture.md`](../04_technical_architecture.md) for target resource groups. Verify actual routes and tests before building a feature against a planned endpoint.

## Related Documents

- `02_routing_and_rendering.md`
- `04_data_fetching_and_forms.md`
- [`../backend/03_api_contract.md`](../backend/03_api_contract.md)
- [`../backend/05_validation_authorization.md`](../backend/05_validation_authorization.md)
- [`../backend/11_security.md`](../backend/11_security.md)
