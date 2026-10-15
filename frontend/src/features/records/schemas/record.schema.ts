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

function isoWeekDates(year: number, week: number): { monday: string; sunday: string } | null {
  const fourthOfJanuary = new Date(Date.UTC(year, 0, 4));
  const mondayOfWeekOne = new Date(fourthOfJanuary);
  mondayOfWeekOne.setUTCDate(fourthOfJanuary.getUTCDate() - ((fourthOfJanuary.getUTCDay() + 6) % 7));

  const monday = new Date(mondayOfWeekOne);
  monday.setUTCDate(mondayOfWeekOne.getUTCDate() + (week - 1) * 7);

  const isoYear = new Date(monday);
  isoYear.setUTCDate(monday.getUTCDate() + 3);
  if (isoYear.getUTCFullYear() !== year) return null;

  const sunday = new Date(monday);
  sunday.setUTCDate(monday.getUTCDate() + 6);

  return {
    monday: monday.toISOString().slice(0, 10),
    sunday: sunday.toISOString().slice(0, 10),
  };
}

const manualRecordFormBaseSchema = z.object({
  company_id: z.number().int().positive("Selecciona una empresa."),
  year: z.number().int().min(1).max(9999),
  week: z.number().int().min(1).max(53),
  week_start_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
  week_end_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
  affiliation: z.string().trim().min(1, "La afiliación es obligatoria.").max(255),
  branch: z.string().trim().min(1, "La sucursal es obligatoria.").max(255),
  details: z.array(recordDetailFormSchema).min(1, "Agrega al menos un movimiento."),
});

function validateIsoDates(
  record: z.infer<typeof manualRecordFormBaseSchema>,
  context: z.RefinementCtx,
) {
  const dates = isoWeekDates(record.year, record.week);

  if (!dates) {
    context.addIssue({ code: "custom", message: "La semana no existe en el año ISO indicado.", path: ["week"] });
    return;
  }

  if (record.week_start_date !== dates.monday) {
    context.addIssue({ code: "custom", message: "La fecha inicial debe ser el lunes de la semana ISO.", path: ["week_start_date"] });
  }
  if (record.week_end_date !== dates.sunday) {
    context.addIssue({ code: "custom", message: "La fecha final debe ser el domingo de la semana ISO.", path: ["week_end_date"] });
  }
}

export const manualRecordFormSchema = manualRecordFormBaseSchema.superRefine(validateIsoDates);

export const updateManualRecordFormSchema = z.object({
  ...manualRecordFormBaseSchema.shape,
  reason: z.string().trim().min(1, "El motivo de la edición es obligatorio.").max(2000),
}).superRefine(validateIsoDates);

export type ManualRecordFormValues = z.infer<typeof manualRecordFormSchema>;
export type UpdateManualRecordFormValues = z.infer<typeof updateManualRecordFormSchema>;
