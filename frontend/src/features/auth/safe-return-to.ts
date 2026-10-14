const defaultReturnTo = "/dashboard";

export function getSafeReturnTo(value: string | string[] | undefined): string {
  if (typeof value !== "string" || !value.startsWith("/")) {
    return defaultReturnTo;
  }

  if (value.startsWith("//") || value.includes("\\") || /[\u0000-\u001f]/.test(value)) {
    return defaultReturnTo;
  }

  try {
    const parsed = new URL(value, "http://tpvs.local");
    if (parsed.origin !== "http://tpvs.local" || parsed.pathname === "/login") {
      return defaultReturnTo;
    }

    return `${parsed.pathname}${parsed.search}${parsed.hash}`;
  } catch {
    return defaultReturnTo;
  }
}
