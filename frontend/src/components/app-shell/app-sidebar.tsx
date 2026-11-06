"use client"

import type { ReactNode } from "react"
import Link from "next/link"
import { usePathname } from "next/navigation"
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarGroup,
  SidebarGroupContent,
  SidebarGroupLabel,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  useSidebar,
} from "@/components/ui/sidebar"
import { getInitials, getRoleLabel } from "./user-display"
import { appNavItems, isNavItemActive } from "./nav-items"

type AppSidebarProps = {
  user: {
    name: string
    role: "EDITOR" | "VIEWER"
  }
  recordsBadge?: ReactNode
  sidebarLogout: ReactNode
}

function SidebarNavigation({
  user,
  recordsBadge,
  sidebarLogout,
}: AppSidebarProps) {
  const pathname = usePathname()
  const { isMobile, setOpenMobile } = useSidebar()

  function closeMobileSidebar() {
    if (isMobile) {
      setOpenMobile(false)
    }
  }

  return (
    <Sidebar
      collapsible="icon"
      variant="floating"
      className="top-4 bottom-4 h-auto p-2"
    >
      <SidebarHeader className="gap-0 px-4 pb-4 pt-3 group-data-[collapsible=icon]:items-center group-data-[collapsible=icon]:px-0">
        <span className="text-center text-xl font-bold leading-7 tracking-tight group-data-[collapsible=icon]:text-sm group-data-[collapsible=icon]:leading-5">
          TPVS
        </span>
      </SidebarHeader>

      <SidebarContent>
        <nav aria-label="Principal" className="flex min-h-full flex-col">
          <SidebarGroup className="p-0">
            <SidebarGroupLabel className="h-auto px-3 pb-2 pt-4 text-[11px] font-semibold uppercase tracking-[0.08em] text-muted-foreground group-data-[collapsible=icon]:hidden">
              Sistema
            </SidebarGroupLabel>
            <SidebarGroupContent>
              <SidebarMenu className="gap-1">
                {appNavItems.map((item) => {
                  const isActive = isNavItemActive(pathname, item.href)
                  const Icon = item.icon

                  return (
                    <SidebarMenuItem key={item.id}>
                      <SidebarMenuButton
                        aria-current={isActive ? "page" : undefined}
                        isActive={isActive}
                        render={
                          <Link
                            href={item.href}
                            onNavigate={closeMobileSidebar}
                          />
                        }
                        size="default"
                        tooltip={item.label}
                      >
                        <Icon aria-hidden="true" />
                        <span>{item.label}</span>
                      </SidebarMenuButton>
                      {item.id === "records" ? recordsBadge : null}
                    </SidebarMenuItem>
                  )
                })}
              </SidebarMenu>
            </SidebarGroupContent>
          </SidebarGroup>
        </nav>
      </SidebarContent>

      <SidebarFooter className="mt-auto gap-1 p-0 pt-3">
        <SidebarGroupLabel className="h-auto px-3 pb-2 pt-2 text-[11px] font-semibold uppercase tracking-[0.08em] text-muted-foreground group-data-[collapsible=icon]:hidden">
          Cuenta
        </SidebarGroupLabel>
        <div className="flex min-w-0 items-center gap-3 px-3 py-2 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:px-0">
          <span
            aria-hidden="true"
            className="grid size-9 shrink-0 place-items-center rounded-full bg-accent text-sm font-bold text-accent-foreground"
          >
            {getInitials(user.name)}
          </span>
          <span className="min-w-0 group-data-[collapsible=icon]:hidden">
            <span className="block truncate text-sm font-semibold text-sidebar-foreground">
              {user.name}
            </span>
            <span className="block text-xs text-muted-foreground">
              {getRoleLabel(user.role)}
            </span>
          </span>
        </div>
        <SidebarMenu>{sidebarLogout}</SidebarMenu>
      </SidebarFooter>
    </Sidebar>
  )
}

export function AppSidebar(props: AppSidebarProps) {
  return <SidebarNavigation {...props} />
}
