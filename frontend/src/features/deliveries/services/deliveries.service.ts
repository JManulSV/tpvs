import { apiClient } from "@/lib/api-client";
import {
  toDeliveryApiError,
  type Delivery,
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
};
