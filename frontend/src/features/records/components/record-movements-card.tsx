import { formatMoney } from "@/lib/format";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import type { RecordDetail } from "../types";

const labelClass = "text-[11px]/4 font-semibold tracking-[0.08em] uppercase";
const headClass = `${labelClass} h-auto px-4 py-3 text-muted-foreground`;

function percentage(value: string | null): string {
  return value === null ? "—" : `${value}%`;
}

function MovementField({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className={`${labelClass} text-muted-foreground`}>{label}</dt>
      <dd className="text-sm/5 font-medium tabular-nums">{value}</dd>
    </div>
  );
}

export function RecordMovementsCard({ details }: { details: RecordDetail[] }) {
  return (
    <section
      aria-labelledby="record-movements-heading"
      className="@container overflow-hidden rounded-lg border bg-card shadow-card"
    >
      <div className="space-y-1 p-5">
        <h2 className="text-base/6 font-semibold" id="record-movements-heading">Movimientos</h2>
        <p className="text-xs/4 text-muted-foreground">La comisión y el retorno los calcula el servidor.</p>
      </div>

      {details.length === 0 ? (
        <p className="px-5 pb-5 text-sm text-muted-foreground">Todavía no hay movimientos.</p>
      ) : (
        <>
          <div className="hidden @[45rem]:block">
            <Table>
              <caption className="sr-only">Movimientos y cantidades calculadas por el servidor</caption>
              <TableHeader className="bg-muted">
                <TableRow>
                  <TableHead className={headClass} scope="col">#</TableHead>
                  <TableHead className={headClass} scope="col">Afiliación</TableHead>
                  <TableHead className={headClass} scope="col">Sucursal</TableHead>
                  <TableHead className={`${headClass} text-right`} scope="col">Ingreso</TableHead>
                  <TableHead className={`${headClass} text-right`} scope="col">Com. %</TableHead>
                  <TableHead className={`${headClass} text-right`} scope="col">Comisión</TableHead>
                  <TableHead className={`${headClass} text-right`} scope="col">Retorno</TableHead>
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
                    <TableCell className="px-4 py-3">{detail.branch ?? "—"}</TableCell>
                    <TableCell className="px-4 py-3 text-right tabular-nums">{formatMoney(detail.income)}</TableCell>
                    <TableCell className="px-4 py-3 text-right tabular-nums">{percentage(detail.commission_percentage)}</TableCell>
                    <TableCell className="px-4 py-3 text-right tabular-nums">{formatMoney(detail.commission)}</TableCell>
                    <TableCell className="px-4 py-3 text-right font-bold tabular-nums">{formatMoney(detail.return_amount)}</TableCell>
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
                  <MovementField label="Sucursal" value={detail.branch ?? "—"} />
                  <MovementField label="Ingreso" value={formatMoney(detail.income)} />
                  <MovementField label="Comisión %" value={percentage(detail.commission_percentage)} />
                  <MovementField label="Comisión" value={formatMoney(detail.commission)} />
                  <MovementField label="Retorno" value={formatMoney(detail.return_amount)} />
                </dl>
              </li>
            ))}
          </ul>
        </>
      )}
    </section>
  );
}
