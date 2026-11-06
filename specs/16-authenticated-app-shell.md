# SPEC 16 — Shell de la aplicación autenticada

> **Status:** Implemented
> **Depends on:** SPEC 02, SPEC 04, SPEC 15
> **Date:** 2026-11-05
> **Objective:** Reemplazar el header plano de `(app)/layout.tsx` por un shell con sidebar colapsable de shadcn y topbar, construido con los tokens INNOVA de SPEC 15, sin cambiar el contenido de las pantallas.

## Why this spec exists

El layout autenticado actual es un header con nombre, correo, rol, cuatro enlaces subrayados, `ThemeToggle` y `LogoutButton`. SPEC 15 dejó fuera de alcance el «nuevo layout autenticado» y dejó listos los tokens `--sidebar-*`, `--accent`, `--status-needs-review*`, `shadow-card` y la escala de radios.

El diseño entregado («Sidebar y topbar») define el shell: sidebar de 248 px que colapsa a 72 px y topbar en tarjeta. También trae un título de página y tarjetas skeleton. Esos dos elementos son placeholders del dashboard y no entran aquí.

La regla de `docs/frontend/01_architecture_and_structure.md` dice que lo compartido no importa código de features. Por eso el shell compartido recibe por slots las piezas que dependen de `auth` y `records` (cerrar sesión y contador por revisar).

## Scope

**In:**

- Agregar con el CLI de shadcn (registro `base-nova`) los primitivos `sidebar`, `dropdown-menu` y sus dependencias (`sheet`, `tooltip`, `separator`, `skeleton`, hook `use-mobile`). Ajustarlos a los tokens INNOVA.
- Crear el shell compartido en `frontend/src/components/app-shell/`: sidebar, topbar, configuración de navegación y utilidades puras.
- Sidebar con marca, sección «Sistema» (Dashboard · Registros · Empresas · Reportes), sección «Cuenta» (avatar, nombre, rol) y «Cerrar sesión».
- Colapso del sidebar a solo iconos con botón en el topbar y atajo `Ctrl/⌘+B`. La preferencia persiste en la cookie `sidebar_state` y se lee en el servidor.
- Debajo de 768 px el sidebar se muestra como drawer (`Sheet`) que abre el mismo botón del topbar.
- Ítem activo según la ruta, con `aria-current="page"`.
- Contador real de registros `NEEDS_REVIEW` junto a «Registros», visible solo para `EDITOR`.
- Topbar con: botón de colapso, buscador deshabilitado, botón «Nuevo registro» (solo `EDITOR`), botón de notificaciones deshabilitado, `ThemeToggle` y bloque de usuario con menú.
- Menú del avatar en el topbar con el correo del usuario y «Cerrar sesión».
- Extraer la lógica de cierre de sesión a un hook `useLogout` en `features/auth`.
- Renombrar el enlace «Inicio» a «Dashboard». La página `/dashboard` no cambia.
- Pruebas unitarias de las utilidades puras del shell.
- Documentar el shell en `docs/frontend/05_ui_and_accessibility.md` y la ubicación de cierre de sesión en `docs/frontend/features/auth.md`.

**Out of scope (for future specs):**

- Contenido del Dashboard: saludo, contadores por estado, actividad reciente, subtítulo «Semana 38 · …» y tarjetas de métricas.
- Título de página pintado por el shell. Cada pantalla conserva su propio `<h1>`.
- Funcionalidad del buscador global y el atajo `⌘K`.
- Notificaciones (modelo, endpoint y panel).
- Logotipo o imagen de marca. La marca es solo texto.
- Rediseño interno de las pantallas de registros, detalle, entregas, empresas, reportes y login.
- Cambios en `/login` y en `(auth)`.
- Cambios de backend.

## Data model

Esta spec no agrega datos persistidos ni cambia contratos con Laravel. Reutiliza `AuthUser` de SPEC 02 y `GET /api/records` de SPEC 04.

### Navegación — `frontend/src/components/app-shell/nav-items.ts`

```ts
export type AppNavItem = {
  id: "dashboard" | "records" | "companies" | "reports";
  label: string;      // "Dashboard"
  href: string;       // "/dashboard"
  icon: LucideIcon;   // LayoutDashboard
};

export const appNavItems: readonly AppNavItem[];
export function isNavItemActive(pathname: string, href: string): boolean;
```

| `id` | `label` | `href` | `icon` |
| --- | --- | --- | --- |
| `dashboard` | Dashboard | `/dashboard` | `LayoutDashboard` |
| `records` | Registros | `/records` | `FileText` |
| `companies` | Empresas | `/companies` | `Building2` |
| `reports` | Reportes | `/reports` | `ChartColumn` |

`isNavItemActive` devuelve `true` si `pathname` es igual a `href` o empieza con `href + "/"`. Así `/records/new`, `/records/12` y `/records/12/delivery` activan «Registros», y `/recordsx` no.

### Utilidades de usuario — `frontend/src/components/app-shell/user-display.ts`

```ts
export function getInitials(name: string): string;       // "María López" -> "ML"
export function getRoleLabel(role: "EDITOR" | "VIEWER"): string; // "Editor" | "Viewer"
```

- `getInitials` toma la primera letra de las dos primeras palabras, en mayúsculas. Con una sola palabra devuelve una letra. Con cadena vacía devuelve `"?"`.
- El shell recibe el rol como literal y no importa `AuthUser` de `features/auth`.

### Props del shell — `frontend/src/components/app-shell/app-shell.tsx`

```ts
type AppShellUser = { name: string; email: string; role: "EDITOR" | "VIEWER" };

type AppShellProps = {
  user: AppShellUser;
  defaultOpen: boolean;          // leído de la cookie sidebar_state
  recordsBadge?: ReactNode;      // slot: contador por revisar
  sidebarLogout: ReactNode;      // slot: ítem «Cerrar sesión» del sidebar
  userMenuLogout: ReactNode;     // slot: ítem «Cerrar sesión» del menú del avatar
  children: ReactNode;
};
```

### Cookie de colapso

- Nombre: `sidebar_state` (el que escribe el `SidebarProvider` de shadcn).
- Valores: `"true"` expandido, `"false"` colapsado.
- Sin cookie, el sidebar inicia expandido.

### Contador por revisar

- Componente `frontend/src/features/records/components/needs-review-nav-badge.tsx`.
- Usa `useRecords({ status: "NEEDS_REVIEW", per_page: 1 })` y muestra `meta.total`.
- Su clave queda bajo `recordKeys.lists()`, así que las mutaciones existentes de confirmar, cancelar, reabrir, editar y crear lo invalidan sin cambios.

## UI contract

### Estructura

- El fondo de la página es `bg-background`. El sidebar y el topbar son tarjetas `bg-sidebar` / `bg-card` con borde `border-border`.
- El layout tiene 16 px de margen exterior y 16 px entre sidebar y contenido. Topbar y contenido de la pantalla están separados por 24 px.
- El contenido de cada pantalla se renderiza dentro de `<main>`, con el ancho máximo `max-w-6xl` actual.
- Se conserva `data-authenticated-app` en el contenedor raíz.

### Sidebar

- Variante `floating`, `collapsible="icon"`, `rounded-xl`, ancho 248 px expandido y 72 px colapsado (`--sidebar-width: 15.5rem`, `--sidebar-width-icon: 4.5rem`).
- `<nav aria-label="Principal">`.
- Marca: texto «TPVS», 20 px / 700 expandido y 14 px colapsado, centrado.
- Etiquetas de sección «Sistema» y «Cuenta»: 11 px, 600, mayúsculas, `tracking-[0.08em]`, `text-muted-foreground`. Ocultas en colapsado.
- Ítem de navegación: `next/link`, alto 44 px, `rounded-md`, icono de 20 px `aria-hidden`, texto 14 px / 600.
  - Reposo: `text-sidebar-foreground`.
  - Hover: `bg-sidebar-accent text-sidebar-accent-foreground`.
  - Activo: `bg-sidebar-primary text-sidebar-primary-foreground` y `aria-current="page"`.
  - Foco con teclado: anillo `ring` de 2 px con offset de 2 px.
  - En colapsado se centra el icono y aparece un tooltip con la etiqueta.
- Contador por revisar:
  - Solo se renderiza para `EDITOR`.
  - Solo aparece cuando `meta.total > 0`. Mientras carga o si la petición falla, no se muestra nada.
  - Píldora `bg-status-needs-review-bg text-status-needs-review`, 12 px / 700, `tabular-nums`.
  - `aria-label="N registros por revisar"`.
  - En colapsado se muestra en la esquina superior derecha del icono, 18 px de alto.
- Bloque «Cuenta» al pie:
  - Avatar circular de 36 px `bg-accent text-accent-foreground` con iniciales, `aria-hidden`.
  - Nombre (600) y rol («Editor» / «Viewer», 12 px, `text-muted-foreground`). En colapsado solo queda el avatar.
  - «Cerrar sesión»: ítem con icono `LogOut` y color `text-destructive`. En colapsado, solo icono con tooltip.
- En pantallas menores a 768 px el sidebar es un `Sheet` lateral con el mismo contenido expandido. Navegar a un enlace cierra el drawer.

### Topbar

- `<header>` en tarjeta `bg-card`, `rounded-lg`, `shadow-card`, padding 12 px / 16 px. Si no cabe, los elementos pasan a otra línea.
- Orden de izquierda a derecha:
  1. `SidebarTrigger` de solo icono, con `aria-label` «Contraer menú» / «Expandir menú» en escritorio y «Abrir menú» en móvil.
  2. Buscador: contenedor `rounded-full`, alto 44 px, borde `border-input`, icono `Search` y placeholder «Buscar empresa, afiliación o sucursal…». El `<input type="search">` está `disabled`, con `aria-label="Buscar (próximamente)"` y `title="Próximamente"`. No muestra el indicador `⌘K`.
  3. A la derecha, «Nuevo registro»: `Link` a `/records/new` con estilo de botón píldora `bg-accent text-accent-foreground` e icono `Plus`. Solo para `EDITOR`.
  4. Notificaciones: botón de solo icono (`Bell`, 20 px), `disabled`, `aria-label="Notificaciones (próximamente)"`.
  5. `ThemeToggle` existente.
  6. Bloque de usuario separado por un borde izquierdo: avatar, nombre y rol. Es el disparador de un `DropdownMenu` con `aria-label="Menú de cuenta"`. Por debajo de 640 px solo se ve el avatar.
- El menú del avatar contiene el correo del usuario como etiqueta no interactiva y el ítem «Cerrar sesión».

### Cerrar sesión

- Ambos ítems usan `useLogout`: llaman `authService.logout()`, limpian `queryClient` y hacen `router.replace("/login")`.
- Mientras está pendiente, el ítem queda deshabilitado y su texto cambia a «Cerrando sesión…».
- Si falla, se muestra el mensaje de error actual en un `role="alert"` visible junto al ítem que lo disparó.

### Sin cambios

- El estado de error «No se pudo validar la sesión con el servidor…» se sigue mostrando a pantalla completa, sin shell.
- `/login` no cambia.
- Las pantallas existentes conservan su `<h1>` y su contenido.

## Implementation plan

1. Agregar con `npx shadcn@latest add sidebar dropdown-menu` los primitivos y sus dependencias. Ajustar `components/ui/sidebar.tsx`:
   - anchos `15.5rem` y `4.5rem`;
   - variante `floating` con `rounded-xl` y borde `border-sidebar-border`;
   - `SidebarMenuButton` con alto 44 px, `rounded-md`, foco de 2 px con offset de 2 px y estado activo con `bg-sidebar-primary`;
   - `SidebarMenuBadge` visible en colapsado, en la esquina del icono.
   Ajustar `dropdown-menu.tsx` a `rounded-lg`, `shadow-popover` y foco de tokens. Verificar con `npx tsc --noEmit` y `npm run build`.
2. Crear `components/app-shell/nav-items.ts` y `components/app-shell/user-display.ts`. Agregar `frontend/tests/app-shell.test.ts` con pruebas de `isNavItemActive`, `getInitials` y `getRoleLabel`.
3. Crear `features/auth/hooks/use-logout.ts` con la lógica actual de `LogoutButton`. Reescribir `LogoutButton` para usarlo. Crear `features/auth/components/logout-nav-item.tsx` (ítem del sidebar) y `features/auth/components/logout-menu-item.tsx` (ítem del `DropdownMenu`).
4. Crear `features/records/components/needs-review-nav-badge.tsx` con `useRecords` y `SidebarMenuBadge`.
5. Crear `components/app-shell/app-sidebar.tsx` (client): marca, sección «Sistema» con `appNavItems`, `usePathname` e `isNavItemActive`, slot `recordsBadge`, sección «Cuenta» y slot `sidebarLogout`. Cerrar el `Sheet` al navegar en móvil.
6. Crear `components/app-shell/app-topbar.tsx` (client): `SidebarTrigger`, buscador deshabilitado, «Nuevo registro» condicional, notificaciones deshabilitadas, `ThemeToggle` y menú de usuario con slot `userMenuLogout`.
7. Crear `components/app-shell/app-shell.tsx` que compone `SidebarProvider` (`defaultOpen`), `AppSidebar`, `SidebarInset`, `AppTopbar` y `<main>`.
8. Reescribir `frontend/src/app/(app)/layout.tsx`:
   - leer `sidebar_state` con `cookies()` de `next/headers` (revisar la guía en `node_modules/next/dist/docs/`);
   - renderizar `AppShell` con el usuario y los slots `NeedsReviewNavBadge` (solo `EDITOR`), `LogoutNavItem` y `LogoutMenuItem`;
   - conservar la validación de sesión, el estado de error y `instant = false`.
   Eliminar el header anterior.
9. Revisar en el navegador `/dashboard`, `/records`, `/records/new`, detalle, entrega, `/companies` y `/reports` como `EDITOR` y `VIEWER`. Hacerlo en claro y oscuro, a 1440, 1024 y 375 px, y con el sidebar expandido y colapsado. Ajustar solo espaciados del shell.
10. Actualizar `docs/frontend/05_ui_and_accessibility.md` con una sección «Application shell»: ubicación en `components/app-shell/`, slots para piezas de features, cookie `sidebar_state`, breakpoint del drawer, regla de `aria-current` y de que las pantallas conservan su `<h1>`. Actualizar `docs/frontend/features/auth.md` con las dos ubicaciones de «Cerrar sesión» y `useLogout`.

## Acceptance criteria

- [x] Existen `sidebar.tsx`, `dropdown-menu.tsx`, `sheet.tsx` y `tooltip.tsx` en `frontend/src/components/ui/`, y solo usan clases de tokens (sin colores literales ni de la paleta de Tailwind).
- [x] Ningún archivo de `frontend/src/components/app-shell/` importa de `@/features/`.
- [x] El sidebar muestra «Dashboard», «Registros», «Empresas» y «Reportes» en ese orden, y el texto «Inicio» ya no aparece en `(app)/layout.tsx` ni en `components/app-shell/`.
- [x] En `/records/12/delivery` el ítem «Registros» tiene `aria-current="page"` y ningún otro ítem lo tiene.
- [x] `isNavItemActive("/recordsx", "/records")` devuelve `false` y `isNavItemActive("/records/new", "/records")` devuelve `true`.
- [x] `getInitials("María López")` devuelve `"ML"`, `getInitials("Ana")` devuelve `"A"` y `getInitials("")` devuelve `"?"`.
- [x] A 1440 px el sidebar mide 248 px expandido y 72 px colapsado.
- [x] Al pulsar el botón de colapso, o `Ctrl/⌘+B`, el sidebar alterna, y al recargar conserva el estado sin destello del estado contrario.
- [x] En colapsado, cada ítem muestra un tooltip con su etiqueta al recibir foco o hover.
- [x] A 375 px el sidebar no ocupa espacio, el botón «Abrir menú» abre un drawer con la navegación completa, y pulsar un enlace navega y cierra el drawer.
- [x] Como `EDITOR`, con 3 registros en `NEEDS_REVIEW`, junto a «Registros» aparece «3» con `aria-label="3 registros por revisar"`.
- [x] Como `EDITOR`, con 0 registros en `NEEDS_REVIEW`, no aparece el contador.
- [x] Como `VIEWER`, no aparece el contador ni se hace la petición `GET /api/records?status=NEEDS_REVIEW`.
- [x] Tras confirmar un registro en revisión, el contador baja en uno sin recargar la página.
- [x] «Nuevo registro» aparece en el topbar solo para `EDITOR` y lleva a `/records/new`.
- [x] El input del buscador está `disabled`, tiene `aria-label="Buscar (próximamente)"` y no muestra `⌘K`.
- [x] El botón de notificaciones está `disabled` y tiene `aria-label="Notificaciones (próximamente)"`.
- [x] `ThemeToggle` está en el topbar y sigue alternando el tema.
- [x] El bloque de usuario muestra iniciales, nombre y «Editor» o «Viewer» según el rol, en el sidebar expandido y en el topbar.
- [x] El menú del avatar abre con teclado (Enter/Espacio), muestra el correo y «Cerrar sesión», y se cierra con Esc devolviendo el foco al disparador.
- [x] «Cerrar sesión» desde el sidebar y desde el menú del avatar termina la sesión y lleva a `/login`.
- [x] Si el cierre de sesión falla, aparece el mensaje de error con `role="alert"`.
- [x] Con Tab se recorren en orden el botón de colapso, los ítems del sidebar y los controles habilitados del topbar, y todos muestran el anillo de foco de 2 px.
- [x] Si `getAuthenticatedUser` lanza error, se muestra el mensaje de sesión a pantalla completa sin sidebar ni topbar.
- [x] La consola del navegador no muestra advertencias de hidratación en `/dashboard`, `/records` ni `/reports`, con el sidebar expandido ni colapsado.
- [x] `docs/frontend/05_ui_and_accessibility.md` tiene la sección «Application shell» y `docs/frontend/features/auth.md` documenta `useLogout` y las dos ubicaciones de «Cerrar sesión».
- [x] `npm run lint`, `npx tsc --noEmit`, `npm test` y `npm run build` en `frontend/` pasan sin errores.
- [x] Las pantallas del paso 9 se revisaron en claro y oscuro, a 1440, 1024 y 375 px, sin texto ilegible, controles invisibles ni scroll horizontal de página.

## Decisions

- **Sí:** solo sidebar y topbar. El título de página, el subtítulo de semana y las tarjetas del diseño son del Dashboard y van en su spec.
- **Sí:** cada pantalla conserva su `<h1>`. Evita duplicar títulos y no toca las features.
- **No:** que el shell pinte el `<h1>` según la ruta. Obligaría a quitarlo de cada pantalla y no sabe títulos dinámicos, como el nombre de la empresa en el detalle.
- **Sí:** `Sidebar` de shadcn (`collapsible="icon"`, variante `floating`). Trae colapso, drawer móvil, cookie, atajo de teclado y tooltips resueltos, y el tema ya define los tokens `--sidebar-*`.
- **No:** sidebar propio. Habría que reimplementar drawer, cookie y tooltips.
- **Sí:** persistir el colapso en la cookie `sidebar_state` y leerla en el servidor. Evita el destello que daría `localStorage`.
- **Sí:** drawer por debajo de 768 px, el breakpoint de `use-mobile` de shadcn.
- **Sí:** el shell vive en `components/app-shell/` y recibe por slots el contador y los ítems de cierre de sesión. Cumple la regla de que lo compartido no importa features.
- **No:** poner el shell en `features/`. La navegación es compartida y `docs/frontend/features/dashboard.md` dice que pertenece a componentes de layout compartidos.
- **Sí:** contador real solo para `EDITOR`, con `GET /records?status=NEEDS_REVIEW&per_page=1` y `meta.total`. Reusa un endpoint existente, y su clave de caché se invalida con las mutaciones actuales.
- **No:** mostrar el contador al `VIEWER`. No tiene acciones de revisión.
- **No:** endpoint nuevo de conteos. Lo definirá la spec del Dashboard.
- **Sí:** ocultar el contador si carga, falla o es 0. Un número incorrecto o un «0» falso es peor que no mostrar nada.
- **Sí:** buscador visible pero deshabilitado, sin `⌘K`. Respeta el diseño sin prometer una función que no existe.
- **No:** conectar el buscador a `/records?search=`. Se decidió dejarlo para otra spec.
- **Sí:** notificaciones visibles y deshabilitadas, por decisión de diseño.
- **Sí:** «Cerrar sesión» en el sidebar y en el menú del avatar, con un solo hook `useLogout`.
- **Sí:** `ThemeToggle` en el topbar. El diseño no lo incluye, pero SPEC 15 exige que esté en el header autenticado.
- **Sí:** etiqueta de rol «Editor» / «Viewer», como el diseño.
- **Sí:** «Dashboard» en lugar de «Inicio», como el diseño y `docs/10_ui_ux.md`.
- **Sí:** marca «TPVS» en mayúsculas, como el README y el header actual. El diseño muestra «Tpvs».
- **Sí:** conservar `max-w-6xl` en `<main>`. Con el sidebar abierto a 1440 px el ancho útil es casi el mismo, y las pantallas ya están probadas con ese ancho.

## Risks

| Risk | Mitigation |
| --- | --- |
| El `sidebar` de shadcn para `base-nova` puede traer anchos, radios o anillos que no siguen INNOVA (`ring-2 ring-sidebar-ring` sin offset, `rounded-lg`). | El paso 1 ajusta el primitivo y un criterio exige solo clases de tokens. |
| `SidebarMenuBadge` se oculta por defecto en modo icono. | El paso 1 lo hace visible en la esquina del icono. Hay un criterio para el estado colapsado. |
| Leer `cookies()` en el layout puede cambiar el modo de renderizado en Next 16. | El layout ya es dinámico por la sesión (`instant = false`). Se revisa la guía en `node_modules/next/dist/docs/` antes de escribir el código. |
| `useIsMobile` decide el drawer en el cliente y puede generar un cambio visual en la hidratación en móvil. | En escritorio el SSR coincide. En móvil el sidebar está oculto hasta abrir el drawer. La consola sin advertencias es criterio. |
| Un `<h1>` del contenido más el texto de marca pueden confundir lectores de pantalla. | La marca no es encabezado. Solo las pantallas usan `<h1>`. |
| Dos instancias de «Cerrar sesión» pueden divergir. | Ambas usan `useLogout` y `LogoutButton` también. |

## What is **not** in this spec

- Contenido del Dashboard (saludo, métricas, actividad reciente, semana actual).
- Búsqueda global y atajo `⌘K`.
- Notificaciones funcionales.
- Logotipo o imagen de marca.
- Rediseño interno de pantallas y del login.
- Endpoints nuevos en Laravel.

Cada uno de esos puntos, si se hace, va en su propia spec.
