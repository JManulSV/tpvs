import type { ReactNode } from "react"
import { SidebarInset, SidebarProvider } from "@/components/ui/sidebar"
import { AppBottomNav } from "./app-bottom-nav"
import { AppMobileTopbar } from "./app-mobile-topbar"
import { AppSidebar } from "./app-sidebar"
import { AppTopbar } from "./app-topbar"

type AppShellUser = {
  name: string
  email: string
  role: "EDITOR" | "VIEWER"
}

type AppShellProps = {
  user: AppShellUser
  defaultOpen: boolean
  recordsBadge?: ReactNode
  mobileRecordsBadge?: ReactNode
  sidebarLogout: ReactNode
  userMenuLogout: ReactNode
  sheetLogout: ReactNode
  children: ReactNode
}

export function AppShell({
  user,
  defaultOpen,
  recordsBadge,
  mobileRecordsBadge,
  sidebarLogout,
  userMenuLogout,
  sheetLogout,
  children,
}: AppShellProps) {
  return (
    <div
      className="min-h-screen bg-background md:p-4"
      data-authenticated-app
    >
      <SidebarProvider
        className="min-h-svh gap-4 bg-background md:min-h-[calc(100svh-2rem)]"
        defaultOpen={defaultOpen}
      >
        <AppSidebar
          user={{ name: user.name, role: user.role }}
          recordsBadge={recordsBadge}
          sidebarLogout={sidebarLogout}
        />
        <SidebarInset className="min-w-0 bg-transparent md:m-0 md:gap-6 md:rounded-none md:shadow-none">
          <AppMobileTopbar user={user} sheetLogout={sheetLogout} />
          <div className="hidden md:block">
            <AppTopbar user={user} userMenuLogout={userMenuLogout} />
          </div>
          <div className="mx-auto w-full max-w-6xl px-4 pt-4 pb-[calc(9rem+env(safe-area-inset-bottom))] md:p-0">
            {children}
          </div>
          <AppBottomNav role={user.role} recordsBadge={mobileRecordsBadge} />
        </SidebarInset>
      </SidebarProvider>
    </div>
  )
}
