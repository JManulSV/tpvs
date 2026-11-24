import { CircleX } from "lucide-react";
import { cn } from "@/lib/utils";
import type { DeliveryBlock } from "../utils";

export function DeliveryBlocksBanner({ items }: { items: DeliveryBlock[] }) {
  return (
    <div
      className="flex items-start gap-3 rounded-md bg-status-error-bg px-4 py-3.5 text-[13px]/5 font-medium text-status-error"
      role="alert"
    >
      <CircleX aria-hidden="true" className="mt-px size-5 shrink-0" />
      <ul className={cn("flex flex-col gap-1.5", items.length > 1 && "list-disc pl-[18px]")}>
        {items.map((item) => (
          <li key={item.text}>
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
