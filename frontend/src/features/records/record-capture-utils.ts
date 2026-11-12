import type { RecordStatus } from "@/lib/status";
import type { FieldChip } from "./types";

export const SLOW_READING_MS = 10 * 60 * 1000;

const allowedImageTypes = ["image/jpeg", "image/png", "image/webp"];
const maxImageBytes = 10 * 1024 * 1024;

// Códigos reales de ai_processing.issues (ver SPEC 06 y el paso 0 de SPEC 21).
const missingCodes = ["MISSING_VALUE", "COMPANY_NAME_MISSING"];
const inconsistentCodes = ["SOURCE_TOTAL_MISMATCH", "INVERTED_WEEK_DATE_RANGE"];

export function validateImageFile(file: Pick<File, "type" | "size">): string | null {
  if (!allowedImageTypes.includes(file.type)) {
    return "Selecciona una imagen JPEG, PNG o WebP.";
  }

  if (file.size > maxImageBytes) {
    return "La imagen supera el límite de 10 MiB.";
  }

  return null;
}

export function getUploadProgressLabel(progress: number | null): string {
  if (progress === null) return "Subiendo imagen…";

  return `Subiendo imagen… ${progress}%`;
}

export function formatElapsed(ms: number): string {
  const totalSeconds = Math.floor(ms / 1000);
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = String(totalSeconds % 60).padStart(2, "0");

  return `${minutes}:${seconds}`;
}

export type AiReadingView = {
  step: "queued" | "reading" | "ready" | "error" | "canceled";
  elapsedLabel: string | null;
  slow: boolean;
};

export function getAiReadingView(status: RecordStatus, elapsedMs: number): AiReadingView {
  switch (status) {
    case "PENDING":
      return { step: "queued", elapsedLabel: null, slow: elapsedMs >= SLOW_READING_MS };
    case "PROCESSING":
      return {
        step: "reading",
        elapsedLabel: formatElapsed(elapsedMs),
        slow: elapsedMs >= SLOW_READING_MS,
      };
    case "NEEDS_REVIEW":
      return { step: "ready", elapsedLabel: null, slow: false };
    case "ERROR":
      return { step: "error", elapsedLabel: null, slow: false };
    case "CANCELED":
      return { step: "canceled", elapsedLabel: null, slow: false };
    default:
      return { step: "error", elapsedLabel: null, slow: false };
  }
}

export function getFieldChip(input: {
  value: unknown;
  issueCodes: ReadonlyArray<string>;
  isImageRecord: boolean;
  status: RecordStatus;
}): FieldChip | null {
  if (!input.isImageRecord || input.status !== "NEEDS_REVIEW") return null;

  if (input.value === null || input.value === undefined || input.value === "") return "MISSING";
  if (input.issueCodes.some((code) => missingCodes.includes(code))) return "MISSING";
  if (input.issueCodes.some((code) => inconsistentCodes.includes(code))) return "INCONSISTENT";
  if (input.issueCodes.length > 0) return "REVIEW";

  return "HIGH_CONFIDENCE";
}

// Cuenta cambios hoja a hoja. Un movimiento agregado o quitado cuenta como 1.
export function getDirtyCount(initial: unknown, current: unknown): number {
  if (Array.isArray(initial) && Array.isArray(current)) {
    let count = 0;
    const length = Math.max(initial.length, current.length);
    for (let index = 0; index < length; index += 1) {
      if (index >= initial.length || index >= current.length) {
        count += 1;
      } else {
        count += getDirtyCount(initial[index], current[index]);
      }
    }
    return count;
  }

  if (isPlainObject(initial) && isPlainObject(current)) {
    const keys = new Set([...Object.keys(initial), ...Object.keys(current)]);
    let count = 0;
    for (const key of keys) {
      count += getDirtyCount(initial[key], current[key]);
    }
    return count;
  }

  return JSON.stringify(initial) === JSON.stringify(current) ? 0 : 1;
}

export function getSaveBarState(input: { dirtyCount: number; reason: string; canEdit: boolean }): {
  visible: boolean;
  canSave: boolean;
} {
  const visible = input.dirtyCount > 0;

  return {
    visible,
    canSave: input.canEdit && visible && input.reason.trim() !== "",
  };
}

function isPlainObject(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}
