import { apiClient } from "@/lib/api-client";
import {
  toRecordsApiError,
  type CancelRecordInput,
  type ManualRecordInput,
  type RecordDetailView,
  type RecordHistoryParams,
  type RecordHistoryResponse,
  type RecordListParams,
  type RecordListResponse,
  type RecordResponse,
  type UpdateManualRecordInput,
} from "../types";

export const recordsService = {
  async list(params: RecordListParams = {}): Promise<RecordListResponse> {
    try {
      const response = await apiClient.get<RecordListResponse>("records", { params });
      return response.data;
    } catch (error) {
      throw toRecordsApiError(error);
    }
  },

  async get(id: number): Promise<RecordDetailView> {
    try {
      const response = await apiClient.get<RecordResponse>(`records/${id}`);
      return response.data.data;
    } catch (error) {
      throw toRecordsApiError(error);
    }
  },

  async create(input: ManualRecordInput): Promise<RecordDetailView> {
    try {
      const response = await apiClient.post<RecordResponse>("records", input);
      return response.data.data;
    } catch (error) {
      throw toRecordsApiError(error);
    }
  },

  async createFromImage(
    file: File,
    options: { onProgress?: (percent: number) => void; signal?: AbortSignal } = {},
  ): Promise<RecordDetailView> {
    try {
      const formData = new FormData();
      formData.append("image", file);
      const response = await apiClient.post<RecordResponse>("records/from-image", formData, {
        signal: options.signal,
        onUploadProgress: (event) => {
          const total = event.total ?? file.size;
          options.onProgress?.(Math.round((event.loaded * 100) / total));
        },
      });
      return response.data.data;
    } catch (error) {
      throw toRecordsApiError(error);
    }
  },

  async update(id: number, input: UpdateManualRecordInput): Promise<RecordDetailView> {
    try {
      const response = await apiClient.put<RecordResponse>(`records/${id}`, input);
      return response.data.data;
    } catch (error) {
      throw toRecordsApiError(error);
    }
  },

  async getHistory(id: number, params: RecordHistoryParams = {}): Promise<RecordHistoryResponse> {
    try {
      const response = await apiClient.get<RecordHistoryResponse>(`records/${id}/history`, { params });
      return response.data;
    } catch (error) {
      throw toRecordsApiError(error);
    }
  },

  async confirm(id: number): Promise<RecordDetailView> {
    try {
      const response = await apiClient.post<RecordResponse>(`records/${id}/confirm`, {});
      return response.data.data;
    } catch (error) {
      throw toRecordsApiError(error);
    }
  },

  async cancel(id: number, input: CancelRecordInput): Promise<RecordDetailView> {
    try {
      const response = await apiClient.post<RecordResponse>(`records/${id}/cancel`, input);
      return response.data.data;
    } catch (error) {
      throw toRecordsApiError(error);
    }
  },

  async retryAiProcessing(id: number): Promise<RecordDetailView> {
    try {
      const response = await apiClient.post<RecordResponse>(`records/${id}/ai-processing/retry`, {});
      return response.data.data;
    } catch (error) {
      throw toRecordsApiError(error);
    }
  },

  imageUrl(id: number): string {
    return `/api/records/${id}/image`;
  },
};
