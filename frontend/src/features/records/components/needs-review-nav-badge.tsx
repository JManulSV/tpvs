"use client"

import { SidebarMenuBadge } from "@/components/ui/sidebar"
import { useRecords } from "../hooks/use-records"

type NeedsReviewNavBadgeProps = {
  variant?: "sidebar" | "bottom-nav"
}

export function NeedsReviewNavBadge({
  variant = "sidebar",
}: NeedsReviewNavBadgeProps) {
  const { data } = useRecords({ status: "NEEDS_REVIEW", per_page: 1 })
  const total = data?.meta.total

  if (total === undefined || total <= 0) {
    return null
  }

  const label = `${total} registros por revisar`

  if (variant === "bottom-nav") {
    return (
      <span
        aria-label={label}
        className="pointer-events-none absolute top-0.5 left-[calc(50%+6px)] grid h-[18px] min-w-[18px] place-items-center rounded-full bg-status-needs-review-bg px-[5px] text-[11px] font-bold leading-[14px] text-status-needs-review tabular-nums"
      >
        {total}
      </span>
    )
  }

  return (
    <SidebarMenuBadge
      aria-label={label}
      className="top-0.5 right-auto left-[18px] group-data-[collapsible=icon]:left-[calc(50%+12px)]"
    >
      {total}
    </SidebarMenuBadge>
  )
}
