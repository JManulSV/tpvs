"use client"

import type { ReactNode } from "react"
import Link from "next/link"
import { Bell, Plus, Search } from "lucide-react"
import { ThemeToggle } from "@/components/theme-toggle"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { SidebarTrigger, useSidebar } from "@/components/ui/sidebar"
import { getInitials, getRoleLabel } from "./user-display"

type AppTopbarProps = {
  user: {
    name: string
    email: string
    role: "EDITOR" | "VIEWER"
  }
  userMenuLogout: ReactNode
}

export function AppTopbar({ user, userMenuLogout }: AppTopbarProps) {
  const { isMobile, open } = useSidebar()

  return (
    <header className="flex flex-wrap items-center gap-3 rounded-lg border border-border bg-card px-3 py-3 shadow-card sm:px-4">
      <SidebarTrigger
        aria-label={
          isMobile
            ? "Abrir menú"
            : open
              ? "Contraer menú"
              : "Expandir menú"
        }
        className="shrink-0"
      />

      <label className="flex h-11 min-w-0 flex-[1_1_20rem] items-center gap-2 rounded-full border border-input px-4 text-muted-foreground">
        <Search aria-hidden="true" className="size-4 shrink-0" />
        <input
          aria-label="Buscar (próximamente)"
          className="h-full min-w-0 flex-1 border-0 bg-transparent text-sm text-foreground outline-none placeholder:text-muted-foreground disabled:cursor-not-allowed"
          disabled
          placeholder="Buscar empresa, afiliación o sucursal…"
          title="Próximamente"
          type="search"
        />
      </label>

      <div className="ml-auto flex flex-wrap items-center justify-end gap-2 sm:gap-3">
        {user.role === "EDITOR" && (
          <Link
            className="inline-flex h-10 items-center justify-center gap-2 rounded-full bg-accent px-4 text-sm font-semibold text-accent-foreground transition-colors hover:border hover:border-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-card"
            href="/records/new"
          >
            <Plus aria-hidden="true" className="size-4" />
            <span>Nuevo registro</span>
          </Link>
        )}

        <button
          aria-label="Notificaciones (próximamente)"
          className="grid size-10 shrink-0 place-items-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-card disabled:cursor-not-allowed disabled:opacity-50"
          disabled
          type="button"
        >
          <Bell aria-hidden="true" className="size-5" />
        </button>

        <ThemeToggle />

        <DropdownMenu>
          <DropdownMenuTrigger
            aria-label="Menú de cuenta"
            className="flex min-w-0 items-center gap-3 border-l border-border pl-3 text-left outline-none focus-visible:rounded-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-card"
          >
            <span
              aria-hidden="true"
              className="grid size-9 shrink-0 place-items-center rounded-full bg-accent text-sm font-bold text-accent-foreground"
            >
              {getInitials(user.name)}
            </span>
            <span className="min-w-0 max-[639px]:hidden">
              <span className="block max-w-36 truncate text-sm font-semibold text-card-foreground">
                {user.name}
              </span>
              <span className="block text-xs text-muted-foreground">
                {getRoleLabel(user.role)}
              </span>
            </span>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="min-w-56">
            <DropdownMenuGroup>
              <DropdownMenuLabel className="max-w-64 truncate font-normal">
                {user.email}
              </DropdownMenuLabel>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            {userMenuLogout}
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </header>
  )
}
