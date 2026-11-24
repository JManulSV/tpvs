import Link from "next/link";
import type { ReactNode } from "react";
import { Button, buttonVariants } from "@/components/ui/button";
import { cardSurface } from "@/lib/styles";
import { cn } from "@/lib/utils";

type LoadErrorProps = {
  message: string;
  onRetry: () => void;
  /** Page-level failure: rendered as the screen's `<h1>`. */
  title?: string;
  back?: { href: string; label: string };
  children?: ReactNode;
};

/** Recoverable load failure with «Reintentar». */
export function LoadError({ message, onRetry, title, back, children }: LoadErrorProps) {
  const actionSize = "min-h-11 md:min-h-10";

  return (
    <section className={cn(cardSurface, "flex flex-col items-center gap-4 p-6 text-center")} role="alert">
      {title && <h1 className="text-xl/7 font-semibold">{title}</h1>}
      <p className={title ? "text-sm text-muted-foreground" : undefined}>{message}</p>
      {children}
      <div className="flex flex-wrap justify-center gap-3">
        <Button className={actionSize} onClick={onRetry} type="button" variant="outline">
          Reintentar
        </Button>
        {back && (
          <Link className={buttonVariants({ className: actionSize, variant: "outline" })} href={back.href}>
            {back.label}
          </Link>
        )}
      </div>
    </section>
  );
}
