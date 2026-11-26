import { z } from "zod";

const decimalAmountSchema = z
  .string()
  .regex(/^\d{1,10}(?:\.\d{1,2})?$/, "Ingresa un importe válido con hasta dos decimales.");

const percentageSchema = z
  .string()
  .regex(/^(?:\d|\d{2}|100)(?:\.\d{1,2})?$/, "Ingresa un porcentaje entre 0 y 100.")
  .refine((value) => Number(value) <= 100, "El porcentaje no puede superar 100.");

const recordDetailFormSchema = z.object({
  affiliation: z.string().trim().min(1, "La afiliación es obligatoria.").max(255),
  bank: z.string().trim().min(1, "El banco es obligatorio.").max(255),
  income: decimalAmountSchema,
});

const manualRecordFormBaseSchema = z.object({
  company_id: z.number().int().positive("Selecciona una empresa."),
  year: z.number().int().min(1).max(9999),
  week: z.number().int().min(1).max(53),
  week_start_date: z.string().date("Ingresa una fecha de inicio válida."),
  week_end_date: z.string().date("Ingresa una fecha de fin válida."),
  // One global percentage per record; Laravel calculates the commission and return (SPEC 27).
  commission_percentage: percentageSchema,
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
