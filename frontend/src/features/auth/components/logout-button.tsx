"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { Button } from "@/components/ui/button";
import { authService } from "../services/auth.service";
import { AuthApiError } from "../types";

export function LogoutButton() {
  const router = useRouter();
  const queryClient = useQueryClient();
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [isLoggingOut, setIsLoggingOut] = useState(false);

  async function handleLogout() {
    setErrorMessage(null);
    setIsLoggingOut(true);

    try {
      await authService.logout();
      queryClient.clear();
      router.replace("/login");
    } catch (error) {
      setErrorMessage(
        error instanceof AuthApiError
          ? error.message
          : "No se pudo cerrar la sesión. Inténtalo nuevamente.",
      );
    } finally {
      setIsLoggingOut(false);
    }
  }

  return (
    <div className="flex flex-col items-end gap-2">
      <Button
        type="button"
        variant="outline"
        disabled={isLoggingOut}
        onClick={handleLogout}
      >
        {isLoggingOut ? "Cerrando sesión…" : "Cerrar sesión"}
      </Button>
      {errorMessage && (
        <p className="max-w-xs text-right text-sm text-destructive" role="alert">
          {errorMessage}
        </p>
      )}
    </div>
  );
}
