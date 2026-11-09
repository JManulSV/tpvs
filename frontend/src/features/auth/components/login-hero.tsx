import type { CSSProperties } from "react";
import { Banknote, ClipboardCheck, ImageIcon, type LucideIcon } from "lucide-react";

type HeroStep = {
  icon: LucideIcon;
  title: string;
  description: string;
};

const steps: HeroStep[] = [
  {
    icon: ImageIcon,
    title: "Recibe la imagen",
    description: "Cada registro llega con la imagen que envía el proveedor.",
  },
  {
    icon: ClipboardCheck,
    title: "Revisa la información",
    description: "Información extraída automáticamente. Revisa antes de confirmar.",
  },
  {
    icon: Banknote,
    title: "Confirma y prepara la entrega",
    description: "Con el registro confirmado, prepara la entrega de efectivo.",
  },
];

const dotPatternStyle: CSSProperties = {
  backgroundImage:
    "radial-gradient(circle, var(--brand-sky) 1.5px, transparent 1.5px)",
  backgroundSize: "28px 28px",
  maskImage: "linear-gradient(to left, black, transparent)",
  WebkitMaskImage: "linear-gradient(to left, black, transparent)",
};

export function LoginHero() {
  return (
    <section
      aria-label="Presentación"
      className="relative hidden flex-1 basis-[560px] flex-col justify-between gap-12 overflow-hidden bg-hero p-14 text-on-hero lg:flex"
    >
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-y-0 right-0 w-[42%] opacity-35"
        style={dotPatternStyle}
      />

      <div className="relative z-10 flex flex-col gap-4">
        <p className="text-[11px] font-semibold uppercase tracking-[0.08em] opacity-85">
          Plataforma interna
        </p>
        <p className="max-w-[400px] text-[32px] font-bold leading-10 tracking-[-0.02em]">
          Revisa y confirma los registros de tus proveedores
        </p>
        <p className="text-sm">
          Recibe imágenes, valida la información y prepara las entregas de
          efectivo desde un solo lugar.
        </p>

        <p className="mt-6 text-[11px] font-semibold uppercase tracking-[0.08em] opacity-85">
          Cómo funciona
        </p>
        <ol className="flex max-w-[380px] flex-col gap-6">
          {steps.map(({ icon: Icon, title, description }) => (
            <li className="flex items-start gap-4" key={title}>
              <span className="flex size-11 shrink-0 items-center justify-center rounded-md bg-brand-sky text-hero">
                <Icon aria-hidden="true" className="size-5" />
              </span>
              <div className="flex flex-col gap-1">
                <p className="text-base font-semibold">{title}</p>
                <p className="text-sm">{description}</p>
              </div>
            </li>
          ))}
        </ol>
      </div>

      <p className="relative z-10 text-xs font-medium opacity-85">TPVS</p>
    </section>
  );
}
