import { redirect } from "next/navigation";
import { getAuthenticatedUser } from "@/features/auth/server/auth-session";
import { RecordDetailScreen } from "@/features/records/components/record-detail-screen";

export const instant = false;

export default async function RecordDetailPage({
  params,
}: {
  params: Promise<{ recordId: string }>;
}) {
  const user = await getAuthenticatedUser();
  if (!user) redirect("/login?returnTo=%2Frecords");

  const { recordId } = await params;
  const id = Number(recordId);
  if (!Number.isInteger(id) || id < 1) redirect("/records");

  return <RecordDetailScreen canEdit={user.role === "EDITOR"} recordId={id} />;
}
