const currencyFormatter = new Intl.NumberFormat("es-MX", {
  style: "currency",
  currency: "MXN",
});

export function toCents(amount: string | number | null | undefined): number {
  if (amount === null || amount === undefined || amount === "") {
    return 0;
  }

  const normalized = String(amount).trim();

  if (!/^-?\d+(?:\.\d{1,2})?$/.test(normalized)) {
    return 0;
  }

  const negative = normalized.startsWith("-");
  const [whole, fraction = ""] = normalized.replace(/^[+-]/, "").split(".");
  const cents = Number(whole) * 100 + Number(fraction.padEnd(2, "0").slice(0, 2));

  return negative ? -cents : cents;
}

export function centsToAmount(cents: number): string {
  const sign = cents < 0 ? "-" : "";
  const absolute = Math.abs(Math.trunc(cents));

  return `${sign}${Math.floor(absolute / 100)}.${String(absolute % 100).padStart(2, "0")}`;
}

export function formatMoney(amount: string | number | null | undefined): string {
  if (amount === null || amount === undefined || amount === "") {
    return "—";
  }

  return currencyFormatter.format(Number(amount));
}

export function denominationsTotalCents(
  denominations: Array<{ value: string; quantity: number | string }>,
): number {
  return denominations.reduce((total, denomination) => {
    const quantity = Math.trunc(Number(denomination.quantity) || 0);

    return total + toCents(denomination.value) * Math.max(quantity, 0);
  }, 0);
}
