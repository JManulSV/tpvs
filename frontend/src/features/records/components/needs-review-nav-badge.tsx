"use client"

import { SidebarMenuBadge } from "@/components/ui/sidebar"
import { useRecords } from "../hooks/use-records"

export function NeedsReviewNavBadge() {
  const { data } = useRecords({ status: "NEEDS_REVIEW", per_page: 1 })
  const total = data?.meta.total

  if (total === undefined || total <= 0) {
    return null
  }

  return (
    <SidebarMenuBadge aria-label={`${total} registros por revisar`}>
      {total}
    </SidebarMenuBadge>
  )
}
