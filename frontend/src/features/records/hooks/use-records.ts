import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { recordsService } from "../services/records.service";
import type { ManualRecordInput, RecordListParams, UpdateManualRecordInput } from "../types";

export const recordKeys = {
  all: ["records"] as const,
  lists: () => [...recordKeys.all, "list"] as const,
  list: (params: RecordListParams) => [...recordKeys.lists(), params] as const,
  details: () => [...recordKeys.all, "detail"] as const,
  detail: (id: number) => [...recordKeys.details(), id] as const,
};

export function useRecords(params: RecordListParams = {}) {
  return useQuery({
    queryKey: recordKeys.list(params),
    queryFn: () => recordsService.list(params),
  });
}

export function useRecord(id: number) {
  return useQuery({
    queryKey: recordKeys.detail(id),
    queryFn: () => recordsService.get(id),
    enabled: Number.isInteger(id) && id > 0,
  });
}

export function useCreateRecord() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: ManualRecordInput) => recordsService.create(input),
    retry: false,
    onSuccess: async (record) => {
      queryClient.setQueryData(recordKeys.detail(record.id), record);
      await queryClient.invalidateQueries({ queryKey: recordKeys.lists() });
    },
  });
}

export function useUpdateRecord() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: UpdateManualRecordInput }) =>
      recordsService.update(id, input),
    retry: false,
    onSuccess: async (record) => {
      queryClient.setQueryData(recordKeys.detail(record.id), record);
      await queryClient.invalidateQueries({ queryKey: recordKeys.lists() });
    },
  });
}

export function useConfirmRecord() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => recordsService.confirm(id),
    retry: false,
    onSuccess: async (record) => {
      queryClient.setQueryData(recordKeys.detail(record.id), record);
      await queryClient.invalidateQueries({ queryKey: recordKeys.lists() });
    },
  });
}
