import {
  useMutation,
  useQuery,
  useQueryClient,
} from "@tanstack/react-query";
import { companiesService } from "../services/companies.service";
import type { CompanyListParams } from "../types";

export const companyKeys = {
  all: ["companies"] as const,
  lists: () => [...companyKeys.all, "list"] as const,
  list: (params: CompanyListParams) => [...companyKeys.lists(), params] as const,
  details: () => [...companyKeys.all, "detail"] as const,
  detail: (id: number) => [...companyKeys.details(), id] as const,
};

export function useCompanies(params: CompanyListParams = {}) {
  return useQuery({
    queryKey: companyKeys.list(params),
    queryFn: () => companiesService.list(params),
  });
}

export function useCompany(id: number) {
  return useQuery({
    queryKey: companyKeys.detail(id),
    queryFn: () => companiesService.get(id),
    enabled: Number.isInteger(id) && id > 0,
  });
}

export function useCreateCompany() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (name: string) => companiesService.create(name),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: companyKeys.lists() });
    },
  });
}
