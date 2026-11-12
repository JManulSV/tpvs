"use client";

import { ChevronDown, RefreshCw, TriangleAlert } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState, useTransition } from "react";
import { Button, buttonVariants } from "@/components/ui/button";

const copy = {
  server: {
    title: "No pudimos cargar la información",
    text: "El servidor no respondió como esperábamos. Puede ser un problema temporal: intenta de nuevo en unos segundos.",
    kind: "Error del servidor",
  },
  connection: {
    title: "Sin conexión con el servidor",
    text: "No logramos comunicarnos con el sistema. Revisa tu conexión a internet e intenta de nuevo.",
    kind: "Sin conexión",
  },
} as const;

/**
 * Error boundary for every authenticated page. It renders inside the shell
 * (sidebar and top bar stay visible), and `reset()` re-renders the segment.
 * The message is generic on purpose: production builds hide server error
 * messages, so the page cannot tell a session failure from any other error.
 * The template's attempt counter is not included: Next remounts this boundary
 * on every `reset()`, so a counter kept here would restart at 1 each time.
 */
export default function AuthenticatedError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  const router = useRouter();
  const [, startTransition] = useTransition();
  const [loading, setLoading] = useState(false);
  const [open, setOpen] = useState(false);

  useEffect(() => {
    console.error(error);
  }, [error]);

  const variant = typeof navigator !== "undefined" && !navigator.onLine ? "connection" : "server";
  const content = copy[variant];
  const code = error.digest ?? "No disponible";

  function handleRetry() {
    if (loading) return;
    setLoading(true);
    // reset() alone re-renders the failed payload; refresh() asks the server again.
    startTransition(() => {
      router.refresh();
      reset();
    });
  }

  return (
    <section
      aria-labelledby="authenticated-error-heading"
      className="mx-auto flex w-full max-w-[560px] flex-col items-center gap-5 rounded-xl border bg-card px-5 py-8 text-center shadow-card md:px-8 md:py-10"
    >
      <span
        aria-hidden="true"
        className="grid size-[72px] place-items-center rounded-full bg-status-error-bg text-status-error"
      >
        <TriangleAlert className="size-9" />
      </span>

      <div className="flex flex-col items-center gap-2">
        <h1 className="text-2xl/8 font-bold tracking-[-0.01em]" id="authenticated-error-heading">
          {content.title}
        </h1>
        <p className="max-w-[44ch] text-[15px]/6 font-medium text-muted-foreground" role="alert">
          {content.text}
        </p>
      </div>

      <div className="flex w-full flex-col gap-3 sm:w-auto sm:flex-row sm:justify-center">
        <Button
          className="h-11 px-6"
          disabled={loading}
          onClick={handleRetry}
          type="button"
        >
          <RefreshCw
            aria-hidden="true"
            className={loading ? "animate-spin motion-reduce:animate-none" : undefined}
          />
          {loading ? "Reintentando…" : "Reintentar"}
        </Button>
        <Link className={buttonVariants({ className: "h-11 px-6", variant: "outline" })} href="/dashboard">
          Ir al dashboard
        </Link>
      </div>

      <p
        aria-live="polite"
        className="min-h-5 text-xs/4 font-medium text-muted-foreground"
        role="status"
      >
        {loading ? "Intentando conectar con el servidor…" : ""}
      </p>

      <Button
        aria-expanded={open}
        className="min-h-11 text-accent-foreground md:min-h-8"
        onClick={() => setOpen((current) => !current)}
        type="button"
        variant="ghost"
      >
        Detalles técnicos
        <ChevronDown
          aria-hidden="true"
          className={open ? "rotate-180 transition-transform motion-reduce:transition-none" : "transition-transform motion-reduce:transition-none"}
        />
      </Button>

      {open && (
        <dl className="grid w-full grid-cols-1 gap-x-4 gap-y-3 rounded-md border bg-muted p-4 text-left sm:grid-cols-2">
          <div>
            <dt className="text-xs/4 font-semibold text-muted-foreground">Tipo</dt>
            <dd className="text-sm/5 font-semibold">{content.kind}</dd>
          </div>
          <div>
            <dt className="text-xs/4 font-semibold text-muted-foreground">Código</dt>
            <dd className="text-sm/5 font-semibold tabular-nums break-all">{code}</dd>
          </div>
        </dl>
      )}
    </section>
  );
}
