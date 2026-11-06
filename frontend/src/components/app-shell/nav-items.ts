import {
  Building2,
  ChartColumn,
  FileText,
  LayoutDashboard,
  type LucideIcon,
} from "lucide-react"

export type AppNavItem = {
  id: "dashboard" | "records" | "companies" | "reports"
  label: string
  href: string
  icon: LucideIcon
}

export const appNavItems: readonly AppNavItem[] = [
  {
    id: "dashboard",
    label: "Dashboard",
    href: "/dashboard",
    icon: LayoutDashboard,
  },
  {
    id: "records",
    label: "Registros",
    href: "/records",
    icon: FileText,
  },
  {
    id: "companies",
    label: "Empresas",
    href: "/companies",
    icon: Building2,
  },
  {
    id: "reports",
    label: "Reportes",
    href: "/reports",
    icon: ChartColumn,
  },
]

export function isNavItemActive(pathname: string, href: string): boolean {
  return pathname === href || pathname.startsWith(`${href}/`)
}
