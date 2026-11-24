"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useQueryClient } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { useEffect, useMemo, useState } from "react";
import { useFieldArray, useForm, useWatch, type FieldPath } from "react-hook-form";
import { FormField, fieldErrorProps } from "@/components/form-field";
import { Button } from "@/components/ui/button";
import { useCompanies } from "@/features/companies/hooks/use-companies";
import { cardSurface, fieldControl } from "@/lib/styles";
import { cn } from "@/lib/utils";
import { recordKeys, useCreateRecord, useUpdateRecord } from "../../hooks/use-records";
import { manualRecordFormSchema, type ManualRecordFormValues } from "../../schemas/record.schema";
import { RecordsApiError, type RecordDetailView } from "../../types";
import { getDirtyCount, getSaveBarState } from "../../utils/capture";
import { getReportMonthName } from "../../utils/detail";
import {
  commissionModesFor,
  emptyMovement,
  initialManualRecordValues,
  toRecordDetailInputs,
  type CommissionMode,
} from "../../utils/manual-form";
import { CorrectionSaveBar } from "./correction-save-bar";
import { ManualRecordSummary } from "./manual-record-summary";
import { MovementFieldset } from "./movement-fieldset";

type ManualRecordFormProps =
  | {
      mode: "create";
      record?: never;
      onCreated?: () => void;
    }
  | {
      mode: "edit";
      record: RecordDetailView;
      onEditComplete: () => void;
      onCancelEdit: () => void;
      onDirtyChange?: (dirtyCount: number) => void;
    };

type PeriodField = "company_id" | "year" | "week" | "week_start_date" | "week_end_date";

const cardClass = cn(cardSurface, "space-y-4 p-5");

export function ManualRecordForm(props: ManualRecordFormProps) {
  const router = useRouter();
  const queryClient = useQueryClient();
  const companiesQuery = useCompanies({ per_page: 100 });
  const createMutation = useCreateRecord();
  const updateMutation = useUpdateRecord();
  const editRecord = props.mode === "edit" ? props.record : null;
  const isEdit = editRecord !== null;
  const mutation = isEdit ? updateMutation : createMutation;

  const form = useForm<ManualRecordFormValues>({
    resolver: zodResolver(manualRecordFormSchema),
    defaultValues: initialManualRecordValues(editRecord ?? undefined),
  });
  const movements = useFieldArray({ control: form.control, name: "details" });
  const [commissionModes, setCommissionModes] = useState<CommissionMode[]>(() =>
    editRecord ? commissionModesFor(editRecord) : ["percentage"],
  );
  const [serverDetailErrors, setServerDetailErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [createdRecord, setCreatedRecord] = useState<RecordDetailView | null>(null);
  const [editReason, setEditReason] = useState("");

  const isSubmitting = form.formState.isSubmitting || mutation.isPending;
  const weekEndDate = useWatch({ control: form.control, name: "week_end_date" });
  const reportMonth = getReportMonthName(weekEndDate || null);

  const initialEditValues = useMemo(() => (editRecord ? initialManualRecordValues(editRecord) : null), [editRecord]);
  const currentValues = useWatch({ control: form.control });
  const dirtyCount = initialEditValues ? getDirtyCount(initialEditValues, currentValues) : 0;
  const saveBar = getSaveBarState({ dirtyCount, reason: editReason, canEdit: true });
  const onDirtyChange = props.mode === "edit" ? props.onDirtyChange : undefined;

  // The company select is filled once the catalog has loaded the record's company.
  const recordCompanyId = editRecord?.company?.id ?? null;
  useEffect(() => {
    if (recordCompanyId !== null && companiesQuery.isSuccess) {
      form.setValue("company_id", recordCompanyId, { shouldValidate: true });
    }
  }, [companiesQuery.isSuccess, form, recordCompanyId]);

  useEffect(() => {
    onDirtyChange?.(dirtyCount);
  }, [dirtyCount, onDirtyChange]);

  function periodError(field: PeriodField) {
    return form.formState.errors[field]?.message;
  }

  function resetMessages() {
    setFormError(null);
    setServerDetailErrors({});
    form.clearErrors();
  }

  function discardChanges() {
    if (!initialEditValues || !editRecord) return;
    form.reset(initialEditValues);
    setCommissionModes(commissionModesFor(editRecord));
    setEditReason("");
    resetMessages();
  }

  function startNewRecord() {
    form.reset(initialManualRecordValues());
    movements.replace([emptyMovement()]);
    setCommissionModes(["percentage"]);
    setEditReason("");
    setFormError(null);
    setCreatedRecord(null);
    mutation.reset();
  }

  function addMovement() {
    movements.append(emptyMovement());
    setCommissionModes((current) => [...current, "percentage"]);
  }

  function removeMovement(index: number) {
    movements.remove(index);
    setCommissionModes((current) => current.filter((_, itemIndex) => itemIndex !== index));
  }

  function setMovementMode(index: number, mode: CommissionMode) {
    setCommissionModes((current) => current.map((item, itemIndex) => (itemIndex === index ? mode : item)));
  }

  function showSaveError(error: unknown) {
    if (!(error instanceof RecordsApiError)) {
      setFormError("No se pudo guardar el registro. Inténtalo nuevamente.");
      return;
    }

    // 409: the record changed in another session; reload it to show its current state.
    if (error.status === 409 && editRecord) {
      void queryClient.invalidateQueries({ queryKey: recordKeys.detail(editRecord.id) });
    }

    let mapped = false;
    const detailErrors: Record<string, string> = {};
    for (const [field, [message]] of Object.entries(error.validationErrors)) {
      if (!message || field === "reason") continue;

      if (field.startsWith("details.")) detailErrors[field] = message;
      else form.setError(field as FieldPath<ManualRecordFormValues>, { type: "server", message });
      mapped = true;
    }

    setServerDetailErrors(detailErrors);
    setFormError(mapped ? "Corrige los campos señalados por el servidor." : error.message);
  }

  async function onSubmit(values: ManualRecordFormValues) {
    resetMessages();
    mutation.reset();

    if (props.mode === "edit" && !editReason.trim()) {
      setFormError("El motivo de la edición es obligatorio.");
      return;
    }

    const payload = { ...values, details: toRecordDetailInputs(values.details, commissionModes) };

    try {
      if (props.mode === "edit") {
        await updateMutation.mutateAsync({ id: props.record.id, input: { ...payload, reason: editReason.trim() } });
        props.onEditComplete();
      } else {
        setCreatedRecord(await createMutation.mutateAsync(payload));
        props.onCreated?.();
      }
    } catch (error) {
      showSaveError(error);
    }
  }

  function cancel() {
    if (props.mode === "edit") props.onCancelEdit();
    else router.push("/records");
  }

  if (createdRecord !== null) {
    return <ManualRecordSummary onCreateAnother={startNewRecord} record={createdRecord} />;
  }

  const companies = companiesQuery.data?.data ?? [];

  return (
    <section aria-labelledby="manual-record-heading" className="space-y-6">
      <header className="space-y-1">
        <h2 className="text-2xl font-semibold tracking-tight" id="manual-record-heading">
          {isEdit ? "Editar registro" : "Captura manual"}
        </h2>
        <p className="text-sm text-muted-foreground">{formIntro(editRecord)}</p>
      </header>

      <div className="@container">
        <form
          className="grid gap-6 @[60rem]:grid-cols-[minmax(0,1fr)_340px] @[60rem]:items-start"
          noValidate
          onSubmit={form.handleSubmit(onSubmit)}
        >
          <div className="space-y-6">
            <fieldset className={cardClass} disabled={isSubmitting}>
              <legend className="sr-only">Empresa y periodo</legend>
              <SectionHeading subtitle="La semana se identifica por semana y año." title="Empresa y periodo" />
              <div className="grid grid-cols-[repeat(auto-fit,minmax(150px,1fr))] gap-4">
                <FormField error={periodError("company_id")} id="manual-record-company" label="Empresa">
                  <select
                    {...fieldErrorProps("manual-record-company", periodError("company_id"))}
                    className={fieldControl}
                    id="manual-record-company"
                    {...form.register("company_id", { valueAsNumber: true })}
                  >
                    <option value={0}>Selecciona…</option>
                    {companies.map((company) => (
                      <option key={company.id} value={company.id}>
                        {company.name}
                      </option>
                    ))}
                  </select>
                </FormField>
                <FormField error={periodError("year")} id="manual-record-year" label="Año">
                  <input
                    {...fieldErrorProps("manual-record-year", periodError("year"))}
                    className={fieldControl}
                    id="manual-record-year"
                    inputMode="numeric"
                    type="number"
                    {...form.register("year", { valueAsNumber: true })}
                  />
                </FormField>
                <FormField error={periodError("week")} id="manual-record-week" label="Semana (1–53)">
                  <input
                    {...fieldErrorProps("manual-record-week", periodError("week"))}
                    className={fieldControl}
                    id="manual-record-week"
                    inputMode="numeric"
                    max={53}
                    min={1}
                    placeholder="1–53"
                    type="number"
                    {...form.register("week", { valueAsNumber: true })}
                  />
                </FormField>
                <FormField error={periodError("week_start_date")} id="manual-record-start-date" label="Inicio">
                  <input
                    {...fieldErrorProps("manual-record-start-date", periodError("week_start_date"))}
                    className={fieldControl}
                    id="manual-record-start-date"
                    type="date"
                    {...form.register("week_start_date")}
                  />
                </FormField>
                <FormField error={periodError("week_end_date")} id="manual-record-end-date" label="Fin">
                  <input
                    {...fieldErrorProps("manual-record-end-date", periodError("week_end_date"))}
                    className={fieldControl}
                    id="manual-record-end-date"
                    type="date"
                    {...form.register("week_end_date")}
                  />
                </FormField>
              </div>
              <p className="text-xs/4 text-muted-foreground">
                {reportMonth !== null && (
                  <>
                    Mes del reporte: <strong>{reportMonth}</strong>.{" "}
                  </>
                )}
                Cuando una semana cruza dos meses se usa el mes de la fecha de fin.
              </p>
            </fieldset>

            <section aria-labelledby="movements-heading" className={cn(cardClass, "@container")}>
              <SectionHeading
                id="movements-heading"
                subtitle="Captura el ingreso y el porcentaje; la comisión y el retorno los calcula el servidor."
                title="Movimientos"
              />

              {movements.fields.map((field, index) => (
                <MovementFieldset
                  canRemove={movements.fields.length > 1}
                  disabled={isSubmitting}
                  form={form}
                  index={index}
                  key={field.id}
                  mode={commissionModes[index] ?? "percentage"}
                  onModeChange={(mode) => setMovementMode(index, mode)}
                  onRemove={() => removeMovement(index)}
                  serverDetail={editRecord?.details[index]}
                  serverErrors={serverDetailErrors}
                />
              ))}

              <div className="flex justify-start">
                <Button disabled={isSubmitting} onClick={addMovement} type="button" variant="outline">
                  Agregar movimiento
                </Button>
              </div>
              {form.formState.errors.details?.message && (
                <p className="text-sm text-destructive" role="alert">
                  {form.formState.errors.details.message}
                </p>
              )}
            </section>

            {formError && (
              <p className="rounded-md border border-destructive/40 px-4 py-3 text-sm text-destructive" role="alert">
                {formError}
              </p>
            )}
            {companiesQuery.isError && (
              <p className="text-sm text-destructive" role="alert">
                No se pudieron cargar las empresas. Recarga e inténtalo nuevamente.
              </p>
            )}
            {companiesQuery.isSuccess && companies.length === 0 && (
              <p className="text-sm" role="status">
                Primero debe existir al menos una empresa en el catálogo.
              </p>
            )}

            <div className="flex flex-wrap justify-end gap-3">
              <Button disabled={isSubmitting} onClick={cancel} type="button" variant="outline">
                Cancelar
              </Button>
              {!isEdit && (
                <Button disabled={isSubmitting || companiesQuery.isPending} type="submit">
                  {isSubmitting ? "Guardando…" : "Guardar y confirmar"}
                </Button>
              )}
            </div>

            {isEdit && saveBar.visible && (
              <CorrectionSaveBar
                canSave={saveBar.canSave}
                dirtyCount={dirtyCount}
                isSubmitting={isSubmitting}
                onDiscard={discardChanges}
                onReasonChange={setEditReason}
                reason={editReason}
              />
            )}
          </div>

          <aside aria-labelledby="server-validates-heading" className={cn(cardClass, "@[60rem]:sticky @[60rem]:top-4")}>
            <h2 className="text-base/6 font-semibold" id="server-validates-heading">
              Qué valida el servidor
            </h2>
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

function formIntro(record: RecordDetailView | null): string {
  if (record === null) return "Captura los datos tú mismo. Al guardar, el registro queda confirmado.";

  return record.status === "CONFIRMED"
    ? "Este registro está confirmado. Al guardar los cambios se reabrirá para revisión, deberás capturar un motivo y confirmarlo nuevamente. Los importes oficiales los calcula el servidor."
    : "Corrige los datos y guarda con un motivo. Los importes oficiales los calcula el servidor y el estado no cambia.";
}

function SectionHeading({ id, title, subtitle }: { id?: string; title: string; subtitle: string }) {
  return (
    <div className="space-y-1">
      <h2 className="text-base/6 font-semibold" id={id}>
        {title}
      </h2>
      <p className="text-xs/4 text-muted-foreground">{subtitle}</p>
    </div>
  );
}
