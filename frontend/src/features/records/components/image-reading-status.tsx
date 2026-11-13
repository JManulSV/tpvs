"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Button, buttonVariants } from "@/components/ui/button";
import { useCancelRecord, useConfirmRecord, useRecord, useRetryAiProcessing } from "../hooks/use-records";
import { getAiReadingView } from "../record-capture-utils";
import { recordsService } from "../services/records.service";
import { RecordsApiError } from "../types";
import { RecordActionDialog } from "./record-action-dialog";

const zoomLevels = [0.75, 1, 1.5, 2, 3];

export function ImageReadingStatus({ onStartNew, recordId }: { onStartNew: () => void; recordId: number }) {
  const recordQuery = useRecord(recordId);
  const confirmRecord = useConfirmRecord();
  const cancelRecord = useCancelRecord();
  const retryAiProcessing = useRetryAiProcessing();

  // Reloj del cliente solo para el tiempo transcurrido; no se guarda en el navegador.
  const [startedAt] = useState(() => Date.now());
  const [now, setNow] = useState(() => Date.now());
  const [zoomIndex, setZoomIndex] = useState(1);
  const [isConfirmOpen, setIsConfirmOpen] = useState(false);
  const [confirmed, setConfirmed] = useState(false);
  const [isCancelOpen, setIsCancelOpen] = useState(false);
  const [cancelReason, setCancelReason] = useState("");
  const [actionError, setActionError] = useState<string | null>(null);

  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(timer);
  }, []);

  const record = recordQuery.data;

  if (confirmed) {
    return (
      <section aria-labelledby="image-reading-heading" className="space-y-4 rounded-lg border bg-card p-5 shadow-card">
        <h2 className="text-base/6 font-semibold" id="image-reading-heading">Registro confirmado</h2>
        <p className="text-sm text-muted-foreground">El registro ya cuenta en los totales.</p>
        <div className="flex flex-wrap gap-3">
          <Link className={buttonVariants({ variant: "outline" })} href={`/records/${recordId}`}>Ver registro</Link>
          <Button onClick={onStartNew} type="button">Subir otra imagen</Button>
        </div>
      </section>
    );
  }

  if (!record) {
    return <p className="text-sm text-muted-foreground" role="status">Cargando el estado de la lectura…</p>;
  }

  const view = getAiReadingView(record.status, now - startedAt);

  async function handleConfirm() {
    setActionError(null);
    confirmRecord.reset();
    try {
      await confirmRecord.mutateAsync(recordId);
      setIsConfirmOpen(false);
      setConfirmed(true);
    } catch (error) {
      setActionError(error instanceof RecordsApiError ? error.message : "No se pudo confirmar el registro.");
      if (error instanceof RecordsApiError && error.status === 409) await recordQuery.refetch();
    }
  }

  async function handleRetry() {
    setActionError(null);
    retryAiProcessing.reset();
    try {
      await retryAiProcessing.mutateAsync(recordId);
    } catch (error) {
      setActionError(error instanceof RecordsApiError ? error.message : "No se pudo reintentar la lectura.");
      if (error instanceof RecordsApiError && error.status === 409) await recordQuery.refetch();
    }
  }

  async function handleCancel() {
    const reason = cancelReason.trim();
    if (reason === "") {
      setActionError("El motivo de la cancelación es obligatorio.");
      return;
    }

    setActionError(null);
    cancelRecord.reset();
    try {
      await cancelRecord.mutateAsync({ id: recordId, input: { reason } });
      setIsCancelOpen(false);
      setCancelReason("");
    } catch (error) {
      setActionError(error instanceof RecordsApiError ? error.message : "No se pudo cancelar el registro.");
      if (error instanceof RecordsApiError && error.status === 409) await recordQuery.refetch();
    }
  }

  return (
    <section aria-labelledby="image-reading-heading" className="space-y-4 rounded-lg border bg-card p-5 shadow-card">
      <h2 className="text-base/6 font-semibold" id="image-reading-heading">Imagen del proveedor</h2>

      {view.step === "queued" && (
        <div aria-live="polite" className="flex items-center gap-3 text-sm" role="status">
          <span aria-hidden className="inline-block size-2.5 rounded-full bg-status-pending" />
          Imagen subida: el registro está en cola.
        </div>
      )}

      {view.step === "reading" && (
        <div aria-live="polite" className="flex items-center gap-3 text-sm" role="status">
          <span aria-hidden className="inline-block size-3 animate-spin rounded-full border-2 border-primary border-t-transparent" />
          La IA está leyendo la imagen. <span className="tabular-nums text-muted-foreground">{view.elapsedLabel}</span>
        </div>
      )}

      {view.slow && (
        <p className="rounded-lg bg-accent px-4 py-3 text-[13px]/5 text-accent-foreground" role="note">
          Tarda más de lo normal. Puedes salir: el registro sigue procesándose y aparece en Registros.
        </p>
      )}

      {(view.step === "queued" || view.step === "reading") && (
        <Link className={buttonVariants({ variant: "outline" })} href="/records">Seguir en segundo plano</Link>
      )}

      {view.step === "ready" && (
        <>
          <div className="flex items-center justify-end gap-2">
            <Button
              aria-label="Alejar"
              disabled={zoomIndex === 0}
              onClick={() => setZoomIndex((index) => Math.max(0, index - 1))}
              type="button"
              variant="outline"
            >
              −
            </Button>
            <span className="min-w-12 text-center text-sm tabular-nums">{Math.round(zoomLevels[zoomIndex] * 100)}%</span>
            <Button
              aria-label="Acercar"
              disabled={zoomIndex === zoomLevels.length - 1}
              onClick={() => setZoomIndex((index) => Math.min(zoomLevels.length - 1, index + 1))}
              type="button"
              variant="outline"
            >
              +
            </Button>
          </div>
          <div className="max-h-[70vh] overflow-auto rounded-md border">
            {/* eslint-disable-next-line @next/next/no-img-element -- imagen del registro con zoom */}
            <img
              alt={`Imagen del registro ${recordId}`}
              className="block h-auto max-w-none"
              src={recordsService.imageUrl(recordId)}
              style={{ width: `${zoomLevels[zoomIndex] * 100}%` }}
            />
          </div>
          <div className="flex flex-wrap justify-end gap-3">
            <Link className={buttonVariants({ variant: "outline" })} href={`/records/${recordId}`}>Editar registro</Link>
            <Button onClick={() => setIsConfirmOpen(true)} type="button">Confirmar registro</Button>
          </div>
        </>
      )}

      {view.step === "error" && (
        <>
          <div className="space-y-1 rounded-md bg-status-error-bg px-4 py-3 text-sm text-status-error" role="alert">
            <p className="font-semibold">No se pudo leer la imagen.</p>
            <p>El intento quedó registrado. Puedes reintentar la lectura o cancelar el registro.</p>
          </div>
          <div className="flex flex-wrap justify-end gap-3">
            <Button disabled={retryAiProcessing.isPending} onClick={() => void handleRetry()} type="button" variant="outline">
              Reintentar lectura
            </Button>
            <Button onClick={() => setIsCancelOpen(true)} type="button" variant="outline">Cancelar registro</Button>
          </div>

          {isCancelOpen && (
            <div aria-labelledby="cancel-panel-title" className="space-y-3 rounded-md border p-4" role="region">
              <h3 className="font-semibold" id="cancel-panel-title">Cancelar este registro</h3>
              <p className="text-sm text-muted-foreground">
                Un registro cancelado no cuenta en reportes ni totales. Esta acción queda en el historial.
              </p>
              <label className="block text-sm font-medium" htmlFor="cancel-reason">Motivo</label>
              <input
                className="block w-full rounded-md border bg-background px-3 py-2 text-sm"
                id="cancel-reason"
                onChange={(event) => setCancelReason(event.target.value)}
                required
                type="text"
                value={cancelReason}
              />
              <div className="flex flex-wrap justify-end gap-3">
                <Button onClick={() => setIsCancelOpen(false)} type="button" variant="outline">Volver</Button>
                <Button disabled={cancelRecord.isPending} onClick={() => void handleCancel()} type="button" variant="destructive">
                  Confirmar cancelación
                </Button>
              </div>
            </div>
          )}
        </>
      )}

      {view.step === "canceled" && (
        <div className="space-y-3" role="alert">
          <p className="rounded-md bg-status-canceled-bg px-4 py-3 text-sm text-status-canceled">El registro fue cancelado.</p>
          <Link className={buttonVariants({ variant: "outline" })} href="/records">Ver en registros</Link>
        </div>
      )}

      {actionError && <p className="text-sm text-destructive" role="alert">{actionError}</p>}

      {isConfirmOpen && (
        <RecordActionDialog
          confirmLabel="Confirmar registro"
          description="¿Confirmas que revisaste este registro y que los datos son correctos? La confirmación no modifica empresa, periodo ni movimientos."
          error={actionError}
          isPending={confirmRecord.isPending}
          onClose={() => setIsConfirmOpen(false)}
          onConfirm={() => void handleConfirm()}
          title="Confirmar registro"
        />
      )}
    </section>
  );
}
