import { CircleAlert, Info } from "lucide-react";
import type { RecordBanner } from "../record-detail-utils";

const toneClass: Record<RecordBanner["tone"], string> = {
  info: "bg-accent text-accent-foreground",
  review: "bg-status-needs-review-bg text-status-needs-review",
  cancel: "bg-status-canceled-bg text-status-canceled",
  error: "bg-status-error-bg text-status-error",
};

export function RecordDetailBanner({ banner }: { banner: RecordBanner }) {
  const Icon = banner.tone === "info" ? Info : CircleAlert;

  return (
    <div
      className={`flex items-start gap-3 rounded-lg px-4 py-3.5 text-[13px]/5 ${toneClass[banner.tone]}`}
      role={banner.tone === "error" ? "alert" : "status"}
    >
      <Icon aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
      <p>
        <span className="font-bold">{banner.title}</span>
        {banner.text !== null && <span className="font-medium"> {banner.text}</span>}
      </p>
    </div>
  );
}
