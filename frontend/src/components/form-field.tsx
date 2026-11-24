import type { ReactNode } from "react";

type FormFieldProps = {
  /** Id of the control; the error text uses `${id}-error`. */
  id: string;
  label: string;
  badge?: ReactNode;
  error?: string;
  children: ReactNode;
};

/** Label, control and error text. The control sets `aria-describedby` with `fieldErrorId(id)`. */
export function FormField({ id, label, badge, error, children }: FormFieldProps) {
  return (
    <div className="flex min-w-0 flex-col gap-1.5">
      <label className="flex flex-wrap items-center gap-1.5 text-xs/4 font-semibold text-muted-foreground" htmlFor={id}>
        {label}
        {badge}
      </label>
      {children}
      {error && (
        <p className="text-xs text-destructive" id={fieldErrorId(id)}>
          {error}
        </p>
      )}
    </div>
  );
}

export function fieldErrorId(id: string): string {
  return `${id}-error`;
}

/** ARIA props for a control whose `FormField` may show an error. */
export function fieldErrorProps(id: string, error: string | undefined) {
  return {
    "aria-invalid": Boolean(error),
    "aria-describedby": error ? fieldErrorId(id) : undefined,
  };
}
