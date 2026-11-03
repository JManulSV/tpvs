import axios from "axios";

/** With `responseType: "blob"` error bodies also arrive as a Blob; decode them so error mappers can read them. */
export async function decodeBlobError(error: unknown): Promise<unknown> {
  if (!axios.isAxiosError(error) || !(error.response?.data instanceof Blob)) {
    return error;
  }

  try {
    error.response.data = JSON.parse(await error.response.data.text());
  } catch {
    error.response.data = {};
  }

  return error;
}

export function fileNameFrom(contentDisposition: unknown, fallback: string): string {
  if (typeof contentDisposition !== "string") {
    return fallback;
  }

  const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(contentDisposition);

  return match?.[1] ? decodeURIComponent(match[1]) : fallback;
}

export function downloadBlob(blob: Blob, fileName: string): void {
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");

  link.href = url;
  link.download = fileName;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}
