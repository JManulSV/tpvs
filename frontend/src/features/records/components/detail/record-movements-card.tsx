import { formatMoney } from "@/lib/format";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import type { RecordListItem } from "../../types";

const labelClass = "text-[11px]/4 font-semibold tracking-[0.08em] uppercase";
const headClass = `${labelClass} h-auto px-4 py-3 text-muted-foreground`;

function MovementField({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className={`${labelClass} text-muted-foreground`}>{label}</dt>
      <dd className="text-sm/5 font-medium tabular-nums">{value}</dd>
    </div>
  );
}

function TotalField({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) {
  return (
    <div>
      <dt className={`${labelClass} text-muted-foreground`}>{label}</dt>
      <dd className={`text-sm/5 tabular-nums ${strong ? "font-bold" : "font-medium"}`}>{value}</dd>
    </div>
  );
}

type RecordMovementsCardProps = {
  record: Pick<RecordListItem, "details" | "commission_percentage" | "income_total" | "commission_total" | "return_total">;
};

/** Movements (affiliation, bank, income) and the record's global percentage and totals from the API. */
export function RecordMovementsCard({ record }: RecordMovementsCardProps) {
  const { details } = record;

  return (
    <section
      aria-labelledby="record-movements-heading"
      className="@container overflow-hidden rounded-lg border bg-card shadow-card"
    >
      <div className="space-y-1 p-5">
        <h2 className="text-base/6 font-semibold" id="record-movements-heading">Movimientos</h2>
        <p className="text-xs/4 text-muted-foreground">
          La comisión es un solo porcentaje para todo el registro; el servidor la calcula sobre el ingreso total.
        </p>
      </div>

      {details.length === 0 ? (
        <p className="px-5 pb-5 text-sm text-muted-foreground">Todavía no hay movimientos.</p>
      ) : (
        <>
          <div className="hidden @[45rem]:block">
            <Table>
              <caption className="sr-only">Movimientos del registro</caption>
              <TableHeader className="bg-muted">
                <TableRow>
                  <TableHead className={headClass} scope="col">#</TableHead>
                  <TableHead className={headClass} scope="col">Afiliación</TableHead>
                  <TableHead className={headClass} scope="col">Banco</TableHead>
                  <TableHead className={`${headClass} text-right`} scope="col">Ingreso</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {details.map((detail) => (
                  <TableRow key={detail.id}>
                    <TableCell className="px-4 py-3">
                      <span className="inline-flex size-7 items-center justify-center rounded-full bg-muted text-xs font-semibold tabular-nums">
                        {detail.order}
                      </span>
                    </TableCell>
                    <TableCell className="px-4 py-3">{detail.affiliation ?? "—"}</TableCell>
                    <TableCell className="px-4 py-3">{detail.bank ?? "—"}</TableCell>
                    <TableCell className="px-4 py-3 text-right tabular-nums">{formatMoney(detail.income)}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>

          <ul className="divide-y border-t @[45rem]:hidden">
            {details.map((detail) => (
              <li className="space-y-3 p-4" key={detail.id}>
                <p className="font-bold">Movimiento {detail.order}</p>
                <dl className="grid grid-cols-2 gap-x-4 gap-y-3">
                  <MovementField label="Afiliación" value={detail.affiliation ?? "—"} />
                  <MovementField label="Banco" value={detail.bank ?? "—"} />
                  <MovementField label="Ingreso" value={formatMoney(detail.income)} />
                </dl>
              </li>
            ))}
          </ul>
        </>
      )}

      <dl aria-label="Totales del registro" className="grid grid-cols-2 gap-4 border-t bg-muted/40 p-5 @[45rem]:grid-cols-4">
        <TotalField label="Ingreso total" value={formatMoney(record.income_total ?? null)} />
        <TotalField
          label="Comisión %"
          value={record.commission_percentage === null ? "—" : `${record.commission_percentage}%`}
        />
        <TotalField label="Comisión" value={formatMoney(record.commission_total ?? null)} />
        <TotalField label="Retorno" strong value={formatMoney(record.return_total ?? null)} />
      </dl>
    </section>
  );
}
