import { describe, expect, it } from "vitest"

import { getLoginErrorDisplay } from "@/features/auth/login-error"
import { AuthApiError } from "@/features/auth/types"

describe("getLoginErrorDisplay", () => {
  it("uses the template message and marks invalid credentials on 401", () => {
    const error = new AuthApiError(
      "El correo o la contraseña no son correctos.",
      401,
    )

    expect(getLoginErrorDisplay(error)).toEqual({
      message:
        "El correo o la contraseña no coinciden. Revisa tus datos e inténtalo de nuevo.",
      invalidCredentials: true,
    })
  })

  it("keeps the AuthApiError message for other statuses", () => {
    const error = new AuthApiError(
      "No se pudo conectar con el servidor. Inténtalo nuevamente.",
      null,
    )

    expect(getLoginErrorDisplay(error)).toEqual({
      message: "No se pudo conectar con el servidor. Inténtalo nuevamente.",
      invalidCredentials: false,
    })
  })

  it("keeps the 419 message when the CSRF token was refreshed", () => {
    const error = new AuthApiError(
      "La solicitud de seguridad venció. Se renovó el token; vuelve a intentarlo.",
      419,
      {},
      true,
    )

    expect(getLoginErrorDisplay(error)).toEqual({
      message:
        "La solicitud de seguridad venció. Se renovó el token; vuelve a intentarlo.",
      invalidCredentials: false,
    })
  })

  it("falls back to a generic message for unknown errors", () => {
    expect(getLoginErrorDisplay(new Error("x"))).toEqual({
      message: "No se pudo iniciar sesión. Inténtalo nuevamente.",
      invalidCredentials: false,
    })
  })
})
