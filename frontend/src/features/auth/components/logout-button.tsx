"use client";

import { Button } from "@/components/ui/button";
import { useLogout } from "../hooks/use-logout";

export function LogoutButton() {
  const { logout, isLoggingOut, errorMessage } = useLogout();

  return (
    <div className="flex flex-col items-end gap-2">
      <Button
        type="button"
        variant="outline"
        disabled={isLoggingOut}
        onClick={() => void logout()}
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
