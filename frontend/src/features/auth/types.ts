import axios from "axios";

export type AuthUser = {
  id: number;
  name: string;
  email: string;
  role: "EDITOR" | "VIEWER";
};

export type LoginCredentials = {
  email: string;
  password: string;
};

export type AuthUserResponse = {
  data: AuthUser;
};

export type AuthValidationErrors = Record<string, string[]>;

type LaravelErrorResponse = {
  message?: string;
  errors?: AuthValidationErrors;
};

export class AuthApiError extends Error {
  constructor(
    message: string,
    readonly status: number | null,
    readonly validationErrors: AuthValidationErrors = {},
    readonly csrfRefreshed = false,
  ) {
    super(message);
    this.name = "AuthApiError";
  }
}

export function toAuthApiError(
  error: unknown,
  csrfRefreshed = false,
): AuthApiError {
  if (!axios.isAxiosError<LaravelErrorResponse>(error)) {
    return new AuthApiError("Ocurrió un error inesperado.", null);
  }

  const status = error.response?.status ?? null;
  const response = error.response?.data;

  switch (status) {
    case 401:
      return new AuthApiError(
        "El correo o la contraseña no son correctos.",
        status,
      );
    case 403:
      return new AuthApiError("No tienes permiso para realizar esta acción.", status);
    case 419:
      return new AuthApiError(
        csrfRefreshed
          ? "La solicitud de seguridad venció. Se renovó el token; vuelve a intentarlo."
          : "La solicitud de seguridad venció y no se pudo renovar el token. Recarga la página e inténtalo nuevamente.",
        status,
        {},
        csrfRefreshed,
      );
    case 422:
      return new AuthApiError(
        response?.message ?? "Revisa los datos ingresados.",
        status,
        response?.errors ?? {},
      );
    default:
      if (!error.response) {
        return new AuthApiError(
          "No se pudo conectar con el servidor. Inténtalo nuevamente.",
          null,
        );
      }

      return new AuthApiError(
        "No se pudo completar la solicitud. Inténtalo nuevamente.",
        status,
      );
  }
}
