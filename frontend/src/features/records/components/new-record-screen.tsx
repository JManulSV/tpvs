"use client";

import { useState } from "react";
import { Button } from "@/components/ui/button";
import { ImageRecordForm } from "./image-record-form";
import { ManualRecordForm } from "./manual-record-form";

type RecordOrigin = "manual" | "image";

export function NewRecordScreen() {
  const [origin, setOrigin] = useState<RecordOrigin | null>(null);

  if (origin !== null) {
    return (
      <div className="space-y-4">
        <Button onClick={() => setOrigin(null)} type="button" variant="outline">← Cambiar origen</Button>
        {origin === "manual" ? <ManualRecordForm mode="create" /> : <ImageRecordForm />}
      </div>
    );
  }

  return (
    <section aria-labelledby="new-record-heading" className="space-y-6">
      <header className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight" id="new-record-heading">Nuevo registro</h1>
        <p className="text-sm text-muted-foreground">Elige cómo vas a capturar la información del registro.</p>
      </header>

      <div className="grid gap-4 sm:grid-cols-2">
        <button
          className="space-y-2 rounded-md border p-5 text-left transition-colors hover:bg-muted/40 focus-visible:outline-2 focus-visible:outline-ring"
          onClick={() => setOrigin("manual")}
          type="button"
        >
          <span className="block text-lg font-semibold">Captura manual</span>
          <span className="block text-sm text-muted-foreground">
            Registra empresa, periodo y movimientos. El servidor calcula los importes y confirma el registro al guardarlo.
          </span>
        </button>

        <button
          className="space-y-2 rounded-md border p-5 text-left transition-colors hover:bg-muted/40 focus-visible:outline-2 focus-visible:outline-ring"
          onClick={() => setOrigin("image")}
          type="button"
        >
          <span className="block text-lg font-semibold">Cargar imagen</span>
          <span className="block text-sm text-muted-foreground">
            Sube un JPEG, PNG o WebP de hasta 10 MiB. La IA propondrá los datos para que los revises y corrijas.
          </span>
        </button>
      </div>
    </section>
  );
}
