"use client";

import type { FieldErrors, UseFormReturn } from "react-hook-form";
import { FormField, fieldErrorProps } from "@/components/form-field";
import { Button } from "@/components/ui/button";
import { formatMoney } from "@/lib/format";
import { fieldControl } from "@/lib/styles";
import type { ManualRecordFormValues } from "../../schemas/record.schema";
import type { RecordDetail } from "../../types";
import type { CommissionMode } from "../../utils/manual-form";

type MovementField = "affiliation" | "branch" | "income" | "commission_percentage" | "commission";

type MovementFieldsetProps = {
  index: number;
  form: UseFormReturn<ManualRecordFormValues>;
  mode: CommissionMode;
  onModeChange: (mode: CommissionMode) => void;
  onRemove: () => void;
  canRemove: boolean;
  disabled: boolean;
  /** `422` messages keyed by `details.{index}.{field}`; they win over client validation. */
  serverErrors: Record<string, string>;
  /** Amounts calculated by the server for this movement, when the record exists. */
  serverDetail?: RecordDetail;
};

export function MovementFieldset({
  index,
  form,
  mode,
  onModeChange,
  onRemove,
  canRemove,
  disabled,
  serverErrors,
  serverDetail,
}: MovementFieldsetProps) {
  const clientErrors: FieldErrors<ManualRecordFormValues["details"][number]> | undefined =
    form.formState.errors.details?.[index];
  const errorOf = (field: MovementField) =>
    serverErrors[`details.${index}.${field}`] ?? clientErrors?.[field]?.message;
  const idOf = (field: string) => `movement-${index}-${field}`;
  const commissionField = mode === "percentage" ? "commission_percentage" : "commission";

  function changeMode(next: CommissionMode) {
    onModeChange(next);
    form.setValue(`details.${index}.commission_percentage`, next === "percentage" ? "" : undefined, {
      shouldValidate: true,
    });
    form.setValue(`details.${index}.commission`, next === "fixed" ? "" : undefined, { shouldValidate: true });
  }

  return (
    <fieldset className="space-y-4 rounded-md border p-4" disabled={disabled}>
      <legend className="px-2 font-semibold">Movimiento {index + 1}</legend>
      <div className="grid grid-cols-1 gap-4 @[45rem]:grid-cols-2">
        <FormField error={errorOf("affiliation")} id={idOf("affiliation")} label="Afiliación">
          <input
            {...fieldErrorProps(idOf("affiliation"), errorOf("affiliation"))}
            className={fieldControl}
            id={idOf("affiliation")}
            maxLength={255}
            {...form.register(`details.${index}.affiliation`)}
          />
        </FormField>
        <FormField error={errorOf("branch")} id={idOf("branch")} label="Sucursal">
          <input
            {...fieldErrorProps(idOf("branch"), errorOf("branch"))}
            className={fieldControl}
            id={idOf("branch")}
            maxLength={255}
            {...form.register(`details.${index}.branch`)}
          />
        </FormField>
        <FormField error={errorOf("income")} id={idOf("income")} label="Ingreso">
          <input
            {...fieldErrorProps(idOf("income"), errorOf("income"))}
            className={fieldControl}
            id={idOf("income")}
            inputMode="decimal"
            {...form.register(`details.${index}.income`)}
          />
        </FormField>
        <FormField id={idOf("commission-type")} label="Tipo de comisión">
          <select
            className={fieldControl}
            id={idOf("commission-type")}
            onChange={(event) => changeMode(event.target.value as CommissionMode)}
            value={mode}
          >
            <option value="percentage">Porcentaje</option>
            <option value="fixed">Importe fijo</option>
          </select>
        </FormField>
        <FormField
          error={errorOf(commissionField)}
          id={idOf("commission")}
          label={mode === "percentage" ? "Porcentaje" : "Comisión fija"}
        >
          <input
            {...fieldErrorProps(idOf("commission"), errorOf(commissionField))}
            className={fieldControl}
            id={idOf("commission")}
            inputMode="decimal"
            key={commissionField}
            {...form.register(`details.${index}.${commissionField}`)}
          />
        </FormField>
      </div>
      <dl className="grid grid-cols-2 gap-4 rounded-md bg-muted/40 p-3 text-sm">
        <ServerAmount label="Comisión" value={serverDetail?.commission} />
        <ServerAmount label="Retorno" value={serverDetail?.return_amount} />
      </dl>
      <div className="flex justify-end">
        <Button className="text-status-error" disabled={disabled || !canRemove} onClick={onRemove} type="button" variant="outline">
          Quitar movimiento
        </Button>
      </div>
    </fieldset>
  );
}

function ServerAmount({ label, value }: { label: string; value: string | null | undefined }) {
  return (
    <div>
      <dt className="text-xs font-semibold text-muted-foreground">{label}</dt>
      <dd className="font-medium tabular-nums">{value === undefined ? "—" : formatMoney(value)}</dd>
    </div>
  );
}
