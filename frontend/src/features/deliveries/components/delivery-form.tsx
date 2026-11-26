"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useState } from "react";
import { useForm, useWatch, type FieldPath } from "react-hook-form";
import { FormField, fieldErrorProps } from "@/components/form-field";
import type { RecordDetailView } from "@/features/records/types";
import { useResetOnHide } from "@/hooks/use-reset-on-hide";
import { formatMoney } from "@/lib/format";
import { cardSurface, fieldControl } from "@/lib/styles";
import { useGenerateDeliveryDocument, useUpsertDelivery } from "../hooks/use-deliveries";
import { deliveryFormSchema, type DeliveryFormValues } from "../schemas/delivery.schema";
import { DeliveryApiError, documentBlockedMessages, type Delivery, type DeliveryErrorDetails } from "../types";
import {
  buildDeliveryFormValues,
  centsToAmount,
  deliverySplitPreview,
  denominationBreakdownKey,
  documentReturnOptions,
  getCashCheck,
  getDeliveryBlocks,
  getDeliveryHeading,
  getDeliveryReadiness,
  isDeliveryClean,
  isDeliveryFormField,
  toUpsertDeliveryInput,
  type SplitError,
} from "../utils";
import { DeliveryActionBar } from "./delivery-action-bar";
import { DeliveryAmountsHero } from "./delivery-amounts-hero";
import { DeliveryBlocksBanner } from "./delivery-blocks-banner";
import { DeliveryCashBreakdown } from "./delivery-cash-breakdown";
import { DeliveryImageCard } from "./delivery-image-card";
import { DeliveryReadinessCard } from "./delivery-readiness-card";
import { DeliveryReturnSplit, type SplitAmounts } from "./delivery-return-split";
import { DeliverySectionHeader } from "./delivery-section-header";
import { DocumentAmountGroup } from "./document-amount-group";

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

const SAVE_FAILED = "No se pudo guardar la entrega. Inténtalo de nuevo.";
const DOCUMENT_AMOUNT_HEADING_ID = "delivery-document-amount-heading";

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
  const [defaultValues] = useState(() => buildDeliveryFormValues(delivery, userName, currentReturnAmount));
  const form = useForm<DeliveryFormValues>({ resolver: zodResolver(deliveryFormSchema), defaultValues });
  const [saveError, setSaveError] = useState<string | null>(null);
  const [mismatch, setMismatch] = useState<DeliveryErrorDetails | null>(null);
  const [splitServerError, setSplitServerError] = useState<SplitError | null>(null);
  const [generateError, setGenerateError] = useState<string | null>(null);
  const [generatedKey, setGeneratedKey] = useState<string | null>(null);

  // Save and generate errors describe the last attempt; a later visit starts without them.
  useResetOnHide(() => {
    setSaveError(null);
    setMismatch(null);
    setSplitServerError(null);
    setGenerateError(null);
  });

  const [
    deliveryDate,
    deliveredByName,
    receivedByName,
    requestedCashAmount,
    cashCommissionPercentage,
    watchedDenominations,
  ] = useWatch({
    control: form.control,
    name: [
      "delivery_date",
      "delivered_by_name",
      "received_by_name",
      "requested_cash_amount",
      "cash_commission_percentage",
      "denominations",
    ],
  });
  const values: DeliveryFormValues = {
    delivery_date: deliveryDate ?? "",
    delivered_by_name: deliveredByName ?? "",
    received_by_name: receivedByName ?? "",
    requested_cash_amount: requestedCashAmount ?? "",
    cash_commission_percentage: cashCommissionPercentage ?? "",
    denominations: watchedDenominations ?? defaultValues.denominations,
  };
  // Preview only: Laravel recalculates the split when saving.
  const split = deliverySplitPreview({
    returnAmount: currentReturnAmount,
    requestedCashAmount: values.requested_cash_amount,
    cashCommissionPercentage: values.cash_commission_percentage,
  });
  const documentAmountOptions = documentReturnOptions(centsToAmount(split.cashCents));
  const documentAmount = documentAmountOptions.includes(documentReturnAmount)
    ? documentReturnAmount
    : documentAmountOptions[0];

  const hasImage = record.image !== null;
  const isSaving = form.formState.isSubmitting || upsert.isPending;
  const cash = getCashCheck({
    split,
    documentAmount,
    denominations: values.denominations,
  });
  const clean = isDeliveryClean(delivery, values);
  // Once saved, the screen shows Laravel's amounts; the preview only covers unsaved edits.
  const splitAmounts: SplitAmounts = clean && delivery !== null
    ? {
      transferAmount: delivery.transfer_amount,
      cashCommission: delivery.cash_commission,
      cashAmount: delivery.cash_amount,
    }
    : {
      transferAmount: centsToAmount(Math.max(split.transferCents, 0)),
      cashCommission: centsToAmount(split.commissionCents),
      cashAmount: centsToAmount(split.cashCents),
    };
  // A generated document only counts while nothing that feeds it has changed.
  const stateKey = [
    values.delivery_date,
    values.delivered_by_name,
    values.received_by_name,
    values.requested_cash_amount,
    values.cash_commission_percentage,
    denominationBreakdownKey(values.denominations),
    documentAmount,
    String(customerWillGiveChange),
  ].join("~");
  const readiness = getDeliveryReadiness({
    hasImage,
    split,
    cash,
    changeDeclared: customerWillGiveChange,
    clean,
    missingData: values.delivery_date.trim() === "" || values.delivered_by_name.trim() === "",
    generated: generatedKey === stateKey,
  });
  const blocks = getDeliveryBlocks({ cash, hasImage, mismatch, errors: [saveError, generateError] });
  const errors = form.formState.errors;

  function handleQuantityChange(index: number, quantity: string) {
    form.setValue(`denominations.${index}.quantity`, quantity, { shouldDirty: true });
    onCustomerWillGiveChangeChange(false);
  }

  function handleRequestedCashChange(amount: string) {
    form.setValue("requested_cash_amount", amount, { shouldDirty: true });
    setSplitServerError(null);
    onCustomerWillGiveChangeChange(false);
  }

  function handlePercentageChange(percentage: string) {
    form.setValue("cash_commission_percentage", percentage, { shouldDirty: true });
    onCustomerWillGiveChangeChange(false);
  }

  function handleAllTransfer() {
    handleRequestedCashChange("0.00");
    values.denominations.forEach((_, index) => {
      form.setValue(`denominations.${index}.quantity`, "0", { shouldDirty: true });
    });
  }

  function handleDocumentAmountChange(amount: string) {
    onDocumentReturnAmountChange(amount);
    onCustomerWillGiveChangeChange(false);
  }

  function showSaveError(error: unknown) {
    if (!(error instanceof DeliveryApiError) || error.status === null) {
      setSaveError(SAVE_FAILED);
      return;
    }

    if (error.status === 409 && error.reason === "CASH_MISMATCH") {
      setMismatch(error.details);
      return;
    }

    if (error.status === 409 && error.reason === "CASH_REQUEST_EXCEEDS_RETURN") {
      // The return changed under the Editor: reload it and explain the rejection in «Forma de retorno».
      setSplitServerError({
        code: "CASH_REQUEST_EXCEEDS_RETURN",
        text: `El efectivo supera el retorno oficial actual (${formatMoney(error.details.current_return_amount)}). Reduce el efectivo; el resto va por transferencia.`,
      });
      onInvalidRecordState();
      return;
    }

    if (error.status === 409 && error.reason === "INVALID_RECORD_STATE") {
      onInvalidRecordState();
      return;
    }

    // 422: each message goes next to its field; anything without a visible field goes to the banner.
    const unmapped: string[] = [];
    for (const [field, [message]] of Object.entries(error.validationErrors)) {
      if (!message) continue;

      if (isDeliveryFormField(field)) {
        form.setError(field as FieldPath<DeliveryFormValues>, { type: "server", message });
      } else {
        unmapped.push(message);
      }
    }

    if (Object.keys(error.validationErrors).length === 0) setSaveError(error.message);
    else if (unmapped.length > 0) setSaveError(unmapped.join(" "));
  }

  async function onSubmit(submitted: DeliveryFormValues) {
    setSaveError(null);
    setMismatch(null);
    setSplitServerError(null);
    setGenerateError(null);
    form.clearErrors();

    if (!split.valid) return;

    try {
      await upsert.mutateAsync({ recordId: record.id, input: toUpsertDeliveryInput(submitted) });
    } catch (error) {
      showSaveError(error);
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
          document_return_amount: documentAmount,
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

  return (
    <form className="space-y-6" noValidate onSubmit={form.handleSubmit(onSubmit)}>
      {blocks.length > 0 && <DeliveryBlocksBanner items={blocks} />}

      <div className="@container">
        <div className="grid gap-6 @[60rem]:grid-cols-[minmax(0,1fr)_340px] @[60rem]:items-start">
          <div className="flex min-w-0 flex-col gap-6">
            <DeliveryAmountsHero
              cash={cash}
              cashAmount={splitAmounts.cashAmount}
              officialAmount={currentReturnAmount}
              transferAmount={splitAmounts.transferAmount}
            />

            <section aria-labelledby="delivery-data-heading" className={cardSurface}>
              <DeliverySectionHeader
                id="delivery-data-heading"
                subtitle="Quién entrega el efectivo y quién lo recibe."
                title="Datos de la entrega"
              />
              <fieldset
                className="grid grid-cols-[repeat(auto-fit,minmax(180px,1fr))] gap-x-5 gap-y-4 p-4 md:px-6 md:pb-6"
                disabled={isSaving}
              >
                <legend className="sr-only">Datos de la entrega</legend>
                <FormField error={errors.delivery_date?.message} id="delivery-date" label="Fecha de entrega">
                  <input
                    {...fieldErrorProps("delivery-date", errors.delivery_date?.message)}
                    className={fieldControl}
                    id="delivery-date"
                    type="date"
                    {...form.register("delivery_date")}
                  />
                </FormField>
                <FormField error={errors.delivered_by_name?.message} id="delivered-by-name" label="Entrega">
                  <input
                    {...fieldErrorProps("delivered-by-name", errors.delivered_by_name?.message)}
                    autoComplete="name"
                    className={fieldControl}
                    id="delivered-by-name"
                    maxLength={255}
                    type="text"
                    {...form.register("delivered_by_name")}
                  />
                </FormField>
                <FormField
                  badge={<OptionalBadge />}
                  error={errors.received_by_name?.message}
                  id="received-by-name"
                  label="Recibe"
                >
                  <input
                    {...fieldErrorProps("received-by-name", errors.received_by_name?.message)}
                    className={fieldControl}
                    id="received-by-name"
                    maxLength={255}
                    type="text"
                    {...form.register("received_by_name")}
                  />
                </FormField>
              </fieldset>
            </section>

            <DeliveryReturnSplit
              amounts={splitAmounts}
              cashCommissionPercentage={values.cash_commission_percentage}
              disabled={isSaving}
              officialAmount={currentReturnAmount}
              onAllTransfer={handleAllTransfer}
              onPercentageChange={handlePercentageChange}
              onRequestedCashChange={handleRequestedCashChange}
              requestedCashAmount={values.requested_cash_amount}
              serverError={splitServerError}
              split={split}
            />

            <section aria-labelledby={DOCUMENT_AMOUNT_HEADING_ID} className={cardSurface}>
              <DeliverySectionHeader
                id={DOCUMENT_AMOUNT_HEADING_ID}
                subtitle="Es el monto que imprime el recibo de Word."
                title="Monto del documento"
              />
              <div className="p-4 md:px-6 md:pb-6">
                <DocumentAmountGroup
                  disabled={isSaving}
                  labelledBy={DOCUMENT_AMOUNT_HEADING_ID}
                  onChange={handleDocumentAmountChange}
                  options={documentAmountOptions}
                  value={documentAmount}
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
        saveLabel={getDeliveryHeading(delivery).saveLabel}
      />
    </form>
  );
}

function OptionalBadge() {
  return (
    <span className="inline-flex h-5 items-center rounded-full bg-status-pending-bg px-2 text-[11px]/4 font-semibold text-status-pending">
      Opcional
    </span>
  );
}
