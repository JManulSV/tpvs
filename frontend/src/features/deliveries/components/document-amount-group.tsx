"use client";

import { useRef, type KeyboardEvent } from "react";
import { formatMoney } from "@/lib/format";
import { focusRing } from "@/lib/styles";
import { cn } from "@/lib/utils";

const arrowStep: Record<string, 1 | -1> = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 };

type DocumentAmountGroupProps = {
  labelledBy: string;
  options: readonly string[];
  value: string;
  onChange: (amount: string) => void;
  disabled: boolean;
};

/** Radio group with roving focus: arrows move the selection, only the checked option is tabbable. */
export function DocumentAmountGroup({ labelledBy, options, value, onChange, disabled }: DocumentAmountGroupProps) {
  const buttons = useRef<Array<HTMLButtonElement | null>>([]);
  const selectedIndex = Math.max(options.indexOf(value), 0);

  function handleKeyDown(event: KeyboardEvent<HTMLButtonElement>, index: number) {
    const step = arrowStep[event.key];
    if (step === undefined) return;

    event.preventDefault();
    const next = (index + step + options.length) % options.length;
    onChange(options[next]);
    buttons.current[next]?.focus();
  }

  return (
    <div
      aria-labelledby={labelledBy}
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
