import "server-only";

import { cookies } from "next/headers";
import { cache } from "react";
import { fetchLaravel } from "@/lib/server/laravel-api";
import type { AuthUserResponse } from "../types";

/**
 * Deduplicated per server request with React `cache()`, so the authenticated
 * layout and its pages share one `/api/auth/me` call.
 */
export const getAuthenticatedUser = cache(async () => {
  const cookieStore = await cookies();
  const response = await fetchLaravel("/api/auth/me", {
    headers: {
      accept: "application/json",
      cookie: cookieStore.toString(),
    },
    useTrustedOriginFallback: true,
  });

  if (response.status === 401) return null;
  if (!response.ok) {
    throw new Error(`Unable to verify the current session (${response.status}).`);
  }

  const body = (await response.json()) as AuthUserResponse;
  return body.data;
});
