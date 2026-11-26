"use client";

import { ChevronLeft, Image as ImageIcon, Pencil, TriangleAlert } from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { StatusBadge } from "@/components/status-badge";
import { LoadError } from "@/components/load-error";
import { Skeleton } from "@/components/ui/skeleton";
import { formatMoney } from "@/lib/format";
import { cardSurface } from "@/lib/styles";
import { cn } from "@/lib/utils";
import { useRecord } from "@/features/records/hooks/use-records";
import { getRecordPeriodLine } from "@/features/records/utils/detail";
import type { RecordListItem } from "@/features/records/types";
import { useDelivery } from "../hooks/use-deliveries";
import type { Delivery } from "../types";
import { getDeliveryHeading } from "../utils";
import { DeliveryForm } from "./delivery-form";
import { DeliveryReadOnly } from "./delivery-read-only";

export function DeliveryScreen({
  canEdit,
  recordId,
  userName,
}: {
  canEdit: boolean;
  recordId: number;
  /** Prefills «Entrega» when the record has no delivery yet. */
  userName: string;
}) {
  const recordQuery = useRecord(recordId);
  const deliveryQuery = useDelivery(recordId);
  const record = recordQuery.data;
  const delivery = deliveryQuery.data ?? null;
  // The form falls back to the first option derived from the cash to deliver.
  const [selectedDocumentReturnAmount, setSelectedDocumentReturnAmount] = useState<string>("");
  const [customerWillGiveChange, setCustomerWillGiveChange] = useState(false);
  // The official return is calculated and stored by Laravel on the record (SPEC 27).
  const currentReturnAmount = record?.return_total ?? "0.00";


  if (recordQuery.isPending) {
    return <DeliverySkeleton label="Cargando registro…" />;
  }

  if (recordQuery.isError || !record) {
    return (
      <LoadError
        back={{ href: "/records", label: "Volver a registros" }}
        message={recordQuery.error?.message ?? "El registro no está disponible."}
        onRetry={() => void recordQuery.refetch()}
        title="No se pudo cargar el registro"
      />
    );
  }

  if (deliveryQuery.isPending) {
    return <DeliverySkeleton label="Cargando preparación de entrega…" />;
  }

  if (deliveryQuery.isError) {
    return (
      <LoadError
        back={{ href: `/records/${record.id}`, label: "Volver al registro" }}
        message={deliveryQuery.error?.message ?? "La entrega no está disponible."}
        onRetry={() => void deliveryQuery.refetch()}
        title="No se pudo cargar la preparación de entrega"
      />
    );
  }

  const canPrepare = canEdit && record.status === "CONFIRMED";

  function handleInvalidRecordState() {
    void recordQuery.refetch();
    void deliveryQuery.refetch();
  }

  return (
    <section aria-labelledby="delivery-heading" className="space-y-6">
      <DeliveryHeader delivery={delivery} record={record} />

      {!canPrepare && (
        <p className="rounded-md bg-accent px-4 py-3 text-sm font-medium text-accent-foreground" role="note">
          {canEdit
            ? "El registro no está confirmado. La entrega podrá actualizarse cuando vuelva a estar confirmado."
            : "Tienes acceso de solo lectura. Solo un Editor puede preparar o actualizar la entrega."}
        </p>
      )}

      {delivery?.is_stale && <StaleDeliveryBanner delivery={delivery} />}

      {!canPrepare && delivery !== null && (
        <DeliveryReadOnly currentReturnAmount={currentReturnAmount} delivery={delivery} record={record} />
      )}

      {!canPrepare && delivery === null && (
        <p className="rounded-lg border bg-card px-5 py-4 text-sm text-muted-foreground shadow-card">
          {record.status === "CONFIRMED"
            ? "Aún no existe una preparación de entrega para este registro."
            : "La entrega solo puede prepararse después de confirmar el registro."}
        </p>
      )}

      {canPrepare && (
        <DeliveryForm
          key={`${delivery?.id ?? "new"}:${delivery?.updated_at ?? "initial"}`}
          currentReturnAmount={currentReturnAmount}
          customerWillGiveChange={customerWillGiveChange}
          delivery={delivery}
          documentReturnAmount={selectedDocumentReturnAmount}
          onCustomerWillGiveChangeChange={setCustomerWillGiveChange}
          onDocumentReturnAmountChange={setSelectedDocumentReturnAmount}
          onInvalidRecordState={handleInvalidRecordState}
          record={record}
          userName={userName}
        />
      )}
    </section>
  );
}

function DeliveryHeader({ delivery, record }: { delivery: Delivery | null; record: RecordListItem }) {
  const SourceIcon = record.source === "IMAGE" ? ImageIcon : Pencil;

  return (
    <header className="min-w-0 space-y-2">
      <Link
        className="inline-flex min-h-11 items-center gap-1 rounded-md text-sm font-medium text-accent-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background md:min-h-8"
        href={`/records/${record.id}`}
      >
        <ChevronLeft aria-hidden="true" className="size-4" />
        Registro
      </Link>
      <h1 className="text-2xl/8 font-bold tracking-[-0.01em] md:text-[28px]/9" id="delivery-heading">
        {getDeliveryHeading(delivery).title}
      </h1>
      <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
        <StatusBadge status={record.status} />
        <span className="inline-flex items-center gap-1.5 rounded-full border bg-card px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
          <SourceIcon aria-hidden="true" className="size-3.5" />
          {record.source === "IMAGE" ? "Imagen" : "Manual"}
        </span>
        <span className="text-sm tabular-nums text-muted-foreground">{getRecordPeriodLine(record)}</span>
      </div>
    </header>
  );
}

function StaleDeliveryBanner({ delivery }: { delivery: Delivery }) {
  return (
    <div
      className="flex items-start gap-3 rounded-md bg-status-needs-review-bg px-4 py-3.5 text-[13px]/5 font-medium text-status-needs-review"
      role="status"
    >
      <TriangleAlert aria-hidden="true" className="mt-px size-5 shrink-0" />
      <div>
        <strong className="block font-bold">La entrega se preparó con un monto anterior.</strong>
        <dl className="my-2 grid grid-cols-2 gap-x-4 gap-y-2">
          <div>
            <dt className="text-xs/4 font-medium">Retorno actual</dt>
            <dd className="text-sm/5 font-bold tabular-nums">{formatMoney(delivery.current_return_amount)}</dd>
          </div>
          <div>
            <dt className="text-xs/4 font-medium">Entrega preparada</dt>
            <dd className="text-sm/5 font-bold tabular-nums">{formatMoney(delivery.prepared_return_amount)}</dd>
          </div>
        </dl>
        <span>Ajusta el efectivo al retorno actual y guarda: se actualiza la misma entrega.</span>
      </div>
    </div>
  );
}

/** Loading placeholder for the delivery screen. It never paints an amount. */
function DeliverySkeleton({ label }: { label: string }) {
  return (
    <div className="flex flex-col gap-6">
      <p aria-live="polite" className="sr-only">{label}</p>
      <div aria-hidden="true" className="flex flex-col gap-6">
        <div className="space-y-3">
          <Skeleton className="h-5 w-24" />
          <Skeleton className="h-8 w-64 max-w-full" />
          <Skeleton className="h-6 w-80 max-w-full" />
        </div>
        <Skeleton className="h-44 w-full rounded-xl" />
        <div className={cn(cardSurface, "space-y-3 p-5")}>
          <Skeleton className="h-5 w-40" />
          <Skeleton className="h-10 w-full" />
          <Skeleton className="h-10 w-2/3" />
        </div>
        <div className={cn(cardSurface, "space-y-3 p-5")}>
          <Skeleton className="h-5 w-32" />
          <Skeleton className="h-12 w-full" />
          <Skeleton className="h-12 w-full" />
          <Skeleton className="h-12 w-3/4" />
        </div>
      </div>
    </div>
  );
}
