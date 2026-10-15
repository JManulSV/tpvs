import { redirect } from "next/navigation";
import { getAuthenticatedUser } from "@/features/auth/server/auth-session";
import { ManualRecordForm } from "@/features/records/components/manual-record-form";

export const instant = false;

export default async function NewRecordPage() {
  const user = await getAuthenticatedUser();

  if (!user) redirect("/login?returnTo=%2Frecords%2Fnew");
  if (user.role !== "EDITOR") redirect("/records");

  return <ManualRecordForm mode="create" />;
}
