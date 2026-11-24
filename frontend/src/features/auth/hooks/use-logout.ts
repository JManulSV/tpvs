"use client";

import { useCallback, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { authService } from "../services/auth.service";
import { AuthApiError } from "../types";

export function useLogout() {
  const queryClient = useQueryClient();
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [isLoggingOut, setIsLoggingOut] = useState(false);

  const logout = useCallback(async () => {
    setErrorMessage(null);
    setIsLoggingOut(true);

    try {
      await authService.logout();
      queryClient.clear();
      // Full reload: with cacheComponents, routes stay mounted (hidden by <Activity>)
      // and a client navigation would keep the previous user's UI state.
      window.location.replace("/login");
    } catch (error) {
      setErrorMessage(
        error instanceof AuthApiError
          ? error.message
          : "No se pudo cerrar la sesión. Inténtalo nuevamente.",
      );
    } finally {
      setIsLoggingOut(false);
    }
  }, [queryClient]);

  return { logout, isLoggingOut, errorMessage };
}
