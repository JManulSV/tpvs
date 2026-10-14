import "server-only";

const forwardedRequestHeaders = new Set([
  "accept",
  "authorization",
  "content-type",
  "cookie",
  "if-modified-since",
  "if-none-match",
  "range",
  "referer",
  "x-request-id",
  "x-xsrf-token",
]);

type LaravelRequestOptions = RequestInit & {
  /** Add the configured frontend origin when the request has no Origin. */
  useTrustedOriginFallback?: boolean;
};

function getBackendUrl(): URL {
  const configuredUrl = process.env.LARAVEL_API_URL ?? "http://localhost:8000";
  const backendUrl = new URL(configuredUrl);

  if (
    !["http:", "https:"].includes(backendUrl.protocol) ||
    backendUrl.username ||
    backendUrl.password
  ) {
    throw new Error("LARAVEL_API_URL must be an HTTP(S) URL without credentials.");
  }

  return backendUrl;
}

function getTrustedFrontendOrigins(): string[] {
  const configuredOrigins = process.env.FRONTEND_ORIGIN;

  if (!configuredOrigins) {
    throw new Error("FRONTEND_ORIGIN must be configured on the server.");
  }

  return configuredOrigins.split(",").map((configuredOrigin) => {
    const parsedOrigin = new URL(configuredOrigin.trim());
    if (
      !["http:", "https:"].includes(parsedOrigin.protocol) ||
      parsedOrigin.username ||
      parsedOrigin.password ||
      parsedOrigin.pathname !== "/" ||
      parsedOrigin.search ||
      parsedOrigin.hash
    ) {
      throw new Error("FRONTEND_ORIGIN must contain only comma-separated origins.");
    }

    return parsedOrigin.origin;
  });
}

function findTrustedOrigin(value: string, trustedOrigins: string[]): string | null {
  try {
    const parsed = new URL(value);
    return trustedOrigins.find((trustedOrigin) =>
      !parsed.username &&
      !parsed.password &&
      parsed.origin === trustedOrigin
    ) ?? null;
  } catch {
    return null;
  }
}

function filterRequestHeaders(
  source: HeadersInit | undefined,
  trustedOrigins: string[],
  useTrustedOriginFallback: boolean,
): Headers {
  const incoming = new Headers(source);
  const forwarded = new Headers();

  for (const name of forwardedRequestHeaders) {
    const value = incoming.get(name);
    if (value) forwarded.set(name, value);
  }

  const origin = incoming.get("origin");
  if (origin) {
    const trustedOrigin = findTrustedOrigin(origin, trustedOrigins);
    if (trustedOrigin) {
      forwarded.set("origin", trustedOrigin);
    }
  } else if (useTrustedOriginFallback) {
    forwarded.set("origin", trustedOrigins[0]);
  }

  const referer = incoming.get("referer");
  if (referer && !findTrustedOrigin(referer, trustedOrigins)) {
    forwarded.delete("referer");
  }

  return forwarded;
}

/**
 * Calls a fixed path on the configured Laravel server while forwarding only
 * the explicitly allowed browser headers. Authentication requests must not be
 * cached. The caller may opt into a trusted Origin fallback for SSR requests.
 */
export async function fetchLaravel(
  path: string,
  options: LaravelRequestOptions = {},
): Promise<Response> {
  if (!path.startsWith("/") || path.startsWith("//") || path.includes("\\")) {
    throw new Error("Laravel request paths must be absolute paths on the API server.");
  }

  const backendUrl = getBackendUrl();
  const trustedOrigins = getTrustedFrontendOrigins();
  const target = new URL(path, backendUrl);

  if (target.origin !== backendUrl.origin) {
    throw new Error("Laravel request path resolved outside the configured server.");
  }

  const {
    useTrustedOriginFallback = false,
    headers: requestHeaders,
    ...requestOptions
  } = options;

  return fetch(target, {
    ...requestOptions,
    headers: filterRequestHeaders(
      requestHeaders,
      trustedOrigins,
      useTrustedOriginFallback,
    ),
    cache: "no-store",
  });
}
