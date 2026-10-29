"use client";

import Link from "next/link";
import { Button } from "@/components/ui/button";
import { useRecord } from "@/features/records/hooks/use-records";
import type { RecordStatus } from "@/features/records/types";
import { useDelivery } from "../hooks/use-deliveries";
import type { Delivery } from "../types";
import { centsToAmount, formatMoney, denominationsTotalCents, toCents } from "../utils";
import { DeliveryForm } from "./delivery-form";

const statusLabels: Record<RecordStatus, string> = {
  PENDING: "Pendiente",
  PROCESSING: "Procesando",
  NEEDS_REVIEW: "Necesita revisión",
  ERROR: "Error",
  CONFIRMED: "Confirmado",
  CANCELED: "Cancelado",
};

export function DeliveryScreen({ canEdit, recordId }: { canEdit: boolean; recordId: number }) {
  const recordQuery = useRecord(recordId);
  const deliveryQuery = useDelivery(recordId);
  const record = recordQuery.data;
  const delivery = deliveryQuery.data ?? null;

  if (recordQuery.isPending) {
    return <p aria-live="polite" className="py-10 text-center text-sm text-muted-foreground">Cargando registro…</p>;
  }

  if (recordQuery.isError || !record) {
    return (
      <section className="space-y-4 rounded-md border p-6 text-center" role="alert">
        <h1 className="text-xl font-semibold">No se pudo cargar el registro</h1>
        <p className="text-sm text-muted-foreground">{recordQuery.error?.message ?? "El registro no está disponible."}</p>
        <div className="flex justify-center gap-3">
          <Button onClick={() => void recordQuery.refetch()} type="button" variant="outline">Reintentar</Button>
          <Link className="inline-flex h-8 items-center justify-center rounded-lg border px-3 text-sm font-medium hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring" href="/records">Volver a registros</Link>
        </div>
      </section>
    );
  }

  if (deliveryQuery.isPending) {
    return <p aria-live="polite" className="py-10 text-center text-sm text-muted-foreground">Cargando preparación de entrega…</p>;
  }

  if (deliveryQuery.isError) {
    return (
      <section className="space-y-4 rounded-md border p-6 text-center" role="alert">
        <h1 className="text-xl font-semibold">No se pudo cargar la preparación de entrega</h1>
        <p className="text-sm text-muted-foreground">{deliveryQuery.error?.message ?? "La entrega no está disponible."}</p>
        <div className="flex justify-center gap-3">
          <Button onClick={() => void deliveryQuery.refetch()} type="button" variant="outline">Reintentar</Button>
          <Link className="inline-flex h-8 items-center justify-center rounded-lg border px-3 text-sm font-medium hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring" href={`/records/${record.id}`}>Volver al registro</Link>
        </div>
      </section>
    );
  }

  const currentReturnAmount = record.return_total
    ?? centsToAmount(record.details.reduce((total, detail) => total + toCents(detail.return_amount), 0));
  const canPrepare = canEdit && record.status === "CONFIRMED";

  function handleInvalidRecordState() {
    void recordQuery.refetch();
    void deliveryQuery.refetch();
  }

  return (
    <section aria-labelledby="delivery-heading" className="space-y-6">
      <header className="space-y-2">
        <Link className="inline-flex h-8 items-center justify-center rounded-lg border px-3 text-sm font-medium hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring" href={`/records/${record.id}`}>← Registro</Link>
        <h1 className="text-2xl font-semibold tracking-tight" id="delivery-heading">Preparación de entrega</h1>
        <p className="text-sm text-muted-foreground">
          {record.company?.name ?? "Registro sin empresa"} · Semana {record.week ?? "—"}, {record.year ?? "—"} · {statusLabels[record.status]}
        </p>
      </header>

      <div className="grid gap-4 rounded-md border p-4 sm:grid-cols-2 lg:grid-cols-4">
        <Info label="Empresa" value={record.company?.name ?? "—"} />
        <Info label="Semana / año" value={`${record.week ?? "—"} / ${record.year ?? "—"}`} />
        <Info label="Retorno oficial actual" value={formatMoney(currentReturnAmount)} />
        <Info label="Imagen" value={record.image === null ? "Sin imagen" : "Disponible"} />
      </div>

      {delivery?.is_stale && (
        <p className="rounded-md border px-4 py-3 text-sm" role="note">
          La preparación está obsoleta: el importe preparado ya no coincide con el retorno actual del registro.
        </p>
      )}

      {delivery !== null && !canPrepare && (
        <DeliveryReadOnly delivery={delivery} />
      )}

      {delivery === null && !canPrepare && (
        <p className="rounded-md border px-4 py-3 text-sm text-muted-foreground" role="note">
          {record.status === "CONFIRMED"
            ? "Aún no existe una preparación de entrega para este registro."
            : "La entrega solo puede prepararse después de confirmar el registro."}
        </p>
      )}

      {canPrepare && (
        <DeliveryForm
          currentReturnAmount={currentReturnAmount}
          delivery={delivery}
          onInvalidRecordState={handleInvalidRecordState}
          recordId={record.id}
        />
      )}

      {delivery !== null && !canEdit && (
        <p className="rounded-md border px-4 py-3 text-sm text-muted-foreground" role="note">
          Tienes acceso de solo lectura. Solo un Editor puede preparar o actualizar la entrega.
        </p>
      )}

      {delivery !== null && canEdit && record.status !== "CONFIRMED" && (
        <p className="rounded-md border px-4 py-3 text-sm text-muted-foreground" role="note">
          El registro no está confirmado. La entrega podrá actualizarse cuando vuelva a estar confirmado.
        </p>
      )}

      <p className="rounded-md border px-4 py-3 text-sm text-muted-foreground" role="note">
        La generación del documento Word no forma parte de esta fase.
      </p>
    </section>
  );
}

function DeliveryReadOnly({ delivery }: { delivery: Delivery }) {
  const totalCents = denominationsTotalCents(delivery.denominations);

  return (
    <section aria-labelledby="delivery-readonly-heading" className="space-y-4 rounded-md border p-4">
      <h2 className="text-lg font-semibold" id="delivery-readonly-heading">Entrega preparada</h2>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <Info label="Fecha de entrega" value={delivery.delivery_date} />
        <Info label="Entrega realizada por" value={delivery.delivered_by_name} />
        <Info label="Recibido por" value={delivery.received_by_name ?? "—"} />
        <Info label="Importe preparado" value={formatMoney(delivery.prepared_return_amount)} />
        <Info label="Retorno actual" value={formatMoney(delivery.current_return_amount)} />
        <Info label="Total de efectivo" value={formatMoney(centsToAmount(totalCents))} />
      </div>

      {delivery.denominations.length === 0 ? (
        <p className="text-sm text-muted-foreground">Sin efectivo desglosado.</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[24rem] border-collapse text-left text-sm">
            <caption className="sr-only">Desglose de efectivo preparado</caption>
            <thead className="bg-muted/50">
              <tr>
                <th className="px-4 py-3 font-medium" scope="col">Denominación</th>
                <th className="px-4 py-3 font-medium" scope="col">Cantidad</th>
                <th className="px-4 py-3 text-right font-medium" scope="col">Subtotal</th>
              </tr>
            </thead>
            <tbody>
              {delivery.denominations.map((denomination) => (
                <tr className="border-t" key={denomination.value}>
                  <th className="px-4 py-2 font-normal" scope="row">{formatMoney(denomination.value)}</th>
                  <td className="px-4 py-2 tabular-nums">{denomination.quantity}</td>
                  <td className="px-4 py-2 text-right tabular-nums">
                    {formatMoney(centsToAmount(toCents(denomination.value) * denomination.quantity))}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}

function Info({ label, value }: { label: string; value: string }) {
  return (
    <div className="space-y-1">
      <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className="text-sm">{value}</p>
    </div>
  );
}
