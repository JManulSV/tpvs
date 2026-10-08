async function getBackendHealth() {
  const baseUrl = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

  try {
    const response = await fetch(`${baseUrl}/api/health`, { cache: "no-store" });
    if (!response.ok) return { status: "down" as const };
    return { status: "up" as const };
  } catch {
    return { status: "down" as const };
  }
}

export default async function Home() {
  const health = await getBackendHealth();

  return (
    <main className="flex min-h-screen flex-col items-center justify-center gap-6 p-8 text-center">
      <h1 className="text-3xl font-semibold">TPVS</h1>
      <p className="text-sm text-zinc-600">Frontend running locally.</p>
      <p className={health.status === "up" ? "text-green-600" : "text-red-600"}>
        Backend: {health.status}
      </p>
    </main>
  );
}
