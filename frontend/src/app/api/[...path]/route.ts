import { fetchLaravel } from "@/lib/server/laravel-api";

type RouteContext = {
  params: Promise<{ path: string[] }>;
};

const responseHeaders = [
  "accept-ranges",
  "cache-control",
  "content-disposition",
  "content-range",
  "content-type",
  "etag",
  "last-modified",
];

async function proxyToLaravel(request: Request, context: RouteContext) {
  const { path } = await context.params;

  if (path.join("/") === "auth/csrf-cookie") {
    if (request.method !== "GET") {
      return new Response(null, {
        status: 405,
        headers: { allow: "GET" },
      });
    }

    return proxyRequest(request, "/sanctum/csrf-cookie");
  }

  const apiPath = `/api/${path.map(encodeURIComponent).join("/")}`;
  return proxyRequest(request, apiPath);
}

async function proxyRequest(request: Request, upstreamPath: string) {
  try {
    const query = new URL(request.url).search;

    const hasBody = request.method !== "GET" && request.method !== "HEAD";
    const upstream = await fetchLaravel(`${upstreamPath}${query}`, {
      method: request.method,
      headers: request.headers,
      body: hasBody ? await request.arrayBuffer() : undefined,
      useTrustedOriginFallback: true,
    });

    const forwardedHeaders = new Headers();
    for (const name of responseHeaders) {
      const value = upstream.headers.get(name);
      if (value) forwardedHeaders.set(name, value);
    }
    for (const cookie of upstream.headers.getSetCookie()) {
      forwardedHeaders.append("set-cookie", cookie);
    }
    forwardedHeaders.set("cache-control", "no-store");

    return new Response(upstream.body, {
      status: upstream.status,
      headers: forwardedHeaders,
    });
  } catch {
    return Response.json(
      { message: "The Laravel API is unavailable." },
      { status: 502 },
    );
  }
}

export const GET = proxyToLaravel;
export const HEAD = proxyToLaravel;
export const POST = proxyToLaravel;
export const PUT = proxyToLaravel;
export const PATCH = proxyToLaravel;
export const DELETE = proxyToLaravel;
