import {
  BUSINESS_TIME_ZONE,
  getZonedDateParts,
  toZonedDateString,
} from "@/lib/business-time";
import { formatDate } from "@/lib/format";
import type { RecordStatus } from "@/lib/status";

export type DashboardGreeting = "Buenos días" | "Buenas tardes" | "Buenas noches";

export type DashboardStatusCellId =
  | "needs_review"
  | "error"
  | "pending"
  | "processing"
  | "confirmed_month"
  | "canceled_month";

const cellStatus: Record<DashboardStatusCellId, RecordStatus> = {
  needs_review: "NEEDS_REVIEW",
  error: "ERROR",
  pending: "PENDING",
  processing: "PROCESSING",
  confirmed_month: "CONFIRMED",
  canceled_month: "CANCELED",
};

const monthNames = [
  "enero",
  "febrero",
  "marzo",
  "abril",
  "mayo",
  "junio",
  "julio",
  "agosto",
  "septiembre",
  "octubre",
  "noviembre",
  "diciembre",
] as const;

const MINUTE_MS = 60_000;
const HOUR_MS = 60 * MINUTE_MS;
const DAY_MS = 24 * HOUR_MS;

/** 05:00–11:59 días, 12:00–18:59 tardes, otherwise noches, in `timeZone`. */
export function getGreeting(date: Date, timeZone: string): DashboardGreeting {
  const { hour } = getZonedDateParts(date, timeZone);

  if (hour >= 5 && hour < 12) return "Buenos días";
  if (hour >= 12 && hour < 19) return "Buenas tardes";

  return "Buenas noches";
}

export function getReviewHeadline(count: number): string {
  if (count <= 0) return "No tienes registros por revisar";
  if (count === 1) return "Tienes 1 registro por revisar";

  return `Tienes ${count} registros por revisar`;
}

/** Relative time in Spanish; calendar days are evaluated in the business time zone. */
export function formatRelativeTime(iso: string, now: Date): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return iso;

  const elapsed = Math.max(0, now.getTime() - date.getTime());

  if (elapsed < MINUTE_MS) return "hace un momento";
  if (elapsed < HOUR_MS) return `hace ${Math.floor(elapsed / MINUTE_MS)} min`;
  if (elapsed < DAY_MS) return `hace ${Math.floor(elapsed / HOUR_MS)} h`;

  const day = toZonedDateString(date, BUSINESS_TIME_ZONE);
  const yesterday = toZonedDateString(new Date(now.getTime() - DAY_MS), BUSINESS_TIME_ZONE);
  if (day === yesterday) return "ayer";

  return formatDate(day);
}

export function getMonthName(month: number): string {
  return monthNames[month - 1] ?? "";
}

export function getStatusCellHref(
  cell: DashboardStatusCellId,
  period: { year: number; month: number },
): string {
  const params = new URLSearchParams({ status: cellStatus[cell] });

  if (cell === "confirmed_month" || cell === "canceled_month") {
    params.set("year", String(period.year));
    params.set("month", String(period.month));
  }

  return `/records?${params.toString()}`;
}
