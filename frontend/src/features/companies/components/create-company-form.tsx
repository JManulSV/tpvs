"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { CircleAlert } from "lucide-react";
import { useEffect, useState, type RefObject } from "react";
import { useForm } from "react-hook-form";
import { Button } from "@/components/ui/button";
// `Sheet` is built on the same Base UI dialog, so these title and description
// parts label both containers.
import { DialogDescription, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";
import { getCompanyNameErrorMessage } from "../utils";
import { useCreateCompany } from "../hooks/use-companies";
import { companyFormSchema, type CompanyFormValues } from "../schemas/company.schema";
import { CompaniesApiError, type CompanyDetail } from "../types";

const NAME_INPUT_ID = "company-name";
const NAME_HELP_ID = "company-name-help";
const NAME_ERROR_ID = "company-name-error";

type CreateCompanyFormProps = {
  /** `dialog`: actions aligned to the right. `sheet`: stacked, full width. */
  layout: "dialog" | "sheet";
  /** Lets the container focus the input when it opens. */
  inputRef?: RefObject<HTMLInputElement | null>;
  onCancel: () => void;
  onCreated: (company: CompanyDetail) => void;
  /** The container must not close while the request is in flight. */
  onSubmittingChange?: (isSubmitting: boolean) => void;
};

/** Company creation form, without its container (`Dialog` or `Sheet`). */
export function CreateCompanyForm({
  layout,
  inputRef,
  onCancel,
  onCreated,
  onSubmittingChange,
}: CreateCompanyFormProps) {
  const [formError, setFormError] = useState<string | null>(null);
  const createCompany = useCreateCompany();
  const {
    register,
    handleSubmit,
    setError,
    setFocus,
    clearErrors,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<CompanyFormValues>({
    resolver: zodResolver(companyFormSchema),
    defaultValues: { name: "" },
    // Typing clears the error instead of revalidating on every keystroke.
    reValidateMode: "onSubmit",
  });
  const nameField = register("name", {
    onChange: () => {
      clearErrors("name");
      setFormError(null);
    },
  });
  const nameError = errors.name?.message;

  useEffect(() => {
    onSubmittingChange?.(isSubmitting);
  }, [isSubmitting, onSubmittingChange]);

  // The input is disabled while submitting, so focus returns to it once the
  // request ends with a field error.
  useEffect(() => {
    if (!isSubmitting && errors.name?.type === "server") setFocus("name");
  }, [errors.name, isSubmitting, setFocus]);

  async function onSubmit(values: CompanyFormValues) {
    setFormError(null);
    createCompany.reset();

    try {
      const company = await createCompany.mutateAsync(values.name);
      reset();
      onCreated(company);
    } catch (error) {
      const nameMessages =
        error instanceof CompaniesApiError && error.status === 422
          ? error.validationErrors.name
          : undefined;

      if (nameMessages) {
        setError("name", {
          type: "server",
          message: getCompanyNameErrorMessage(nameMessages),
        });
      } else {
        setFormError(
          error instanceof CompaniesApiError
            ? error.message
            : "No se pudo crear la empresa. Inténtalo nuevamente.",
        );
      }

      createCompany.reset();
    }
  }

  const isSheet = layout === "sheet";

  return (
    <form
      className="flex flex-col gap-5"
      noValidate
      onSubmit={(event) => {
        event.preventDefault();
        void handleSubmit(onSubmit)(event);
      }}
    >
      <div className="flex flex-col gap-1">
        <DialogTitle className="text-xl leading-7 font-bold tracking-[-0.01em]">
          Nueva empresa
        </DialogTitle>
        <DialogDescription className="text-sm text-muted-foreground">
          Agrégala al catálogo para poder asignarle registros.
        </DialogDescription>
      </div>

      <div className="flex flex-col gap-1.5">
        <label
          className="text-xs leading-4 font-semibold text-muted-foreground"
          htmlFor={NAME_INPUT_ID}
        >
          Nombre
        </label>
        <Input
          aria-describedby={nameError ? NAME_ERROR_ID : NAME_HELP_ID}
          aria-invalid={Boolean(nameError)}
          autoComplete="off"
          className={cn(
            "h-11 rounded-md border-input bg-card px-4",
            "aria-invalid:border-status-error aria-invalid:ring-1 aria-invalid:ring-status-error",
            "dark:aria-invalid:border-status-error dark:aria-invalid:ring-status-error",
          )}
          disabled={isSubmitting}
          id={NAME_INPUT_ID}
          maxLength={255}
          placeholder="Ej. Company Centro"
          type="text"
          {...nameField}
          ref={(element) => {
            nameField.ref(element);
            if (inputRef) inputRef.current = element;
          }}
        />
        {nameError ? (
          <p
            className="flex items-center gap-1.5 text-xs leading-4 font-semibold text-status-error"
            id={NAME_ERROR_ID}
          >
            <CircleAlert aria-hidden="true" className="size-4 shrink-0" />
            {nameError}
          </p>
        ) : (
          <p className="text-xs leading-4 font-medium text-muted-foreground" id={NAME_HELP_ID}>
            Después de crearla no podrás cambiar el nombre.
          </p>
        )}
      </div>

      {formError && (
        <p className="text-sm font-medium text-status-error" role="alert">
          {formError}
        </p>
      )}

      <div className={cn("flex gap-3", isSheet ? "flex-col" : "justify-end")}>
        {isSheet ? (
          <>
            <Button className="w-full" disabled={isSubmitting} size="lg" type="submit">
              {isSubmitting ? "Creando…" : "Crear empresa"}
            </Button>
            <Button
              className="w-full"
              disabled={isSubmitting}
              onClick={onCancel}
              size="lg"
              type="button"
              variant="outline"
            >
              Cancelar
            </Button>
          </>
        ) : (
          <>
            <Button disabled={isSubmitting} onClick={onCancel} type="button" variant="outline">
              Cancelar
            </Button>
            <Button disabled={isSubmitting} type="submit">
              {isSubmitting ? "Creando…" : "Crear empresa"}
            </Button>
          </>
        )}
      </div>
    </form>
  );
}
