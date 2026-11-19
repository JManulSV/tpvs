import { z } from "zod";

export const companyFormSchema = z.object({
  name: z
    .string()
    .trim()
    .min(1, "Escribe el nombre de la empresa.")
    .max(255, "El nombre no puede superar los 255 caracteres."),
});

export type CompanyFormValues = z.infer<typeof companyFormSchema>;
