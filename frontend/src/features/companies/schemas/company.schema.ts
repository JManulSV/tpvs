import { z } from "zod";

export const companyFormSchema = z.object({
  name: z
    .string()
    .trim()
    .min(1, "El nombre de la empresa es obligatorio.")
    .max(255, "El nombre no puede superar los 255 caracteres."),
});

export type CompanyFormValues = z.infer<typeof companyFormSchema>;
