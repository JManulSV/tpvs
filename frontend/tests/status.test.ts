import {
  Ban,
  CircleAlert,
  CircleCheck,
  CircleX,
  Clock,
  LoaderCircle,
} from "lucide-react";
import { describe, expect, it } from "vitest";
import { recordStatuses, recordStatusMeta } from "@/lib/status";

describe("recordStatusMeta", () => {
  it("defines metadata for the six record statuses in canonical order", () => {
    expect(recordStatuses).toEqual([
      "PENDING",
      "PROCESSING",
      "NEEDS_REVIEW",
      "ERROR",
      "CONFIRMED",
      "CANCELED",
    ]);
    expect(Object.keys(recordStatusMeta)).toEqual(recordStatuses);
  });

  it.each([
    ["PENDING", "Pendiente", Clock, "bg-status-pending-bg text-status-pending"],
    ["PROCESSING", "Procesando", LoaderCircle, "bg-status-processing-bg text-status-processing"],
    ["NEEDS_REVIEW", "Por revisar", CircleAlert, "bg-status-needs-review-bg text-status-needs-review"],
    ["ERROR", "Error", CircleX, "bg-status-error-bg text-status-error"],
    ["CONFIRMED", "Confirmado", CircleCheck, "bg-status-confirmed-bg text-status-confirmed"],
    ["CANCELED", "Cancelado", Ban, "bg-status-canceled-bg text-status-canceled"],
  ] as const)("maps %s to its label, icon, and complete token classes", (status, label, icon, className) => {
    expect(recordStatusMeta[status]).toMatchObject({ label, icon, className });
  });

  it("marks only PROCESSING for a spinning icon", () => {
    expect(recordStatusMeta.PROCESSING.spin).toBe(true);
    expect(
      recordStatuses.filter((status) => recordStatusMeta[status].spin === true),
    ).toEqual(["PROCESSING"]);
  });
});
