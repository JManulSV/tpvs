import { z } from "zod";

const decimalAmountSchema = z
  .string()
  .regex(/^\d{1,10}(?:\.\d{1,2})?$/, "Ingresa un importe válido con hasta dos decimales.");

const percentageSchema = z
  .string()
  .regex(/^(?:\d|\d{2}|100)(?:\.\d{1,2})?$/, "Ingresa un porcentaje entre 0 y 100.")
  .refine((value) => Number(value) <= 100, "El porcentaje no puede superar 100.");

const recordDetailFormSchema = z.object({
  operation_number: z.string().trim().min(1, "El número de operación es obligatorio.").max(255),
  affiliation: z.string().trim().min(1, "La afiliación es obligatoria.").max(255),
  branch: z.string().trim().min(1, "La sucursal es obligatoria.").max(255),
  income: decimalAmountSchema,
  commission_percentage: percentageSchema.optional(),
  commission: decimalAmountSchema.optional(),
}).superRefine((detail, context) => {
  const hasPercentage = detail.commission_percentage !== undefined;
  const hasFixedAmount = detail.commission !== undefined;

  if (hasPercentage === hasFixedAmount) {
    context.addIssue({
      code: "custom",
      message: "Indica la comisión como porcentaje o importe fijo, pero no ambos.",
      path: ["commission_percentage"],
    });
  }

  if (hasFixedAmount && toMinorUnits(detail.commission!) > toMinorUnits(detail.income)) {
    context.addIssue({
      code: "custom",
      message: "La comisión fija no puede superar el ingreso.",
      path: ["commission"],
    });
  }
});

function toMinorUnits(amount: string): number {
  const [whole, fraction = ""] = amount.split(".");
  return Number(whole) * 100 + Number(fraction.padEnd(2, "0"));
}

const manualRecordFormBaseSchema = z.object({
  company_id: z.number().int().positive("Selecciona una empresa."),
  year: z.number().int().min(1).max(9999),
  week: z.number().int().min(1).max(53),
  week_start_date: z.string().date("Ingresa una fecha de inicio válida."),
  week_end_date: z.string().date("Ingresa una fecha de fin válida."),
  details: z.array(recordDetailFormSchema).min(1, "Agrega al menos un movimiento."),
});

function validateBusinessDateRange(
  record: z.infer<typeof manualRecordFormBaseSchema>,
  context: z.RefinementCtx,
) {
  if (record.week_start_date > record.week_end_date) {
    context.addIssue({ code: "custom", message: "La fecha inicial no puede ser posterior a la fecha final.", path: ["week_end_date"] });
  }
}

export const manualRecordFormSchema = manualRecordFormBaseSchema.superRefine(validateBusinessDateRange);

export const updateManualRecordFormSchema = z.object({
  ...manualRecordFormBaseSchema.shape,
  reason: z.string().trim().min(1, "El motivo de la edición es obligatorio.").max(2000),
}).superRefine(validateBusinessDateRange);

export type ManualRecordFormValues = z.infer<typeof manualRecordFormSchema>;
export type UpdateManualRecordFormValues = z.infer<typeof updateManualRecordFormSchema>;
