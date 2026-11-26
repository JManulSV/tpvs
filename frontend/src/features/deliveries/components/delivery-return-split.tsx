"use client";

import { CircleX } from "lucide-react";
import { useState } from "react";
import { FormField } from "@/components/form-field";
import { formatMoney } from "@/lib/format";
import { cardSurface, fieldControl, focusRing } from "@/lib/styles";
import { cn } from "@/lib/utils";
import { centsToAmount, sanitizeAmountInput, toCents, type DeliverySplitPreview, type SplitError } from "../utils";
import { DeliverySectionHeader } from "./delivery-section-header";

const HEADING_ID = "delivery-split-heading";

/** Amounts the section shows: the preview while editing, Laravel's response once saved. */
export type SplitAmounts = {
  transferAmount: string;
  cashCommission: string;
  cashAmount: string;
};

type DeliveryReturnSplitProps = {
  officialAmount: string;
  split: DeliverySplitPreview;
  amounts: SplitAmounts;
  requestedCashAmount: string;
  cashCommissionPercentage: string;
  onRequestedCashChange: (amount: string) => void;
  onPercentageChange: (percentage: string) => void;
  /** «Todo por transferencia» also empties the cash breakdown. */
  onAllTransfer: () => void;
  /** A server rejection, such as CASH_REQUEST_EXCEEDS_RETURN after the return changed. */
  serverError: SplitError | null;
  disabled: boolean;
};

/**
 * «Forma de retorno»: the two linked fields always add up to the official return.
 * Only the requested cash is stored in the form; the transfer is derived from it.
 */
export function DeliveryReturnSplit({
  officialAmount,
  split,
  amounts,
  requestedCashAmount,
  cashCommissionPercentage,
  onRequestedCashChange,
  onPercentageChange,
  onAllTransfer,
  serverError,
  disabled,
}: DeliveryReturnSplitProps) {
  // While the Editor types the transfer, the field keeps the text as typed.
  const [transferDraft, setTransferDraft] = useState<string | null>(null);
  const [percentageTouched, setPercentageTouched] = useState(false);

  const transferExceeds = split.errors.some((error) => error.code === "TRANSFER_EXCEEDS_RETURN");
  const cashExceeds = split.errors.some((error) => error.code === "CASH_REQUEST_EXCEEDS_RETURN")
    || serverError?.code === "CASH_REQUEST_EXCEEDS_RETURN";
  // An empty extra commission is required, but it is only flagged once the Editor leaves the field.
  const percentageEmpty = cashCommissionPercentage.trim() === "";
  const percentageInvalid = split.percentage === null && (!percentageEmpty || percentageTouched);
  const errors = [
    ...split.errors.filter((error) => error.code !== "EXTRA_COMMISSION_INVALID" || percentageInvalid),
    ...(serverError && !split.errors.some((error) => error.code === serverError.code) ? [serverError] : []),
  ];

  const transferPercent = split.returnCents > 0
    ? Math.max(0, Math.min(100, Math.round((split.transferCents / split.returnCents) * 100)))
    : 0;
  const allTransfer = split.requestedCashCents === 0;
  const allCash = split.requestedCashCents === split.returnCents;

  function handleTransferChange(raw: string) {
    const value = sanitizeAmountInput(raw);
    setTransferDraft(value);
    onRequestedCashChange(centsToAmount(split.returnCents - toCents(value)));
  }

  return (
    <section aria-labelledby={HEADING_ID} className={cardSurface}>
      <div className="flex flex-wrap items-start justify-between gap-3 pr-4 md:pr-6">
        <DeliverySectionHeader
          id={HEADING_ID}
          subtitle="Indica cuánto se entrega por transferencia y cuánto en efectivo. Los dos campos se ajustan entre sí."
          title="Forma de retorno"
        />
        <div aria-label="Atajos de reparto" className="flex flex-wrap gap-2 pl-4 pt-4 md:pl-0 md:pt-[18px]" role="group">
          <PresetButton disabled={disabled} onClick={() => { setTransferDraft(null); onAllTransfer(); }} pressed={allTransfer}>
            Todo por transferencia
          </PresetButton>
          <PresetButton
            disabled={disabled}
            onClick={() => { setTransferDraft(null); onRequestedCashChange(officialAmount); }}
            pressed={allCash}
          >
            Todo en efectivo
          </PresetButton>
        </div>
      </div>

      <fieldset className="grid grid-cols-[repeat(auto-fit,minmax(260px,1fr))] items-start gap-6 p-4 md:px-6 md:pb-6" disabled={disabled}>
        <legend className="sr-only">Forma de retorno</legend>
        <div className="flex min-w-0 flex-col gap-4">
          <FormField badge={<MiniBadge tone="confirmed">Solo comisión base</MiniBadge>} id="split-transfer" label="Por transferencia">
            <MoneyInput
              id="split-transfer"
              invalid={transferExceeds}
              onBlur={() => setTransferDraft(null)}
              onChange={handleTransferChange}
              value={transferDraft ?? centsToAmount(split.transferCents)}
            />
          </FormField>
          <FormField
            badge={<MiniBadge tone="review">Comisión extra</MiniBadge>}
            id="split-cash"
            label="En efectivo, lo que pide el cliente"
          >
            <MoneyInput
              id="split-cash"
              invalid={cashExceeds}
              onChange={(raw) => { setTransferDraft(null); onRequestedCashChange(sanitizeAmountInput(raw)); }}
              value={requestedCashAmount}
            />
          </FormField>
          <div className="flex min-w-0 flex-col gap-1.5">
            <FormField id="split-percentage" label="Comisión extra por efectivo">
              <span className="relative block">
                <input
                  aria-describedby="split-percentage-hint"
                  aria-invalid={percentageInvalid}
                  aria-required="true"
                  className={cn(fieldControl, "pr-8 tabular-nums")}
                  id="split-percentage"
                  inputMode="decimal"
                  onBlur={() => setPercentageTouched(true)}
                  onChange={(event) => onPercentageChange(sanitizeAmountInput(event.target.value))}
                  placeholder="0.00"
                  value={cashCommissionPercentage}
                />
                <span aria-hidden="true" className="pointer-events-none absolute inset-y-0 right-3 grid place-items-center text-sm font-semibold text-muted-foreground">
                  %
                </span>
              </span>
            </FormField>
            <p className="text-xs/4 font-medium text-muted-foreground" id="split-percentage-hint">
              Cambia según el caso. Solo se cobra sobre lo que se entrega en efectivo.
            </p>
          </div>
          {errors.length > 0 && <SplitErrors errors={errors} />}
        </div>

        <div className="min-w-0">
          <div aria-hidden="true" className="flex h-3 overflow-hidden rounded-full bg-primary">
            <div className="bg-brand-sky" style={{ width: `${transferPercent}%` }} />
          </div>
          <p className="mt-2 flex flex-wrap gap-x-4 gap-y-1.5 text-xs/4 font-medium text-muted-foreground">
            <span><Dot className="bg-brand-sky" />Transferencia {transferPercent}%</span>
            <span><Dot className="bg-primary" />Efectivo {100 - transferPercent}%</span>
          </p>
          <dl className="mt-4 flex flex-col">
            <LedgerRow label="Retorno oficial" value={formatMoney(officialAmount)} />
            <LedgerRow label="Por transferencia" value={`− ${formatMoney(amounts.transferAmount)}`} />
            <LedgerRow label="Efectivo que pide el cliente" value={formatMoney(centsToAmount(split.requestedCashCents))} />
            <LedgerRow
              label={`Comisión extra · ${split.percentage ?? "—"}%`}
              value={`− ${formatMoney(amounts.cashCommission)}`}
            />
            <div className="mt-1 flex items-baseline justify-between gap-3 rounded-md bg-accent px-3.5 py-3 text-accent-foreground">
              <dt className="text-[13px]/5 font-semibold">Efectivo a entregar</dt>
              <dd className="text-base/6 font-bold tabular-nums">{formatMoney(amounts.cashAmount)}</dd>
            </div>
          </dl>
        </div>
      </fieldset>
    </section>
  );
}

function MoneyInput({
  id,
  value,
  invalid,
  onChange,
  onBlur,
}: {
  id: string;
  value: string;
  invalid: boolean;
  onChange: (raw: string) => void;
  onBlur?: () => void;
}) {
  return (
    <span className="relative block">
      <span aria-hidden="true" className="pointer-events-none absolute inset-y-0 left-3 grid place-items-center text-sm font-semibold text-muted-foreground">
        $
      </span>
      <input
        aria-invalid={invalid}
        className={cn(fieldControl, "pl-6.5 tabular-nums")}
        id={id}
        inputMode="decimal"
        onBlur={onBlur}
        onChange={(event) => onChange(event.target.value)}
        value={value}
      />
    </span>
  );
}

function PresetButton({
  pressed,
  disabled,
  onClick,
  children,
}: {
  pressed: boolean;
  disabled: boolean;
  onClick: () => void;
  children: string;
}) {
  return (
    <button
      aria-pressed={pressed}
      className={cn(
        "inline-flex min-h-11 items-center rounded-full border border-input bg-card px-3.5 text-[13px]/4 font-semibold transition-colors hover:bg-muted md:min-h-8",
        "aria-pressed:border-accent-foreground aria-pressed:bg-accent aria-pressed:text-accent-foreground",
        "disabled:cursor-not-allowed disabled:opacity-50",
        focusRing,
      )}
      disabled={disabled}
      onClick={onClick}
      type="button"
    >
      {children}
    </button>
  );
}

function SplitErrors({ errors }: { errors: SplitError[] }) {
  return (
    <div className="flex items-start gap-3 rounded-md bg-status-error-bg px-4 py-3.5 text-[13px]/5 font-medium text-status-error" role="alert">
      <CircleX aria-hidden="true" className="mt-px size-5 shrink-0" />
      <ul className={cn("flex flex-col gap-1.5", errors.length > 1 && "list-disc pl-[18px]")}>
        {errors.map((error) => (
          <li key={error.code}>
            <strong className="font-bold">{error.text}</strong>
            <br />
            <code className="font-mono font-normal">{error.code}</code>
          </li>
        ))}
      </ul>
    </div>
  );
}

function MiniBadge({ tone, children }: { tone: "confirmed" | "review"; children: string }) {
  return (
    <span
      className={cn(
        "inline-flex h-5 items-center rounded-full px-2 text-[11px]/4 font-semibold",
        tone === "confirmed" ? "bg-status-confirmed-bg text-status-confirmed" : "bg-status-needs-review-bg text-status-needs-review",
      )}
    >
      {children}
    </span>
  );
}

function LedgerRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-baseline justify-between gap-3 border-t py-2.5 first:border-t-0">
      <dt className="text-[13px]/5 font-medium text-muted-foreground">{label}</dt>
      <dd className="text-sm/5 font-semibold tabular-nums">{value}</dd>
    </div>
  );
}

function Dot({ className }: { className: string }) {
  return <i aria-hidden="true" className={cn("mr-1.5 inline-block size-2.5 rounded-full", className)} />;
}
