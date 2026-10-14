import { Suspense } from "react";
import { redirect } from "next/navigation";
import { LoginForm } from "@/features/auth/components/login-form";
import { getSafeReturnTo } from "@/features/auth/safe-return-to";
import { getAuthenticatedUser } from "@/features/auth/server/auth-session";

export const instant = false;

type LoginPageProps = {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
};

async function LoginPageContent({ searchParams }: LoginPageProps) {
  const params = await searchParams;
  let user;

  try {
    user = await getAuthenticatedUser();
  } catch {
    return (
      <main className="flex min-h-screen items-center justify-center px-4 py-12">
        <p className="max-w-md text-center text-sm text-destructive" role="alert">
          No se pudo validar la sesión con el servidor. Recarga la página para volver a intentarlo.
        </p>
      </main>
    );
  }

  if (user) redirect("/dashboard");

  return <LoginForm returnTo={getSafeReturnTo(params.returnTo)} />;
}

export default function LoginPage({ searchParams }: LoginPageProps) {
  return (
    <Suspense
      fallback={
        <main className="flex min-h-screen items-center justify-center px-4 py-12">
          <p className="text-sm text-muted-foreground">Cargando acceso…</p>
        </main>
      }
    >
      <LoginPageContent searchParams={searchParams} />
    </Suspense>
  );
}
