"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { useFieldArray, useForm, useWatch, type FieldPath, type FieldErrors } from "react-hook-form";
import { Button, buttonVariants } from "@/components/ui/button";
import { useCompanies } from "@/features/companies/hooks/use-companies";
import { formatDate, formatMoney } from "@/lib/format";
import { useCreateRecord, useUpdateRecord } from "../hooks/use-records";
import { getReportMonthName } from "../record-detail-utils";
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
  onCancelEdit: () => void;
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

const inputClass = "h-10 w-full rounded-md border bg-background px-3 text-sm";
const cardClass = "space-y-4 rounded-lg border bg-card p-5 shadow-card";

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
  const [createdRecord, setCreatedRecord] = useState<RecordDetailView | null>(null);
  const [editReason, setEditReason] = useState("");
  // Un modo por movimiento. El alta empieza con un movimiento vacío (ver initialValues).
  const [commissionModes, setCommissionModes] = useState<Array<"percentage" | "fixed">>(() =>
    isEdit
      ? props.record.details.map((detail) => detail.commission_percentage === null ? "fixed" : "percentage")
      : ["percentage"],
  );
  const [detailErrors, setDetailErrors] = useState<Record<string, string>>({});
  const isSubmitting = form.formState.isSubmitting || mutation.isPending;
  const weekEndDate = useWatch({ control: form.control, name: "week_end_date" });
  const reportMonth = getReportMonthName(weekEndDate || null);

  async function onSubmit(values: ManualRecordFormValues) {
    setFormError(null);
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
        // El servidor devuelve los importes; el cliente no los calcula.
        const record = await createMutation.mutateAsync(payload);
        setCreatedRecord(record);
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

  function startNewRecord() {
    form.reset(initialValues());
    details.replace([{ affiliation: "", branch: "", income: "", commission_percentage: "" }]);
    setCommissionModes(["percentage"]);
    setEditReason("");
    setFormError(null);
    setCreatedRecord(null);
    mutation.reset();
  }

  if (createdRecord !== null) {
    return <ManualRecordSummary onCreateAnother={startNewRecord} record={createdRecord} />;
  }

  const HeadingTag = isEdit ? "h2" : "h1";

  const globalError = (field: "company_id" | "year" | "week" | "week_start_date" | "week_end_date") =>
    (form.formState.errors as FieldErrors<ManualRecordFormValues>)[field]?.message as string | undefined;

  return (
    <section aria-labelledby="manual-record-heading" className="space-y-6">
      <header className="space-y-1">
        {/* En edición va dentro del detalle, que ya tiene el h1 de la página. */}
        <HeadingTag className="text-2xl font-semibold tracking-tight" id="manual-record-heading">
          {isEdit ? "Editar registro" : "Nuevo registro"}
        </HeadingTag>
        <p className="text-sm text-muted-foreground">
          {isEdit
            ? props.record.status === "CONFIRMED"
              ? "Este registro está confirmado. Al guardar los cambios se reabrirá para revisión, deberás capturar un motivo y confirmarlo nuevamente. Los importes oficiales los calcula el servidor."
              : "Corrige los datos y guarda con un motivo. Los importes oficiales los calcula el servidor y el estado no cambia."
            : "Captura los datos tú mismo. Al guardar, el registro queda confirmado."}
        </p>
      </header>

      <div className="@container">
        <form className="grid gap-6 @[60rem]:grid-cols-[minmax(0,1fr)_340px] @[60rem]:items-start" noValidate onSubmit={form.handleSubmit(onSubmit)}>
          <div className="space-y-6">
            <fieldset className={cardClass} disabled={isSubmitting}>
              <legend className="sr-only">Empresa y periodo</legend>
              <div className="space-y-1">
                <h2 className="text-base/6 font-semibold">Empresa y periodo</h2>
                <p className="text-xs/4 text-muted-foreground">La semana se identifica por semana y año.</p>
              </div>
              <div className="grid grid-cols-[repeat(auto-fit,minmax(150px,1fr))] gap-4">
                <Field id="manual-record-company" label="Empresa" error={globalError("company_id")}>
                  <select className={inputClass} id="manual-record-company" aria-invalid={Boolean(globalError("company_id"))} {...form.register("company_id", { valueAsNumber: true })}>
                    <option value={0}>Selecciona…</option>
                    {(companiesQuery.data?.data ?? []).map((company) => <option key={company.id} value={company.id}>{company.name}</option>)}
                  </select>
                </Field>
                <Field id="manual-record-year" label="Año" error={globalError("year")}>
                  <input className={inputClass} id="manual-record-year" inputMode="numeric" type="number" {...form.register("year", { valueAsNumber: true })} />
                </Field>
                <Field id="manual-record-week" label="Semana (1–53)" error={globalError("week")}>
                  <input className={inputClass} id="manual-record-week" inputMode="numeric" max={53} min={1} placeholder="1–53" type="number" {...form.register("week", { valueAsNumber: true })} />
                </Field>
                <Field id="manual-record-start-date" label="Inicio" error={globalError("week_start_date")}>
                  <input className={inputClass} id="manual-record-start-date" type="date" {...form.register("week_start_date")} />
                </Field>
                <Field id="manual-record-end-date" label="Fin" error={globalError("week_end_date")}>
                  <input className={inputClass} id="manual-record-end-date" type="date" {...form.register("week_end_date")} />
                </Field>
              </div>
              <p className="text-xs/4 text-muted-foreground">
                {reportMonth !== null && <>Mes del reporte: <strong>{reportMonth}</strong>. </>}
                Cuando una semana cruza dos meses se usa el mes de la fecha de fin.
              </p>
            </fieldset>

            <section aria-labelledby="movements-heading" className={`${cardClass} @container`}>
              <div className="space-y-1">
                <h2 className="text-base/6 font-semibold" id="movements-heading">Movimientos</h2>
                <p className="text-xs/4 text-muted-foreground">Captura el ingreso y el porcentaje; la comisión y el retorno los calcula el servidor.</p>
              </div>

              {details.fields.map((field, index) => {
                const mode = commissionModes[index] ?? "percentage";
                const serverDetail = isEdit ? props.record.details[index] : undefined;
                const affiliationError = detailErrors[`details.${index}.affiliation`] ?? form.formState.errors.details?.[index]?.affiliation?.message;
                const branchError = detailErrors[`details.${index}.branch`] ?? form.formState.errors.details?.[index]?.branch?.message;
                const incomeError = detailErrors[`details.${index}.income`] ?? form.formState.errors.details?.[index]?.income?.message;
                const commissionError = mode === "percentage"
                  ? detailErrors[`details.${index}.commission_percentage`] ?? form.formState.errors.details?.[index]?.commission_percentage?.message
                  : detailErrors[`details.${index}.commission`] ?? form.formState.errors.details?.[index]?.commission?.message;
                return (
                  <fieldset className="space-y-4 rounded-md border p-4" disabled={isSubmitting} key={field.id}>
                    <legend className="px-2 font-semibold">Movimiento {index + 1}</legend>
                    <div className="grid grid-cols-1 gap-4 @[45rem]:grid-cols-2">
                      <Field id={`movement-${index}-affiliation`} label="Afiliación" error={affiliationError}>
                        <input className={inputClass} id={`movement-${index}-affiliation`} maxLength={255} aria-invalid={Boolean(affiliationError)} {...form.register(`details.${index}.affiliation`)} />
                      </Field>
                      <Field id={`movement-${index}-branch`} label="Sucursal" error={branchError}>
                        <input className={inputClass} id={`movement-${index}-branch`} maxLength={255} aria-invalid={Boolean(branchError)} {...form.register(`details.${index}.branch`)} />
                      </Field>
                      <Field id={`movement-${index}-income`} label="Ingreso" error={incomeError}>
                        <input className={inputClass} id={`movement-${index}-income`} inputMode="decimal" aria-invalid={Boolean(incomeError)} {...form.register(`details.${index}.income`)} />
                      </Field>
                      <Field id={`movement-${index}-commission-type`} label="Tipo de comisión">
                        <select className={inputClass} id={`movement-${index}-commission-type`} value={mode} onChange={(event) => {
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
                          ? <input className={inputClass} id={`movement-${index}-commission`} inputMode="decimal" aria-invalid={Boolean(commissionError)} {...form.register(`details.${index}.commission_percentage`)} />
                          : <input className={inputClass} id={`movement-${index}-commission`} inputMode="decimal" aria-invalid={Boolean(commissionError)} {...form.register(`details.${index}.commission`)} />}
                      </Field>
                    </div>
                    <div className="grid grid-cols-2 gap-4 rounded-md bg-muted/40 p-3 text-sm">
                      <ServerValue label="Comisión" value={serverDetail ? formatMoney(serverDetail.commission) : "—"} />
                      <ServerValue label="Retorno" value={serverDetail ? formatMoney(serverDetail.return_amount) : "—"} />
                    </div>
                    <div className="flex justify-end">
                      <Button className="text-status-error" disabled={isSubmitting || details.fields.length <= 1} onClick={() => {
                        details.remove(index);
                        setCommissionModes((current) => current.filter((_, itemIndex) => itemIndex !== index));
                      }} type="button" variant="outline">Quitar movimiento</Button>
                    </div>
                  </fieldset>
                );
              })}

              <div className="flex justify-start">
                <Button disabled={isSubmitting} onClick={() => {
                  details.append({ affiliation: "", branch: "", income: "", commission_percentage: "" });
                  setCommissionModes((current) => [...current, "percentage"]);
                }} type="button" variant="outline">Agregar movimiento</Button>
              </div>
              {form.formState.errors.details?.message && <p className="text-sm text-destructive" role="alert">{form.formState.errors.details.message}</p>}
            </section>

            {isEdit && (
              <Field id="manual-record-reason" label="Motivo de la edición (obligatorio)" error={!editReason.trim() && formError ? "El motivo de la edición es obligatorio." : undefined}>
                <textarea className="min-h-24 w-full rounded-md border bg-background px-3 py-2 text-sm" id="manual-record-reason" maxLength={2000} onChange={(event) => setEditReason(event.target.value)} value={editReason} />
                <p className="text-xs text-muted-foreground">
                  {props.record.status === "CONFIRMED"
                    ? "Explica por qué reabres este registro. El motivo quedará registrado en el historial junto con los cambios."
                    : "El motivo quedará registrado en el historial junto con los cambios."}
                </p>
              </Field>
            )}

            {formError && <p className="rounded-md border border-destructive/40 px-4 py-3 text-sm text-destructive" role="alert">{formError}</p>}
            {companiesQuery.isError && <p className="text-sm text-destructive" role="alert">No se pudieron cargar las empresas. Recarga e inténtalo nuevamente.</p>}
            {companiesQuery.isSuccess && companiesQuery.data.data.length === 0 && <p className="text-sm" role="status">Primero debe existir al menos una empresa en el catálogo.</p>}

            <div className="flex flex-wrap justify-end gap-3">
              <Button disabled={isSubmitting} onClick={() => {
                if (props.mode === "edit") props.onCancelEdit();
                else router.push("/records");
              }} type="button" variant="outline">Cancelar</Button>
              <Button disabled={isSubmitting || companiesQuery.isPending || (isEdit && !editReason.trim())} type="submit">
                {isSubmitting ? "Guardando…" : isEdit ? "Guardar cambios" : "Guardar y confirmar"}
              </Button>
            </div>
          </div>

          <aside aria-labelledby="server-validates-heading" className={`${cardClass} @[60rem]:sticky @[60rem]:top-4`}>
            <h2 className="text-base/6 font-semibold" id="server-validates-heading">Qué valida el servidor</h2>
            <ul className="list-disc space-y-2 pl-5 text-sm text-muted-foreground">
              <li>Empresa, semana, año y fechas completos.</li>
              <li>La fecha de fin no puede ser anterior a la de inicio.</li>
              <li>Al menos un movimiento completo.</li>
              <li>La comisión y el retorno los calcula el servidor.</li>
            </ul>
          </aside>
        </form>
      </div>
    </section>
  );
}

function ServerValue({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-xs font-semibold text-muted-foreground">{label}</dt>
      <dd className="font-medium tabular-nums">{value}</dd>
    </div>
  );
}

function ManualRecordSummary({ onCreateAnother, record }: { onCreateAnother: () => void; record: RecordDetailView }) {
  const month = getReportMonthName(record.week_end_date);

  return (
    <section aria-labelledby="manual-summary-heading" className="space-y-6">
      <div className="space-y-4 rounded-lg border bg-card p-6 shadow-card">
        <div className="space-y-1">
          <h1 className="text-xl font-semibold" id="manual-summary-heading">Registro confirmado</h1>
          <p className="text-sm text-muted-foreground">El registro se guardó y ya cuenta en los totales.</p>
        </div>

        <dl className="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
          <div><dt className="text-xs font-semibold text-muted-foreground">Empresa</dt><dd className="font-medium">{record.company?.name ?? "—"}</dd></div>
          <div><dt className="text-xs font-semibold text-muted-foreground">Periodo</dt><dd className="font-medium tabular-nums">Semana {record.week ?? "—"} · {record.year ?? "—"}</dd></div>
          <div><dt className="text-xs font-semibold text-muted-foreground">Movimientos</dt><dd className="font-medium tabular-nums">{record.details.length}</dd></div>
          <div><dt className="text-xs font-semibold text-muted-foreground">Mes del reporte</dt><dd className="font-medium">{month ?? "—"}</dd></div>
          <div><dt className="text-xs font-semibold text-muted-foreground">Inicio</dt><dd className="font-medium tabular-nums">{formatDate(record.week_start_date)}</dd></div>
          <div><dt className="text-xs font-semibold text-muted-foreground">Fin</dt><dd className="font-medium tabular-nums">{formatDate(record.week_end_date)}</dd></div>
        </dl>

        <dl className="grid grid-cols-3 gap-4 rounded-md bg-muted/40 p-4">
          <div><dt className="text-xs font-semibold text-muted-foreground">Ingreso</dt><dd className="text-lg font-bold tabular-nums">{formatMoney(record.income_total ?? null)}</dd></div>
          <div><dt className="text-xs font-semibold text-muted-foreground">Comisión</dt><dd className="text-lg font-bold tabular-nums">{formatMoney(record.commission_total ?? null)}</dd></div>
          <div><dt className="text-xs font-semibold text-muted-foreground">Retorno</dt><dd className="text-lg font-bold tabular-nums">{formatMoney(record.return_total ?? null)}</dd></div>
        </dl>

        <div className="flex flex-wrap justify-end gap-3">
          <Button onClick={onCreateAnother} type="button" variant="outline">Crear otro registro</Button>
          <Link className={buttonVariants({ variant: "outline" })} href={`/records/${record.id}`}>Ver registro</Link>
          <Link className={buttonVariants({})} href={`/records/${record.id}/delivery`}>Preparar entrega</Link>
        </div>
      </div>
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
