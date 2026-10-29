import { z } from "zod";
import { officialDenominationValues } from "../types";

const quantitySchema = z
  .string()
  .trim()
  .regex(/^\d*$/, "La cantidad debe ser un número entero mayor o igual a 0.")
  .refine((value) => value === "" || Number(value) <= 999999, "La cantidad es demasiado grande.");

const denominationSchema = z.object({
  value: z.enum(officialDenominationValues),
  quantity: quantitySchema,
});

export const deliveryFormSchema = z.object({
  delivery_date: z
    .string()
    .min(1, "La fecha de entrega es obligatoria.")
    .date("Ingresa una fecha de entrega válida."),
  delivered_by_name: z
    .string()
    .trim()
    .min(1, "Indica quién realizó la entrega.")
    .max(255, "El nombre no puede superar los 255 caracteres."),
  received_by_name: z
    .string()
    .trim()
    .max(255, "El nombre no puede superar los 255 caracteres."),
  denominations: z
    .array(denominationSchema)
    .length(officialDenominationValues.length, "El desglose debe incluir las denominaciones oficiales."),
});

export type DeliveryFormValues = z.infer<typeof deliveryFormSchema>;
