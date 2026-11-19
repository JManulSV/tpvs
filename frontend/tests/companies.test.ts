import { afterEach, describe, expect, it, vi } from "vitest";

vi.mock("@/lib/api-client", () => ({
  apiClient: {
    get: vi.fn(),
    post: vi.fn(),
  },
}));

import { apiClient } from "@/lib/api-client";
import {
  COMPANY_DUPLICATE_MESSAGE,
  getCompaniesSubtitle,
  getCompanyCountLabel,
  getCompanyMetaLine,
  getCompanyNameErrorMessage,
  getCompanyRecordsHref,
  getCompanyRowLabel,
  getConfirmedCountLabel,
} from "@/features/companies/company-list-utils";
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

  it("uses the template's Spanish messages", () => {
    const empty = companyFormSchema.safeParse({ name: "   " });
    const overlong = companyFormSchema.safeParse({ name: "a".repeat(256) });

    expect(empty.error?.issues[0]?.message).toBe("Escribe el nombre de la empresa.");
    expect(overlong.error?.issues[0]?.message).toBe(
      "El nombre no puede superar los 255 caracteres.",
    );
  });
});

describe("company list utils", () => {
  const company = {
    id: 1,
    name: "Company ABC",
    records_count: 24,
    created_at: "2026-01-12T17:04:11.000000Z",
  };

  it("picks the subtitle by role", () => {
    expect(getCompaniesSubtitle("EDITOR")).toBe("Catálogo de empresas para tus registros");
    expect(getCompaniesSubtitle("VIEWER")).toBe("Catálogo de empresas");
  });

  it("pluralizes the company and confirmed counts", () => {
    expect(getCompanyCountLabel(0)).toBe("0 empresas");
    expect(getCompanyCountLabel(1)).toBe("1 empresa");
    expect(getCompanyCountLabel(6)).toBe("6 empresas");
    expect(getConfirmedCountLabel(0)).toBe("0 confirmados");
    expect(getConfirmedCountLabel(1)).toBe("1 confirmado");
    expect(getConfirmedCountLabel(24)).toBe("24 confirmados");
  });

  it("builds the mobile meta line with the short creation date", () => {
    expect(getCompanyMetaLine(company)).toBe("24 confirmados · creada 12/01/26");
    expect(getCompanyMetaLine({ ...company, records_count: 1 })).toBe(
      "1 confirmado · creada 12/01/26",
    );
  });

  it("labels the row link and points it to the company's records", () => {
    expect(getCompanyRowLabel(company)).toBe("Ver registros de Company ABC");
    expect(getCompanyRecordsHref(1)).toBe("/records?company_id=1");
  });

  it("translates the backend duplicate message and keeps any other message", () => {
    expect(
      getCompanyNameErrorMessage(["A company with this name already exists."]),
    ).toBe(COMPANY_DUPLICATE_MESSAGE);
    expect(
      getCompanyNameErrorMessage([
        "The name field must not be greater than 255 characters.",
        "A company with this name already exists.",
      ]),
    ).toBe(COMPANY_DUPLICATE_MESSAGE);
    expect(getCompanyNameErrorMessage(["The name field is required."])).toBe(
      "The name field is required.",
    );
    expect(getCompanyNameErrorMessage([])).toBe("Revisa el nombre de la empresa.");
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
      data: { data: { id: 7, name: "Acme", created_at: "2026-01-12T17:04:11.000000Z" } },
    });
    await expect(companiesService.create("Acme")).resolves.toEqual({
      id: 7,
      name: "Acme",
      created_at: "2026-01-12T17:04:11.000000Z",
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
