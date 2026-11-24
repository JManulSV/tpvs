"use client";

import { useCallback, useRef, useState, type RefObject } from "react";
import { Dialog, DialogContent } from "@/components/ui/dialog";
import { Sheet, SheetContent } from "@/components/ui/sheet";
import { useIsMobile } from "@/hooks/use-mobile";
import type { CompanyDetail } from "../types";
import { CreateCompanyForm } from "./create-company-form";

type CreateCompanyContainerProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onCreated: (company: CompanyDetail) => void;
  /** Button that opened the form; focus returns to it on close. */
  openerRef: RefObject<HTMLElement | null>;
};

/** `Dialog` from `md`, bottom `Sheet` below it. It cannot be dismissed while a request is pending. */
export function CreateCompanyContainer({ open, onOpenChange, onCreated, openerRef }: CreateCompanyContainerProps) {
  const isMobile = useIsMobile();
  const nameInputRef = useRef<HTMLInputElement | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const handleSubmittingChange = useCallback((submitting: boolean) => setIsSubmitting(submitting), []);

  function handleOpenChange(next: boolean) {
    if (!next && isSubmitting) return;
    onOpenChange(next);
  }

  const form = (
    <CreateCompanyForm
      inputRef={nameInputRef}
      layout={isMobile ? "sheet" : "dialog"}
      onCancel={() => onOpenChange(false)}
      onCreated={onCreated}
      onSubmittingChange={handleSubmittingChange}
    />
  );

  if (isMobile) {
    return (
      <Sheet disablePointerDismissal={isSubmitting} onOpenChange={handleOpenChange} open={open}>
        <SheetContent
          className="gap-5 rounded-t-xl px-5 pt-3 pb-[calc(1.5rem+env(safe-area-inset-bottom))]"
          finalFocus={openerRef}
          initialFocus={nameInputRef}
          showCloseButton={false}
          side="bottom"
        >
          <span aria-hidden="true" className="h-1 w-10 self-center rounded-full bg-border" />
          {form}
        </SheetContent>
      </Sheet>
    );
  }

  return (
    <Dialog disablePointerDismissal={isSubmitting} onOpenChange={handleOpenChange} open={open}>
      <DialogContent
        className="max-w-[calc(100%-2rem)] gap-5 rounded-xl p-6 sm:max-w-[440px]"
        finalFocus={openerRef}
        initialFocus={nameInputRef}
        showCloseButton={false}
      >
        {form}
      </DialogContent>
    </Dialog>
  );
}
