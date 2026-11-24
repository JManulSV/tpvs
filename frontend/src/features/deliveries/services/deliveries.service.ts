import { apiClient } from "@/lib/api-client";
import { decodeBlobError, fileNameFrom } from "@/lib/file-download";
import {
  toDeliveryApiError,
  type Delivery,
  type DeliveryDocument,
  type GenerateDeliveryDocumentInput,
  type DeliveryResponse,
  type UpsertDeliveryInput,
} from "../types";

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

  async generateDocument(deliveryId: number, input: GenerateDeliveryDocumentInput): Promise<DeliveryDocument> {
    try {
      const response = await apiClient.post<Blob>(`deliveries/${deliveryId}/document`, input, {
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
