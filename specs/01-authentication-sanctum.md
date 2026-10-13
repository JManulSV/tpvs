# SPEC 01 — Autenticación y autorización con Sanctum

> **Status:** Implemented
> **Depends on:** Ninguna
> **Date:** 2026-10-13
> **Objective:** Implementar autenticación backend con Laravel Sanctum y sesión protegida por cookie, definir el contrato con el BFF y autorizar las mutaciones según los roles `EDITOR` y `VIEWER`.

## Scope

**In:**

- Alinear la documentación necesaria para sustituir JWT por Sanctum con sesiones Laravel y para precisar el contrato de cookies, CSRF, BFF, roles y errores.
- Implementar en Laravel `POST /api/auth/login`, `POST /api/auth/logout` y `GET /api/auth/me`.
- Emitir y administrar una cookie de sesión `tpvs_session` `HttpOnly` y `SameSite=Lax`; marcarla `Secure` en HTTPS.
- Hacer que la cookie de sesión expire al cerrar el navegador, mantener el límite de 120 minutos de inactividad, renovar la sesión al iniciar sesión e invalidarla al cerrar sesión.
- Definir el puente CSRF del BFF y el reenvío de cookies y encabezados en la documentación. El navegador usa el BFF same-origin y no llama directamente a Laravel.
- Permitir lectura a cualquier usuario autenticado y reservar las mutaciones para `EDITOR`. Las Policies específicas se implementan con los recursos de sus respectivas fases.
- Añadir un seeder dedicado e invocable explícitamente para las cuentas de prueba `editor@test.com` (`EDITOR`) y `viewer@test.com` (`VIEWER`), ambas con contraseña `12345678`. El seeder debe abortar en `production` y no debe ser invocado automáticamente por `DatabaseSeeder`.
- Añadir pruebas feature esenciales, incluyendo CSRF y autorización de ambos roles sin exponer rutas de prueba en la aplicación.

**Out of scope (for future specs):**

- Implementación de código Next.js/BFF, pantallas, formularios o navegación autenticada.
- JWT, tokens bearer, almacenamiento de credenciales en localStorage o revocación de tokens.
- Registro público, restablecimiento de contraseña, administración de usuarios y verificación de email.
- Limitación de intentos de login.
- Endpoints de negocio para empresas, registros, imágenes, entregas o reportes.
- Policies específicas de recursos que todavía no tienen endpoints.

## Data model

Esta spec no añade entidades ni columnas de dominio. Reutiliza `User`, el enum `UserRole` y la tabla `sessions` que ya existen. Sanctum autentica la SPA mediante la sesión Laravel; no se usan tokens personales ni se requiere guardar tokens en una tabla.

La respuesta pública mínima del usuario autenticado es:

```json
{
  "data": {
    "id": 1,
    "name": "Test Editor",
    "email": "editor@test.com",
    "role": "EDITOR"
  }
}
```

Las credenciales de prueba se crean solo al invocar el seeder dedicado. Las contraseñas deben persistirse mediante el hashing de Laravel.

## Implementation plan

1. Actualizar los documentos de arquitectura, seguridad, API, validación/autorización, operaciones, roadmap y los resúmenes README que describen autenticación. Actualizar las guías frontend BFF/auth para documentar el contrato de integración sin implementar código frontend. Todas las referencias al JWT anterior deben quedar consistentes con esta spec.
2. Añadir Laravel Sanctum como dependencia y configurar autenticación SPA stateful mediante el middleware de Laravel 12. Mantener el driver de sesión de base de datos existente y configurar nombre, atributos y duración acordados para la cookie.
3. Implementar los endpoints de autenticación con validación de entrada y una representación JSON estable del usuario. Responder `401` ante credenciales o sesión no válidas, `403` ante una mutación prohibida, `422` ante entrada inválida y `419` ante CSRF inválido, preservando el formato estándar de error de Laravel donde aplique. El logout invalida la sesión y renueva el token CSRF.
4. Implementar el control central de rol para mutaciones, registrado como middleware de rutas Laravel. Las futuras rutas de escritura deberán exigir usuario autenticado y rol `EDITOR`; no agregar endpoints de negocio ficticios en esta fase.
5. Crear el seeder dedicado de usuarios de prueba con protección explícita contra `APP_ENV=production`; no llamarlo desde `DatabaseSeeder` ni desde el arranque normal de la aplicación.
6. Añadir pruebas feature para login correcto e incorrecto, `me` autenticado y anónimo, logout, rechazo CSRF y autorización. Registrar rutas temporales de lectura/escritura solo dentro de las pruebas para demostrar que ambos roles reciben el resultado HTTP esperado.
7. Ejecutar la suite backend completa y verificar manualmente el contrato backend de CSRF/cookies. Actualizar `docs/frontend/features/auth.md` con el contrato que realmente quedó implementado y probado, incluidos endpoints, respuestas, cookies, CSRF, errores y las tareas frontend/BFF pendientes; esta guía será la base para la siguiente spec de autenticación frontend. Mantener la fase 8.4 pendiente hasta que el BFF implemente y verifique el contrato documentado.

## Acceptance criteria

- [x] La documentación aplicable ya no presenta JWT como mecanismo de autenticación objetivo y describe Sanctum/sesión de forma consistente.
- [x] `POST /api/auth/login` autentica credenciales válidas, regenera la sesión y entrega el usuario con `id`, `name`, `email` y `role`.
- [x] Credenciales incorrectas reciben `401`; entrada inválida recibe `422`.
- [x] `GET /api/auth/me` devuelve el usuario autenticado y `401` sin sesión válida.
- [x] `POST /api/auth/logout` invalida la sesión; solicitudes posteriores con esa sesión reciben `401`.
- [x] La cookie de sesión se llama `tpvs_session`, es `HttpOnly`, usa `SameSite=Lax` y se marca `Secure` en los despliegues HTTPS de producción; expira al cerrar el navegador y también después de 120 minutos de inactividad.
- [x] Las solicitudes de escritura con CSRF inválido reciben `419`, y el flujo CSRF requerido a través del BFF está documentado con la cookie `XSRF-TOKEN` y el encabezado correspondiente.
- [x] Una ruta de escritura protegida por el control de rol permite a `EDITOR` y devuelve `403` a `VIEWER`; ambos pueden acceder a una ruta de lectura autenticada. Las rutas usadas para demostrarlo existen únicamente durante las pruebas.
- [x] El seeder dedicado crea `editor@test.com` y `viewer@test.com` con sus roles y contraseña acordados, no se ejecuta automáticamente y se niega a correr con `APP_ENV=production`.
- [x] Las pruebas feature de autenticación, CSRF y roles y la suite backend existente pasan.
- [x] `docs/frontend/features/auth.md` describe el contrato backend realmente probado y los puntos pendientes que debe cubrir la siguiente spec frontend/BFF.
- [x] No se añadieron código de frontend/BFF ni endpoints de negocio como parte de esta spec.

## Decisions

- **Sí:** Laravel Sanctum con sesión stateful de base de datos. Es una SPA propia detrás de BFF y evita implementar emisión, validación y revocación manual de JWT.
- **No:** JWT en cookie. Añadiría un mecanismo de tokens que no aporta valor necesario al flujo same-origin del BFF.
- **Sí:** cookie de sesión temporal que expira al cerrar el navegador, con límite de 120 minutos de inactividad; logout invalida la sesión y login la regenera.
- **Sí:** cookie de sesión `HttpOnly`, `SameSite=Lax` y `Secure` bajo HTTPS; cookie CSRF `XSRF-TOKEN` disponible para el mecanismo XSRF del cliente HTTP.
- **Sí:** el BFF es el límite HTTP del navegador. El BFF debe reenviar cookies, `Origin`/`Referer` y el encabezado CSRF y preservar cookies y estados relevantes; esta spec documenta ese contrato pero no implementa el BFF.
- **Sí:** las cuentas de prueba viven en un seeder dedicado, invocado explícitamente y bloqueado en producción. Son dos usuarios diferenciados para validar permisos.
- **No:** cuenta de prueba en `DatabaseSeeder` o creada en producción. Evita credenciales conocidas presentes en instalaciones operativas.
- **Sí:** solo dos roles y una regla sencilla para mutaciones; las Policies de recursos se agregan cuando existan esos recursos.
- **No:** endpoints de negocio temporales en la aplicación. Las rutas de verificación se registran exclusivamente dentro de las pruebas.
- **No:** límite de intentos de login en esta fase, según la decisión acordada.
- **Sí:** pruebas feature enfocadas en contratos y seguridad observable; no duplicar la misma lógica con pruebas unitarias redundantes.

## Risks

| Risk | Mitigation |
| --- | --- |
| Cookies o CSRF no atraviesan correctamente el BFF y Laravel interpreta el navegador como una sesión distinta. | Documentar explícitamente el puente same-origin, el dominio stateful y los encabezados/cookies que el BFF debe preservar; verificarlo con integración en la etapa frontend antes de marcar 8.4 completa. |
| Credenciales de prueba conocidas terminan sembradas en producción. | No invocar el seeder desde `DatabaseSeeder`, bloquear su ejecución en `production` y documentar que las cuentas son exclusivamente de prueba. |
| Una ruta futura de escritura omite el control de rol. | Establecer el middleware central de rol en esta fase y requerir autorización y pruebas por endpoint al crear cada recurso. |

## What is **not** in this spec

- Implementación de Next.js/BFF, UI de login o navegación protegida.
- JWT, tokens bearer, registro, recuperación de contraseña o administración de cuentas.
- Rate limiting de autenticación.
- Endpoints de negocio o Policies de recursos que aún no existen.
- Completar el checkpoint 8.4 antes de verificar la integración real del BFF.
