"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { useCreateRecordFromImage } from "../hooks/use-records";
import { RecordsApiError } from "../types";

const allowedImageTypes = ["image/jpeg", "image/png", "image/webp"];
const maxImageBytes = 10 * 1024 * 1024;

function formatFileSize(bytes: number) {
  if (bytes < 1024) return `${bytes} B`;

  const kilobytes = bytes / 1024;
  if (kilobytes < 1024) return `${kilobytes.toFixed(1)} KB`;

  return `${(kilobytes / 1024).toFixed(1)} MB`;
}

export function ImageRecordForm() {
  const router = useRouter();
  const uploadRecord = useCreateRecordFromImage();
  const [file, setFile] = useState<File | null>(null);
  const [fileError, setFileError] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);

  function handleFileChange(event: React.ChangeEvent<HTMLInputElement>) {
    const nextFile = event.target.files?.[0] ?? null;
    setFormError(null);
    setFileError(null);
    uploadRecord.reset();

    if (nextFile === null) {
      setFile(null);
      return;
    }

    if (!allowedImageTypes.includes(nextFile.type)) {
      setFile(null);
      setFileError("Selecciona una imagen JPEG, PNG o WebP.");
      event.target.value = "";
      return;
    }

    if (nextFile.size > maxImageBytes) {
      setFile(null);
      setFileError("La imagen supera el límite de 10 MiB.");
      event.target.value = "";
      return;
    }

    setFile(nextFile);
  }

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setFormError(null);
    uploadRecord.reset();

    if (file === null) {
      setFileError("Selecciona una imagen para continuar.");
      return;
    }

    try {
      const record = await uploadRecord.mutateAsync(file);
      router.push(`/records/${record.id}`);
    } catch (error) {
      if (error instanceof RecordsApiError) {
        setFileError(error.validationErrors.image?.[0] ?? null);
        setFormError(error.validationErrors.image?.[0] ? null : error.message);
      } else {
        setFormError("No se pudo cargar la imagen. Inténtalo nuevamente.");
      }
    }
  }

  return (
    <section aria-labelledby="image-record-heading" className="space-y-6">
      <header className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight" id="image-record-heading">Nuevo registro desde imagen</h1>
        <p className="text-sm text-muted-foreground">
          Sube una imagen legible. La IA propondrá empresa, periodo y movimientos para que los revises antes de confirmar.
        </p>
      </header>

      <form className="space-y-6" noValidate onSubmit={handleSubmit}>
        <fieldset className="space-y-3 rounded-md border p-4" disabled={uploadRecord.isPending}>
          <legend className="px-2 font-medium">Imagen del registro</legend>
          <label className="block text-sm font-medium" htmlFor="record-image-upload">Archivo</label>
          <input
            accept={allowedImageTypes.join(",")}
            aria-describedby="record-image-help"
            aria-invalid={Boolean(fileError)}
            className="block w-full rounded-md border bg-background px-3 py-2 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-muted file:px-3 file:py-1.5 file:text-sm file:font-medium"
            id="record-image-upload"
            onChange={handleFileChange}
            type="file"
          />
          <p className="text-sm text-muted-foreground" id="record-image-help">JPEG, PNG o WebP de hasta 10 MiB.</p>
          {file && (
            <p className="rounded-md border px-4 py-3 text-sm" role="status">
              {file.name} · {formatFileSize(file.size)}
            </p>
          )}
          {fileError && <p className="text-sm text-destructive" role="alert">{fileError}</p>}
        </fieldset>

        <p className="rounded-md border px-4 py-3 text-sm text-muted-foreground" role="note">
          Al cargar la imagen se crea un registro provisional. El procesamiento puede tardar; la pantalla de detalle se actualizará automáticamente.
        </p>

        {formError && <p className="rounded-md border border-destructive/40 px-4 py-3 text-sm text-destructive" role="alert">{formError}</p>}

        <div className="flex flex-wrap justify-end gap-3">
          <Button disabled={uploadRecord.isPending} onClick={() => router.push("/records")} type="button" variant="outline">Cancelar</Button>
          <Button disabled={uploadRecord.isPending || file === null} type="submit">
            {uploadRecord.isPending ? "Subiendo…" : "Subir y procesar imagen"}
          </Button>
        </div>
      </form>
    </section>
  );
}
