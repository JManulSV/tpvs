import { redirect } from "next/navigation";
import { getAuthenticatedUser } from "@/features/auth/server/auth-session";
import { DashboardScreen } from "@/features/dashboard/components/dashboard-screen";
import { getGreeting } from "@/features/dashboard/utils";
import { BUSINESS_TIME_ZONE } from "@/lib/business-time";

export const instant = false;

export default async function DashboardPage() {
  // Shares the layout's `/api/auth/me` call through React `cache()`.
  const user = await getAuthenticatedUser();

  if (!user) redirect("/login?returnTo=%2Fdashboard");

  // Computed on the server in the office time zone so the greeting is plain text in the HTML.
  const greeting = getGreeting(new Date(), BUSINESS_TIME_ZONE);
  const firstName = user.name.trim().split(/\s+/)[0] ?? user.name;

  return (
    <section aria-labelledby="dashboard-heading" className="flex flex-col gap-4 md:gap-6">
      <h1
        className="sr-only md:not-sr-only md:text-2xl md:font-bold md:leading-8 md:tracking-[-0.01em]"
        id="dashboard-heading"
      >
        Dashboard
      </h1>
      <DashboardScreen firstName={firstName} greeting={greeting} role={user.role} />
    </section>
  );
}
