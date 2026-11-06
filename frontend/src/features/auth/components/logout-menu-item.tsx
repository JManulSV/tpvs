"use client"

import { DropdownMenuItem } from "@/components/ui/dropdown-menu"
import { useLogout } from "../hooks/use-logout"

export function LogoutMenuItem() {
  const { logout, isLoggingOut, errorMessage } = useLogout()

  return (
    <>
      <DropdownMenuItem
        className="text-destructive focus:text-destructive"
        disabled={isLoggingOut}
        onClick={(event) => {
          // Keep the menu open on failure so its alert remains visible.
          event.preventDefault()
          void logout()
        }}
        variant="destructive"
      >
        {isLoggingOut ? "Cerrando sesión…" : "Cerrar sesión"}
      </DropdownMenuItem>
      {errorMessage && (
        <p className="px-2 py-1 text-xs text-destructive" role="alert">
          {errorMessage}
        </p>
      )}
    </>
  )
}
