"use client"

import { LogOut } from "lucide-react"
import {
  SidebarMenuButton,
  SidebarMenuItem,
} from "@/components/ui/sidebar"
import { useLogout } from "../hooks/use-logout"

export function LogoutNavItem() {
  const { logout, isLoggingOut, errorMessage } = useLogout()

  return (
    <SidebarMenuItem>
      <SidebarMenuButton
        className="text-destructive hover:text-destructive focus-visible:text-destructive"
        disabled={isLoggingOut}
        onClick={() => void logout()}
        tooltip="Cerrar sesión"
      >
        <LogOut aria-hidden="true" />
        <span className="group-data-[collapsible=icon]:hidden">
          {isLoggingOut ? "Cerrando sesión…" : "Cerrar sesión"}
        </span>
      </SidebarMenuButton>
      {errorMessage && (
        <p className="px-2 py-1 text-xs text-destructive" role="alert">
          {errorMessage}
        </p>
      )}
    </SidebarMenuItem>
  )
}
