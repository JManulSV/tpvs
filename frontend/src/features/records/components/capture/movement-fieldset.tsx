"use client";

import type { FieldErrors, UseFormReturn } from "react-hook-form";
import { FormField, fieldErrorProps } from "@/components/form-field";
import { Button } from "@/components/ui/button";
import { fieldControl } from "@/lib/styles";
import type { ManualRecordFormValues } from "../../schemas/record.schema";

type MovementField = "affiliation" | "bank" | "income";

type MovementFieldsetProps = {
  index: number;
  form: UseFormReturn<ManualRecordFormValues>;
  onRemove: () => void;
  canRemove: boolean;
  disabled: boolean;
  /** `422` messages keyed by `details.{index}.{field}`; they win over client validation. */
  serverErrors: Record<string, string>;
};

/** One movement: affiliation, bank and income. The commission is global to the record (SPEC 27). */
export function MovementFieldset({ index, form, onRemove, canRemove, disabled, serverErrors }: MovementFieldsetProps) {
  const clientErrors: FieldErrors<ManualRecordFormValues["details"][number]> | undefined =
    form.formState.errors.details?.[index];
  const errorOf = (field: MovementField) =>
    serverErrors[`details.${index}.${field}`] ?? clientErrors?.[field]?.message;
  const idOf = (field: MovementField) => `movement-${index}-${field}`;

  return (
    <fieldset className="space-y-4 rounded-md border p-4" disabled={disabled}>
      <legend className="px-2 font-semibold">Movimiento {index + 1}</legend>
      <div className="grid grid-cols-1 gap-4 @[45rem]:grid-cols-3">
        <FormField error={errorOf("affiliation")} id={idOf("affiliation")} label="Afiliación">
          <input
            {...fieldErrorProps(idOf("affiliation"), errorOf("affiliation"))}
            className={fieldControl}
            id={idOf("affiliation")}
            maxLength={255}
            {...form.register(`details.${index}.affiliation`)}
          />
        </FormField>
        <FormField error={errorOf("bank")} id={idOf("bank")} label="Banco">
          <input
            {...fieldErrorProps(idOf("bank"), errorOf("bank"))}
            className={fieldControl}
            id={idOf("bank")}
            maxLength={255}
            {...form.register(`details.${index}.bank`)}
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
      </div>
      <div className="flex justify-end">
        <Button className="text-status-error" disabled={disabled || !canRemove} onClick={onRemove} type="button" variant="outline">
          Quitar movimiento
        </Button>
      </div>
    </fieldset>
  );
}
