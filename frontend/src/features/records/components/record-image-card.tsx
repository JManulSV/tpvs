import Image from "next/image";
import { formatFileSize } from "@/lib/format";
import { buttonVariants } from "@/components/ui/button";
import { recordsService } from "../services/records.service";
import type { RecordListItem } from "../types";

function FileField({ className, label, value }: { className?: string; label: string; value: string }) {
  return (
    <div className={className}>
      <dt className="text-xs/4 font-semibold text-muted-foreground">{label}</dt>
      <dd className="text-[13px]/5 font-medium break-words">{value}</dd>
    </div>
  );
}

export function RecordImageCard({ record }: { record: RecordListItem }) {
  const { image } = record;
  const imageUrl = recordsService.imageUrl(record.id);

  return (
    <section
      aria-labelledby="record-image-heading"
      className="space-y-4 rounded-lg border bg-card p-5 shadow-card"
    >
      <h2 className="text-base/6 font-semibold" id="record-image-heading">Imagen recibida</h2>

      {record.status === "CANCELED" ? (
        <p className="text-sm text-muted-foreground">La imagen ya no está disponible.</p>
      ) : image === null ? (
        <p className="text-sm text-muted-foreground">Este registro no tiene imagen asociada.</p>
      ) : (
        <>
          <div className="overflow-hidden rounded-md border bg-muted">
            <Image
              alt={`Imagen del registro ${record.id}`}
              className="mx-auto h-auto max-h-[32rem] w-auto object-contain"
              height={1600}
              src={imageUrl}
              unoptimized
              width={1200}
            />
          </div>
          <dl className="grid grid-cols-2 gap-x-4 gap-y-3">
            <FileField className="col-span-2" label="Archivo" value={image.original_file_name} />
            <FileField label="Tipo" value={image.mime_type} />
            <FileField label="Tamaño" value={formatFileSize(image.file_size)} />
          </dl>
          <a
            className={buttonVariants({ className: "h-9 w-full", variant: "outline" })}
            href={imageUrl}
            rel="noopener noreferrer"
            target="_blank"
          >
            Ver imagen completa
          </a>
        </>
      )}
    </section>
  );
}
