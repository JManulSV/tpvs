import type { ReactNode } from "react";
import { cookies } from "next/headers";
import { redirect } from "next/navigation";
import { AppShell } from "@/components/app-shell/app-shell";
import { LogoutMenuItem } from "@/features/auth/components/logout-menu-item";
import { LogoutNavItem } from "@/features/auth/components/logout-nav-item";
import { getAuthenticatedUser } from "@/features/auth/server/auth-session";
import type { AuthUser } from "@/features/auth/types";
import { NeedsReviewNavBadge } from "@/features/records/components/needs-review-nav-badge";

// Session validation may redirect before the authenticated segment can render.
// It is intentionally blocking rather than an instant-navigation shell.
export const instant = false;

async function AuthenticatedLayout({ children }: { children: ReactNode }) {
  let user: AuthUser | null;
  try {
    user = await getAuthenticatedUser();
  } catch {
    return (
      <main className="flex min-h-screen items-center justify-center px-4">
        <p className="max-w-md text-center text-sm text-destructive" role="alert">
          No se pudo validar la sesión con el servidor. Recarga la página para volver a intentarlo.
        </p>
      </main>
    );
  }

  if (!user) redirect("/login?returnTo=%2Fdashboard");

  const cookieStore = await cookies();
  const sidebarState = cookieStore.get("sidebar_state")?.value;
  const defaultOpen = sidebarState === undefined ? true : sidebarState === "true";

  return (
    <AppShell
      user={user}
      defaultOpen={defaultOpen}
      recordsBadge={user.role === "EDITOR" ? <NeedsReviewNavBadge /> : undefined}
      sidebarLogout={<LogoutNavItem />}
      userMenuLogout={<LogoutMenuItem />}
    >
      {children}
    </AppShell>
  );
}

export default function AppLayout({ children }: { children: ReactNode }) {
  return (
    <AuthenticatedLayout>{children}</AuthenticatedLayout>
  );
}
