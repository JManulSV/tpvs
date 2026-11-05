"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useEffect, useState } from "react";
import { useForm, useWatch, type FieldPath } from "react-hook-form";
import { Button } from "@/components/ui/button";
import { useUpsertDelivery } from "../hooks/use-deliveries";
import { deliveryFormSchema, type DeliveryFormValues } from "../schemas/delivery.schema";
import {
  DeliveryApiError,
  officialDenominationValues,
  type Delivery,
  type DeliveryErrorDetails,
} from "../types";
import { centsToAmount, denominationsTotalCents, documentReturnOptions, formatMoney, toCents } from "../utils";

type DeliveryFormProps = {
  recordId: number;
  delivery: Delivery | null;
  currentReturnAmount: string;
  documentReturnAmount: string;
  onDocumentReturnAmountChange: (amount: string) => void;
  customerWillGiveChange: boolean;
  onCustomerWillGiveChangeChange: (willGiveChange: boolean) => void;
  onCashBreakdownChange: (denominations: Array<{ value: string; quantity: number | string }>) => void;
  onInvalidRecordState: () => void;
};

function buildFormValues(delivery: Delivery | null): DeliveryFormValues {
  const quantities = new Map(
    (delivery?.denominations ?? []).map((denomination) => [denomination.value, denomination.quantity]),
  );

  return {
    delivery_date: delivery?.delivery_date ?? new Date().toISOString().slice(0, 10),
    delivered_by_name: delivery?.delivered_by_name ?? "",
    received_by_name: delivery?.received_by_name ?? "",
    denominations: officialDenominationValues.map((value) => ({
      value,
      quantity: String(quantities.get(value) ?? 0),
    })),
  };
}

export function DeliveryForm({
  recordId,
  delivery,
  currentReturnAmount,
  documentReturnAmount,
  onDocumentReturnAmountChange,
  customerWillGiveChange,
  onCustomerWillGiveChangeChange,
  onCashBreakdownChange,
  onInvalidRecordState,
}: DeliveryFormProps) {
  const mutation = useUpsertDelivery();
  const form = useForm<DeliveryFormValues>({
    resolver: zodResolver(deliveryFormSchema),
    defaultValues: buildFormValues(delivery),
  });
  const [formError, setFormError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [mismatch, setMismatch] = useState<DeliveryErrorDetails | null>(null);
  const watchedDenominations = useWatch({ control: form.control, name: "denominations" });
  const isSubmitting = form.formState.isSubmitting || mutation.isPending;
  const totalCents = denominationsTotalCents(watchedDenominations ?? []);
  const expectedCents = toCents(currentReturnAmount);
  const differenceCents = totalCents - expectedCents;
  const documentAmountOptions = documentReturnOptions(currentReturnAmount);
  const documentDifferenceCents = totalCents - toCents(documentReturnAmount);
  const cashShortfallExceedsTolerance = differenceCents < -100;
  const emptyCashForNonzeroReturn = expectedCents > 0 && totalCents === 0;
  const customerChangeIsRequired = documentDifferenceCents > 100;

  useEffect(() => {
    form.reset(buildFormValues(delivery));
  }, [delivery?.id, delivery?.updated_at, form, delivery]);

  async function onSubmit(values: DeliveryFormValues) {
    setFormError(null);
    setSuccessMessage(null);
    setMismatch(null);
    form.clearErrors();
    mutation.reset();

    const receivedByName = values.received_by_name.trim();

    try {
      await mutation.mutateAsync({
        recordId,
        input: {
          delivery_date: values.delivery_date,
          delivered_by_name: values.delivered_by_name.trim(),
          received_by_name: receivedByName === "" ? null : receivedByName,
          denominations: values.denominations.map((denomination) => ({
            value: denomination.value,
            quantity: Math.trunc(Number(denomination.quantity) || 0),
          })),
        },
      });
      setSuccessMessage("La preparación de entrega se guardó correctamente.");
    } catch (error) {
      if (error instanceof DeliveryApiError) {
        if (error.status === 409 && error.reason === "CASH_MISMATCH") {
          setMismatch(error.details);
        }

        if (error.status === 409 && error.reason === "INVALID_RECORD_STATE") {
          onInvalidRecordState();
        }

        let mapped = false;
        for (const [field, messages] of Object.entries(error.validationErrors)) {
          if (!messages[0]) continue;

          form.setError(field as FieldPath<DeliveryFormValues>, {
            type: "server",
            message: messages[0],
          });
          mapped = true;
        }

        setFormError(mapped ? "Corrige los campos señalados por el servidor." : error.message);
        return;
      }

      setFormError("No se pudo guardar la preparación de entrega. Inténtalo nuevamente.");
    }
  }

  return (
    <form className="space-y-6" noValidate onSubmit={form.handleSubmit(onSubmit)}>
      <fieldset className="grid gap-4 rounded-md border p-4 sm:grid-cols-3" disabled={isSubmitting}>
        <legend className="px-2 font-medium">Datos de la entrega</legend>
        <Field id="delivery-date" label="Fecha de entrega" error={form.formState.errors.delivery_date?.message}>
          <input
            aria-invalid={Boolean(form.formState.errors.delivery_date)}
            className="h-10 w-full rounded-md border bg-background px-3 text-sm"
            id="delivery-date"
            type="date"
            {...form.register("delivery_date")}
          />
        </Field>
        <Field id="delivered-by-name" label="Entrega realizada por" error={form.formState.errors.delivered_by_name?.message}>
          <input
            aria-invalid={Boolean(form.formState.errors.delivered_by_name)}
            className="h-10 w-full rounded-md border bg-background px-3 text-sm"
            id="delivered-by-name"
            maxLength={255}
            type="text"
            {...form.register("delivered_by_name")}
          />
        </Field>
        <Field id="received-by-name" label="Recibido por (opcional)" error={form.formState.errors.received_by_name?.message}>
          <input
            aria-invalid={Boolean(form.formState.errors.received_by_name)}
            className="h-10 w-full rounded-md border bg-background px-3 text-sm"
            id="received-by-name"
            maxLength={255}
            type="text"
            {...form.register("received_by_name")}
          />
        </Field>
      </fieldset>

      <fieldset className="space-y-4 rounded-md border p-4" disabled={isSubmitting}>
        <legend className="px-2 font-medium">Desglose de efectivo</legend>
        <p className="text-sm text-muted-foreground">
          Ingresa las denominaciones oficiales. Se conserva el retorno oficial; puede haber hasta $1.00 de faltante o efectivo excedente.
        </p>
        <div className="overflow-x-auto">
          <table className="w-full min-w-[28rem] border-collapse text-left text-sm">
            <caption className="sr-only">Denominaciones oficiales y cantidades</caption>
            <thead className="bg-muted/50">
              <tr>
                <th className="px-4 py-3 font-medium" scope="col">Denominación</th>
                <th className="px-4 py-3 font-medium" scope="col">Cantidad</th>
                <th className="px-4 py-3 text-right font-medium" scope="col">Subtotal</th>
              </tr>
            </thead>
            <tbody>
              {(watchedDenominations ?? buildFormValues(delivery).denominations).map((denomination, index) => {
                const quantity = Math.max(Math.trunc(Number(denomination.quantity) || 0), 0);
                const quantityError = form.formState.errors.denominations?.[index]?.quantity?.message;
                const quantityField = form.register(`denominations.${index}.quantity` as FieldPath<DeliveryFormValues>);

                return (
                  <tr className="border-t" key={denomination.value}>
                    <th className="px-4 py-2 font-normal" scope="row">{formatMoney(denomination.value)}</th>
                    <td className="px-4 py-2">
                      <input
                        aria-invalid={Boolean(quantityError)}
                        aria-label={`Cantidad de ${formatMoney(denomination.value)}`}
                        className="h-9 w-28 rounded-md border bg-background px-3 text-sm tabular-nums"
                        id={`denomination-${index}`}
                        inputMode="numeric"
                        min={0}
                        step={1}
                        type="number"
                        {...quantityField}
                        onChange={(event) => {
                          void quantityField.onChange(event);
                          const currentDenominations = watchedDenominations ?? buildFormValues(delivery).denominations;
                          const nextDenominations = currentDenominations.map((current, currentIndex) => currentIndex === index
                            ? { ...current, quantity: event.target.value }
                            : current);
                          onCashBreakdownChange(nextDenominations.map((current) => ({
                            value: current.value,
                            quantity: Math.trunc(Number(current.quantity) || 0),
                          })));
                          onCustomerWillGiveChangeChange(false);
                        }}
                      />
                      {quantityError && <p className="mt-1 text-xs text-destructive">{quantityError}</p>}
                    </td>
                    <td className="px-4 py-2 text-right tabular-nums">
                      {formatMoney(centsToAmount(toCents(denomination.value) * quantity))}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </fieldset>

      <section aria-label="Conciliación del efectivo" className="grid gap-3 rounded-md border p-4 sm:grid-cols-3">
        <Info label="Retorno actual" value={formatMoney(currentReturnAmount)} />
        <Info label="Efectivo bruto" value={formatMoney(centsToAmount(totalCents))} />
        <Info
          label="Diferencia contra retorno oficial"
          value={differenceCents === 0 ? "Cuadra" : formatMoney(centsToAmount(differenceCents))}
        />
      </section>

      <fieldset className="space-y-3 rounded-md border p-4" disabled={isSubmitting}>
        <legend className="px-2 font-medium">Importe para el documento</legend>
        <p className="text-sm text-muted-foreground">
          Elige el ajuste de centavos para la constancia. Esta selección no modifica el retorno oficial guardado.
        </p>
        <label className="grid max-w-md gap-1 text-sm font-medium" htmlFor="document-return-amount">
          Importe de entrega
          <select
            className="h-10 rounded-md border bg-background px-3 text-sm"
            id="document-return-amount"
            onChange={(event) => onDocumentReturnAmountChange(event.target.value)}
            value={documentReturnAmount}
          >
            {documentAmountOptions.map((amount) => (
              <option key={amount} value={amount}>{formatMoney(amount)}</option>
            ))}
          </select>
        </label>
        <Info label="Diferencia contra importe de entrega" value={formatMoney(centsToAmount(documentDifferenceCents))} />
        {customerChangeIsRequired && (
          <div className="space-y-2">
            <label className="flex items-start gap-2 text-sm" htmlFor="customer-will-give-change">
              <input
                checked={customerWillGiveChange}
                className="mt-1"
                id="customer-will-give-change"
                onChange={(event) => onCustomerWillGiveChangeChange(event.target.checked)}
                type="checkbox"
              />
              <span>El cliente dará cambio por {formatMoney(centsToAmount(documentDifferenceCents))}.</span>
            </label>
            <p className="text-xs text-muted-foreground">
              Esta confirmación se envía al generar Word y no se guarda en la preparación.
            </p>
          </div>
        )}
      </fieldset>

      {delivery?.is_stale && (
        <p className="rounded-md border px-4 py-3 text-sm" role="note">
          La preparación está obsoleta: el importe preparado no coincide con el retorno actual del registro. Actualiza el desglose para la misma entrega.
        </p>
      )}

      {mismatch && (
        <section className="space-y-2 rounded-md border border-destructive/40 px-4 py-3 text-sm" role="alert">
          <h2 className="font-semibold">El desglose de efectivo no coincide</h2>
          <p>Retorno esperado: <strong>{formatMoney(mismatch.expected_amount)}</strong></p>
          <p>Total de efectivo: <strong>{formatMoney(mismatch.cash_amount)}</strong></p>
          <p>Diferencia: <strong>{formatMoney(mismatch.difference)}</strong></p>
        </section>
      )}

      {(cashShortfallExceedsTolerance || emptyCashForNonzeroReturn) && !mismatch && (
        <p className="rounded-md border border-destructive/40 px-4 py-3 text-sm text-destructive" role="alert">
          El efectivo bruto no puede quedar más de $1.00 por debajo del retorno oficial. Ingresa denominaciones antes de guardar.
        </p>
      )}

      {formError && (
        <p className="rounded-md border border-destructive/40 px-4 py-3 text-sm text-destructive" role="alert">
          {formError}
        </p>
      )}

      {successMessage && (
        <p aria-live="polite" className="rounded-md border px-4 py-3 text-sm" role="status">
          {successMessage}
        </p>
      )}

      <div className="flex justify-end">
        <Button disabled={isSubmitting || cashShortfallExceedsTolerance || emptyCashForNonzeroReturn} type="submit">
          {isSubmitting ? "Guardando…" : delivery === null ? "Preparar entrega" : "Actualizar entrega"}
        </Button>
      </div>
    </form>
  );
}

function Field({
  id,
  label,
  error,
  children,
}: {
  id: string;
  label: string;
  error?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="space-y-1">
      <label className="text-sm font-medium" htmlFor={id}>{label}</label>
      {children}
      {error && <p className="text-xs text-destructive">{error}</p>}
    </div>
  );
}

function Info({ label, value }: { label: string; value: string }) {
  return (
    <div className="space-y-1">
      <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className="text-sm tabular-nums">{value}</p>
    </div>
  );
}
