import { afterEach, describe, expect, it, vi } from "vitest";

vi.mock("@/lib/api-client", () => ({
  apiClient: {
    get: vi.fn(),
    post: vi.fn(),
  },
}));

import { apiClient } from "@/lib/api-client";
import { companyFormSchema } from "@/features/companies/schemas/company.schema";
import { companiesService } from "@/features/companies/services/companies.service";

const apiClientMock = vi.mocked(apiClient);

afterEach(() => {
  vi.resetAllMocks();
});

describe("company form schema", () => {
  it("trims a valid name and rejects empty or overlong names", () => {
    expect(companyFormSchema.parse({ name: "  Acme  " })).toEqual({
      name: "Acme",
    });
    expect(companyFormSchema.safeParse({ name: "   " }).success).toBe(false);
    expect(
      companyFormSchema.safeParse({ name: "a".repeat(256) }).success,
    ).toBe(false);
  });
});

describe("companies service", () => {
  it("requests the same-origin paginated list with its filters", async () => {
    const listResponse = {
      data: [],
      links: { first: null, last: null, prev: null, next: null },
      meta: {
        current_page: 2,
        from: null,
        last_page: 2,
        links: [],
        path: "http://localhost/api/companies",
        per_page: 15,
        to: null,
        total: 0,
      },
    };
    apiClientMock.get.mockResolvedValueOnce({ data: listResponse });

    await expect(
      companiesService.list({ search: "Acme", page: 2, per_page: 15 }),
    ).resolves.toEqual(listResponse);
    expect(apiClientMock.get).toHaveBeenCalledWith("companies", {
      params: { search: "Acme", page: 2, per_page: 15 },
    });
  });

  it("returns the created company and preserves Laravel validation details", async () => {
    apiClientMock.post.mockResolvedValueOnce({
      data: { data: { id: 7, name: "Acme" } },
    });
    await expect(companiesService.create("Acme")).resolves.toEqual({
      id: 7,
      name: "Acme",
    });
    expect(apiClientMock.post).toHaveBeenCalledWith("companies", {
      name: "Acme",
    });

    apiClientMock.post.mockRejectedValueOnce({
      isAxiosError: true,
      response: {
        status: 422,
        data: {
          message: "The given data was invalid.",
          errors: { name: ["A company with this name already exists."] },
        },
      },
    });

    await expect(companiesService.create("Acme")).rejects.toMatchObject({
      status: 422,
      validationErrors: {
        name: ["A company with this name already exists."],
      },
    });
  });
});
