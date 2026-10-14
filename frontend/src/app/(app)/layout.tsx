import type { ReactNode } from "react";
import { redirect } from "next/navigation";
import { LogoutButton } from "@/features/auth/components/logout-button";
import { getAuthenticatedUser } from "@/features/auth/server/auth-session";
import type { AuthUser } from "@/features/auth/types";

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

  return (
    <div className="min-h-screen" data-authenticated-app>
      <header className="flex items-center justify-between border-b px-6 py-4" data-authenticated-header>
        <div>
          <p className="text-sm font-medium text-muted-foreground">TPVS</p>
          <p className="font-medium">{user.name}</p>
          <p className="text-sm text-muted-foreground">
            {user.email} · {user.role}
          </p>
        </div>
        <LogoutButton />
      </header>
      <main className="mx-auto w-full max-w-5xl px-6 py-10">{children}</main>
    </div>
  );
}

export default function AppLayout({ children }: { children: ReactNode }) {
  return (
    <AuthenticatedLayout>{children}</AuthenticatedLayout>
  );
}
