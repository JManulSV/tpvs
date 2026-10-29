import axios from "axios";
import { apiClient } from "@/lib/api-client";
import {
  toDeliveryApiError,
  type Delivery,
  type DeliveryDocument,
  type DeliveryResponse,
  type UpsertDeliveryInput,
} from "../types";

/** With `responseType: "blob"` error bodies also arrive as a Blob; decode them so the error mapper can read them. */
async function decodeBlobError(error: unknown): Promise<unknown> {
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

function fileNameFrom(contentDisposition: unknown, fallback: string): string {
  if (typeof contentDisposition !== "string") {
    return fallback;
  }

  const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(contentDisposition);

  return match?.[1] ? decodeURIComponent(match[1]) : fallback;
}

export const deliveriesService = {
  async get(recordId: number): Promise<Delivery | null> {
    try {
      const response = await apiClient.get<DeliveryResponse>(`records/${recordId}/delivery`);
      return response.data.data;
    } catch (error) {
      throw toDeliveryApiError(error);
    }
  },

  async upsert(recordId: number, input: UpsertDeliveryInput): Promise<Delivery> {
    try {
      const response = await apiClient.put<DeliveryResponse>(`records/${recordId}/delivery`, input);

      if (response.data.data === null) {
        throw new Error("The delivery response is empty.");
      }

      return response.data.data;
    } catch (error) {
      throw toDeliveryApiError(error);
    }
  },

  async generateDocument(deliveryId: number): Promise<DeliveryDocument> {
    try {
      const response = await apiClient.post<Blob>(`deliveries/${deliveryId}/document`, null, {
        responseType: "blob",
      });

      return {
        blob: response.data,
        fileName: fileNameFrom(response.headers["content-disposition"], `entrega-${deliveryId}.docx`),
      };
    } catch (error) {
      throw toDeliveryApiError(await decodeBlobError(error));
    }
  },
};
