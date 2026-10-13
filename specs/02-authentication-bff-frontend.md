# SPEC 02 — BFF y autenticación frontend

> **Status:** Approved
> **Depends on:** SPEC 01
> **Date:** 2026-10-13
> **Objective:** Integrar el BFF de Next.js con la sesión y protección CSRF de Laravel Sanctum, y habilitar login, logout y una vista autenticada mínima.

## Scope

**In:**

- Integrar el BFF con el contrato backend de Sanctum: puente CSRF, reenvío controlado de cookies y encabezados, y preservación de estados, cookies y respuestas relevantes.
- Hacer que el BFF reenvíe `Cookie`, `X-XSRF-TOKEN` y los encabezados de origen requeridos por Sanctum. Validar `Origin`/`Referer` contra el origen frontend confiable.
- Añadir `FRONTEND_ORIGIN` como configuración server-only explícita para solicitudes SSR; nunca derivar el origen confiable de un `Host` no validado.
- Implementar el acceso a Laravel desde las páginas de servidor mediante una función proxy server-only compartida con el BFF. Reenviar la cookie entrante de forma explícita.
- Implementar `/login`, `/dashboard` y el comportamiento de la raíz `/`: enviar usuarios autenticados a `/dashboard` y usuarios anónimos a `/login`.
- Proteger `/dashboard` desde un layout Server Component consultando `GET /api/auth/me`. Redirigir sesiones inválidas a `/login` sin renderizar contenido protegido.
- Si `/login` recibe a un usuario autenticado, redirigir a `/dashboard`. Conservar destinos de retorno solo cuando sean rutas internas seguras.
- Crear un formulario de login básico en español con validación, estado de envío y mensajes seguros para credenciales inválidas, validación, CSRF vencido e indisponibilidad.
- Solicitar un token CSRF al iniciar login o logout. Si una solicitud obtiene `419`, renovar el token y pedir reintento explícito; no repetir automáticamente la mutación.
- Crear una vista autenticada mínima que muestre identidad y rol (`EDITOR` o `VIEWER`) obtenidos desde Laravel.
- Implementar logout, limpiar el estado/caché de usuario y regresar a `/login`.
- Añadir pruebas focalizadas con Vitest para el proxy/BFF, además de un recorrido manual reproducible frente a Laravel.
- Actualizar la documentación frontend y el roadmap tras verificar el checkpoint 8.4.

**Out of scope (for future specs):**

- Pantallas y flujos de negocio del dashboard, registros, empresas, reportes o entregas.
- Administración de usuarios, registro público, recuperación de contraseña o “recordarme”.
- Autorización de negocio en Next.js; Laravel continúa siendo la autoridad para permisos.
- Cambios en Laravel, sus endpoints, políticas o esquema de base de datos.
- Rediseño visual completo o navegación de módulos más allá de la vista mínima autenticada.

## Data model

Esta spec no introduce estructuras persistentes nuevas. Reutiliza la identidad de `GET /api/auth/me`:

```ts
type AuthUser = {
  id: number;
  name: string;
  email: string;
  role: "EDITOR" | "VIEWER";
};
```

Las cookies de sesión y CSRF siguen siendo propiedad de Laravel. No se guardan credenciales ni tokens de sesión en localStorage, URLs o estado persistente del cliente.

## Implementation plan

1. Crear una función server-only compartida para llamar a Laravel mediante `LARAVEL_API_URL`. Debe admitir el reenvío explícito de cookies y encabezados permitidos, usar `FRONTEND_ORIGIN` como origen confiable para SSR y no exponer la URL del backend al cliente.
2. Adaptar `src/app/api/[...path]/route.ts` y añadir el puente `GET /api/auth/csrf-cookie` hacia `GET /sanctum/csrf-cookie`. Preservar múltiples `Set-Cookie`, estados upstream y encabezados necesarios, incluidos `Origin`/`Referer` validados. Mantener una respuesta de gateway segura ante indisponibilidad de Laravel.
3. Implementar el servicio de autenticación y la configuración del cliente HTTP para `/api/auth/login`, `/api/auth/logout` y `/api/auth/me`, con CSRF previo a las mutaciones. Manejar `401`, `403`, `419` y `422` sin alterar sus significados ni reintentar mutaciones automáticamente.
4. Añadir `/login` con validación de email y contraseña, mensajes en español y prevención de envíos duplicados. Tras autenticación correcta, navegar al destino interno seguro solicitado o a `/dashboard`.
5. Añadir `/dashboard` bajo un grupo de rutas autenticado con layout servidor que consulte `/me`; mostrar nombre y rol y ofrecer logout. Redirigir solicitudes anónimas a `/login` sin contenido protegido.
6. Convertir `/` en punto de entrada que consulte la sesión en servidor y redirija a `/dashboard` o `/login`. Mantener `/api/health` como endpoint de salud.
7. Añadir pruebas Vitest focalizadas al puente CSRF y al contrato de proxy —cookies, encabezados, estados y errores—, y documentar el flujo manual navegador-backend junto con los comandos de verificación frontend.
8. Actualizar `docs/frontend/features/auth.md`, `docs/frontend/03_api_bff_and_sessions.md`, `docs/frontend/06_testing.md` y la documentación de configuración necesaria. Marcar 8.4 completa en el roadmap solo después de pasar el recorrido integrado.

## Acceptance criteria

- [ ] El navegador solo realiza solicitudes de negocio al BFF same-origin; `LARAVEL_API_URL` y `FRONTEND_ORIGIN` no se exponen al bundle cliente.
- [ ] `GET /api/auth/csrf-cookie` obtiene el CSRF de Sanctum y entrega al navegador las cookies emitidas por Laravel.
- [ ] El BFF reenvía cookies de sesión/CSRF y `X-XSRF-TOKEN` cuando están presentes, y preserva todos los `Set-Cookie` upstream.
- [ ] `Origin`/`Referer` entrantes se reenvían solo cuando coinciden con el origen confiable; las solicitudes SSR usan `FRONTEND_ORIGIN` configurado.
- [ ] El BFF conserva los estados y cuerpos de Laravel, incluidos `401`, `403`, `419` y `422`; la caída del backend devuelve un error estable sin revelar URL interna ni detalles de excepción.
- [ ] Un login válido establece una sesión Laravel y lleva al usuario a `/dashboard`; credenciales inválidas muestran un mensaje seguro.
- [ ] Abrir o refrescar `/dashboard` con sesión válida muestra el usuario devuelto por Laravel; sin sesión válida redirige a `/login` sin mostrar datos protegidos.
- [ ] Un usuario con sesión activa que visita `/login` es redirigido a `/dashboard`.
- [ ] El formulario muestra errores de validación, estado pendiente y fallos de transporte; evita envíos duplicados.
- [ ] Ante `419`, el flujo renueva CSRF y requiere una acción explícita del usuario para reintentar; ninguna mutación se repite automáticamente.
- [ ] Logout invalida la sesión en Laravel, limpia el estado de usuario/cache y retorna a `/login`.
- [ ] El recorrido manual verifica login, actualización de página, sesión inválida y logout contra Laravel.
- [ ] Las pruebas Vitest enfocadas, lint, TypeScript y build de producción pasan.
- [ ] La documentación y el checkpoint de la fase 8.4 reflejan la integración realmente verificada.

## Decisions

- **Sí:** proteger páginas autenticadas desde un layout Server Component. Verifica la sesión antes de renderizar contenido privado.
- **No:** basar la protección únicamente en un guard cliente. Puede mostrar contenido antes de validar la sesión.
- **Sí:** compartir una función server-only para proxy y SSR. Evita llamadas SSR a una URL de origen reconstruida y concentra las reglas de reenvío.
- **Sí:** configurar `FRONTEND_ORIGIN` explícitamente. El header `Host` entrante no es una fuente confiable para establecer el origen stateful de Sanctum.
- **Sí:** `/dashboard` como vista mínima de identidad y rol; `/` redirige según la sesión y `/api/health` conserva el chequeo operativo.
- **Sí:** Vitest para pruebas focalizadas del proxy, complementado por aceptación manual real contra Laravel.
- **No:** reintentar automáticamente una escritura tras `419`. La renovación de CSRF no debe duplicar mutaciones.
- **Sí:** mostrar el rol recibido del backend solo como identidad contextual. Laravel mantiene toda decisión de autorización.

## Risks

| Risk | Mitigation |
| --- | --- |
| El BFF pierde cookies múltiples o el header CSRF y Laravel crea una sesión distinta. | Probar `Set-Cookie`, cookie entrante, `X-XSRF-TOKEN` y el recorrido integrado contra el backend real. |
| SSR no es reconocido como stateful por Sanctum. | Definir `FRONTEND_ORIGIN` y verificar el flujo en carga directa y refresh de `/dashboard`. |
| Un origen manipulado llega a Laravel mediante `Origin`/`Referer`. | Comparar el origen entrante con `FRONTEND_ORIGIN`; no confiar en `Host` para originar la solicitud SSR. |
| Destino de retorno permite redirecciones externas. | Aceptar solo rutas relativas internas y usar `/dashboard` como fallback. |

## What is **not** in this spec

- Implementación o cambios en el backend Laravel.
- Dashboard funcional, navegación de negocio, empresas, registros, reportes o entregas.
- Registro, recuperación de contraseña, administración de usuarios o rate limiting.
- JWT, bearer tokens, almacenamiento persistente de credenciales o sesiones paralelas.
- Reemplazar la autorización de Laravel por controles frontend.
