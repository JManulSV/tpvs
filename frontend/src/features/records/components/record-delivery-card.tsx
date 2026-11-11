import { CircleCheck, Lock } from "lucide-react";
import Link from "next/link";
import type { ReactNode } from "react";
import { Button, buttonVariants } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { useDelivery } from "@/features/deliveries/hooks/use-deliveries";
import type { Delivery } from "@/features/deliveries/types";
import { centsToAmount, denominationsTotalCents } from "@/features/deliveries/utils";
import { formatDate, formatMoney } from "@/lib/format";
import { getDeliveryCardState, type DeliveryCardState } from "../record-detail-utils";
import type { RecordListItem } from "../types";

const chipClass: Record<DeliveryCardState["tone"], string> = {
  ready: "bg-status-confirmed-bg text-status-confirmed",
  stale: "bg-status-needs-review-bg text-status-needs-review",
  none: "bg-status-pending-bg text-status-pending",
};

const labelClass = "text-xs/4 font-semibold text-muted-foreground";

function CardShell({ children, state }: { children: ReactNode; state: DeliveryCardState }) {
  return (
    <section
      aria-labelledby="record-delivery-heading"
      className="space-y-4 rounded-lg border bg-card p-5 shadow-card"
    >
      <div className="flex items-center justify-between gap-3">
        <h2 className="text-base/6 font-semibold" id="record-delivery-heading">Entrega</h2>
        <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${chipClass[state.tone]}`}>{state.chip}</span>
      </div>
      {children}
    </section>
  );
}

function DeliveryAction({ label, recordId }: { label: string | null; recordId: number }) {
  if (label === null) return null;

  return (
    <Link
      className={buttonVariants({ className: "h-10 w-full" })}
      href={`/records/${recordId}/delivery`}
    >
      {label}
    </Link>
  );
}

function DeliveryField({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className={labelClass}>{label}</dt>
      <dd className="text-[13px]/5 font-medium tabular-nums break-words">{value}</dd>
    </div>
  );
}

function DeliveryDetails({ delivery, hasImage }: { delivery: Delivery; hasImage: boolean }) {
  const cashTotal = centsToAmount(denominationsTotalCents(delivery.denominations));

  return (
    <>
      {delivery.is_stale && (
        <div
          className="space-y-2 rounded-lg bg-status-needs-review-bg px-4 py-3 text-[13px]/5 text-status-needs-review"
          role="alert"
        >
          <p className="font-bold">La entrega se preparó con un monto anterior.</p>
          <dl className="grid grid-cols-2 gap-3">
            <DeliveryField label="Retorno actual" value={formatMoney(delivery.current_return_amount)} />
            <DeliveryField label="Entrega preparada" value={formatMoney(delivery.prepared_return_amount)} />
          </dl>
        </div>
      )}

      <dl className="grid grid-cols-2 gap-x-4 gap-y-3">
        <DeliveryField label="Fecha de entrega" value={formatDate(delivery.delivery_date)} />
        <DeliveryField label="Monto preparado" value={formatMoney(delivery.prepared_return_amount)} />
        <DeliveryField label="Entrega" value={delivery.delivered_by_name} />
        <DeliveryField label="Recibe" value={delivery.received_by_name ?? "—"} />
      </dl>

      <table className="w-full text-[13px]/5">
        <caption className="sr-only">Desglose de efectivo</caption>
        <thead>
          <tr className="border-b text-left">
            <th className={`${labelClass} pb-2`} scope="col">Denominación</th>
            <th className={`${labelClass} pb-2 text-right`} scope="col">Cant.</th>
            <th className={`${labelClass} pb-2 text-right`} scope="col">Total</th>
          </tr>
        </thead>
        <tbody>
          {delivery.denominations.map((denomination) => (
            <tr className="border-b last:border-0" key={denomination.value}>
              <th className="py-1.5 text-left font-medium tabular-nums" scope="row">{formatMoney(denomination.value)}</th>
              <td className="py-1.5 text-right tabular-nums">{denomination.quantity}</td>
              <td className="py-1.5 text-right tabular-nums">
                {formatMoney(centsToAmount(denominationsTotalCents([denomination])))}
              </td>
            </tr>
          ))}
        </tbody>
        <tfoot>
          <tr className="border-t font-bold">
            <th className="pt-2 text-left" colSpan={2} scope="row">Efectivo total</th>
            <td className="pt-2 text-right tabular-nums">{formatMoney(cashTotal)}</td>
          </tr>
        </tfoot>
      </table>

      {hasImage ? (
        <p className="flex items-center gap-2 text-[13px]/5 font-medium text-status-confirmed">
          <CircleCheck aria-hidden="true" className="size-4 shrink-0" />
          Imagen asociada: lista para generar el recibo
        </p>
      ) : (
        <p className="text-[13px]/5 text-muted-foreground">Falta una imagen asociada para generar el recibo.</p>
      )}
    </>
  );
}

function ConfirmedDeliveryCard({ canEdit, record }: { canEdit: boolean; record: RecordListItem }) {
  const deliveryQuery = useDelivery(record.id);
  const delivery = deliveryQuery.data ?? null;
  const state = getDeliveryCardState(record.status, delivery, canEdit);

  if (deliveryQuery.isPending) {
    return (
      <CardShell state={{ chip: "Cargando…", tone: "none", actionLabel: null }}>
        <div aria-hidden="true" className="space-y-2">
          <Skeleton className="h-4 w-full" />
          <Skeleton className="h-4 w-3/4" />
          <Skeleton className="h-4 w-1/2" />
        </div>
        <span className="sr-only" role="status">Cargando entrega…</span>
      </CardShell>
    );
  }

  if (deliveryQuery.isError && deliveryQuery.data === undefined) {
    return (
      <CardShell state={{ chip: "Sin datos", tone: "none", actionLabel: null }}>
        <div className="space-y-3" role="alert">
          <p className="text-sm">{deliveryQuery.error.message}</p>
          <Button onClick={() => void deliveryQuery.refetch()} type="button" variant="outline">Reintentar</Button>
        </div>
      </CardShell>
    );
  }

  return (
    <CardShell state={state}>
      {delivery === null ? (
        <p className="text-sm text-muted-foreground">Todavía no hay una entrega preparada para este registro.</p>
      ) : (
        <DeliveryDetails delivery={delivery} hasImage={record.image !== null} />
      )}
      <DeliveryAction label={state.actionLabel} recordId={record.id} />
    </CardShell>
  );
}

export function RecordDeliveryCard({ canEdit, record }: { canEdit: boolean; record: RecordListItem }) {
  if (record.status !== "CONFIRMED") {
    return (
      <CardShell state={getDeliveryCardState(record.status, null, canEdit)}>
        <p className="flex items-start gap-2 text-sm text-muted-foreground">
          <Lock aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
          La entrega se prepara cuando el registro está confirmado.
        </p>
      </CardShell>
    );
  }

  return <ConfirmedDeliveryCard canEdit={canEdit} record={record} />;
}
