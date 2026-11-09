"use client"

import { LogOut } from "lucide-react"
import { useLogout } from "../hooks/use-logout"

export function LogoutSheetButton() {
  const { logout, isLoggingOut, errorMessage } = useLogout()

  return (
    <>
      <button
        className="flex h-12 items-center gap-3 rounded-md px-3 text-sm font-semibold text-destructive transition-colors hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:opacity-60"
        disabled={isLoggingOut}
        onClick={() => void logout()}
        type="button"
      >
        <LogOut aria-hidden="true" className="size-5" />
        {isLoggingOut ? "Cerrando sesión…" : "Cerrar sesión"}
      </button>
      {errorMessage && (
        <p className="px-3 py-1 text-xs text-destructive" role="alert">
          {errorMessage}
        </p>
      )}
    </>
  )
}
