import { apiClient } from "@/lib/api-client";
import {
  toCompaniesApiError,
  type CompanyDetail,
  type CompanyListParams,
  type CompanyListResponse,
  type CompanyResponse,
} from "../types";

export const companiesService = {
  async list(params: CompanyListParams = {}): Promise<CompanyListResponse> {
    try {
      const response = await apiClient.get<CompanyListResponse>("companies", {
        params,
      });

      return response.data;
    } catch (error) {
      throw toCompaniesApiError(error);
    }
  },

  async get(id: number): Promise<CompanyDetail> {
    try {
      const response = await apiClient.get<CompanyResponse>(`companies/${id}`);

      return response.data.data;
    } catch (error) {
      throw toCompaniesApiError(error);
    }
  },

  async create(name: string): Promise<CompanyDetail> {
    try {
      const response = await apiClient.post<CompanyResponse>("companies", {
        name,
      });

      return response.data.data;
    } catch (error) {
      throw toCompaniesApiError(error);
    }
  },
};
