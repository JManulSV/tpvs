import { Suspense } from "react";
import { redirect } from "next/navigation";
import { getAuthenticatedUser } from "@/features/auth/server/auth-session";
import { ReportsScreen } from "@/features/reports/components/reports-screen";

export const instant = false;

export default async function ReportsPage() {
  const user = await getAuthenticatedUser();

  if (!user) redirect("/login?returnTo=%2Freports");

  return (
    <Suspense fallback={<p className="py-8 text-center text-sm text-muted-foreground">Cargando reporte…</p>}>
      <ReportsScreen />
    </Suspense>
  );
}
