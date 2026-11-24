"use client";

import { ImageIcon, Upload } from "lucide-react";
import { useRef, useState } from "react";
import { Button } from "@/components/ui/button";
import { RecordImageCard } from "@/features/records/components/detail/record-image-card";
import { useAttachRecordImage } from "@/features/records/hooks/use-records";
import { validateImageFile } from "@/features/records/utils/capture";
import { RecordsApiError, type RecordListItem } from "@/features/records/types";

const acceptedImageTypes = "image/jpeg,image/png,image/webp";

export function DeliveryImageCard({ record, canAttach }: { record: RecordListItem; canAttach: boolean }) {
  return (
    <RecordImageCard
      emptyContent={(
        <div className="flex flex-col items-start gap-3 rounded-md border border-dashed border-input bg-muted p-5 text-sm text-muted-foreground">
          <ImageIcon aria-hidden="true" className="size-6" />
          <p>
            Este registro se capturó de forma manual y no tiene imagen. Puedes guardar la entrega, pero el Word requiere la imagen.
          </p>
          {canAttach && <AttachImageButton recordId={record.id} />}
        </div>
      )}
      record={record}
      title="Documento asociado"
    />
  );
}

function AttachImageButton({ recordId }: { recordId: number }) {
  const inputRef = useRef<HTMLInputElement>(null);
  const attach = useAttachRecordImage();
  const [error, setError] = useState<string | null>(null);

  function handleFile(file: File | undefined) {
    if (!file) return;

    const validationError = validateImageFile(file);
    if (validationError) {
      setError(validationError);
      return;
    }

    setError(null);
    attach.mutate(
      { id: recordId, file },
      {
        onError: (mutationError) => {
          if (mutationError instanceof RecordsApiError) {
            setError(mutationError.validationErrors.image?.[0] ?? mutationError.message);
            return;
          }

          setError("No se pudo subir la imagen. Inténtalo de nuevo.");
        },
      },
    );
  }

  return (
    <div className="flex flex-col items-start gap-2">
      <input
        accept={acceptedImageTypes}
        className="sr-only"
        onChange={(event) => {
          handleFile(event.target.files?.[0]);
          event.target.value = "";
        }}
        ref={inputRef}
        tabIndex={-1}
        type="file"
        aria-hidden="true"
      />
      <Button
        aria-describedby={error ? "attach-image-error" : undefined}
        className="h-11 px-4 md:h-9"
        disabled={attach.isPending}
        onClick={() => inputRef.current?.click()}
        type="button"
        variant="secondary"
      >
        <Upload aria-hidden="true" />
        {attach.isPending ? "Subiendo imagen…" : "Adjuntar imagen"}
      </Button>
      {error && (
        <p className="text-sm text-status-error" id="attach-image-error" role="alert">{error}</p>
      )}
    </div>
  );
}
