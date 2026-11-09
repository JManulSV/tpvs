/** Office time zone. Must match `config('tpvs.business_timezone')` in Laravel. */
export const BUSINESS_TIME_ZONE = "America/Mexico_City";

export type ZonedDateParts = {
  year: number;
  month: number;
  day: number;
  hour: number;
  minute: number;
};

const partsFormatters = new Map<string, Intl.DateTimeFormat>();

function partsFormatter(timeZone: string): Intl.DateTimeFormat {
  let formatter = partsFormatters.get(timeZone);
  if (!formatter) {
    formatter = new Intl.DateTimeFormat("en-US", {
      timeZone,
      year: "numeric",
      month: "numeric",
      day: "numeric",
      hour: "numeric",
      minute: "numeric",
      hourCycle: "h23",
    });
    partsFormatters.set(timeZone, formatter);
  }

  return formatter;
}

/** Calendar date and wall-clock time of `date` in `timeZone`, independent of the runtime zone. */
export function getZonedDateParts(date: Date, timeZone: string = BUSINESS_TIME_ZONE): ZonedDateParts {
  const values: Partial<Record<Intl.DateTimeFormatPartTypes, number>> = {};
  for (const part of partsFormatter(timeZone).formatToParts(date)) {
    if (part.type !== "literal") values[part.type] = Number(part.value);
  }

  return {
    year: values.year ?? 0,
    month: values.month ?? 0,
    day: values.day ?? 0,
    hour: values.hour ?? 0,
    minute: values.minute ?? 0,
  };
}

/** `Y-m-d` of `date` in `timeZone`. */
export function toZonedDateString(date: Date, timeZone: string = BUSINESS_TIME_ZONE): string {
  const { year, month, day } = getZonedDateParts(date, timeZone);

  return `${year}-${String(month).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
}
