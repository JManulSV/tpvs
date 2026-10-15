import { apiClient } from "@/lib/api-client";
import {
  toRecordsApiError,
  type ManualRecordInput,
  type RecordDetailView,
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

  async update(id: number, input: UpdateManualRecordInput): Promise<RecordDetailView> {
    try {
      const response = await apiClient.put<RecordResponse>(`records/${id}`, input);
      return response.data.data;
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
};
