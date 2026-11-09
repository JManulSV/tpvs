# SPEC 17 — Rediseño de la pantalla de login

> **Status:** Implemented
> **Depends on:** SPEC 02, SPEC 15, SPEC 16
> **Date:** 2026-11-08
> **Objective:** Rediseñar `/login` con la plantilla entregada (hero de marca + panel de formulario) usando solo los tokens INNOVA existentes, sin cambiar el flujo de autenticación de SPEC 02.

## Why this spec exists

SPEC 15 y SPEC 16 dejaron fuera el rediseño del login. Hoy `/login` es una tarjeta centrada con estilos sueltos, sin relación con el shell autenticado.

La plantilla entregada («Iniciar sesión») define una pantalla de dos columnas: hero azul con los pasos del flujo y panel con el formulario. Trae dos controles que el sistema no soporta: «Mantener sesión iniciada» y «¿Olvidaste tu contraseña?». SPEC 02 los dejó fuera porque necesitan backend. Se muestran deshabilitados, con el mismo patrón «próximamente» del buscador y las notificaciones de SPEC 16.

Los colores de la plantilla se traducen a los tokens globales de SPEC 15. Esta spec no agrega tokens.

## Scope

**In:**

- Marco de la plantilla: fondo `bg-background`, margen de 16 px y una tarjeta `rounded-xl` con borde y `shadow-card` que ocupa la altura de la ventana.
- Hero de marca en `frontend/src/features/auth/components/login-hero.tsx`, con el texto de la plantilla y el patrón de puntos decorativo. Visible solo desde 1024 px (`lg`).
- Rediseño de `frontend/src/features/auth/components/login-form.tsx`: marca «TPVS», `ThemeToggle`, título, alerta de error, campos, botón para mostrar u ocultar la contraseña, controles «próximamente», botón «Iniciar sesión» y pie «¿No tienes acceso? Solicítalo a tu administrador.».
- Alerta de error con título fijo «No pudimos iniciar tu sesión» y el mensaje real según el error.
- Texto de la plantilla para `401` en el login: «El correo o la contraseña no coinciden. Revisa tus datos e inténtalo de nuevo.».
- Utilidad pura `frontend/src/features/auth/login-error.ts` y sus pruebas en `frontend/tests/auth-login.test.ts`.
- Mover `ThemeToggle` de la esquina fija de la página al panel del formulario.
- Actualizar `docs/frontend/features/auth.md` con la descripción de la pantalla.

**Out of scope (for future specs):**

- Tokens nuevos o cambios en `frontend/src/app/globals.css`.
- Cambios en `frontend/src/components/ui/input.tsx` (el estilo inválido se ajusta solo en el login).
- «Mantener sesión iniciada» funcional (remember token de Laravel).
- Recuperación de contraseña (endpoint, correo y pantallas).
- Cambios en el flujo de SPEC 02: CSRF, `returnTo`, redirección con sesión activa y reintento explícito tras `419`.
- Cambios en `toAuthApiError` y en los mensajes de logout y `me`.
- Rediseño del estado «No se pudo validar la sesión con el servidor…» y del fallback «Cargando acceso…» de `page.tsx`.
- Logotipo o imagen de marca.
- Cambios de backend.

## Data model

Esta spec no agrega datos persistidos, no cambia contratos con Laravel y no agrega tokens. Reutiliza `AuthApiError` y `LoginCredentials` de SPEC 02.

### Mensaje de error del login — `frontend/src/features/auth/login-error.ts`

```ts
export type LoginErrorDisplay = {
  message: string;              // texto bajo el título de la alerta
  invalidCredentials: boolean;  // true solo con 401
};

export function getLoginErrorDisplay(error: unknown): LoginErrorDisplay;
```

| Entrada | `message` | `invalidCredentials` |
| --- | --- | --- |
| `AuthApiError` con `status === 401` | «El correo o la contraseña no coinciden. Revisa tus datos e inténtalo de nuevo.» | `true` |
| Otro `AuthApiError` (`419`, `422`, `403`, sin conexión, 5xx) | `error.message` | `false` |
| Cualquier otro valor | «No se pudo iniciar sesión. Inténtalo nuevamente.» | `false` |

- La utilidad solo la usa `login-form.tsx`. `toAuthApiError` no cambia, así que logout sigue mostrando sus mensajes actuales.
- Los errores de validación de `422` se siguen mostrando bajo cada campo, como hoy.

### Correspondencia de colores — plantilla → tokens globales

| Elemento | Token | Claro / oscuro |
| --- | --- | --- |
| Fondo de página | `bg-background` | `#EEF3F8` / `#0D1426` |
| Tarjeta | `bg-card`, `border-border`, `shadow-card` | `#FFFFFF` / `#151E36` |
| Hero | `bg-hero text-on-hero` | `#324168` / `#243B6E`, texto `#FFFFFF` |
| Chip de paso | `bg-brand-sky` | `#64B3DD` en ambos |
| Icono del chip | `text-hero` | `#324168` / `#243B6E` |
| Patrón de puntos | `var(--brand-sky)` | `#64B3DD` en ambos |
| Alerta de error | `bg-status-error-bg text-status-error` | `#FCE6E7` + `#A3202A` / `#3D1519` + `#F5A0A6` |
| Campo inválido y error de campo | `border-status-error`, `text-status-error` | `#A3202A` / `#F5A0A6` |
| «¿Olvidaste tu contraseña?» | `text-accent-foreground` | `#1B5C85` / `#9ED2EE` |
| Textos secundarios | `text-muted-foreground` | `#4F5B77` / `#A9B6CF` |
| Hover del botón del ojo | `bg-muted text-foreground` | — |
| Foco | `ring` | `#324168` / `#64B3DD` |

- El icono del chip usa `text-hero`, no el `#17203A` literal de la plantilla. Contraste sobre `#64B3DD`: 4.3:1 en claro y 4.7:1 en oscuro (mínimo para iconos: 3:1).

## UI contract

### Marco — `frontend/src/app/(auth)/login/page.tsx`

- Raíz `min-h-svh bg-background p-4 flex`.
- Tarjeta `flex flex-1 overflow-hidden rounded-xl border border-border bg-card shadow-card`.
- Desde `lg` (1024 px) muestra dos columnas: `LoginHero` (base 560 px) y `LoginForm` (base 520 px), ambas flexibles. Por debajo de `lg` solo se muestra `LoginForm`, dentro de la misma tarjeta.
- Se elimina el `ThemeToggle` fijo (`fixed right-4 top-4`).
- El estado de error de sesión y el fallback de `Suspense` no cambian.

### Hero — `login-hero.tsx`

- `<section aria-label="Presentación">`, `hidden lg:flex`, `bg-hero text-on-hero`, padding 56 px, columna con `justify-between` y gap de 48 px.
- Contenido, de arriba abajo:
  1. Overline «Plataforma interna»: 11 px / 600, mayúsculas, `tracking-[0.08em]`, opacidad 85 %.
  2. Titular «Revisa y confirma los registros de tus proveedores»: `<p>`, 32/40 px, 700, `tracking-[-0.02em]`, ancho máximo 400 px. No es encabezado.
  3. Texto «Recibe imágenes, valida la información y prepara las entregas de efectivo desde un solo lugar.», 14/20 px.
  4. Overline «Cómo funciona», 24 px por encima.
  5. `<ol>` de tres pasos, gap 24 px, ancho máximo 380 px. Cada paso tiene un chip de 44 px `rounded-md bg-brand-sky text-hero` con icono de 20 px `aria-hidden`, título 16/24 px / 600 y descripción 14/20 px:
     - `Image` · «Recibe la imagen» · «Cada registro llega con la imagen que envía el proveedor.»
     - `ClipboardCheck` · «Revisa la información» · «Información extraída automáticamente. Revisa antes de confirmar.»
     - `Banknote` · «Confirma y prepara la entrega» · «Con el registro confirmado, prepara la entrega de efectivo.»
  6. Pie «TPVS», 12/16 px / 500, opacidad 85 %.
- Patrón de puntos: `div` absoluto `aria-hidden` que cubre el 42 % derecho, `radial-gradient` de `var(--brand-sky)` de 3 px cada 28 px, opacidad 35 % y máscara lineal que se desvanece hacia la izquierda. `pointer-events-none`. El contenido queda por encima con `relative z-10`.

### Panel — `login-form.tsx`

- Raíz `<main>`, columna con `justify-between`, gap 40 px y padding 24 px (56 px desde `sm`).
- Fila superior: marca «TPVS» (24/32 px, 700, no encabezado) a la izquierda y `ThemeToggle` a la derecha.
- Bloque central de ancho máximo 400 px, centrado, gap 32 px:
  - `<h1>` «Iniciar sesión» (32/40 px, 700, `tracking-[-0.02em]`) y el subtítulo «Entra con tu correo y contraseña.» en `text-muted-foreground`.
  - Alerta, solo si hay error: `id="login-error"`, `role="alert"`, `rounded-md bg-status-error-bg text-status-error`, padding 12/16 px, icono `CircleAlert` de 18 px `aria-hidden`, título «No pudimos iniciar tu sesión» en 700 y debajo `message`.
  - `<form>` con `noValidate`, gap 20 px entre campos:
    - «Correo electrónico»: `Input` de `components/ui`, `type="email"`, `inputMode="email"`, `autoComplete="username"`, placeholder «tu.correo@ejemplo.com».
    - «Contraseña»: `Input`, `autoComplete="current-password"`, placeholder «Escribe tu contraseña», padding derecho de 48 px.
    - Etiquetas de campo: 12/16 px / 500, `text-muted-foreground`.
    - Botón para mostrar u ocultar la contraseña: `type="button"`, 32 px, `rounded-full`, dentro del input a 4 px del borde derecho. Icono `Eye` / `EyeOff` de 18 px `aria-hidden`. `aria-label` «Mostrar contraseña» / «Ocultar contraseña». Cambia el `type` del input entre `password` y `text`. Hover `bg-muted text-foreground`. Se deshabilita junto con el formulario.
    - Error de campo: debajo del input, 12/16 px, `text-status-error`, con `id="email-error"` / `id="password-error"`.
    - Fila «próximamente», con `flex-wrap` y `justify-between`:
      - Checkbox «Mantener sesión iniciada» de 18 px, `disabled`, `title="Próximamente"`, `aria-label="Mantener sesión iniciada (próximamente)"`, etiqueta con `opacity-50`.
      - «¿Olvidaste tu contraseña?»: `<button type="button" disabled>`, no un enlace, con estilo de texto `text-accent-foreground` 14 px / 600, `opacity-50`, `title="Próximamente"` y `aria-label="¿Olvidaste tu contraseña? (próximamente)"`.
    - `Button` de envío `type="submit"`, `w-full`, alto 40 px, `rounded-full`, icono `LogIn` de 16 px `aria-hidden`. Texto «Preparando formulario…» antes de hidratar, «Iniciando sesión…» al enviar y «Iniciar sesión» en reposo.
- Pie centrado «¿No tienes acceso? Solicítalo a tu administrador.», 12/16 px / 500, `text-muted-foreground`.

### Estados de los campos

- `aria-invalid="true"` en un campo si tiene error propio o si `invalidCredentials` es `true`.
- Campo inválido: borde de 2 px `border-status-error`, en claro y en oscuro. Las clases se pasan por `className` desde `login-form.tsx` y anulan el borde y el anillo `destructive` del `Input` base, incluidas sus variantes `dark:`. `input.tsx` no cambia.
- `aria-describedby` apunta al error propio del campo. Con `invalidCredentials`, también apunta a `login-error`.
- La alerta y las marcas de inválido se mantienen hasta el siguiente envío.
- Foco con teclado en inputs, botones y checkbox: anillo `ring` de 2 px con offset de 2 px.

### Sin cambios

- Envío, CSRF, `returnTo`, `router.replace(returnTo)`, redirección a `/dashboard` con sesión activa y reintento explícito tras `419`.
- Bloqueo de campos y botón hasta hidratar (`useSyncExternalStore`).
- Validación Zod y sus mensajes.
- `frontend/src/app/globals.css` y `frontend/src/components/ui/input.tsx`.

## Implementation plan

1. Crear `frontend/src/features/auth/login-error.ts` con `getLoginErrorDisplay`. Agregar `frontend/tests/auth-login.test.ts` con los tres casos de la tabla y un `419` con `csrfRefreshed`. Verificar con `npm test`.
2. Crear `frontend/src/features/auth/components/login-hero.tsx` (Server Component, sin estado) con el contenido, los chips `bg-brand-sky text-hero` y el patrón de puntos.
3. Reescribir `frontend/src/features/auth/components/login-form.tsx` con la nueva estructura de panel:
   - usar `getLoginErrorDisplay`, el estado `showPassword` y los controles «próximamente»;
   - aplicar el estilo inválido `border-status-error` de 2 px por `className`, anulando el `destructive` del `Input` base;
   - mover `ThemeToggle` a la fila superior;
   - conservar intactos `onSubmit`, la validación y el bloqueo hasta hidratar.
4. Reescribir el retorno exitoso de `frontend/src/app/(auth)/login/page.tsx` para renderizar el marco con `LoginHero` y `LoginForm`, y eliminar el `ThemeToggle` fijo. No tocar el estado de error ni el fallback.
5. Revisar `/login` en el navegador en claro y oscuro, a 1440, 1024, 768 y 375 px. Probar credenciales válidas, inválidas, campos vacíos y backend detenido. Ajustar solo espaciados.
6. Actualizar `docs/frontend/features/auth.md`, sección «Routes and Screens»: estructura hero + panel, hero oculto bajo 1024 px, alerta con título fijo, texto de `401`, botón de mostrar contraseña y los dos controles «próximamente».

## Acceptance criteria

- [ ] `getLoginErrorDisplay` devuelve el texto de la plantilla e `invalidCredentials: true` para un `AuthApiError` con `401`.
- [ ] `getLoginErrorDisplay` devuelve `error.message` e `invalidCredentials: false` para un `AuthApiError` con `419`.
- [ ] `getLoginErrorDisplay(new Error("x"))` devuelve «No se pudo iniciar sesión. Inténtalo nuevamente.».
- [ ] `toAuthApiError` en `frontend/src/features/auth/types.ts` no cambia.
- [ ] `frontend/src/app/globals.css` y `frontend/src/components/ui/input.tsx` no cambian.
- [ ] A 1440 px se ven el hero y el panel lado a lado, dentro de una tarjeta con 16 px de margen.
- [ ] A 1023 px y menos el hero no se renderiza visualmente y el formulario es lo primero tras la fila de marca.
- [ ] El hero muestra «Plataforma interna», el titular, los tres pasos con sus iconos y el pie «TPVS».
- [ ] Los chips de los pasos usan `bg-brand-sky text-hero` y el icono se distingue en claro y en oscuro.
- [ ] La página tiene un solo `<h1>`, «Iniciar sesión».
- [ ] La marca dice «TPVS» en el panel y en el hero, y el texto «Tpvs» no aparece.
- [ ] `ThemeToggle` está en la fila superior del panel y alterna el tema. No existe un `ThemeToggle` con `fixed` en `page.tsx`.
- [ ] Con credenciales incorrectas aparece la alerta con `role="alert"`, «No pudimos iniciar tu sesión» y «El correo o la contraseña no coinciden. Revisa tus datos e inténtalo de nuevo.», y ambos campos tienen `aria-invalid="true"` y borde de 2 px `status-error`, sin el anillo `destructive`, en claro y en oscuro.
- [ ] Con el backend detenido, la alerta muestra «No pudimos iniciar tu sesión» y «No se pudo conectar con el servidor. Inténtalo nuevamente.», y los campos no quedan marcados como inválidos.
- [ ] Con campos vacíos se muestran «Ingresa un correo electrónico válido.» e «Ingresa tu contraseña.» bajo cada campo, y no aparece la alerta.
- [ ] El botón del ojo alterna el `type` del input entre `password` y `text`, y su `aria-label` entre «Mostrar contraseña» y «Ocultar contraseña».
- [ ] El checkbox «Mantener sesión iniciada» y el botón «¿Olvidaste tu contraseña?» están `disabled`, tienen `title="Próximamente"` y su `aria-label` termina en «(próximamente)».
- [ ] «¿Olvidaste tu contraseña?» no es un `<a>` y no cambia la URL.
- [ ] El botón de envío dice «Iniciar sesión», cambia a «Iniciando sesión…» al enviar y no permite un segundo envío.
- [ ] Un login válido lleva a `/dashboard`, y con `?returnTo=/records` lleva a `/records`.
- [ ] Un usuario con sesión activa que abre `/login` es redirigido a `/dashboard`.
- [ ] Con Tab se recorren en orden `ThemeToggle`, correo, contraseña, botón del ojo y botón de envío, y todos muestran el anillo de foco de 2 px.
- [ ] Los archivos nuevos y modificados solo usan tokens existentes de `globals.css`, sin colores literales ni de la paleta de Tailwind.
- [ ] La consola del navegador no muestra advertencias de hidratación en `/login`.
- [ ] `docs/frontend/features/auth.md` describe la nueva pantalla y los controles «próximamente».
- [ ] `npm run lint`, `npx tsc --noEmit`, `npm test` y `npm run build` en `frontend/` pasan sin errores.
- [ ] `/login` se revisó en claro y oscuro, a 1440, 1024, 768 y 375 px, sin texto ilegible, controles invisibles ni scroll horizontal.

## Decisions

- **Sí:** cambio solo visual. El flujo de SPEC 02 está verificado y no se toca.
- **Sí:** solo tokens globales existentes. Ningún color de la plantilla justifica un token nuevo.
- **Sí:** icono del chip en `text-hero`. Reutiliza el azul del hero, cumple contraste en ambos temas (4.3:1 y 4.7:1) y da el efecto de icono recortado del fondo.
- **No:** token `--on-brand-sky` con el `#17203A` de la plantilla. Agrega un token para un solo uso cuando `hero` ya cumple.
- **No:** `text-foreground` en el icono del chip. En oscuro cambia a `#EAF0F8` y sobre `brand-sky` queda en unos 2:1.
- **Sí:** campo inválido con borde de 2 px `status-error`, aplicado por `className` en el login. Coincide con el rojo de la alerta y con la plantilla.
- **No:** cambiar el estilo `aria-invalid` del `Input` base. Afectaría a todos los formularios y no está en el alcance.
- **Sí:** «Mantener sesión iniciada» visible y deshabilitado. Respeta el diseño sin prometer una sesión persistente que Laravel no ofrece hoy.
- **No:** implementar el «recordarme» aquí. Necesita backend y una revisión de seguridad propia.
- **Sí:** «¿Olvidaste tu contraseña?» como botón deshabilitado, no como enlace. Un `<a>` deshabilitado no existe en HTML, y `href="#recuperar"` cambiaría la URL.
- **Sí:** mismo patrón «próximamente» que el buscador y las notificaciones de SPEC 16 (`disabled`, `title`, `aria-label`).
- **Sí:** alerta con título fijo y el mensaje real según el error. Un `419` o un servidor caído no deben decir que la contraseña está mal.
- **Sí:** texto de la plantilla para `401`, definido en `getLoginErrorDisplay`.
- **No:** cambiar el texto de `401` en `toAuthApiError`. Logout también lo usa y un `401` ahí no es de credenciales.
- **Sí:** marcar ambos campos como inválidos solo con `401`. Con otros errores los datos pueden ser correctos.
- **Sí:** conservar los errores por campo. La plantilla no los muestra, pero ya existen y son más precisos.
- **Sí:** ocultar el hero por debajo de 1024 px. En móvil y tablet el formulario debe ser lo primero.
- **No:** apilar el hero arriba como hace la plantilla con `flex-wrap`. Empuja el formulario fuera de la pantalla.
- **Sí:** marco con tarjeta y 16 px de margen, igual que el shell de SPEC 16.
- **Sí:** marca «TPVS» en mayúsculas, como decidió SPEC 16. La plantilla dice «Tpvs».
- **Sí:** botón «Iniciar sesión» / «Iniciando sesión…», como la plantilla. Reemplaza «Ingresar» / «Ingresando…».
- **Sí:** `ThemeToggle` en el panel. SPEC 15 exige que esté en `/login` y la plantilla no le da lugar.
- **Sí:** el titular del hero es `<p>` y la marca no es encabezado. Así la página tiene un solo `<h1>`, «Iniciar sesión», también cuando el hero está oculto.
- **Sí:** separar el hero en `login-hero.tsx`. Es estático y puede ser Server Component.

## Risks

| Risk | Mitigation |
| --- | --- |
| Cambiar el `type` del input de contraseña puede romper el autocompletado del gestor de contraseñas. | `autoComplete="current-password"` no cambia y el `id` y el `name` se conservan. Se prueba en el paso 5. |
| El rediseño puede romper el bloqueo hasta hidratar y permitir envíos nativos `POST /login`. | El paso 3 conserva `useSyncExternalStore`, `action`, `method` y `preventDefault`. Hay un criterio para el doble envío. |
| Las clases `dark:aria-invalid:*` del `Input` base pueden ganarle al borde `status-error` en oscuro. | Las clases del login anulan también las variantes `dark:` (vía `cn`/`tailwind-merge`). Hay un criterio que lo verifica en ambos temas. |
| El icono `text-hero` sobre `brand-sky` tiene menos contraste que el `#17203A` de la plantilla. | 4.3:1 en claro y 4.7:1 en oscuro, por encima del 3:1 que piden los iconos. Es decorativo y `aria-hidden`; el título del paso lleva la información. |
| El patrón de puntos con `mask-image` puede verse distinto entre navegadores. | Es decorativo y `aria-hidden`. Si la máscara no se aplica, solo se ve el patrón sin desvanecido. |
| Los controles deshabilitados pueden confundir al usuario. | El `title`, el `aria-label` y el pie «Solicítalo a tu administrador» indican la alternativa. |

## What is **not** in this spec

- Tokens nuevos o cambios en `globals.css`.
- Cambios en el `Input` base.
- «Mantener sesión iniciada» funcional.
- Recuperación de contraseña.
- Cambios en el flujo de autenticación, CSRF o `returnTo`.
- Rediseño de los estados de error de sesión y de carga de `page.tsx`.
- Logotipo o imagen de marca.
- Cambios en Laravel.

Cada uno de esos puntos, si se hace, va en su propia spec.
