type RouteContext = {
  params: Promise<{ path: string[] }>;
};

const requestHeaders = [
  "accept",
  "authorization",
  "content-type",
  "cookie",
  "if-modified-since",
  "if-none-match",
  "range",
  "x-request-id",
  "x-xsrf-token",
];
const responseHeaders = [
  "accept-ranges",
  "cache-control",
  "content-disposition",
  "content-range",
  "content-type",
  "etag",
  "last-modified",
  "set-cookie",
];

async function proxyToLaravel(request: Request, context: RouteContext) {
  const { path } = await context.params;
  try {
    const backendUrl = process.env.LARAVEL_API_URL ?? "http://localhost:8000";
    const target = new URL(
      `/api/${path.map(encodeURIComponent).join("/")}`,
      backendUrl,
    );
    target.search = new URL(request.url).search;

    const headers = new Headers();
    for (const name of requestHeaders) {
      const value = request.headers.get(name);
      if (value) headers.set(name, value);
    }

    const hasBody = request.method !== "GET" && request.method !== "HEAD";
    const upstream = await fetch(target, {
      method: request.method,
      headers,
      body: hasBody ? await request.arrayBuffer() : undefined,
      cache: "no-store",
    });

    const forwardedHeaders = new Headers();
    for (const name of responseHeaders) {
      if (name === "set-cookie") continue;
      const value = upstream.headers.get(name);
      if (value) forwardedHeaders.set(name, value);
    }
    for (const cookie of upstream.headers.getSetCookie()) {
      forwardedHeaders.append("set-cookie", cookie);
    }

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
