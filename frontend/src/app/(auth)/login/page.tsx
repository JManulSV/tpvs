import { Suspense } from "react";
import { LoginForm } from "@/features/auth/components/login-form";
import { getSafeReturnTo } from "@/features/auth/safe-return-to";

type LoginPageProps = {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
};

async function LoginPageContent({ searchParams }: LoginPageProps) {
  const params = await searchParams;

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
