import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { recordKeys } from "@/features/records/hooks/use-records";
import { deliveriesService } from "../services/deliveries.service";
import type { UpsertDeliveryInput } from "../types";

export const deliveryKeys = {
  all: ["deliveries"] as const,
  record: (recordId: number) => [...deliveryKeys.all, "record", recordId] as const,
};

export function useDelivery(recordId: number) {
  return useQuery({
    queryKey: deliveryKeys.record(recordId),
    queryFn: () => deliveriesService.get(recordId),
    enabled: Number.isInteger(recordId) && recordId > 0,
  });
}

export function useUpsertDelivery() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ recordId, input }: { recordId: number; input: UpsertDeliveryInput }) =>
      deliveriesService.upsert(recordId, input),
    retry: false,
    onSuccess: async (delivery) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: deliveryKeys.record(delivery.record_id) }),
        queryClient.invalidateQueries({ queryKey: recordKeys.histories(delivery.record_id) }),
      ]);
    },
  });
}
