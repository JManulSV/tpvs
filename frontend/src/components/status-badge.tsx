import { Badge } from "@/components/ui/badge";
import { recordStatusMeta, type RecordStatus } from "@/lib/status";

export function StatusBadge({ status }: { status: RecordStatus }) {
  const { className, icon: Icon, label, spin } = recordStatusMeta[status];

  return (
    <Badge className={className}>
      <Icon
        aria-hidden="true"
        className={spin ? "animate-spin motion-reduce:animate-none" : undefined}
      />
      {label}
    </Badge>
  );
}
