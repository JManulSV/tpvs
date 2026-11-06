import type { ReactNode } from "react"
import { SidebarInset, SidebarProvider } from "@/components/ui/sidebar"
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
  sidebarLogout: ReactNode
  userMenuLogout: ReactNode
  children: ReactNode
}

export function AppShell({
  user,
  defaultOpen,
  recordsBadge,
  sidebarLogout,
  userMenuLogout,
  children,
}: AppShellProps) {
  return (
    <div
      className="min-h-screen bg-background p-4"
      data-authenticated-app
    >
      <SidebarProvider
        className="min-h-[calc(100svh-2rem)] gap-4 bg-background"
        defaultOpen={defaultOpen}
      >
        <AppSidebar
          user={{ name: user.name, role: user.role }}
          recordsBadge={recordsBadge}
          sidebarLogout={sidebarLogout}
        />
        <SidebarInset className="min-w-0 gap-6 bg-transparent md:m-0 md:rounded-none md:shadow-none">
          <AppTopbar user={user} userMenuLogout={userMenuLogout} />
          <div className="mx-auto w-full max-w-6xl">{children}</div>
        </SidebarInset>
      </SidebarProvider>
    </div>
  )
}
