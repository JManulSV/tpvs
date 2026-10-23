import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { recordsService } from "../services/records.service";
import type {
  CancelRecordInput,
  ManualRecordInput,
  RecordHistoryParams,
  RecordListParams,
  UpdateManualRecordInput,
} from "../types";

const processingPollIntervalMs = 3000;

export const recordKeys = {
  all: ["records"] as const,
  lists: () => [...recordKeys.all, "list"] as const,
  list: (params: RecordListParams) => [...recordKeys.lists(), params] as const,
  details: () => [...recordKeys.all, "detail"] as const,
  detail: (id: number) => [...recordKeys.details(), id] as const,
  histories: (id: number) => [...recordKeys.detail(id), "history"] as const,
  history: (id: number, params: RecordHistoryParams = {}) =>
    [...recordKeys.histories(id), params] as const,
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
    refetchInterval: (query) => {
      const status = query.state.data?.status;
      return status === "PENDING" || status === "PROCESSING" ? processingPollIntervalMs : false;
    },
  });
}

export function useRecordHistory(id: number, params: RecordHistoryParams = {}) {
  return useQuery({
    queryKey: recordKeys.history(id, params),
    queryFn: () => recordsService.getHistory(id, params),
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

export function useCreateRecordFromImage() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (file: File) => recordsService.createFromImage(file),
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
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: recordKeys.lists() }),
        queryClient.invalidateQueries({ queryKey: recordKeys.histories(record.id) }),
      ]);
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
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: recordKeys.lists() }),
        queryClient.invalidateQueries({ queryKey: recordKeys.histories(record.id) }),
      ]);
    },
  });
}

export function useCancelRecord() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: CancelRecordInput }) =>
      recordsService.cancel(id, input),
    retry: false,
    onSuccess: async (record) => {
      queryClient.setQueryData(recordKeys.detail(record.id), record);
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: recordKeys.lists() }),
        queryClient.invalidateQueries({ queryKey: recordKeys.histories(record.id) }),
      ]);
    },
  });
}

export function useRetryAiProcessing() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => recordsService.retryAiProcessing(id),
    retry: false,
    onSuccess: async (record) => {
      queryClient.setQueryData(recordKeys.detail(record.id), record);
      await queryClient.invalidateQueries({ queryKey: recordKeys.lists() });
    },
  });
}
