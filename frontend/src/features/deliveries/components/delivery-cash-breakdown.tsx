"use client";

import { Banknote, Coins, Minus, Plus } from "lucide-react";
import { formatMoney } from "@/lib/format";
import { cn } from "@/lib/utils";
import { centsToAmount, parseQuantityInput, stepQuantity, toCents } from "../utils";

export type CashBreakdownRow = { value: string; quantity: string };

type DeliveryCashBreakdownProps = {
  denominations: CashBreakdownRow[];
  onQuantityChange: (index: number, quantity: string) => void;
  quantityError?: (index: number) => string | undefined;
  excessCents: number;
  customerWillGiveChange: boolean;
  onCustomerWillGiveChangeChange: (willGiveChange: boolean) => void;
  disabled?: boolean;
};

const focusRing = "focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring";

function quantityOf(row: CashBreakdownRow): number {
  return Math.max(Math.trunc(Number(row.quantity) || 0), 0);
}

function subtotalCents(rows: Array<{ row: CashBreakdownRow }>): number {
  return rows.reduce((total, { row }) => total + toCents(row.value) * quantityOf(row), 0);
}

export function DeliveryCashBreakdown({
  denominations,
  onQuantityChange,
  quantityError,
  excessCents,
  customerWillGiveChange,
  onCustomerWillGiveChangeChange,
  disabled = false,
}: DeliveryCashBreakdownProps) {
  const indexed = denominations.map((row, index) => ({ row, index }));
  const bills = indexed.filter(({ row }) => toCents(row.value) >= 2000);
  const coins = indexed.filter(({ row }) => toCents(row.value) < 2000);
  const pieces = denominations.reduce((total, row) => total + quantityOf(row), 0);
  const totalCents = subtotalCents(indexed);
  const excess = formatMoney(centsToAmount(excessCents));

  return (
    <section aria-labelledby="delivery-cash-heading" className="rounded-lg border bg-card shadow-card">
      <header className="px-4 pt-4 md:px-6 md:pt-[18px]">
        <h2 className="text-base/6 font-semibold" id="delivery-cash-heading">Desglose de efectivo</h2>
        <p className="mt-0.5 text-xs/4 font-medium text-muted-foreground">
          Efectivo bruto. Solo se guardan las denominaciones con cantidad mayor a cero.
        </p>
      </header>

      <div className="space-y-5 p-4 md:px-6 md:pb-6">
        <DenominationGroup
          disabled={disabled}
          icon="bill"
          label="Billetes"
          onQuantityChange={onQuantityChange}
          quantityError={quantityError}
          rows={bills}
        />
        <DenominationGroup
          disabled={disabled}
          icon="coin"
          label="Monedas"
          onQuantityChange={onQuantityChange}
          quantityError={quantityError}
          rows={coins}
        />

        <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-md bg-hero px-4 py-3.5 text-on-hero md:px-5 md:py-4">
          <div>
            <span className="block text-[11px]/4 font-semibold tracking-[0.08em] text-on-hero/70 uppercase">Efectivo total</span>
            <span className="mt-0.5 block text-[13px]/5 font-medium text-on-hero/70 tabular-nums">
              {pieces === 1 ? "1 pieza" : `${pieces} piezas`}
            </span>
          </div>
          <span className="text-2xl/8 font-bold tracking-[-0.01em] tabular-nums md:text-[28px]/9">
            {formatMoney(centsToAmount(totalCents))}
          </span>
        </div>

        {excessCents > 0 && (
          <div className="space-y-4">
            <div
              className="rounded-md bg-status-needs-review-bg px-4 py-3.5 text-[13px]/5 font-medium text-status-needs-review"
              role="status"
            >
              <strong className="block font-bold">El efectivo excede el documento por {excess}.</strong>
              Indica que el cliente dará cambio para continuar.
              <br />
              <code className="font-mono">CHANGE_REQUIRED</code>
            </div>
            <label className="flex items-center gap-3 text-sm/5 font-medium" htmlFor="customer-will-give-change">
              <input
                checked={customerWillGiveChange}
                className={cn("size-[18px] accent-primary", focusRing)}
                disabled={disabled}
                id="customer-will-give-change"
                onChange={(event) => onCustomerWillGiveChangeChange(event.target.checked)}
                type="checkbox"
              />
              <span>
                El cliente dará cambio · <span className="tabular-nums">{excess}</span> (calculado)
              </span>
            </label>
          </div>
        )}
      </div>
    </section>
  );
}

function DenominationGroup({
  label,
  icon,
  rows,
  onQuantityChange,
  quantityError,
  disabled,
}: {
  label: string;
  icon: "bill" | "coin";
  rows: Array<{ row: CashBreakdownRow; index: number }>;
  onQuantityChange: (index: number, quantity: string) => void;
  quantityError?: (index: number) => string | undefined;
  disabled: boolean;
}) {
  return (
    <div className="flex flex-col gap-2" role="group" aria-label={label}>
      <div className="mb-0.5 flex items-center justify-between gap-3 text-[11px]/4 font-semibold tracking-[0.08em] text-muted-foreground uppercase tabular-nums">
        <span>{label}</span>
        <span>{formatMoney(centsToAmount(subtotalCents(rows)))}</span>
      </div>
      {rows.map(({ row, index }) => (
        <DenominationRow
          disabled={disabled}
          error={quantityError?.(index)}
          icon={icon}
          index={index}
          key={row.value}
          onQuantityChange={onQuantityChange}
          row={row}
        />
      ))}
    </div>
  );
}

function DenominationRow({
  row,
  index,
  icon,
  error,
  onQuantityChange,
  disabled,
}: {
  row: CashBreakdownRow;
  index: number;
  icon: "bill" | "coin";
  error?: string;
  onQuantityChange: (index: number, quantity: string) => void;
  disabled: boolean;
}) {
  const quantity = quantityOf(row);
  const label = formatMoney(row.value);
  const hasQuantity = quantity > 0;
  const Icon = icon === "bill" ? Banknote : Coins;
  const errorId = `denomination-${index}-error`;
  const stepButton = cn(
    "grid size-11 shrink-0 place-items-center rounded-full border border-input bg-card text-foreground md:size-8",
    "disabled:cursor-not-allowed disabled:opacity-50",
    focusRing,
  );

  return (
    <div>
      <div
        className={cn(
          "grid grid-cols-[minmax(0,1fr)_auto] gap-x-2 rounded-md border p-2 [grid-template-areas:'den_step'_'t_step']",
          "md:grid-cols-[minmax(0,1.1fr)_auto_minmax(96px,1fr)] md:items-center md:gap-4 md:pr-4 md:[grid-template-areas:'den_step_t']",
          hasQuantity ? "bg-muted" : "bg-card",
        )}
      >
        <span className="flex min-w-0 items-center gap-3 self-end [grid-area:den] md:self-auto">
          <span
            aria-hidden="true"
            className={cn(
              "grid h-8 shrink-0 place-items-center",
              icon === "bill" ? "w-11 rounded-sm" : "mx-1.5 w-8 rounded-full",
              hasQuantity ? "bg-accent text-accent-foreground" : "bg-muted text-muted-foreground",
            )}
          >
            <Icon className="size-5" />
          </span>
          <span className={cn("text-base/6 tabular-nums", hasQuantity ? "font-bold" : "font-medium text-muted-foreground")}>
            {label}
          </span>
        </span>

        <span className="inline-flex items-center gap-1 [grid-area:step] md:gap-2">
          <button
            aria-label={`Quitar uno de ${label}`}
            className={stepButton}
            disabled={disabled || quantity === 0}
            onClick={() => onQuantityChange(index, stepQuantity(row.quantity, -1))}
            type="button"
          >
            <Minus aria-hidden="true" className="size-4" />
          </button>
          <input
            aria-describedby={error ? errorId : undefined}
            aria-invalid={Boolean(error)}
            aria-label={`Cantidad de ${label}`}
            className={cn(
              "h-11 w-11 rounded-sm border border-input bg-card p-0 text-center text-sm/5 font-semibold tabular-nums md:h-8 md:w-14",
              "disabled:cursor-not-allowed disabled:opacity-50",
              focusRing,
            )}
            disabled={disabled}
            id={`denomination-${index}`}
            inputMode="numeric"
            onChange={(event) => onQuantityChange(index, parseQuantityInput(event.target.value))}
            onFocus={(event) => event.target.select()}
            type="text"
            value={String(quantity)}
          />
          <button
            aria-label={`Agregar uno de ${label}`}
            className={stepButton}
            disabled={disabled}
            onClick={() => onQuantityChange(index, stepQuantity(row.quantity, 1))}
            type="button"
          >
            <Plus aria-hidden="true" className="size-4" />
          </button>
        </span>

        <span
          className={cn(
            "self-start pl-14 text-[13px]/5 tabular-nums [grid-area:t] md:self-auto md:pl-0 md:text-right md:text-sm/5",
            hasQuantity ? "font-semibold md:font-bold" : "font-medium text-muted-foreground",
          )}
        >
          {formatMoney(centsToAmount(toCents(row.value) * quantity))}
        </span>
      </div>
      {error && <p className="mt-1 text-xs text-destructive" id={errorId}>{error}</p>}
    </div>
  );
}
