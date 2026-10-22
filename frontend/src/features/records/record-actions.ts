import type { RecordSource, RecordStatus } from "./types";

export type RecordActionPermissions = {
  canCorrect: boolean;
  canConfirm: boolean;
  canCancel: boolean;
  canRetryAiProcessing: boolean;
};

type RecordActionState = {
  source: RecordSource;
  status: RecordStatus;
};

const noActions: RecordActionPermissions = {
  canCorrect: false,
  canConfirm: false,
  canCancel: false,
  canRetryAiProcessing: false,
};

export function getRecordActionPermissions(
  record: RecordActionState | null,
  canEdit: boolean,
): RecordActionPermissions {
  if (!canEdit || record === null) {
    return noActions;
  }

  const inReview = record.status === "NEEDS_REVIEW";
  const inError = record.status === "ERROR";

  return {
    canCorrect: inReview,
    canConfirm: inReview,
    canCancel: inReview || inError,
    canRetryAiProcessing: inError && record.source === "IMAGE",
  };
}
