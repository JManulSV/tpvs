"use client";

import { useRouter } from "next/navigation";
import { useMemo, useRef, useState, type DragEvent } from "react";
import { Button } from "@/components/ui/button";
import { formatFileSize } from "@/lib/format";
import { useCreateRecordFromImage } from "../hooks/use-records";
import { getUploadProgressLabel, validateImageFile } from "../record-capture-utils";
import { RecordsApiError, type ImageCaptureState } from "../types";

const networkErrorMessage = "No se pudo subir la imagen. Revisa tu conexión e inténtalo de nuevo.";

function getUploadErrorMessage(error: unknown): string {
  if (!(error instanceof RecordsApiError)) return "No se pudo subir la imagen. Inténtalo nuevamente.";
  if (error.status === null) return networkErrorMessage;

  return error.validationErrors.image?.[0] ?? error.message;
}

export function ImageCapturePanel() {
  const router = useRouter();
  const uploadRecord = useCreateRecordFromImage();
  const [state, setState] = useState<ImageCaptureState>({ kind: "empty" });
  const abortRef = useRef<AbortController | null>(null);

  const file = state.kind === "empty" ? null : state.file;
  const isUploading = state.kind === "uploading";

  // Una URL temporal por archivo; el navegador la libera al salir de la página.
  const previewUrl = useMemo(() => (file === null ? null : URL.createObjectURL(file)), [file]);

  function handleFile(nextFile: File | null) {
    if (nextFile === null) return;

    const message = validateImageFile(nextFile);
    if (message !== null) {
      setState({ kind: "error", stage: "validation", message, file: null });
      return;
    }

    setState({ kind: "selected", file: nextFile });
  }

  function handleInputChange(event: React.ChangeEvent<HTMLInputElement>) {
    handleFile(event.target.files?.[0] ?? null);
    event.target.value = "";
  }

  function handleDrop(event: DragEvent<HTMLLabelElement>) {
    event.preventDefault();
    handleFile(event.dataTransfer.files[0] ?? null);
  }

  async function handleUpload() {
    if (file === null) return;

    const controller = new AbortController();
    abortRef.current = controller;
    setState({ kind: "uploading", file, progress: null });

    try {
      const record = await uploadRecord.mutateAsync({
        file,
        signal: controller.signal,
        onProgress: (progress) => setState((current) => (current.kind === "uploading" ? { ...current, progress } : current)),
      });
      router.push(`/records/${record.id}`);
    } catch (error) {
      if (controller.signal.aborted) {
        setState({ kind: "selected", file });
      } else {
        setState({ kind: "error", stage: "upload", message: getUploadErrorMessage(error), file });
      }
    } finally {
      abortRef.current = null;
    }
  }

  function handleCancelUpload() {
    abortRef.current?.abort();
  }

  function handleRemove() {
    uploadRecord.reset();
    setState({ kind: "empty" });
  }

  return (
    <section aria-labelledby="image-capture-heading" className="space-y-4">
      <h2 className="text-lg font-semibold" id="image-capture-heading">Imagen del registro</h2>

      {file === null || (state.kind === "error" && state.stage === "validation") ? (
        <label
          className="block cursor-pointer space-y-2 rounded-md border border-dashed p-8 text-center transition-colors hover:bg-muted/40 focus-within:outline-2 focus-within:outline-ring"
          onDragOver={(event) => event.preventDefault()}
          onDrop={handleDrop}
        >
          <span className="block font-medium">Arrastra una imagen o elige un archivo</span>
          <span className="block text-sm text-muted-foreground">JPG, PNG o WebP, hasta 10 MiB</span>
          <input
            accept="image/jpeg,image/png,image/webp"
            className="sr-only"
            onChange={handleInputChange}
            type="file"
          />
          <span className="inline-block rounded-md border px-3 py-1.5 text-sm font-medium">Elegir archivo</span>
        </label>
      ) : (
        <div className="space-y-4 rounded-md border p-4">
          <div className="flex gap-4">
            {previewUrl !== null && (
              // eslint-disable-next-line @next/next/no-img-element -- miniatura local (blob URL)
              <img alt={`Vista previa de ${file.name}`} className="h-28 w-28 rounded-md border object-cover" src={previewUrl} />
            )}
            <div className="min-w-0 space-y-1">
              <p className="truncate font-medium">{file.name}</p>
              <p className="text-sm text-muted-foreground">{formatFileSize(file.size)}</p>
            </div>
          </div>

          {isUploading && (
            <div className="space-y-2">
              <div
                aria-label="Progreso de la subida"
                aria-valuemax={100}
                aria-valuemin={0}
                aria-valuenow={state.progress ?? undefined}
                className="h-2 w-full overflow-hidden rounded-full bg-muted"
                role="progressbar"
              >
                <div className="h-full bg-primary transition-all" style={{ width: `${state.progress ?? 0}%` }} />
              </div>
              <p className="text-sm text-muted-foreground" aria-live="polite">{getUploadProgressLabel(state.progress)}</p>
            </div>
          )}

          {state.kind === "error" && (
            <p className="text-sm text-destructive" role="alert">{state.message}</p>
          )}

          <div className="flex flex-wrap justify-end gap-3">
            {isUploading ? (
              <Button onClick={handleCancelUpload} type="button" variant="outline">Cancelar subida</Button>
            ) : (
              <>
                <Button onClick={handleRemove} type="button" variant="outline">Quitar imagen</Button>
                <Button onClick={handleUpload} type="button">Subir y procesar</Button>
              </>
            )}
          </div>
        </div>
      )}

      {state.kind === "error" && state.stage === "validation" && (
        <p className="text-sm text-destructive" role="alert">{state.message}</p>
      )}
    </section>
  );
}
