"use client"

import { useCallback, useState } from "react"
import { useQueryClient } from "@tanstack/react-query"
import { useRouter } from "next/navigation"
import { authService } from "../services/auth.service"
import { AuthApiError } from "../types"

export function useLogout() {
  const router = useRouter()
  const queryClient = useQueryClient()
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  const [isLoggingOut, setIsLoggingOut] = useState(false)

  const logout = useCallback(async () => {
    setErrorMessage(null)
    setIsLoggingOut(true)

    try {
      await authService.logout()
      queryClient.clear()
      router.replace("/login")
    } catch (error) {
      setErrorMessage(
        error instanceof AuthApiError
          ? error.message
          : "No se pudo cerrar la sesión. Inténtalo nuevamente.",
      )
    } finally {
      setIsLoggingOut(false)
    }
  }, [queryClient, router])

  return { logout, isLoggingOut, errorMessage }
}
