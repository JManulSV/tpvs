import type { CSSProperties } from "react";
import Link from "next/link";
import { ArrowRight } from "lucide-react";
import { Skeleton } from "@/components/ui/skeleton";
import { formatMoney } from "@/lib/format";
import type { DashboardSummary } from "../types";
import { getMonthName, getReviewHeadline } from "../utils";

// Same decorative pattern as the login hero (SPEC 17), shown from md.
const dotPatternStyle: CSSProperties = {
  backgroundImage: "radial-gradient(circle, var(--brand-sky) 1.5px, transparent 1.5px)",
  backgroundSize: "28px 28px",
  maskImage: "linear-gradient(to left, black, transparent)",
  WebkitMaskImage: "linear-gradient(to left, black, transparent)",
};

const overlineClass = "text-[11px] font-semibold uppercase leading-4 tracking-[0.08em] opacity-85";
const heroSkeletonClass = "bg-on-hero/20";

type DashboardHeroProps = {
  role: "EDITOR" | "VIEWER";
  greeting: string;
  firstName: string;
  /** Undefined while loading. */
  summary: DashboardSummary | undefined;
};

export function DashboardHero({ role, greeting, firstName, summary }: DashboardHeroProps) {
  return (
    <section
      aria-label={role === "EDITOR" ? "Resumen del día" : "Resumen"}
      aria-busy={summary === undefined}
      className="relative overflow-hidden rounded-xl bg-hero p-6 text-on-hero md:p-8"
    >
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-y-0 right-0 hidden w-[45%] opacity-35 md:block"
        style={dotPatternStyle}
      />
      <div className="relative z-10">
        {role === "EDITOR" ? (
          <EditorHeroContent firstName={firstName} greeting={greeting} summary={summary} />
        ) : (
          <ViewerHeroContent firstName={firstName} greeting={greeting} summary={summary} />
        )}
      </div>
    </section>
  );
}

type HeroContentProps = Omit<DashboardHeroProps, "role">;

function EditorHeroContent({ greeting, firstName, summary }: HeroContentProps) {
  const needsReview = summary?.status_counts?.needs_review;

  return (
    <div className="flex flex-col gap-4 md:flex-row md:flex-wrap md:items-end md:justify-between md:gap-6">
      <div className="flex max-w-[520px] flex-col gap-2 md:gap-3">
        <p className={overlineClass}>
          {greeting}, {firstName}
        </p>
        {needsReview === undefined ? (
          <Skeleton className={`h-8 w-72 max-w-full md:h-10 md:w-96 ${heroSkeletonClass}`} />
        ) : (
          <p className="text-2xl font-bold leading-8 tracking-[-0.02em] md:text-[32px] md:leading-10">
            {getReviewHeadline(needsReview)}
          </p>
        )}
      </div>
      {needsReview !== undefined && needsReview > 0 && (
        <Link
          className="inline-flex h-11 items-center gap-2 self-start rounded-full bg-card px-5 text-sm font-semibold text-primary transition-colors hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-on-hero md:h-10 md:self-auto"
          href="/records?status=NEEDS_REVIEW"
        >
          Ir a revisión
          <ArrowRight aria-hidden="true" className="size-4" />
        </Link>
      )}
    </div>
  );
}

function ViewerHeroContent({ greeting, firstName, summary }: HeroContentProps) {
  const monthName = summary ? getMonthName(summary.period.month) : null;

  return (
    <div className="flex flex-col gap-4 md:flex-row md:flex-wrap md:items-end md:justify-between md:gap-6">
      <div className="flex flex-col gap-2">
        <p className={overlineClass}>
          {greeting}, {firstName}
          {monthName && <> · Retorno acumulado de {monthName}</>}
        </p>
        {summary ? (
          <p className="text-[40px] font-bold leading-[48px] tracking-[-0.02em] tabular-nums md:text-5xl md:leading-[56px]">
            {formatMoney(summary.month_summary.return_total)}
          </p>
        ) : (
          <Skeleton className={`h-12 w-64 max-w-full md:h-14 ${heroSkeletonClass}`} />
        )}
      </div>
      <div className="flex items-baseline gap-3 border-t border-on-hero/10 pt-4 md:flex-col md:items-end md:gap-1 md:border-t-0 md:pt-0 md:text-right">
        {summary ? (
          <p className="text-[28px] font-bold leading-9 tracking-[-0.02em] tabular-nums md:text-[32px] md:leading-10">
            {summary.month_summary.confirmed_count}
          </p>
        ) : (
          <Skeleton className={`h-9 w-14 md:h-10 ${heroSkeletonClass}`} />
        )}
        <p className={overlineClass}>Registros confirmados del mes</p>
      </div>
    </div>
  );
}
