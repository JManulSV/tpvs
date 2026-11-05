"use client";

import { Moon, Sun } from "lucide-react";
import { useTheme } from "next-themes";
import { useSyncExternalStore } from "react";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

function subscribeToHydration(onStoreChange: () => void) {
  const timer = window.setTimeout(onStoreChange, 0);
  return () => window.clearTimeout(timer);
}

function getHydrationSnapshot() {
  return true;
}

function getServerHydrationSnapshot() {
  return false;
}

export function ThemeToggle({ className }: { className?: string }) {
  const { resolvedTheme, setTheme } = useTheme();
  const isHydrated = useSyncExternalStore(
    subscribeToHydration,
    getHydrationSnapshot,
    getServerHydrationSnapshot,
  );

  const isDark = resolvedTheme === "dark";

  return (
    <Button
      aria-label={
        isHydrated
          ? isDark
            ? "Cambiar a tema claro"
            : "Cambiar a tema oscuro"
          : "Cargando tema"
      }
      className={cn("h-10 w-10 rounded-full", className)}
      disabled={!isHydrated}
      onClick={() => setTheme(isDark ? "light" : "dark")}
      size="icon"
      type="button"
      variant="ghost"
    >
      {isHydrated ? (
        isDark ? (
          <Sun aria-hidden="true" className="size-5" />
        ) : (
          <Moon aria-hidden="true" className="size-5" />
        )
      ) : null}
    </Button>
  );
}
