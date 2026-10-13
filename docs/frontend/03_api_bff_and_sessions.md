# API, BFF, and Sessions

## Status

The frontend has a same-origin catch-all Route Handler at `src/app/api/[...path]/route.ts` that proxies requests to Laravel using server-only `LARAVEL_API_URL`. The Axios client targets `/api`. Laravel's authentication routes are implemented and tested; BFF authentication behavior remains pending, and the current proxy must not be treated as proof that the browser session and CSRF contract is fully integrated.

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

The approved authentication contract uses Laravel Sanctum stateful SPA authentication with Laravel's database-backed session. Laravel, not the BFF, owns the session. Laravel sets a browser-session cookie named `tpvs_session` with `HttpOnly`, `SameSite=Lax`, and `Secure` under HTTPS. It expires when the browser closes; the server session expires after 120 minutes of inactivity. Login regenerates the session and logout invalidates it.

The planned browser-facing authentication paths are `POST /api/auth/login`, `POST /api/auth/logout`, and `GET /api/auth/me`. The BFF must expose `GET /api/auth/csrf-cookie` as a same-origin bridge to Laravel Sanctum's CSRF-cookie endpoint. The browser receives the `XSRF-TOKEN` cookie through the BFF and sends its decoded value in `X-XSRF-TOKEN` on state-changing requests.

The BFF must preserve Laravel `Set-Cookie` headers and forward `Cookie`, `Origin`/`Referer`, and `X-XSRF-TOKEN` when present. It must preserve upstream status codes: `401` unauthenticated, `403` forbidden, `419` CSRF mismatch, and `422` validation failure. Requests from server-rendered frontend code must forward the incoming browser session deliberately; they must not use an undocumented credential shortcut.

Laravel authentication routes, session cookie attributes, and CSRF behavior are implemented and manually verified. End-to-end BFF behavior remains pending. The BFF contract above must be implemented and verified before phase 8.4 is complete.

Do not put session credentials in local storage, JavaScript-readable persistent storage, URLs, or client-visible environment variables. Do not add JWT or invent a second cookie/session strategy. The documented backend authentication contract and tested BFF behavior must agree.

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
