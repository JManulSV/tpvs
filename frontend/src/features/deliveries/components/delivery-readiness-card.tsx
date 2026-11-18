import { CircleCheck, Minus } from "lucide-react";
import { cn } from "@/lib/utils";
import type { ReadinessCheck } from "../utils";

export function DeliveryReadinessCard({ checks }: { checks: ReadinessCheck[] }) {
  return (
    <section aria-labelledby="delivery-readiness-heading" className="rounded-lg border bg-card shadow-card">
      <header className="px-4 pt-4 md:px-6 md:pt-[18px]">
        <h2 className="text-base/6 font-semibold" id="delivery-readiness-heading">Para generar el recibo</h2>
        <p className="mt-0.5 text-xs/4 font-medium text-muted-foreground">Debe estar todo listo.</p>
      </header>
      <ul className="flex flex-col p-4 md:px-6 md:pb-6">
        {checks.map((check) => {
          const Icon = check.ok ? CircleCheck : Minus;

          return (
            <li
              className="flex items-center gap-3 border-t py-2.5 first:border-t-0 first:pt-0 last:pb-0"
              key={check.id}
            >
              <Icon
                aria-hidden="true"
                className={cn("size-[18px] shrink-0", check.ok ? "text-status-confirmed" : "text-muted-foreground")}
              />
              <span className="min-w-0 flex-1 text-[13px]/5 font-medium">{check.label}</span>
              <span
                className={cn(
                  "inline-flex h-5 shrink-0 items-center rounded-full px-2 text-[11px]/4 font-semibold",
                  check.ok ? "bg-status-confirmed-bg text-status-confirmed" : "bg-status-pending-bg text-status-pending",
                )}
              >
                {check.ok ? "Listo" : "Pendiente"}
              </span>
            </li>
          );
        })}
      </ul>
    </section>
  );
}
