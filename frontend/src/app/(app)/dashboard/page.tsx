export const instant = false;

export default function DashboardPage() {
  return (
    <section aria-labelledby="dashboard-heading" className="space-y-2">
      <h1 className="text-2xl font-semibold tracking-tight" id="dashboard-heading">
        Panel de TPVS
      </h1>
      <p className="text-sm text-muted-foreground">
        Tu sesión está activa. Aquí estará el espacio de trabajo.
      </p>
    </section>
  );
}
