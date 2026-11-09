"use client"

import type { ReactNode } from "react"
import { useState } from "react"
import { Bell, Search } from "lucide-react"
import { ThemeToggle } from "@/components/theme-toggle"
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetTitle,
  SheetTrigger,
} from "@/components/ui/sheet"
import { getInitials, getRoleLabel } from "./user-display"

type AppMobileTopbarProps = {
  user: {
    name: string
    email: string
    role: "EDITOR" | "VIEWER"
  }
  sheetLogout: ReactNode
}

const topbarButtonClass =
  "grid size-11 shrink-0 place-items-center rounded-full text-sidebar-foreground transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sidebar-ring disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-transparent"

export function AppMobileTopbar({ user, sheetLogout }: AppMobileTopbarProps) {
  const [accountOpen, setAccountOpen] = useState(false)
  const initials = getInitials(user.name)

  return (
    <header className="sticky top-0 z-30 flex items-center gap-1 border-b border-sidebar-border bg-sidebar py-2 pl-5 pr-3 pt-[max(0.5rem,env(safe-area-inset-top))] text-sidebar-foreground md:hidden">
      <span className="mr-auto text-[22px] font-bold leading-7 tracking-tight">
        TPVS
      </span>

      <button
        aria-label="Buscar (próximamente)"
        className={topbarButtonClass}
        disabled
        title="Próximamente"
        type="button"
      >
        <Search aria-hidden="true" className="size-5" />
      </button>

      <button
        aria-label="Notificaciones (próximamente)"
        className={topbarButtonClass}
        disabled
        title="Próximamente"
        type="button"
      >
        <Bell aria-hidden="true" className="size-5" />
      </button>

      <Sheet open={accountOpen} onOpenChange={setAccountOpen}>
        <SheetTrigger
          aria-label={`Cuenta de ${user.name}`}
          className={`${topbarButtonClass} ml-1`}
        >
          <span
            aria-hidden="true"
            className="grid size-9 place-items-center rounded-full bg-accent text-[13px] font-bold leading-4 text-accent-foreground"
          >
            {initials}
          </span>
        </SheetTrigger>
        <SheetContent
          className="gap-2 rounded-t-xl border-0 px-4 pb-[max(1.5rem,env(safe-area-inset-bottom))] pt-3"
          showCloseButton={false}
          side="bottom"
        >
          <span
            aria-hidden="true"
            className="mb-2 h-1 w-9 self-center rounded-full bg-border"
          />
          <div className="flex items-center gap-3 px-3 py-2">
            <span
              aria-hidden="true"
              className="grid size-11 shrink-0 place-items-center rounded-full bg-accent text-[15px] font-bold text-accent-foreground"
            >
              {initials}
            </span>
            <span className="min-w-0 flex-1">
              <SheetTitle className="block truncate text-sm font-semibold">
                {user.name}
              </SheetTitle>
              <SheetDescription className="block truncate text-xs font-medium">
                {getRoleLabel(user.role)} · {user.email}
              </SheetDescription>
            </span>
            <ThemeToggle className="size-11" />
          </div>
          {sheetLogout}
        </SheetContent>
      </Sheet>
    </header>
  )
}
