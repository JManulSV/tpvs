export function DeliverySectionHeader({ id, title, subtitle }: { id: string; title: string; subtitle: string }) {
  return (
    <header className="px-4 pt-4 md:px-6 md:pt-[18px]">
      <h2 className="text-base/6 font-semibold" id={id}>
        {title}
      </h2>
      <p className="mt-0.5 text-xs/4 font-medium text-muted-foreground">{subtitle}</p>
    </header>
  );
}
