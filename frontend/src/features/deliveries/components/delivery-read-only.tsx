import type { RecordListItem } from "@/features/records/types";
import { formatDate, formatMoney } from "@/lib/format";
import type { Delivery } from "../types";
import { centsToAmount, denominationsTotalCents, toCents } from "../utils";
import { DeliveryImageCard } from "./delivery-image-card";

const cardClass = "rounded-lg border bg-card shadow-card";

export function DeliveryReadOnly({
  record,
  delivery,
  currentReturnAmount,
}: {
  record: RecordListItem;
  delivery: Delivery;
  currentReturnAmount: string;
}) {
  const rows = delivery.denominations
    .filter((denomination) => denomination.quantity > 0)
    .sort((a, b) => toCents(b.value) - toCents(a.value));
  const pieces = rows.reduce((total, row) => total + row.quantity, 0);
  const cashAmount = centsToAmount(denominationsTotalCents(rows));

  return (
    <div className="@container">
      <div className="grid gap-6 @[60rem]:grid-cols-[minmax(0,1fr)_340px] @[60rem]:items-start">
        <div className="flex min-w-0 flex-col gap-6">
          <section
            aria-label="Montos de la entrega"
            className="grid gap-4 rounded-xl bg-hero px-5 py-6 text-on-hero md:grid-cols-[minmax(0,1fr)_auto] md:items-end md:gap-x-10 md:px-8 md:py-7"
          >
            <div>
              <span className="block text-[11px]/4 font-semibold tracking-[0.08em] text-on-hero/70 uppercase">Retorno oficial</span>
              <span className="mt-1 block text-[32px]/10 font-bold tracking-[-0.02em] tabular-nums md:text-[40px]/12">
                {formatMoney(currentReturnAmount)}
              </span>
            </div>
            <dl>
              <dt className="text-[11px]/4 font-semibold tracking-[0.08em] text-on-hero/70 uppercase">Efectivo</dt>
              <dd className="mt-1 text-xl/7 font-bold tabular-nums">{formatMoney(cashAmount)}</dd>
            </dl>
          </section>

          <section aria-labelledby="delivery-data-heading" className={cardClass}>
            <CardHeader id="delivery-data-heading" subtitle="Quién entrega el efectivo y quién lo recibe." title="Datos de la entrega" />
            <dl className="grid grid-cols-[repeat(auto-fit,minmax(180px,1fr))] gap-x-5 gap-y-4 p-4 md:px-6 md:pb-6">
              <DataItem label="Fecha de entrega" value={formatDate(delivery.delivery_date)} />
              <DataItem label="Entrega" value={delivery.delivered_by_name} />
              <DataItem label="Recibe" value={delivery.received_by_name ?? "—"} />
            </dl>
          </section>

          <section aria-labelledby="delivery-cash-heading" className={cardClass}>
            <CardHeader
              id="delivery-cash-heading"
              subtitle="Efectivo bruto. Solo se guardan las denominaciones con cantidad mayor a cero."
              title="Desglose de efectivo"
            />
            <div className="space-y-5 p-4 md:px-6 md:pb-6">
              {rows.length === 0 ? (
                <p className="text-sm text-muted-foreground">Sin efectivo desglosado.</p>
              ) : (
                <table className="w-full border-separate border-spacing-y-2 text-left">
                  <caption className="sr-only">Desglose de efectivo guardado</caption>
                  <thead className="text-[11px]/4 font-semibold tracking-[0.08em] text-muted-foreground uppercase">
                    <tr>
                      <th className="px-3 font-semibold" scope="col">Denominación</th>
                      <th className="px-3 text-right font-semibold" scope="col">Cantidad</th>
                      <th className="px-3 text-right font-semibold" scope="col">Subtotal</th>
                    </tr>
                  </thead>
                  <tbody className="tabular-nums">
                    {rows.map((row) => (
                      <tr className="bg-muted" key={row.value}>
                        <th className="rounded-l-md px-3 py-2.5 text-base/6 font-bold" scope="row">{formatMoney(row.value)}</th>
                        <td className="px-3 py-2.5 text-right text-sm/5 font-semibold">{row.quantity}</td>
                        <td className="rounded-r-md px-3 py-2.5 text-right text-sm/5 font-bold">
                          {formatMoney(centsToAmount(toCents(row.value) * row.quantity))}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}

              <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-md bg-hero px-4 py-3.5 text-on-hero md:px-5 md:py-4">
                <div>
                  <span className="block text-[11px]/4 font-semibold tracking-[0.08em] text-on-hero/70 uppercase">Efectivo total</span>
                  <span className="mt-0.5 block text-[13px]/5 font-medium text-on-hero/70 tabular-nums">
                    {pieces === 1 ? "1 pieza" : `${pieces} piezas`}
                  </span>
                </div>
                <span className="text-2xl/8 font-bold tracking-[-0.01em] tabular-nums md:text-[28px]/9">
                  {formatMoney(cashAmount)}
                </span>
              </div>
            </div>
          </section>
        </div>

        <div className="flex min-w-0 flex-col gap-6">
          <DeliveryImageCard canAttach={false} record={record} />
        </div>
      </div>
    </div>
  );
}

function CardHeader({ id, title, subtitle }: { id: string; title: string; subtitle: string }) {
  return (
    <header className="px-4 pt-4 md:px-6 md:pt-[18px]">
      <h2 className="text-base/6 font-semibold" id={id}>{title}</h2>
      <p className="mt-0.5 text-xs/4 font-medium text-muted-foreground">{subtitle}</p>
    </header>
  );
}

function DataItem({ label, value }: { label: string; value: string }) {
  return (
    <div className="min-w-0">
      <dt className="text-xs/4 font-semibold text-muted-foreground">{label}</dt>
      <dd className="mt-1 text-sm/5 font-semibold break-words">{value}</dd>
    </div>
  );
}
