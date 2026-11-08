"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { CircleAlert, Eye, EyeOff, LogIn } from "lucide-react";
import { useRouter } from "next/navigation";
import { useState, useSyncExternalStore } from "react";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { ThemeToggle } from "@/components/theme-toggle";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";
import { getLoginErrorDisplay, type LoginErrorDisplay } from "../login-error";
import { AuthApiError } from "../types";
import { authService } from "../services/auth.service";

const loginSchema = z.object({
  email: z.email("Ingresa un correo electrónico válido."),
  password: z.string().min(1, "Ingresa tu contraseña."),
});

type LoginFormValues = z.infer<typeof loginSchema>;

const LOGIN_ERROR_ID = "login-error";

// Replaces the base Input's destructive ring with a 2 px status-error border,
// in both themes, while keeping the keyboard focus ring.
const invalidFieldClassName =
  "aria-invalid:border-2 aria-invalid:border-status-error aria-invalid:ring-0 aria-invalid:ring-ring aria-invalid:focus-visible:ring-2 dark:aria-invalid:border-status-error dark:aria-invalid:ring-ring";

const labelClassName = "text-xs font-medium text-muted-foreground";

const fieldErrorClassName = "text-xs text-status-error";

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

function describedBy(...ids: Array<string | false | undefined>) {
  const value = ids.filter(Boolean).join(" ");
  return value || undefined;
}

export function LoginForm({ returnTo }: { returnTo: string }) {
  const router = useRouter();
  const isHydrated = useSyncExternalStore(
    subscribeToHydration,
    getHydrationSnapshot,
    getServerHydrationSnapshot,
  );
  const [loginError, setLoginError] = useState<LoginErrorDisplay | null>(null);
  const [showPassword, setShowPassword] = useState(false);
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
      }

      setLoginError(getLoginErrorDisplay(error));
    }
  }

  const isDisabled = !isHydrated || isSubmitting;
  const invalidCredentials = loginError?.invalidCredentials ?? false;
  const emailInvalid = Boolean(errors.email) || invalidCredentials;
  const passwordInvalid = Boolean(errors.password) || invalidCredentials;

  return (
    <main className="flex min-w-0 flex-1 basis-[520px] flex-col justify-between gap-10 p-6 sm:p-14">
      <div className="flex items-center justify-between gap-4">
        <p className="text-2xl font-bold">TPVS</p>
        <ThemeToggle />
      </div>

      <div className="mx-auto flex w-full max-w-[400px] flex-col gap-8">
        <header className="flex flex-col gap-2">
          <h1 className="text-[32px] font-bold leading-10 tracking-[-0.02em]">
            Iniciar sesión
          </h1>
          <p className="text-sm text-muted-foreground">
            Entra con tu correo y contraseña.
          </p>
        </header>

        {loginError && (
          <div
            className="flex items-start gap-3 rounded-md bg-status-error-bg px-4 py-3 text-sm text-status-error"
            id={LOGIN_ERROR_ID}
            role="alert"
          >
            <CircleAlert aria-hidden="true" className="mt-px size-[18px] shrink-0" />
            <div className="flex flex-col gap-0.5">
              <p className="font-bold">No pudimos iniciar tu sesión</p>
              <p>{loginError.message}</p>
            </div>
          </div>
        )}

        <form
          action="/login"
          className="flex flex-col gap-5"
          method="post"
          onSubmit={(event) => {
            event.preventDefault();
            setLoginError(null);
            void handleSubmit(onSubmit)(event);
          }}
          noValidate
        >
          <div className="flex flex-col gap-2">
            <label className={labelClassName} htmlFor="email">
              Correo electrónico
            </label>
            <Input
              autoComplete="username"
              className={invalidFieldClassName}
              id="email"
              inputMode="email"
              placeholder="tu.correo@ejemplo.com"
              type="email"
              disabled={isDisabled}
              aria-invalid={emailInvalid || undefined}
              aria-describedby={describedBy(
                errors.email && "email-error",
                invalidCredentials && LOGIN_ERROR_ID,
              )}
              {...register("email")}
            />
            {errors.email && (
              <p className={fieldErrorClassName} id="email-error">
                {errors.email.message}
              </p>
            )}
          </div>

          <div className="flex flex-col gap-2">
            <label className={labelClassName} htmlFor="password">
              Contraseña
            </label>
            <div className="relative">
              <Input
                autoComplete="current-password"
                className={cn("pr-12", invalidFieldClassName)}
                id="password"
                placeholder="Escribe tu contraseña"
                type={showPassword ? "text" : "password"}
                disabled={isDisabled}
                aria-invalid={passwordInvalid || undefined}
                aria-describedby={describedBy(
                  errors.password && "password-error",
                  invalidCredentials && LOGIN_ERROR_ID,
                )}
                {...register("password")}
              />
              <button
                aria-label={showPassword ? "Ocultar contraseña" : "Mostrar contraseña"}
                className="absolute right-1 top-1/2 flex size-8 -translate-y-1/2 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-card disabled:pointer-events-none disabled:opacity-50"
                disabled={isDisabled}
                onClick={() => setShowPassword((value) => !value)}
                type="button"
              >
                {showPassword ? (
                  <EyeOff aria-hidden="true" className="size-[18px]" />
                ) : (
                  <Eye aria-hidden="true" className="size-[18px]" />
                )}
              </button>
            </div>
            {errors.password && (
              <p className={fieldErrorClassName} id="password-error">
                {errors.password.message}
              </p>
            )}
          </div>

          <div className="flex flex-wrap items-center justify-between gap-3">
            <label className="flex items-center gap-2 text-sm opacity-50">
              <input
                aria-label="Mantener sesión iniciada (próximamente)"
                className="size-[18px] accent-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-card disabled:cursor-not-allowed"
                disabled
                title="Próximamente"
                type="checkbox"
              />
              Mantener sesión iniciada
            </label>
            <button
              aria-label="¿Olvidaste tu contraseña? (próximamente)"
              className="text-sm font-semibold text-accent-foreground opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-card disabled:cursor-not-allowed"
              disabled
              title="Próximamente"
              type="button"
            >
              ¿Olvidaste tu contraseña?
            </button>
          </div>

          <Button
            className="h-10 w-full rounded-full"
            type="submit"
            disabled={isDisabled}
          >
            <LogIn aria-hidden="true" className="size-4" />
            {!isHydrated
              ? "Preparando formulario…"
              : isSubmitting
                ? "Iniciando sesión…"
                : "Iniciar sesión"}
          </Button>
        </form>
      </div>

      <p className="text-center text-xs font-medium text-muted-foreground">
        ¿No tienes acceso? Solicítalo a tu administrador.
      </p>
    </main>
  );
}
