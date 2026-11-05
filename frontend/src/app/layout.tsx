import type { Metadata } from "next";
import "./globals.css";
import { JetBrains_Mono, Plus_Jakarta_Sans } from "next/font/google";
import { cn } from "@/lib/utils";
import { QueryProvider } from "@/components/providers/query-provider";
import { ThemeProvider } from "@/components/providers/theme-provider";

const plusJakarta = Plus_Jakarta_Sans({
  subsets: ["latin"],
  variable: "--font-plus-jakarta",
});
const jetBrainsMono = JetBrains_Mono({
  subsets: ["latin"],
  variable: "--font-jetbrains-mono",
});

export const metadata: Metadata = {
  title: "TPVS",
  description: "TPVS local stack",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html
      lang="es"
      suppressHydrationWarning
      className={cn(
        "h-full antialiased font-sans",
        plusJakarta.variable,
        jetBrainsMono.variable,
      )}
    >
      <body className="min-h-full flex flex-col">
        <ThemeProvider>
          <QueryProvider>{children}</QueryProvider>
        </ThemeProvider>
      </body>
    </html>
  );
}
