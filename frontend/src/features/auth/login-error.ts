import { AuthApiError } from "./types";

export type LoginErrorDisplay = {
  message: string;
  invalidCredentials: boolean;
};

const INVALID_CREDENTIALS_MESSAGE =
  "El correo o la contraseña no coinciden. Revisa tus datos e inténtalo de nuevo.";

const FALLBACK_MESSAGE = "No se pudo iniciar sesión. Inténtalo nuevamente.";

export function getLoginErrorDisplay(error: unknown): LoginErrorDisplay {
  if (!(error instanceof AuthApiError)) {
    return { message: FALLBACK_MESSAGE, invalidCredentials: false };
  }

  if (error.status === 401) {
    return { message: INVALID_CREDENTIALS_MESSAGE, invalidCredentials: true };
  }

  return { message: error.message, invalidCredentials: false };
}
