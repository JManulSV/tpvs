"use client";

import { useState } from "react";
import { useRecord } from "../hooks/use-records";
import { ImageCapturePanel } from "./image-capture-panel";
import { ImageReadingStatus } from "./image-reading-status";
import { RecordEditor } from "./record-editor";

// Alta desde imagen: subir a la izquierda y, tras el 202, leer y revisar lado a lado.
export function ImageCaptureWorkspace() {
  const [recordId, setRecordId] = useState<number | null>(null);
  const [panelKey, setPanelKey] = useState(0);

  function startNew() {
    setRecordId(null);
    setPanelKey((key) => key + 1);
  }

  return (
    <div className="@container">
      <div className="grid gap-6 @[60rem]:grid-cols-2 @[60rem]:items-start">
        <div>
          {recordId === null ? (
            <ImageCapturePanel key={panelKey} onUploaded={setRecordId} />
          ) : (
            <ImageReadingStatus onStartNew={startNew} recordId={recordId} />
          )}
        </div>
        <div>
          {recordId === null ? <EmptyReview /> : <ReviewColumn recordId={recordId} />}
        </div>
      </div>
    </div>
  );
}

function EmptyReview() {
  return (
    <section aria-labelledby="extracted-data-heading" className="space-y-2 rounded-lg border bg-card p-5 shadow-card">
      <h2 className="text-base/6 font-semibold" id="extracted-data-heading">Datos extraídos</h2>
      <p className="text-sm text-muted-foreground">Sube una imagen para ver aquí los datos.</p>
    </section>
  );
}

function ReviewColumn({ recordId }: { recordId: number }) {
  const recordQuery = useRecord(recordId);
  const record = recordQuery.data;

  if (record?.status === "NEEDS_REVIEW") {
    return <RecordEditor canEdit record={record} />;
  }

  return (
    <section aria-labelledby="extracted-data-heading" className="space-y-2 rounded-lg border bg-card p-5 shadow-card">
      <h2 className="text-base/6 font-semibold" id="extracted-data-heading">Datos extraídos</h2>
      <p className="text-sm text-muted-foreground">Los datos aparecen cuando la IA termina de leer la imagen.</p>
    </section>
  );
}
