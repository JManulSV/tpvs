"use client";

import { useEffect, useState } from "react";

type HealthStatus = "checking" | "up" | "down";

export default function Home() {
  const [health, setHealth] = useState<HealthStatus>("checking");

  useEffect(() => {
    fetch("/api/health", { cache: "no-store" })
      .then((response) => setHealth(response.ok ? "up" : "down"))
      .catch(() => setHealth("down"));
  }, []);

  return (
    <main className="flex min-h-screen flex-col items-center justify-center gap-6 p-8 text-center">
      <h1 className="text-3xl font-semibold">TPVS</h1>
      <p className="text-sm text-zinc-600">Frontend running locally.</p>
      <p
        className={
          health === "up"
            ? "text-green-600"
            : health === "down"
              ? "text-red-600"
              : "text-zinc-500"
        }
      >
        Backend: {health}
      </p>
    </main>
  );
}
