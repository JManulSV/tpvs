import { formatMoney } from "@/lib/format";
import { getHeroNote } from "../record-detail-utils";
import type { RecordListItem } from "../types";

const overlineClass = "text-[11px]/4 font-semibold tracking-[0.08em] uppercase opacity-85";

export function RecordDetailHero({ record }: { record: RecordListItem }) {
  return (
    <section
      aria-label="Totales del registro"
      className="grid gap-6 rounded-xl bg-hero p-6 text-on-hero md:grid-cols-[minmax(0,1fr)_auto] md:items-end md:p-8"
    >
      <div className="space-y-1">
        <p className={overlineClass}>Retorno oficial</p>
        <p className="text-[32px]/10 font-bold tabular-nums md:text-[40px]/12">{formatMoney(record.return_total)}</p>
        <p className="text-[13px]/5 opacity-85">{getHeroNote(record.status)}</p>
      </div>
      <dl className="flex gap-6 md:gap-10 md:text-right">
        <div>
          <dt className={overlineClass}>Ingreso</dt>
          <dd className="text-xl/7 font-bold tabular-nums">{formatMoney(record.income_total)}</dd>
        </div>
        <div>
          <dt className={overlineClass}>Comisión</dt>
          <dd className="text-xl/7 font-bold tabular-nums">{formatMoney(record.commission_total)}</dd>
        </div>
      </dl>
    </section>
  );
}
