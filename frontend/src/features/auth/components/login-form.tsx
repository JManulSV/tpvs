"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { useState, useSyncExternalStore } from "react";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { Button } from "@/components/ui/button";
import { AuthApiError } from "../types";
import { authService } from "../services/auth.service";

const loginSchema = z.object({
  email: z.email("Ingresa un correo electrónico válido."),
  password: z.string().min(1, "Ingresa tu contraseña."),
});

type LoginFormValues = z.infer<typeof loginSchema>;

function subscribeToHydration(onStoreChange: () => void) {
  const timer = window.setTimeout(onStoreChange, 0);
  return () => window.clearTimeout(timer);
}

function getHydrationSnapshot() {
  return true;
}

function getServerHydrationSnapshot() {
  return false;
}

export function LoginForm({ returnTo }: { returnTo: string }) {
  const router = useRouter();
  const isHydrated = useSyncExternalStore(
    subscribeToHydration,
    getHydrationSnapshot,
    getServerHydrationSnapshot,
  );
  const [formError, setFormError] = useState<string | null>(null);
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<LoginFormValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: "", password: "" },
  });

  async function onSubmit(values: LoginFormValues) {
    setFormError(null);

    try {
      await authService.login(values);
      router.replace(returnTo);
    } catch (error) {
      if (error instanceof AuthApiError) {
        for (const [field, messages] of Object.entries(error.validationErrors)) {
          if ((field === "email" || field === "password") && messages[0]) {
            setError(field, { type: "server", message: messages[0] });
          }
        }
        setFormError(error.message);
        return;
      }

      setFormError("No se pudo iniciar sesión. Inténtalo nuevamente.");
    }
  }

  return (
    <main className="flex min-h-screen items-center justify-center px-4 py-12">
      <section className="w-full max-w-md rounded-xl border bg-card p-8 shadow-sm">
        <header className="mb-8 space-y-2">
          <p className="text-sm font-medium text-muted-foreground">TPVS</p>
          <h1 className="text-2xl font-semibold tracking-tight">Iniciar sesión</h1>
          <p className="text-sm text-muted-foreground">
            Ingresa con tu cuenta para continuar.
          </p>
        </header>

        <form
          action="/login"
          className="space-y-5"
          method="post"
          onSubmit={(event) => {
            event.preventDefault();
            void handleSubmit(onSubmit)(event);
          }}
          noValidate
        >
          <div className="space-y-2">
            <label className="text-sm font-medium" htmlFor="email">
              Correo electrónico
            </label>
            <input
              autoComplete="username"
              className="flex h-10 w-full rounded-md border bg-background px-3 py-2 text-sm outline-none transition focus-visible:ring-2 focus-visible:ring-ring"
              id="email"
              inputMode="email"
              type="email"
              disabled={!isHydrated || isSubmitting}
              aria-invalid={Boolean(errors.email)}
              aria-describedby={errors.email ? "email-error" : undefined}
              {...register("email")}
            />
            {errors.email && (
              <p className="text-sm text-destructive" id="email-error">
                {errors.email.message}
              </p>
            )}
          </div>

          <div className="space-y-2">
            <label className="text-sm font-medium" htmlFor="password">
              Contraseña
            </label>
            <input
              autoComplete="current-password"
              className="flex h-10 w-full rounded-md border bg-background px-3 py-2 text-sm outline-none transition focus-visible:ring-2 focus-visible:ring-ring"
              id="password"
              type="password"
              disabled={!isHydrated || isSubmitting}
              aria-invalid={Boolean(errors.password)}
              aria-describedby={errors.password ? "password-error" : undefined}
              {...register("password")}
            />
            {errors.password && (
              <p className="text-sm text-destructive" id="password-error">
                {errors.password.message}
              </p>
            )}
          </div>

          {formError && (
            <p
              className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive"
              role="alert"
            >
              {formError}
            </p>
          )}

          <Button
            className="w-full"
            type="submit"
            disabled={!isHydrated || isSubmitting}
          >
            {!isHydrated
              ? "Preparando formulario…"
              : isSubmitting
                ? "Ingresando…"
                : "Ingresar"}
          </Button>
        </form>
      </section>
    </main>
  );
}
