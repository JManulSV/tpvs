import { redirect } from "next/navigation";
import { getAuthenticatedUser } from "@/features/auth/server/auth-session";
import { NewRecordScreen } from "@/features/records/components/new-record-screen";

export const instant = false;

export default async function NewRecordPage() {
  const user = await getAuthenticatedUser();

  if (!user) redirect("/login?returnTo=%2Frecords%2Fnew");
  if (user.role !== "EDITOR") redirect("/records");

  return <NewRecordScreen />;
}
