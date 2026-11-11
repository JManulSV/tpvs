import { describe, expect, it } from "vitest"

import {
  appNavItems,
  isNavItemActive,
  shouldShowNewRecordFab,
} from "@/components/app-shell/nav-items"
import {
  getInitials,
  getRoleLabel,
} from "@/components/app-shell/user-display"

describe("app shell navigation", () => {
  it("defines the application links in the expected order", () => {
    expect(appNavItems.map(({ label }) => label)).toEqual([
      "Dashboard",
      "Registros",
      "Empresas",
      "Reportes",
    ])
  })

  it("activates a link on its route and nested routes only", () => {
    expect(isNavItemActive("/records", "/records")).toBe(true)
    expect(isNavItemActive("/records/new", "/records")).toBe(true)
    expect(isNavItemActive("/records/12/delivery", "/records")).toBe(true)
    expect(isNavItemActive("/recordsx", "/records")).toBe(false)
    expect(isNavItemActive("/companies", "/records")).toBe(false)
  })
})

describe("new record FAB visibility", () => {
  it("shows for editors on the dashboard and the records list only", () => {
    expect(shouldShowNewRecordFab("/dashboard", "EDITOR")).toBe(true)
    expect(shouldShowNewRecordFab("/records", "EDITOR")).toBe(true)
    expect(shouldShowNewRecordFab("/records/new", "EDITOR")).toBe(false)
    expect(shouldShowNewRecordFab("/records/7", "EDITOR")).toBe(false)
    expect(shouldShowNewRecordFab("/companies", "EDITOR")).toBe(false)
    expect(shouldShowNewRecordFab("/reports", "EDITOR")).toBe(false)
  })

  it("never shows for viewers", () => {
    expect(shouldShowNewRecordFab("/dashboard", "VIEWER")).toBe(false)
    expect(shouldShowNewRecordFab("/records", "VIEWER")).toBe(false)
  })
})

describe("app shell user display", () => {
  it("gets initials from the first two name words", () => {
    expect(getInitials("María López")).toBe("ML")
    expect(getInitials("Ana")).toBe("A")
    expect(getInitials("  María   del Carmen López ")).toBe("MD")
    expect(getInitials("")).toBe("?")
    expect(getInitials("   ")).toBe("?")
  })

  it("returns localized role labels", () => {
    expect(getRoleLabel("EDITOR")).toBe("Editor")
    expect(getRoleLabel("VIEWER")).toBe("Viewer")
  })
})
