"use client"

import type { ReactNode } from "react"
import Link from "next/link"
import { usePathname } from "next/navigation"
import { Plus } from "lucide-react"
import { cn } from "@/lib/utils"
import { appNavItems, isNavItemActive } from "./nav-items"

type AppBottomNavProps = {
  role: "EDITOR" | "VIEWER"
  recordsBadge?: ReactNode
}

export function AppBottomNav({ role, recordsBadge }: AppBottomNavProps) {
  const pathname = usePathname()
  const showNewRecord = role === "EDITOR" && pathname === "/dashboard"

  return (
    <>
      {showNewRecord && (
        <Link
          className="fixed right-4 bottom-[calc(5.25rem+env(safe-area-inset-bottom))] z-30 inline-flex h-12 items-center gap-2 rounded-full bg-primary px-5 text-sm font-semibold text-primary-foreground shadow-popover transition-colors hover:bg-primary/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring md:hidden"
          href="/records/new"
        >
          <Plus aria-hidden="true" className="size-5" />
          Nuevo registro
        </Link>
      )}

      <nav
        aria-label="Principal"
        className="fixed inset-x-0 bottom-0 z-30 grid grid-cols-4 gap-1 border-t border-border bg-card p-2 pb-[max(0.5rem,env(safe-area-inset-bottom))] md:hidden"
      >
        {appNavItems.map((item) => {
          const isActive = isNavItemActive(pathname, item.href)
          const Icon = item.icon

          return (
            <Link
              aria-current={isActive ? "page" : undefined}
              className={cn(
                "group/tab relative flex h-14 flex-col items-center justify-center gap-0.5 rounded-md text-[11px] font-semibold leading-4 text-muted-foreground focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring",
                isActive && "text-card-foreground",
              )}
              href={item.href}
              key={item.id}
            >
              <span
                className={cn(
                  "grid h-7 w-14 place-items-center rounded-full transition-colors",
                  isActive && "bg-accent text-accent-foreground",
                )}
              >
                <Icon aria-hidden="true" className="size-5" />
              </span>
              {item.label}
              {item.id === "records" ? recordsBadge : null}
            </Link>
          )
        })}
      </nav>
    </>
  )
}
