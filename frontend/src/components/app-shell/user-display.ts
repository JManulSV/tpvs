export function getInitials(name: string): string {
  const words = name.trim().split(/\s+/).filter(Boolean)

  if (words.length === 0) {
    return "?"
  }

  return words
    .slice(0, 2)
    .map((word) => word[0].toLocaleUpperCase())
    .join("")
}

export function getRoleLabel(role: "EDITOR" | "VIEWER"): string {
  return role === "EDITOR" ? "Editor" : "Viewer"
}
