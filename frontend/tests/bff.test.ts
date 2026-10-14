import { afterEach, describe, expect, it, vi } from "vitest";

vi.mock("server-only", () => ({}));
vi.mock("@/lib/server/laravel-api", () => ({
  fetchLaravel: vi.fn(),
}));

import { DELETE, GET, HEAD, PATCH, POST, PUT } from "@/app/api/[...path]/route";
import { fetchLaravel } from "@/lib/server/laravel-api";

const fetchLaravelMock = vi.mocked(fetchLaravel);

function routeContext(...path: string[]) {
  return { params: Promise.resolve({ path }) };
}

function request(
  method: string,
  path: string,
  options: RequestInit = {},
): Request {
  return new Request(`http://localhost:3000/api/${path}`, {
    method,
    ...options,
  });
}

afterEach(() => {
  fetchLaravelMock.mockReset();
  vi.unstubAllEnvs();
});

describe("Next.js BFF catch-all route", () => {
  it("bridges the CSRF endpoint to Sanctum and preserves each Set-Cookie", async () => {
    const upstreamHeaders = new Headers();
    upstreamHeaders.append(
      "set-cookie",
      "XSRF-TOKEN=csrf-value; Path=/; SameSite=Lax",
    );
    upstreamHeaders.append(
      "set-cookie",
      "tpvs_session=session-value; Path=/; HttpOnly; SameSite=Lax",
    );
    fetchLaravelMock.mockResolvedValue(
      new Response(null, { status: 204, headers: upstreamHeaders }),
    );

    const response = await GET(
      request("GET", "auth/csrf-cookie"),
      routeContext("auth", "csrf-cookie"),
    );

    expect(fetchLaravelMock).toHaveBeenCalledWith(
      "/sanctum/csrf-cookie",
      expect.objectContaining({
        method: "GET",
        useTrustedOriginFallback: true,
      }),
    );
    expect(response.status).toBe(204);
    expect(response.headers.getSetCookie()).toEqual([
      "XSRF-TOKEN=csrf-value; Path=/; SameSite=Lax",
      "tpvs_session=session-value; Path=/; HttpOnly; SameSite=Lax",
    ]);
    expect(response.headers.get("cache-control")).toBe("no-store");
  });

  it("rejects non-GET methods on the CSRF bridge", async () => {
    const response = await POST(
      request("POST", "auth/csrf-cookie", { body: "{}" }),
      routeContext("auth", "csrf-cookie"),
    );

    expect(response.status).toBe(405);
    expect(response.headers.get("allow")).toBe("GET");
    expect(fetchLaravelMock).not.toHaveBeenCalled();
  });

  it("encodes path segments, preserves query, body, and request headers", async () => {
    fetchLaravelMock.mockResolvedValue(
      Response.json({ message: "Validation failed" }, { status: 422 }),
    );
    const input = request("POST", "records/item one", {
      headers: {
        "content-type": "application/json",
        cookie: "tpvs_session=session-value",
        "x-xsrf-token": "csrf-value",
      },
      body: JSON.stringify({ value: 1 }),
    });

    const response = await POST(
      input,
      routeContext("records", "item one"),
    );

    const [path, options] = fetchLaravelMock.mock.calls[0];
    expect(path).toBe("/api/records/item%20one");
    expect(options).toEqual(
      expect.objectContaining({
        method: "POST",
        body: expect.any(ArrayBuffer),
        useTrustedOriginFallback: true,
      }),
    );
    const sentHeaders = new Headers(options?.headers);
    expect(sentHeaders.get("cookie")).toBe("tpvs_session=session-value");
    expect(sentHeaders.get("x-xsrf-token")).toBe("csrf-value");
    expect(response.status).toBe(422);
    expect(await response.json()).toEqual({ message: "Validation failed" });
  });

  it.each([401, 403, 419, 422])(
    "preserves upstream authentication/validation status %i",
    async (status) => {
      fetchLaravelMock.mockResolvedValue(
        Response.json({ message: `error-${status}` }, { status }),
      );

      const response = await GET(
        request("GET", "auth/me"),
        routeContext("auth", "me"),
      );

      expect(response.status).toBe(status);
      expect(await response.json()).toEqual({ message: `error-${status}` });
    },
  );

  it("preserves supported methods and response headers", async () => {
    const upstream = new Response("file-content", {
      status: 206,
      headers: {
        "content-type": "application/octet-stream",
        "content-range": "bytes 0-3/12",
        "content-disposition": 'attachment; filename="export.bin"',
        etag: '"export-v1"',
      },
    });
    fetchLaravelMock.mockResolvedValue(upstream);

    const calls = [
      [HEAD, "HEAD"],
      [PUT, "PUT"],
      [PATCH, "PATCH"],
      [DELETE, "DELETE"],
    ] as const;

    for (const [handler, method] of calls) {
      const response = await handler(
        request(method, "reports/export?period=2026"),
        routeContext("reports", "export"),
      );

      expect(fetchLaravelMock).toHaveBeenLastCalledWith(
        "/api/reports/export?period=2026",
        expect.objectContaining({ method }),
      );
      expect(response.status).toBe(206);
      expect(response.headers.get("content-range")).toBe("bytes 0-3/12");
      expect(response.headers.get("content-disposition")).toBe(
        'attachment; filename="export.bin"',
      );
      expect(response.headers.get("etag")).toBe('"export-v1"');
    }
  });

  it("returns a stable 502 when Laravel is unavailable", async () => {
    fetchLaravelMock.mockRejectedValue(new Error("private upstream detail"));

    const response = await GET(
      request("GET", "auth/me"),
      routeContext("auth", "me"),
    );

    expect(response.status).toBe(502);
    expect(await response.json()).toEqual({
      message: "The Laravel API is unavailable.",
    });
  });
});

describe("server-side Laravel request boundary", () => {
  it("forwards only allowed headers and substitutes trusted origin when absent", async () => {
    vi.stubEnv("LARAVEL_API_URL", "http://tpvs-backend:8000");
    vi.stubEnv(
      "FRONTEND_ORIGIN",
      "http://localhost:3000,http://192.168.3.240:3000",
    );
    const upstreamFetch = vi
      .spyOn(globalThis, "fetch")
      .mockResolvedValue(new Response("ok"));

    const { fetchLaravel: realFetchLaravel } = await vi.importActual<
      typeof import("@/lib/server/laravel-api")
    >("@/lib/server/laravel-api");

    await realFetchLaravel("/api/auth/me", {
      headers: {
        accept: "application/json",
        cookie: "tpvs_session=session-value",
        "x-xsrf-token": "csrf-value",
        "x-untrusted-header": "must-not-forward",
      },
      useTrustedOriginFallback: true,
    });

    const [url, init] = upstreamFetch.mock.calls[0];
    expect(String(url)).toBe("http://tpvs-backend:8000/api/auth/me");
    const headers = new Headers(init?.headers);
    expect(headers.get("origin")).toBe("http://localhost:3000");
    expect(headers.get("cookie")).toBe("tpvs_session=session-value");
    expect(headers.get("x-xsrf-token")).toBe("csrf-value");
    expect(headers.has("x-untrusted-header")).toBe(false);
    expect(init?.cache).toBe("no-store");
  });

  it("drops untrusted Origin and Referer instead of forwarding browser values", async () => {
    vi.stubEnv("LARAVEL_API_URL", "http://tpvs-backend:8000");
    vi.stubEnv("FRONTEND_ORIGIN", "http://localhost:3000");
    const upstreamFetch = vi
      .spyOn(globalThis, "fetch")
      .mockResolvedValue(new Response("ok"));
    const { fetchLaravel: realFetchLaravel } = await vi.importActual<
      typeof import("@/lib/server/laravel-api")
    >("@/lib/server/laravel-api");

    await realFetchLaravel("/api/auth/me", {
      headers: {
        origin: "https://attacker.example",
        referer: "https://attacker.example/login",
      },
      useTrustedOriginFallback: true,
    });

    const [, init] = upstreamFetch.mock.calls[0];
    const headers = new Headers(init?.headers);
    expect(headers.has("origin")).toBe(false);
    expect(headers.has("referer")).toBe(false);
  });

  it("rejects a path that could escape the configured upstream origin", async () => {
    vi.stubEnv("LARAVEL_API_URL", "http://tpvs-backend:8000");
    vi.stubEnv("FRONTEND_ORIGIN", "http://localhost:3000");
    const upstreamFetch = vi.spyOn(globalThis, "fetch");
    const { fetchLaravel: realFetchLaravel } = await vi.importActual<
      typeof import("@/lib/server/laravel-api")
    >("@/lib/server/laravel-api");

    await expect(
      realFetchLaravel("//attacker.example/api/auth/me"),
    ).rejects.toThrow("absolute paths");
    expect(upstreamFetch).not.toHaveBeenCalled();
  });
});
