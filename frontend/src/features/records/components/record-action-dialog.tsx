"use client";

import { useEffect, useRef, type ReactNode } from "react";
import { Button } from "@/components/ui/button";

type RecordActionDialogProps = {
  children?: ReactNode;
  confirmDisabled?: boolean;
  confirmLabel: string;
  description: string;
  destructive?: boolean;
  error?: string | null;
  isPending: boolean;
  onClose: () => void;
  onConfirm: () => void;
  title: string;
};

export function RecordActionDialog({
  children,
  confirmDisabled = false,
  confirmLabel,
  description,
  destructive = false,
  error,
  isPending,
  onClose,
  onConfirm,
  title,
}: RecordActionDialogProps) {
  const dialogRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const element = dialogRef.current?.querySelector<HTMLElement>("[data-autofocus]")
      ?? dialogRef.current?.querySelector<HTMLElement>("button");
    element?.focus();
  }, []);

  useEffect(() => {
    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === "Escape" && !isPending) {
        onClose();
      }
    }

    document.addEventListener("keydown", handleKeyDown);
    return () => document.removeEventListener("keydown", handleKeyDown);
  }, [isPending, onClose]);

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget && !isPending) {
          onClose();
        }
      }}
    >
      <div
        aria-describedby="record-action-dialog-description"
        aria-labelledby="record-action-dialog-title"
        aria-modal="true"
        className="w-full max-w-lg space-y-4 rounded-lg border bg-background p-5 shadow-lg"
        ref={dialogRef}
        role="dialog"
      >
        <div className="space-y-1">
          <h2 className="text-lg font-semibold" id="record-action-dialog-title">{title}</h2>
          <p className="text-sm text-muted-foreground" id="record-action-dialog-description">{description}</p>
        </div>

        {children}

        {error && <p className="rounded-md border border-destructive/40 px-4 py-3 text-sm text-destructive" role="alert">{error}</p>}

        <div className="flex flex-wrap justify-end gap-3">
          <Button disabled={isPending} onClick={onClose} type="button" variant="outline">Volver</Button>
          <Button disabled={isPending || confirmDisabled} onClick={onConfirm} type="button" variant={destructive ? "destructive" : "default"}>
            {isPending ? "Procesando…" : confirmLabel}
          </Button>
        </div>
      </div>
    </div>
  );
}
