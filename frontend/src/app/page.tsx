import { redirect } from "next/navigation";
import { getAuthenticatedUser } from "@/features/auth/server/auth-session";

export const instant = false;

export default async function HomePage() {
  let user;

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

  if (user) redirect("/dashboard");
  redirect("/login");
}
