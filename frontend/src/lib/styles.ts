import { cn } from "@/lib/utils";

/** 2 px keyboard focus ring with a 2 px offset, required on every custom control. */
export const focusRing = "focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring";

/** Base surface for content cards. */
export const cardSurface = "rounded-lg border bg-card shadow-card";

/** Distance from the bottom edge that keeps mobile fixed elements above `AppBottomNav`. */
export const aboveBottomNav = "bottom-[calc(5.25rem+env(safe-area-inset-bottom))]";

/** Mobile floating action button (pill, primary), hidden from `md`. */
export const floatingActionButton = cn(
  "fixed right-4 z-30 inline-flex h-12 items-center gap-2 rounded-full bg-primary px-5 md:hidden",
  "text-sm font-semibold text-primary-foreground shadow-popover transition-colors hover:bg-primary/90",
  aboveBottomNav,
  focusRing,
);

/** Text input, select and date control: 48 px on mobile, 40 px from `md`. */
export const fieldControl = cn(
  "h-12 w-full rounded-sm border border-input bg-card px-3 text-sm/5 font-medium md:h-10",
  "aria-invalid:border-destructive disabled:cursor-not-allowed disabled:opacity-50",
  focusRing,
);

/** Multi-line text, such as a required reason. */
export const textareaControl = cn(
  "min-h-20 w-full rounded-sm border border-input bg-card px-3 py-2 text-sm/5",
  "aria-invalid:border-destructive disabled:cursor-not-allowed disabled:opacity-50",
  focusRing,
);
