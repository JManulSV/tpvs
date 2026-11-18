"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { CircleAlert, CircleCheck, CircleX } from "lucide-react";
import { useRef, useState, type KeyboardEvent, type ReactNode } from "react";
import { useForm, useWatch, type FieldPath } from "react-hook-form";
import type { RecordDetailView } from "@/features/records/types";
import { formatMoney } from "@/lib/format";
import { cn } from "@/lib/utils";
import { useGenerateDeliveryDocument, useUpsertDelivery } from "../hooks/use-deliveries";
import { deliveryFormSchema, type DeliveryFormValues } from "../schemas/delivery.schema";
import {
  DeliveryApiError,
  documentBlockedMessages,
  officialDenominationValues,
  type Delivery,
  type DeliveryErrorDetails,
} from "../types";
import {
  centsToAmount,
  denominationBreakdownKey,
  documentReturnOptions,
  getCashCheck,
  getDeliveryHeading,
  getDeliveryReadiness,
  isDeliveryClean,
  type CashCheck,
} from "../utils";
import { DeliveryActionBar } from "./delivery-action-bar";
import { DeliveryCashBreakdown } from "./delivery-cash-breakdown";
import { DeliveryImageCard } from "./delivery-image-card";
import { DeliveryReadinessCard } from "./delivery-readiness-card";

type DeliveryFormProps = {
  record: RecordDetailView;
  delivery: Delivery | null;
  userName: string;
  currentReturnAmount: string;
  documentReturnAmount: string;
  onDocumentReturnAmountChange: (amount: string) => void;
  customerWillGiveChange: boolean;
  onCustomerWillGiveChangeChange: (willGiveChange: boolean) => void;
  onInvalidRecordState: () => void;
};

type BlockItem = { text: ReactNode; code?: string };

const focusRing = "focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring";
const inputClass = cn(
  "h-12 w-full rounded-sm border border-input bg-card px-3 text-sm/5 font-medium md:h-10",
  "aria-invalid:border-destructive disabled:cursor-not-allowed disabled:opacity-50",
  focusRing,
);
const cardClass = "rounded-lg border bg-card shadow-card";

function todayIsoDate(): string {
  const now = new Date();
  const month = String(now.getMonth() + 1).padStart(2, "0");
  const day = String(now.getDate()).padStart(2, "0");

  return `${now.getFullYear()}-${month}-${day}`;
}

function buildFormValues(delivery: Delivery | null, userName: string): DeliveryFormValues {
  const quantities = new Map(
    (delivery?.denominations ?? []).map((denomination) => [denomination.value, denomination.quantity]),
  );

  return {
    delivery_date: delivery?.delivery_date ?? todayIsoDate(),
    delivered_by_name: delivery?.delivered_by_name ?? userName,
    received_by_name: delivery?.received_by_name ?? "",
    denominations: officialDenominationValues.map((value) => ({
      value,
      quantity: String(quantities.get(value) ?? 0),
    })),
  };
}

export function DeliveryForm({
  record,
  delivery,
  userName,
  currentReturnAmount,
  documentReturnAmount,
  onDocumentReturnAmountChange,
  customerWillGiveChange,
  onCustomerWillGiveChangeChange,
  onInvalidRecordState,
}: DeliveryFormProps) {
  const upsert = useUpsertDelivery();
  const generate = useGenerateDeliveryDocument();
  const [defaultValues] = useState(() => buildFormValues(delivery, userName));
  const form = useForm<DeliveryFormValues>({
    resolver: zodResolver(deliveryFormSchema),
    defaultValues,
  });
  const [saveError, setSaveError] = useState<string | null>(null);
  const [mismatch, setMismatch] = useState<DeliveryErrorDetails | null>(null);
  const [generateError, setGenerateError] = useState<string | null>(null);
  const [generatedKey, setGeneratedKey] = useState<string | null>(null);

  const [deliveryDate, deliveredByName, receivedByName, watchedDenominations] = useWatch({
    control: form.control,
    name: ["delivery_date", "delivered_by_name", "received_by_name", "denominations"],
  });
  const values: DeliveryFormValues = {
    delivery_date: deliveryDate ?? "",
    delivered_by_name: deliveredByName ?? "",
    received_by_name: receivedByName ?? "",
    denominations: watchedDenominations ?? defaultValues.denominations,
  };

  const hasImage = record.image !== null;
  const isSaving = form.formState.isSubmitting || upsert.isPending;
  const cash = getCashCheck({
    officialAmount: currentReturnAmount,
    documentAmount: documentReturnAmount,
    denominations: values.denominations,
  });
  const clean = isDeliveryClean(delivery, values);
  const stateKey = [
    values.delivery_date,
    values.delivered_by_name,
    values.received_by_name,
    denominationBreakdownKey(values.denominations),
    documentReturnAmount,
    String(customerWillGiveChange),
  ].join("~");
  const readiness = getDeliveryReadiness({
    hasImage,
    cash,
    changeDeclared: customerWillGiveChange,
    clean,
    missingData: values.delivery_date.trim() === "" || values.delivered_by_name.trim() === "",
    generated: generatedKey === stateKey,
  });
  const { saveLabel } = getDeliveryHeading(delivery);

  const blocks: BlockItem[] = [];
  if (cash.shortOfficialCents > 0) {
    blocks.push({
      text: `Faltan ${formatMoney(centsToAmount(cash.shortOfficialCents))} en efectivo frente al retorno oficial. Ajusta las denominaciones.`,
      code: "CASH_MISMATCH",
    });
  } else if (cash.shortDocumentCents > 0) {
    blocks.push({
      text: `Faltan ${formatMoney(centsToAmount(cash.shortDocumentCents))} en efectivo frente al monto del documento. Ajusta las denominaciones o elige otro monto del documento.`,
      code: "CASH_MISMATCH",
    });
  }
  if (!hasImage) blocks.push({ text: "Adjunta la imagen del registro para generar el Word." });
  if (mismatch) {
    blocks.push({
      text: `El servidor rechazó el efectivo: esperado ${formatMoney(mismatch.expected_amount)}, contado ${formatMoney(mismatch.cash_amount)}, diferencia ${formatMoney(mismatch.difference)}.`,
      code: "CASH_MISMATCH",
    });
  }
  if (saveError) blocks.push({ text: saveError });
  if (generateError) blocks.push({ text: generateError });

  function handleQuantityChange(index: number, quantity: string) {
    form.setValue(`denominations.${index}.quantity`, quantity, { shouldDirty: true });
    onCustomerWillGiveChangeChange(false);
  }

  async function onSubmit(submitted: DeliveryFormValues) {
    setSaveError(null);
    setMismatch(null);
    setGenerateError(null);
    form.clearErrors();

    const received = submitted.received_by_name.trim();

    try {
      await upsert.mutateAsync({
        recordId: record.id,
        input: {
          delivery_date: submitted.delivery_date,
          delivered_by_name: submitted.delivered_by_name.trim(),
          received_by_name: received === "" ? null : received,
          denominations: submitted.denominations.map((denomination) => ({
            value: denomination.value,
            quantity: Math.trunc(Number(denomination.quantity) || 0),
          })),
        },
      });
    } catch (error) {
      if (!(error instanceof DeliveryApiError)) {
        setSaveError("No se pudo guardar la entrega. Inténtalo de nuevo.");
        return;
      }

      if (error.status === null) {
        setSaveError("No se pudo guardar la entrega. Inténtalo de nuevo.");
        return;
      }

      if (error.status === 409 && error.reason === "CASH_MISMATCH") {
        setMismatch(error.details);
        return;
      }

      if (error.status === 409 && error.reason === "INVALID_RECORD_STATE") {
        onInvalidRecordState();
        return;
      }

      let mapped = false;
      for (const [field, messages] of Object.entries(error.validationErrors)) {
        if (!messages[0]) continue;

        form.setError(field as FieldPath<DeliveryFormValues>, { type: "server", message: messages[0] });
        mapped = true;
      }

      if (!mapped) setSaveError(error.message);
    }
  }

  function handleGenerate() {
    if (delivery === null || !readiness.canGenerate || generate.isPending) return;

    const keyAtClick = stateKey;
    setGenerateError(null);
    generate.mutate(
      {
        deliveryId: delivery.id,
        recordId: record.id,
        input: {
          document_return_amount: documentReturnAmount,
          customer_will_give_change: customerWillGiveChange,
        },
      },
      {
        onSuccess: () => setGeneratedKey(keyAtClick),
        onError: (error) => {
          if (error instanceof DeliveryApiError && error.status === 409) {
            setGenerateError(documentBlockedMessages[error.reason ?? ""] ?? error.message);
            onInvalidRecordState();
            return;
          }

          setGenerateError("No se pudo generar el recibo. Inténtalo de nuevo.");
        },
      },
    );
  }

  const errors = form.formState.errors;

  return (
    <form className="space-y-6" noValidate onSubmit={form.handleSubmit(onSubmit)}>
      {blocks.length > 0 && <BlocksBanner items={blocks} />}

      <div className="@container">
        <div className="grid gap-6 @[60rem]:grid-cols-[minmax(0,1fr)_340px] @[60rem]:items-start">
          <div className="flex min-w-0 flex-col gap-6">
            <AmountsHero
              cash={cash}
              documentReturnAmount={documentReturnAmount}
              officialAmount={currentReturnAmount}
            />

            <section aria-labelledby="delivery-data-heading" className={cardClass}>
              <CardHeader
                id="delivery-data-heading"
                subtitle="Quién entrega el efectivo y quién lo recibe."
                title="Datos de la entrega"
              />
              <fieldset
                className="grid grid-cols-[repeat(auto-fit,minmax(180px,1fr))] gap-x-5 gap-y-4 p-4 md:px-6 md:pb-6"
                disabled={isSaving}
              >
                <legend className="sr-only">Datos de la entrega</legend>
                <Field error={errors.delivery_date?.message} id="delivery-date" label="Fecha de entrega">
                  <input
                    aria-describedby={errors.delivery_date ? "delivery-date-error" : undefined}
                    aria-invalid={Boolean(errors.delivery_date)}
                    className={inputClass}
                    id="delivery-date"
                    type="date"
                    {...form.register("delivery_date")}
                  />
                </Field>
                <Field error={errors.delivered_by_name?.message} id="delivered-by-name" label="Entrega">
                  <input
                    aria-describedby={errors.delivered_by_name ? "delivered-by-name-error" : undefined}
                    aria-invalid={Boolean(errors.delivered_by_name)}
                    autoComplete="name"
                    className={inputClass}
                    id="delivered-by-name"
                    maxLength={255}
                    type="text"
                    {...form.register("delivered_by_name")}
                  />
                </Field>
                <Field
                  badge={(
                    <span className="inline-flex h-5 items-center rounded-full bg-status-pending-bg px-2 text-[11px]/4 font-semibold text-status-pending">
                      Opcional
                    </span>
                  )}
                  error={errors.received_by_name?.message}
                  id="received-by-name"
                  label="Recibe"
                >
                  <input
                    aria-describedby={errors.received_by_name ? "received-by-name-error" : undefined}
                    aria-invalid={Boolean(errors.received_by_name)}
                    className={inputClass}
                    id="received-by-name"
                    maxLength={255}
                    type="text"
                    {...form.register("received_by_name")}
                  />
                </Field>
              </fieldset>
            </section>

            <section aria-labelledby="delivery-document-amount-heading" className={cardClass}>
              <CardHeader
                id="delivery-document-amount-heading"
                subtitle="Es el monto que imprime el recibo de Word."
                title="Monto del documento"
              />
              <div className="p-4 md:px-6 md:pb-6">
                <DocumentAmountGroup
                  disabled={isSaving}
                  onChange={(amount) => {
                    onDocumentReturnAmountChange(amount);
                    onCustomerWillGiveChangeChange(false);
                  }}
                  options={documentReturnOptions(currentReturnAmount)}
                  value={documentReturnAmount}
                />
                <p className="mt-3 text-xs/4 font-medium text-muted-foreground">
                  Es un valor de esta solicitud: no se guarda y el retorno oficial no cambia.
                </p>
              </div>
            </section>

            <DeliveryCashBreakdown
              customerWillGiveChange={customerWillGiveChange}
              denominations={values.denominations}
              disabled={isSaving}
              excessCents={cash.excessCents}
              onCustomerWillGiveChangeChange={onCustomerWillGiveChangeChange}
              onQuantityChange={handleQuantityChange}
              quantityError={(index) => errors.denominations?.[index]?.quantity?.message}
            />
          </div>

          <div className="flex min-w-0 flex-col gap-6">
            <DeliveryImageCard canAttach record={record} />
            <DeliveryReadinessCard checks={readiness.checks} />
          </div>
        </div>
      </div>

      <div aria-hidden="true" className="h-36 md:hidden" />
      <DeliveryActionBar
        clean={clean}
        isGenerating={generate.isPending}
        isSaving={isSaving}
        onGenerate={handleGenerate}
        readiness={readiness}
        recordId={record.id}
        saveLabel={saveLabel}
      />
    </form>
  );
}

function BlocksBanner({ items }: { items: BlockItem[] }) {
  return (
    <div
      className="flex items-start gap-3 rounded-md bg-status-error-bg px-4 py-3.5 text-[13px]/5 font-medium text-status-error"
      role="alert"
    >
      <CircleX aria-hidden="true" className="mt-px size-5 shrink-0" />
      <ul className={cn("flex flex-col gap-1.5", items.length > 1 && "list-disc pl-[18px]")}>
        {items.map((item, index) => (
          <li key={index}>
            <strong className="font-bold">{item.text}</strong>
            {item.code && (
              <>
                <br />
                <code className="font-mono font-normal">{item.code}</code>
              </>
            )}
          </li>
        ))}
      </ul>
    </div>
  );
}

function AmountsHero({
  officialAmount,
  documentReturnAmount,
  cash,
}: {
  officialAmount: string;
  documentReturnAmount: string;
  cash: CashCheck;
}) {
  const CheckIcon = cash.tone === "ok" ? CircleCheck : cash.tone === "excess" ? CircleAlert : CircleX;

  return (
    <section
      aria-label="Montos de la entrega"
      className="grid gap-4 rounded-xl bg-hero px-5 py-6 text-on-hero md:grid-cols-[minmax(0,1fr)_auto] md:items-end md:gap-x-10 md:gap-y-5 md:px-8 md:py-7"
    >
      <div>
        <span className="block text-[11px]/4 font-semibold tracking-[0.08em] text-on-hero/70 uppercase">Retorno oficial</span>
        <span className="mt-1 block text-[32px]/10 font-bold tracking-[-0.02em] tabular-nums md:text-[40px]/12">
          {formatMoney(officialAmount)}
        </span>
        <span className="mt-1 block text-[13px]/5 font-medium text-on-hero/70">
          Es el monto con el que se verifica el efectivo al guardar.
        </span>
      </div>
      <dl className="flex gap-6 md:gap-8">
        <HeroFigure label="Documento" value={formatMoney(documentReturnAmount)} />
        <HeroFigure label="Efectivo" value={formatMoney(centsToAmount(cash.cashCents))} />
      </dl>
      <div
        className={cn(
          "flex flex-wrap items-center gap-2 rounded-md px-4 py-2.5 text-[13px]/5 font-semibold md:col-span-2",
          cash.tone === "ok" && "bg-status-confirmed-bg text-status-confirmed",
          cash.tone === "excess" && "bg-status-needs-review-bg text-status-needs-review",
          cash.tone === "short" && "bg-status-error-bg text-status-error",
        )}
        role="status"
      >
        <CheckIcon aria-hidden="true" className="size-5 shrink-0" />
        {cash.label}
      </div>
    </section>
  );
}

function HeroFigure({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-[11px]/4 font-semibold tracking-[0.08em] text-on-hero/70 uppercase">{label}</dt>
      <dd className="mt-1 text-xl/7 font-bold tabular-nums">{value}</dd>
    </div>
  );
}

function DocumentAmountGroup({
  options,
  value,
  onChange,
  disabled,
}: {
  options: readonly string[];
  value: string;
  onChange: (amount: string) => void;
  disabled: boolean;
}) {
  const buttons = useRef<Array<HTMLButtonElement | null>>([]);
  const selectedIndex = Math.max(options.indexOf(value), 0);

  function handleKeyDown(event: KeyboardEvent<HTMLButtonElement>, index: number) {
    const step = event.key === "ArrowRight" || event.key === "ArrowDown"
      ? 1
      : event.key === "ArrowLeft" || event.key === "ArrowUp" ? -1 : 0;
    if (step === 0) return;

    event.preventDefault();
    const next = (index + step + options.length) % options.length;
    onChange(options[next]);
    buttons.current[next]?.focus();
  }

  return (
    <div
      aria-labelledby="delivery-document-amount-heading"
      className="flex max-w-[460px] gap-1 rounded-full border bg-muted p-1"
      role="radiogroup"
    >
      {options.map((option, index) => {
        const checked = index === selectedIndex;

        return (
          <button
            aria-checked={checked}
            className={cn(
              "h-11 min-w-0 flex-1 rounded-full px-2 text-sm/5 font-semibold tabular-nums transition-colors md:h-9 md:px-4",
              "disabled:cursor-not-allowed disabled:opacity-50",
              checked ? "bg-primary text-primary-foreground" : "text-muted-foreground hover:text-foreground",
              focusRing,
            )}
            disabled={disabled}
            key={option}
            onClick={() => onChange(option)}
            onKeyDown={(event) => handleKeyDown(event, index)}
            ref={(element) => {
              buttons.current[index] = element;
            }}
            role="radio"
            tabIndex={checked ? 0 : -1}
            type="button"
          >
            {formatMoney(option)}
          </button>
        );
      })}
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

function Field({
  id,
  label,
  badge,
  error,
  children,
}: {
  id: string;
  label: string;
  badge?: ReactNode;
  error?: string;
  children: ReactNode;
}) {
  return (
    <div className="flex min-w-0 flex-col gap-1.5">
      <label className="flex flex-wrap items-center gap-1.5 text-xs/4 font-semibold text-muted-foreground" htmlFor={id}>
        {label}
        {badge}
      </label>
      {children}
      {error && <p className="text-xs text-destructive" id={`${id}-error`}>{error}</p>}
    </div>
  );
}
