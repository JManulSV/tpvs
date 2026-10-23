"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { useFieldArray, useForm, type FieldPath, type FieldErrors } from "react-hook-form";
import { Button } from "@/components/ui/button";
import { useCompanies } from "@/features/companies/hooks/use-companies";
import { useCreateRecord, useUpdateRecord } from "../hooks/use-records";
import {
  manualRecordFormSchema,
  type ManualRecordFormValues,
} from "../schemas/record.schema";
import { RecordsApiError, type RecordDetailView, type RecordDetailInput } from "../types";

type ManualRecordFormProps = {
  mode: "create";
  record?: never;
} | {
  mode: "edit";
  record: RecordDetailView;
  onEditComplete: () => void;
};

function initialValues(record?: RecordDetailView): ManualRecordFormValues {
  const currentYear = new Date().getFullYear();

  return {
    company_id: record?.company?.id ?? 0,
    year: record?.year ?? currentYear,
    week: record?.week ?? "" as unknown as number,
    week_start_date: record?.week_start_date ?? "",
    week_end_date: record?.week_end_date ?? "",
    details: record?.details.length
      ? record.details.map((detail) => ({
          affiliation: detail.affiliation ?? "",
          branch: detail.branch ?? "",
          income: detail.income ?? "",
          ...(detail.commission_percentage !== null
            ? { commission_percentage: detail.commission_percentage }
            : { commission: detail.commission ?? "" }),
        }))
      : [{ affiliation: "", branch: "", income: "", commission_percentage: "" }],
  } as ManualRecordFormValues;
}

function fieldErrorKey(field: string) {
  return field.replace(/^details\.(\d+)\./, "details.$1.");
}

export function ManualRecordForm(props: ManualRecordFormProps) {
  const router = useRouter();
  const companiesQuery = useCompanies({ per_page: 100 });
  const createMutation = useCreateRecord();
  const updateMutation = useUpdateRecord();
  const isEdit = props.mode === "edit";
  const mutation = isEdit ? updateMutation : createMutation;
  const form = useForm<ManualRecordFormValues>({
    resolver: zodResolver(manualRecordFormSchema),
    defaultValues: initialValues(isEdit ? props.record : undefined),
  });
  const details = useFieldArray({ control: form.control, name: "details" });
  const recordCompanyId = isEdit ? props.record.company?.id ?? null : null;

  useEffect(() => {
    if (recordCompanyId !== null && companiesQuery.isSuccess) {
      form.setValue("company_id", recordCompanyId, { shouldValidate: true });
    }
  }, [companiesQuery.isSuccess, form, recordCompanyId]);
  const [formError, setFormError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [editReason, setEditReason] = useState("");
  const [commissionModes, setCommissionModes] = useState<Array<"percentage" | "fixed">>(() =>
    (isEdit ? props.record.details : []).map((detail) => detail.commission_percentage === null ? "fixed" : "percentage"),
  );
  const [detailErrors, setDetailErrors] = useState<Record<string, string>>({});
  const isSubmitting = form.formState.isSubmitting || mutation.isPending;

  async function onSubmit(values: ManualRecordFormValues) {
    setFormError(null);
    setSuccessMessage(null);
    setDetailErrors({});
    form.clearErrors();
    mutation.reset();
    if (props.mode === "edit" && !editReason.trim()) {
      setFormError("El motivo de la edición es obligatorio.");
      return;
    }
    const inputs: RecordDetailInput[] = values.details.map((detail, index) => {
      const mode = commissionModes[index] ?? "percentage";
      return {
        affiliation: detail.affiliation.trim(),
        branch: detail.branch.trim(),
        income: detail.income,
        ...(mode === "percentage"
          ? { commission_percentage: detail.commission_percentage ?? "" }
          : { commission: detail.commission ?? "" }),
      } as RecordDetailInput;
    });
    const payload = { ...values, details: inputs };

    try {
      if (props.mode === "edit") {
        await updateMutation.mutateAsync({
          id: props.record.id,
          input: { ...payload, reason: editReason.trim() },
        });
        props.onEditComplete();
      } else {
        await createMutation.mutateAsync(payload);
        form.reset(initialValues());
        setCommissionModes(["percentage"]);
        setEditReason("");
        setSuccessMessage("El registro se guardó y confirmó. El formulario está listo para otra captura.");
      }
    } catch (error) {
      if (error instanceof RecordsApiError) {
        let mapped = false;
        for (const [field, messages] of Object.entries(error.validationErrors)) {
          if (messages[0] && field !== "reason") {
            if (field.startsWith("details.")) {
              setDetailErrors((current) => ({ ...current, [fieldErrorKey(field)]: messages[0] }));
            } else {
              form.setError(fieldErrorKey(field) as FieldPath<ManualRecordFormValues>, {
                type: "server",
                message: messages[0],
              });
            }
            mapped = true;
          }
        }
        setFormError(mapped ? "Corrige los campos señalados por el servidor." : error.message);
      } else {
        setFormError("No se pudo guardar el registro. Inténtalo nuevamente.");
      }
    }
  }

  const globalError = (field: "company_id" | "year" | "week" | "week_start_date" | "week_end_date") =>
    (form.formState.errors as FieldErrors<ManualRecordFormValues>)[field]?.message as string | undefined;

  return (
    <section aria-labelledby="manual-record-heading" className="space-y-6">
      <header className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight" id="manual-record-heading">
          {isEdit ? "Editar registro" : "Nuevo registro manual"}
        </h1>
        <p className="text-sm text-muted-foreground">
          {isEdit
            ? "Corrige los datos y guarda con un motivo. Los importes oficiales los calcula el servidor y el estado no cambia."
            : "Los importes oficiales los calcula el servidor. Si la captura es válida, quedará confirmada al guardarla."}
        </p>
      </header>

      <form className="space-y-8" noValidate onSubmit={form.handleSubmit(onSubmit)}>
        <fieldset className="grid gap-4 rounded-md border p-4 sm:grid-cols-2 lg:grid-cols-3" disabled={isSubmitting}>
          <legend className="px-2 font-medium">Datos generales</legend>
          <Field id="manual-record-company" label="Empresa" error={globalError("company_id")}>
            <select className="h-10 w-full rounded-md border bg-background px-3 text-sm" id="manual-record-company" aria-invalid={Boolean(globalError("company_id"))} {...form.register("company_id", { valueAsNumber: true })}>
              <option value={0}>Selecciona una empresa</option>
              {(companiesQuery.data?.data ?? []).map((company) => <option key={company.id} value={company.id}>{company.name}</option>)}
            </select>
          </Field>
          <Field id="manual-record-year" label="Año" error={globalError("year")}>
            <input className="h-10 w-full rounded-md border bg-background px-3 text-sm" id="manual-record-year" inputMode="numeric" type="number" {...form.register("year", { valueAsNumber: true })} />
          </Field>
          <Field id="manual-record-week" label="Semana del negocio (1–53)" error={globalError("week")}>
            <input className="h-10 w-full rounded-md border bg-background px-3 text-sm" id="manual-record-week" inputMode="numeric" max={53} min={1} placeholder="1–53" type="number" {...form.register("week", { valueAsNumber: true })} />
          </Field>
          <Field id="manual-record-start-date" label="Inicio del período" error={globalError("week_start_date")}>
            <input className="h-10 w-full rounded-md border bg-background px-3 text-sm" id="manual-record-start-date" type="date" {...form.register("week_start_date")} />
          </Field>
          <Field id="manual-record-end-date" label="Fin del período" error={globalError("week_end_date")}>
            <input className="h-10 w-full rounded-md border bg-background px-3 text-sm" id="manual-record-end-date" type="date" {...form.register("week_end_date")} />
          </Field>
        </fieldset>

        <section aria-labelledby="movements-heading" className="space-y-4">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h2 className="font-semibold" id="movements-heading">Movimientos</h2>
              <p className="text-sm text-muted-foreground">Ingresa comisión como porcentaje o como importe fijo.</p>
            </div>
            <Button disabled={isSubmitting} onClick={() => {
              details.append({ affiliation: "", branch: "", income: "", commission_percentage: "" });
              setCommissionModes((current) => [...current, "percentage"]);
            }} type="button" variant="outline">Agregar movimiento</Button>
          </div>
          {details.fields.map((field, index) => {
            const mode = commissionModes[index] ?? "percentage";
            const affiliationError = detailErrors[`details.${index}.affiliation`] ?? form.formState.errors.details?.[index]?.affiliation?.message;
            const branchError = detailErrors[`details.${index}.branch`] ?? form.formState.errors.details?.[index]?.branch?.message;
            const incomeError = detailErrors[`details.${index}.income`] ?? form.formState.errors.details?.[index]?.income?.message;
            const commissionError = mode === "percentage"
              ? detailErrors[`details.${index}.commission_percentage`] ?? form.formState.errors.details?.[index]?.commission_percentage?.message
              : detailErrors[`details.${index}.commission`] ?? form.formState.errors.details?.[index]?.commission?.message;
            return (
              <fieldset className="grid gap-4 rounded-md border p-4 sm:grid-cols-2 lg:grid-cols-4" disabled={isSubmitting} key={field.id}>
                <legend className="px-2 font-medium">Movimiento {index + 1}</legend>
                <Field id={`movement-${index}-affiliation`} label="Afiliación" error={affiliationError}>
                  <input className="h-10 w-full rounded-md border bg-background px-3 text-sm" id={`movement-${index}-affiliation`} maxLength={255} aria-invalid={Boolean(affiliationError)} {...form.register(`details.${index}.affiliation`)} />
                </Field>
                <Field id={`movement-${index}-branch`} label="Sucursal" error={branchError}>
                  <input className="h-10 w-full rounded-md border bg-background px-3 text-sm" id={`movement-${index}-branch`} maxLength={255} aria-invalid={Boolean(branchError)} {...form.register(`details.${index}.branch`)} />
                </Field>
                <Field id={`movement-${index}-income`} label="Ingreso" error={incomeError}>
                  <input className="h-10 w-full rounded-md border bg-background px-3 text-sm" id={`movement-${index}-income`} inputMode="decimal" aria-invalid={Boolean(incomeError)} {...form.register(`details.${index}.income`)} />
                </Field>
                <Field id={`movement-${index}-commission-type`} label="Tipo de comisión">
                  <select className="h-10 w-full rounded-md border bg-background px-3 text-sm" id={`movement-${index}-commission-type`} value={mode} onChange={(event) => {
                    const nextMode = event.target.value as "percentage" | "fixed";
                    setCommissionModes((current) => current.map((item, itemIndex) => itemIndex === index ? nextMode : item));
                    form.setValue(`details.${index}.commission_percentage`, nextMode === "percentage" ? "" : undefined, { shouldValidate: true });
                    form.setValue(`details.${index}.commission`, nextMode === "fixed" ? "" : undefined, { shouldValidate: true });
                  }}>
                    <option value="percentage">Porcentaje</option>
                    <option value="fixed">Importe fijo</option>
                  </select>
                </Field>
                <Field id={`movement-${index}-commission`} label={mode === "percentage" ? "Porcentaje" : "Comisión fija"} error={commissionError}>
                  {mode === "percentage"
                    ? <input className="h-10 w-full rounded-md border bg-background px-3 text-sm" id={`movement-${index}-commission`} inputMode="decimal" aria-invalid={Boolean(commissionError)} {...form.register(`details.${index}.commission_percentage`)} />
                    : <input className="h-10 w-full rounded-md border bg-background px-3 text-sm" id={`movement-${index}-commission`} inputMode="decimal" aria-invalid={Boolean(commissionError)} {...form.register(`details.${index}.commission`)} />}
                </Field>
                <div className="flex items-end">
                  <Button disabled={isSubmitting || details.fields.length <= 1} onClick={() => {
                    details.remove(index);
                    setCommissionModes((current) => current.filter((_, itemIndex) => itemIndex !== index));
                  }} type="button" variant="outline">Quitar</Button>
                </div>
              </fieldset>
            );
          })}
          {form.formState.errors.details?.message && <p className="text-sm text-destructive" role="alert">{form.formState.errors.details.message}</p>}
        </section>

        {isEdit && (
          <Field id="manual-record-reason" label="Motivo de la edición" error={!editReason.trim() && formError ? "El motivo de la edición es obligatorio." : undefined}>
            <textarea className="min-h-24 w-full rounded-md border bg-background px-3 py-2 text-sm" id="manual-record-reason" maxLength={2000} onChange={(event) => setEditReason(event.target.value)} value={editReason} />
          </Field>
        )}

        {successMessage && <p className="rounded-md border px-4 py-3 text-sm" role="status">{successMessage}</p>}
        {formError && <p className="rounded-md border border-destructive/40 px-4 py-3 text-sm text-destructive" role="alert">{formError}</p>}
        {companiesQuery.isError && <p className="text-sm text-destructive" role="alert">No se pudieron cargar las empresas. Recarga e inténtalo nuevamente.</p>}
        {companiesQuery.isSuccess && companiesQuery.data.data.length === 0 && <p className="text-sm" role="status">Primero debe existir al menos una empresa en el catálogo.</p>}

        <div className="flex flex-wrap justify-end gap-3">
          <Button disabled={isSubmitting} onClick={() => router.push(isEdit ? `/records/${props.record.id}` : "/records")} type="button" variant="outline">Cancelar</Button>
          <Button disabled={isSubmitting || companiesQuery.isPending || (isEdit && !editReason.trim())} type="submit">
            {isSubmitting ? "Guardando…" : isEdit ? "Guardar cambios" : "Guardar y confirmar"}
          </Button>
        </div>
      </form>
    </section>
  );
}

function Field({
  children,
  error,
  id,
  label,
}: {
  children: React.ReactNode;
  error?: string;
  id: string;
  label: string;
}) {
  return (
    <div className="space-y-2">
      <label className="text-sm font-medium" htmlFor={id}>{label}</label>
      {children}
      {error && <p className="text-sm text-destructive" role="alert">{error}</p>}
    </div>
  );
}
