import {
  Ban,
  CircleAlert,
  CircleCheck,
  CircleX,
  Clock,
  LoaderCircle,
  type LucideIcon,
} from "lucide-react";

export const recordStatuses = [
  "PENDING",
  "PROCESSING",
  "NEEDS_REVIEW",
  "ERROR",
  "CONFIRMED",
  "CANCELED",
] as const;

export type RecordStatus = (typeof recordStatuses)[number];

type RecordStatusMeta = {
  label: string;
  icon: LucideIcon;
  className: string;
  spin?: boolean;
};

export const recordStatusMeta: Record<RecordStatus, RecordStatusMeta> = {
  PENDING: {
    label: "Pendiente",
    icon: Clock,
    className: "bg-status-pending-bg text-status-pending",
  },
  PROCESSING: {
    label: "Procesando",
    icon: LoaderCircle,
    className: "bg-status-processing-bg text-status-processing",
    spin: true,
  },
  NEEDS_REVIEW: {
    label: "Por revisar",
    icon: CircleAlert,
    className: "bg-status-needs-review-bg text-status-needs-review",
  },
  ERROR: {
    label: "Error",
    icon: CircleX,
    className: "bg-status-error-bg text-status-error",
  },
  CONFIRMED: {
    label: "Confirmado",
    icon: CircleCheck,
    className: "bg-status-confirmed-bg text-status-confirmed",
  },
  CANCELED: {
    label: "Cancelado",
    icon: Ban,
    className: "bg-status-canceled-bg text-status-canceled",
  },
};
