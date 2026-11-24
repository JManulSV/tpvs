import { CircleAlert, CircleCheck, CircleX } from "lucide-react";
import { formatMoney } from "@/lib/format";
import { cn } from "@/lib/utils";
import { centsToAmount, type CashCheck } from "../utils";

const toneIcon = { ok: CircleCheck, excess: CircleAlert, short: CircleX } as const;

const toneClass: Record<CashCheck["tone"], string> = {
  ok: "bg-status-confirmed-bg text-status-confirmed",
  excess: "bg-status-needs-review-bg text-status-needs-review",
  short: "bg-status-error-bg text-status-error",
};

const eyebrow = "text-[11px]/4 font-semibold tracking-[0.08em] text-on-hero/70 uppercase";

type DeliveryAmountsHeroProps = {
  officialAmount: string;
  documentReturnAmount: string;
  cash: CashCheck;
};

export function DeliveryAmountsHero({ officialAmount, documentReturnAmount, cash }: DeliveryAmountsHeroProps) {
  const CheckIcon = toneIcon[cash.tone];

  return (
    <section
      aria-label="Montos de la entrega"
      className="grid gap-4 rounded-xl bg-hero px-5 py-6 text-on-hero md:grid-cols-[minmax(0,1fr)_auto] md:items-end md:gap-x-10 md:gap-y-5 md:px-8 md:py-7"
    >
      <div>
        <span className={cn("block", eyebrow)}>Retorno oficial</span>
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
          toneClass[cash.tone],
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
      <dt className={eyebrow}>{label}</dt>
      <dd className="mt-1 text-xl/7 font-bold tabular-nums">{value}</dd>
    </div>
  );
}
