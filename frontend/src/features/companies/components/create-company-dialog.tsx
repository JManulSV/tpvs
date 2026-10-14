"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useEffect, useRef, useState } from "react";
import { useForm } from "react-hook-form";
import { Button } from "@/components/ui/button";
import { useCreateCompany } from "../hooks/use-companies";
import { companyFormSchema, type CompanyFormValues } from "../schemas/company.schema";
import { CompaniesApiError } from "../types";

type CreateCompanyDialogProps = {
  onClose: () => void;
  onCreated: (name: string) => void;
};

export function CreateCompanyDialog({ onClose, onCreated }: CreateCompanyDialogProps) {
  const nameInputRef = useRef<HTMLInputElement | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const createCompany = useCreateCompany();
  const {
    register,
    handleSubmit,
    setError,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<CompanyFormValues>({
    resolver: zodResolver(companyFormSchema),
    defaultValues: { name: "" },
  });
  const nameField = register("name");

  useEffect(() => {
    const previouslyFocused = document.activeElement instanceof HTMLElement
      ? document.activeElement
      : null;
    nameInputRef.current?.focus();

    return () => previouslyFocused?.focus();
  }, []);

  async function onSubmit(values: CompanyFormValues) {
    setFormError(null);
    createCompany.reset();

    try {
      const company = await createCompany.mutateAsync(values.name);
      reset();
      onCreated(company.name);
    } catch (error) {
      if (error instanceof CompaniesApiError) {
        let hasFieldError = false;

        for (const [field, messages] of Object.entries(error.validationErrors)) {
          if (field === "name" && messages[0]) {
            setError("name", { type: "server", message: messages[0] });
            hasFieldError = true;
          }
        }

        if (!hasFieldError) setFormError(error.message);
        createCompany.reset();
        return;
      }

      setFormError("No se pudo crear la empresa. Inténtalo nuevamente.");
      createCompany.reset();
    }
  }

  function handleKeyDown(event: React.KeyboardEvent<HTMLDivElement>) {
    if (event.key === "Escape" && !isSubmitting) onClose();

    if (event.key !== "Tab") return;

    const dialog = event.currentTarget;
    const focusable = Array.from(
      dialog.querySelectorAll<HTMLElement>(
        'button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])',
      ),
    );
    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last?.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first?.focus();
    }
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget && !isSubmitting) onClose();
      }}
    >
      <section
        aria-labelledby="create-company-heading"
        aria-modal="true"
        className="w-full max-w-md rounded-lg border bg-background p-6 shadow-lg"
        onKeyDown={handleKeyDown}
        role="dialog"
      >
        <header className="mb-5 space-y-1">
          <h2 className="text-xl font-semibold" id="create-company-heading">
            Nueva empresa
          </h2>
          <p className="text-sm text-muted-foreground">
            Agrega una empresa al catálogo.
          </p>
        </header>

        <form
          className="space-y-5"
          onSubmit={(event) => {
            event.preventDefault();
            void handleSubmit(onSubmit)(event);
          }}
          noValidate
        >
          <div className="space-y-2">
            <label className="text-sm font-medium" htmlFor="company-name">
              Nombre
            </label>
            <input
              aria-describedby={errors.name ? "company-name-error" : undefined}
              aria-invalid={Boolean(errors.name)}
              className="flex h-10 w-full rounded-md border bg-background px-3 py-2 text-sm outline-none transition focus-visible:ring-2 focus-visible:ring-ring"
              disabled={isSubmitting}
              id="company-name"
              maxLength={255}
              {...nameField}
              ref={(element) => {
                nameField.ref(element);
                nameInputRef.current = element;
              }}
            />
            {errors.name && (
              <p className="text-sm text-destructive" id="company-name-error">
                {errors.name.message}
              </p>
            )}
          </div>

          {formError && <p className="text-sm text-destructive" role="alert">{formError}</p>}

          <div className="flex justify-end gap-2">
            <Button disabled={isSubmitting} onClick={onClose} type="button" variant="outline">
              Cancelar
            </Button>
            <Button disabled={isSubmitting} type="submit">
              {isSubmitting ? "Creando…" : "Crear"}
            </Button>
          </div>
        </form>
      </section>
    </div>
  );
}
