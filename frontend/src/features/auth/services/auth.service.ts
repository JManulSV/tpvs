import axios from "axios";
import { apiClient } from "@/lib/api-client";
import {
  AuthApiError,
  toAuthApiError,
  type AuthUser,
  type AuthUserResponse,
  type LoginCredentials,
} from "../types";

const csrfCookiePath = "auth/csrf-cookie";

function readCsrfCookie(): string | null {
  if (typeof document === "undefined") return null;

  const csrfCookie = document.cookie
    .split(";")
    .map((cookie) => cookie.trim())
    .find((cookie) => cookie.startsWith("XSRF-TOKEN="));

  if (!csrfCookie) return null;

  try {
    return decodeURIComponent(csrfCookie.slice("XSRF-TOKEN=".length));
  } catch {
    return null;
  }
}

async function refreshCsrfCookie(): Promise<string> {
  try {
    await apiClient.get<void>(csrfCookiePath, { withXSRFToken: false });
  } catch (error) {
    throw toAuthApiError(error);
  }

  const csrfToken = readCsrfCookie();

  if (!csrfToken) {
    throw new AuthApiError(
      "No se pudo preparar la sesión de seguridad. Recarga la página e inténtalo nuevamente.",
      null,
    );
  }

  return csrfToken;
}

async function postWithCsrf<T>(path: string, data?: unknown): Promise<T> {
  const csrfToken = await refreshCsrfCookie();

  try {
    const response = await apiClient.post<T>(path, data, {
      headers: { "X-XSRF-TOKEN": csrfToken },
    });
    return response.data;
  } catch (error) {
    let csrfRefreshed = false;
    if (axios.isAxiosError(error) && error.response?.status === 419) {
      try {
        await refreshCsrfCookie();
        csrfRefreshed = true;
      } catch {
        // Preserve Laravel's original 419 response while still requiring a
        // deliberate user retry instead of replaying the mutation.
      }
    }

    throw toAuthApiError(error, csrfRefreshed);
  }
}

export const authService = {
  async login(credentials: LoginCredentials): Promise<AuthUser> {
    const response = await postWithCsrf<AuthUserResponse>(
      "auth/login",
      credentials,
    );
    return response.data;
  },

  async logout(): Promise<void> {
    await postWithCsrf<{ message: string }>("auth/logout");
  },

  async me(): Promise<AuthUser> {
    try {
      const response = await apiClient.get<AuthUserResponse>("auth/me");
      return response.data.data;
    } catch (error) {
      throw toAuthApiError(error);
    }
  },
};
