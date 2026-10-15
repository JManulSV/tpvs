import { redirect } from "next/navigation";
import { getAuthenticatedUser } from "@/features/auth/server/auth-session";
import { RecordsScreen } from "@/features/records/components/records-screen";

export const instant = false;

export default async function RecordsPage() {
  const user = await getAuthenticatedUser();

  if (!user) redirect("/login?returnTo=%2Frecords");

  return <RecordsScreen canCreate={user.role === "EDITOR"} />;
}
