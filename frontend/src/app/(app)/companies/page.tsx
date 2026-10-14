import { redirect } from "next/navigation";
import { CompaniesScreen } from "@/features/companies/components/companies-screen";
import { getAuthenticatedUser } from "@/features/auth/server/auth-session";

export const instant = false;

export default async function CompaniesPage() {
  const user = await getAuthenticatedUser();

  if (!user) redirect("/login?returnTo=%2Fcompanies");

  return <CompaniesScreen canCreate={user.role === "EDITOR"} />;
}
