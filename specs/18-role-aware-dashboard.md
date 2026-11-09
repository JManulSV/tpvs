# SPEC 18 — Dashboard por rol

> **Status:** Approved
> **Depends on:** SPEC 04, SPEC 09, SPEC 13, SPEC 15, SPEC 16
> **Date:** 2026-11-09
> **Objective:** Reemplazar el placeholder de `/dashboard` por el centro de trabajo de las plantillas `Dashboard{Editor,Viewer}{,Mobile}.dc.html`, alimentado por un endpoint nuevo `GET /api/dashboard` que Laravel calcula según el rol.

## Why this spec exists

Hoy `/dashboard` muestra «Panel de TPVS» y un texto fijo. SPEC 16 dejó fuera de alcance el contenido del Dashboard: saludo, contadores por estado, actividad reciente y retorno del mes.

`docs/frontend/features/dashboard.md` pide un endpoint de lectura propio. También prohíbe calcular totales oficiales en el navegador y mostrar ceros cuando la petición falla. Para armar el Dashboard con los endpoints actuales harían falta unas seis llamadas a `/records?status=X&per_page=1` y una a `/reports`.

Las plantillas pintan dentro de la página un buscador, «Nuevo registro» y notificaciones. Esos controles ya existen en el topbar y en el FAB móvil del shell (SPEC 16 y el commit `3f8a25b`), así que no se repiten aquí.

`docs/10_ui_ux.md` define el Dashboard como centro de trabajo, no como analítica. No lleva gráficas.

## Scope

**In:**

- Endpoint `GET /api/dashboard` en Laravel, autenticado para ambos roles, con respuesta según el rol.
- Configuración de la zona horaria del negocio, `America/Mexico_City`, en `backend/config/tpvs.php`. Se usa para calcular el mes actual. No cambia `app.timezone`.
- Pruebas de feature del endpoint en `backend/tests/Feature/DashboardApiTest.php`.
- Feature `frontend/src/features/dashboard/` con tipos, servicio, hook, utilidades puras y componentes.
- Dashboard del `EDITOR`:
  - hero con saludo y «Tienes N registros por revisar»;
  - «Ir a revisión», solo cuando N > 0;
  - tira de seis contadores clicables;
  - actividad reciente.
- Dashboard del `VIEWER`: hero con el retorno acumulado del mes y los registros confirmados del mes, más la actividad reciente.
- Diseño responsivo:
  - desde `md`, tabla de actividad y tira de contadores en `auto-fit`;
  - por debajo de `md`, tira de 2 columnas y actividad en lista.
- Saludo «Buenos días / tardes / noches, {nombre}», calculado en el servidor de Next con la hora de `America/Mexico_City`.
- Lectura de los filtros `status`, `year` y `month` desde la URL en `/records`, para que los enlaces del Dashboard abran la lista ya filtrada.
- Envolver `getAuthenticatedUser` en `cache()` de React, para que el layout y la página no consulten `/me` dos veces.
- Estados de carga (skeleton), error con reintento y actividad vacía.
- Pruebas unitarias de las utilidades en `frontend/tests/dashboard.test.ts`.
- Documentar el contrato en `docs/backend/03_api_contract.md` y actualizar `docs/frontend/features/dashboard.md` y `docs/frontend/features/records.md`.

**Out of scope (for future specs):**

- Gráficas o analítica (`docs/10_ui_ux.md`: «No charts are required»).
- Buscador funcional, notificaciones y atajo `⌘K`. Siguen deshabilitados en el topbar.
- Polling o actualización en tiempo real del Dashboard.
- Actividad basada en eventos de `History` (por ejemplo, «María confirmó…»).
- Selector de mes o de periodo en el Dashboard. Siempre se usa el mes actual.
- Cambiar `app.timezone` de Laravel o el formato de los timestamps existentes.
- Reemplazar `NeedsReviewNavBadge` por el nuevo endpoint.
- Escribir en la URL los filtros que el usuario elige después en `/records`. Solo se leen al entrar.
- Cambios al shell, al topbar o al FAB móvil.

## Data model

Esta spec no agrega tablas ni migraciones. Reutiliza `records`, `record_details` y `companies`. Agrega un contrato de lectura y una opción de configuración.

### Configuración — `backend/config/tpvs.php`

```php
return [
    'business_timezone' => env('TPVS_BUSINESS_TIMEZONE', 'America/Mexico_City'),
];
```

### Mes actual

- `now(config('tpvs.business_timezone'))` define `year` y `month`.
- Un registro pertenece al mes si `records.year = year` y el mes de `week_end_date` es `month`. Es exactamente el filtro de `ReportQuery::base` (`docs/10_ui_ux.md` §16.1). En las semanas que cruzan el año, manda el año de negocio del registro.
- Los registros con `week_end_date = null` no cuentan en ningún total mensual.

### Contrato — `GET /api/dashboard`

Respuesta para `EDITOR`:

```json
{
  "data": {
    "period": { "year": 2026, "month": 11, "timezone": "America/Mexico_City" },
    "status_counts": {
      "needs_review": 4,
      "error": 1,
      "pending": 3,
      "processing": 2,
      "confirmed_month": 128,
      "canceled_month": 5
    },
    "month_summary": { "confirmed_count": 128, "return_total": "147000.00" },
    "recent_activity": [
      {
        "id": 42,
        "company": { "id": 3, "name": "Company ABC" },
        "year": 2026,
        "week": 38,
        "status": "CONFIRMED",
        "updated_at": "2026-11-09T19:48:00.000000Z"
      }
    ]
  }
}
```

Respuesta para `VIEWER`: la misma forma, **sin** la clave `status_counts`.

| Campo | Regla |
| --- | --- |
| `needs_review`, `error`, `pending`, `processing` | Conteo de todos los registros en ese estado, sin filtro de mes. |
| `confirmed_month` | Registros `CONFIRMED` del mes actual. |
| `canceled_month` | Registros `CANCELED` del mes actual. |
| `month_summary.confirmed_count` | Igual a `confirmed_month`. |
| `month_summary.return_total` | Suma de `record_details.return_amount` de los registros `CONFIRMED` del mes, como cadena decimal con 2 decimales. Vale `"0.00"` si no hay registros. |
| `recent_activity` | Los 5 registros más recientes por `updated_at desc, id desc`, de cualquier estado. `company`, `year` y `week` pueden ser `null`. |

- No requiere parámetros. Los parámetros desconocidos se ignoran.
- Sin sesión responde `401`, igual que el resto de la API.
- Implementación:
  - `App\Http\Controllers\Api\DashboardController@show`;
  - `App\Domain\Dashboard\DashboardQuery`, que hace un `GROUP BY status` para los estados sin mes y un agregado filtrado para el mes;
  - `App\Http\Resources\DashboardActivityResource`.
- `RecordResource` no cambia.

### Tipos — `frontend/src/features/dashboard/types.ts`

```ts
export type DashboardStatusCounts = {
  needs_review: number; error: number; pending: number; processing: number;
  confirmed_month: number; canceled_month: number;
};

export type DashboardActivityItem = {
  id: number;
  company: { id: number; name: string } | null;
  year: number | null;
  week: number | null;
  status: RecordStatus;
  updated_at: string;
};

export type DashboardSummary = {
  period: { year: number; month: number; timezone: string };
  status_counts?: DashboardStatusCounts; // ausente para VIEWER
  month_summary: { confirmed_count: number; return_total: string };
  recent_activity: DashboardActivityItem[];
};
```

### Clave de caché

- `dashboardKeys.summary = [...recordKeys.lists(), "dashboard"]`.
- Al quedar bajo `recordKeys.lists()`, las mutaciones actuales de crear, editar, confirmar, cancelar, reintentar IA y subir imagen lo invalidan sin cambios.
- Sin `refetchInterval`. `refetchOnWindowFocus` queda en el valor por defecto del `QueryClient`.

### Utilidades puras — `frontend/src/features/dashboard/utils.ts`

```ts
export function getGreeting(date: Date, timeZone: string): "Buenos días" | "Buenas tardes" | "Buenas noches";
export function getReviewHeadline(count: number): string;
export function formatRelativeTime(iso: string, now: Date): string;
export function getMonthName(month: number): string; // 11 -> "noviembre"
export function getStatusCellHref(cell: DashboardStatusCellId, period: { year: number; month: number }): string;
```

- `getGreeting`: de 05:00 a 11:59 «Buenos días», de 12:00 a 18:59 «Buenas tardes», y el resto «Buenas noches». La hora se toma en `timeZone`.
- `getReviewHeadline`:
  - con 0, «No tienes registros por revisar»;
  - con 1, «Tienes 1 registro por revisar»;
  - con N, «Tienes N registros por revisar».
- `formatRelativeTime`:
  - menos de 1 min, «hace un momento»;
  - menos de 60 min, «hace N min»;
  - menos de 24 h, «hace N h»;
  - día calendario anterior en `America/Mexico_City`, «ayer»;
  - en otro caso, `formatDate` de la fecha.
- `getStatusCellHref`:
  - `needs_review`, `error`, `pending` y `processing` llevan a `/records?status=NEEDS_REVIEW|ERROR|PENDING|PROCESSING`;
  - `confirmed_month` y `canceled_month` llevan a `/records?status=CONFIRMED|CANCELED&year=Y&month=M`.
- La zona `America/Mexico_City` queda en la constante `BUSINESS_TIME_ZONE` de `frontend/src/lib/business-time.ts`.

## UI contract

### Fusión con el layout existente

El layout autenticado de SPEC 16 y del commit `3f8a25b` se mantiene sin cambios. De las plantillas solo se toma el **contenido** de la página. Ese contenido se renderiza dentro del contenedor que ya da `AppShell` (`max-w-6xl`, `px-4 pt-4` y el espacio inferior para la barra en móvil). La página no agrega su propio margen exterior ni su propio fondo.

| Elemento de la plantilla | Qué se hace |
| --- | --- |
| Sidebar azul (`.side`) con marca, navegación, usuario y «Cerrar sesión» | Se descarta. Se usa `AppSidebar`. |
| Contador «4» junto a «Registros» | Se descarta. Se usa `NeedsReviewNavBadge`. |
| Fila de encabezado: `<h1>` «Dashboard», buscador, «Nuevo registro» y notificaciones | Solo el `<h1>` pasa a la página. El buscador, «Nuevo registro» y las notificaciones siguen en `AppTopbar`. |
| Topbar móvil (`.top`) con marca, buscar, notificaciones y avatar | Se descarta. Se usa `AppMobileTopbar`. |
| Barra inferior móvil (`.bar`) | Se descarta. Se usa `AppBottomNav`. |
| FAB «Nuevo registro» en móvil | Se descarta. Se usa el FAB de `AppBottomNav`, que ya aparece solo en `/dashboard` para `EDITOR`. |
| Hero, tira de contadores y actividad reciente | Se implementan en `features/dashboard/`, como se describe abajo. |

Los colores literales de la plantilla (`--canvas`, `--surface`, `--ink`, etc.) se traducen a los tokens globales de SPEC 15 (`bg-background`, `bg-card`, `text-foreground`, etc.). Esta spec no agrega tokens.

### Página — `frontend/src/app/(app)/dashboard/page.tsx`

- Es un Server Component. Obtiene el usuario con `getAuthenticatedUser()` (ya envuelto en `cache()`).
- Calcula `greeting = getGreeting(new Date(), BUSINESS_TIME_ZONE)` y el primer nombre del usuario.
- Renderiza `<DashboardScreen role greeting firstName />`. Así el saludo llega como texto del servidor y no puede causar un error de hidratación.
- Conserva `export const instant = false`.
- `<h1>` «Dashboard»:
  - desde `md`: 24/32 px, 700, `tracking-[-0.01em]`, visible;
  - por debajo de `md`: `sr-only`.
- Es el único `<h1>` de la página. Los separan 24 px del contenido desde `md` y 16 px en móvil.

### Hero (ambos roles)

- `<section>` con `bg-hero text-on-hero rounded-xl` y `relative overflow-hidden`. Padding de 32 px desde `md` y 24 px en móvil.
- Patrón de puntos decorativo, solo desde `md`. Es el mismo de `login-hero.tsx`: `aria-hidden` y `pointer-events-none`, sobre el 45 % derecho.
- Overline: 11/16 px, 600, mayúsculas, `tracking-[0.08em]`, opacidad 85 %.

**Editor** (`aria-label="Resumen del día"`):

- Overline «{greeting}, {firstName}».
- Titular `<p>` con `getReviewHeadline(needs_review)`: 32/40 px desde `md` y 24/32 px en móvil, 700.
- Sin subtítulo.
- «Ir a revisión» solo si `needs_review > 0`:
  - es un `Link` a `/records?status=NEEDS_REVIEW` con forma de píldora `bg-card text-primary`;
  - icono `ArrowRight` a la derecha;
  - alto de 40 px desde `md` y de 44 px en móvil;
  - en móvil ocupa su propia fila (`self-start`).

**Viewer** (`aria-label="Resumen"`):

- Overline «{greeting}, {firstName} · Retorno acumulado de {getMonthName(month)}».
- Monto `formatMoney(return_total)`: 48/56 px desde `md` y 40/48 px en móvil, 700, `tabular-nums`.
- Bloque de confirmados con `confirmed_count` (32/40 px, `tabular-nums`) y el overline «Registros confirmados del mes».
  - Desde `md`, va a la derecha y alineado al texto.
  - En móvil, va debajo con un borde superior `border-on-hero/10` y en una sola fila con `items-baseline`.

### Tira de contadores (solo Editor)

- `<section aria-label="Registros por estado">`, tarjeta `bg-border` con un gap de 1 px. Así se dibujan los separadores, con `rounded-lg`, borde y `shadow-card`.
- Desde `md`, `grid-cols-[repeat(auto-fit,minmax(150px,1fr))]`. En móvil, `grid-cols-2`.
- Seis celdas en este orden. Cada una es un `Link` a `getStatusCellHref`:

| Celda | Etiqueta | Punto |
| --- | --- | --- |
| `needs_review` | Por revisar | `bg-status-needs-review` |
| `error` | Con error | `bg-status-error` |
| `pending` | Pendientes | `bg-status-pending` |
| `processing` | Procesando | `bg-status-processing` |
| `confirmed_month` | Confirmados del mes | `bg-status-confirmed` |
| `canceled_month` | Cancelados del mes | `bg-status-canceled` |

- Celda: `bg-card`, padding de 20 px (16 px y alto mínimo de 84 px en móvil). Hover `bg-muted`. Foco con anillo `ring` de 2 px hacia dentro.
- Etiqueta 12/16 px, 600, `text-muted-foreground`, con un punto de 10 px `aria-hidden`.
- Número 28/36 px, 700, `tabular-nums`.
- `aria-label` de la celda: «{etiqueta}: N. Ver registros».

### Actividad reciente (ambos roles)

- Tarjeta `bg-card rounded-lg border shadow-card` con `aria-labelledby="dashboard-activity"`.
- Encabezado `<h2 id="dashboard-activity">` «Actividad reciente», 16/24 px, 600.
- Enlace a `/records` en `text-accent-foreground` con `ChevronRight`. Dice «Ver registros» desde `md` y «Ver todo» en móvil. En móvil mide al menos 44 px de alto.
- Desde `md` es una tabla:
  - columnas `Empresa · Semana · Estado · Actividad`, con encabezados de 11 px en mayúsculas sobre `bg-muted`;
  - `min-w-[520px]` dentro de un contenedor con `overflow-x-auto`.
- Por debajo de `md` es una `<ul>`:
  - cada `<li>` muestra la empresa en negrita y, debajo, «Semana N · {tiempo relativo}» a 12 px en `text-muted-foreground`;
  - el `StatusBadge` va a la derecha.
- Toda la fila o elemento es clicable y lleva a `/records/{id}`:
  - en la tabla, el nombre de la empresa es un `Link` con un pseudo-elemento que cubre toda la fila (`relative` en `<tr>`);
  - en la lista, el `<li>` contiene un `Link` de bloque.
- Sin empresa se muestra «Sin empresa» en `text-muted-foreground`. Sin semana se muestra «—».
- El estado usa `StatusBadge` con las etiquetas de `recordStatusMeta`.
- La hora relativa usa `formatRelativeTime`. Su `<time dateTime>` tiene en `title` la fecha completa (`formatDateTime`).
- Vacío: «Todavía no hay actividad.» en `text-muted-foreground`, dentro de la tarjeta.

### Estados de la consulta

- **Cargando:**
  - hero con la overline y el saludo reales;
  - `Skeleton` en lugar del titular o del monto;
  - seis celdas `Skeleton` (solo Editor);
  - cuatro filas `Skeleton` en la actividad.
  - Nunca se muestra «0» mientras carga.
- **Error:** una sola tarjeta con `role="alert"`, «No se pudo cargar el resumen del Dashboard.» y un `Button` «Reintentar» que llama `refetch()`. No se muestran el hero numérico, la tira ni la actividad con datos vacíos.
- **Error con datos en caché:** se siguen mostrando los datos anteriores. No se muestra la alerta.

### `/records` con filtros en la URL

- `RecordsScreen` lee `status`, `year` y `month` de `useSearchParams()` una sola vez, como estado inicial de `filters`.
- Se descartan los valores inválidos: un estado fuera de `recordStatuses`, un mes fuera de 1–12 o un año que no sea entero positivo.
- Los selects de Estado, Año y Mes muestran esos valores. El usuario puede cambiarlos como hoy.
- Cambiar filtros no reescribe la URL.
- `page.tsx` de `/records` envuelve la pantalla en `<Suspense>` si Next 16 lo exige para `useSearchParams` (revisar `node_modules/next/dist/docs/`).

## Implementation plan

1. **Backend: configuración y consulta.**
   - Crear `config/tpvs.php` y `App\Domain\Dashboard\DashboardQuery` con `statusCounts(year, month)`, `monthSummary(year, month)` y `recentActivity(limit)`.
   - Verificar con `php artisan tinker` contra la base de desarrollo.
2. **Backend: endpoint.**
   - Crear `DashboardActivityResource`, `DashboardController@show` y la ruta `GET /dashboard` en el grupo `auth:sanctum`.
   - Omitir `status_counts` cuando el usuario no es `EDITOR`.
   - Formatear `return_total` con 2 decimales.
3. **Backend: pruebas.** Crear `tests/Feature/DashboardApiTest.php` con:
   - `401` sin sesión;
   - la forma de la respuesta para `EDITOR` y para `VIEWER`;
   - los conteos por estado;
   - el mes calculado con `week_end_date` (incluida una semana que cruza el mes);
   - los registros con `week_end_date` nulo;
   - el mes actual con `Carbon::setTestNow` cerca de la medianoche UTC, para comprobar que se usa `America/Mexico_City`;
   - `return_total` igual al de `GET /api/reports` para el mismo `year` y `month`;
   - `"0.00"` sin datos;
   - el orden y el límite de 5 de la actividad.
   Correr `php artisan test` y Pint en los archivos nuevos.
4. **Frontend: utilidades.**
   - Crear `lib/business-time.ts` y `features/dashboard/utils.ts`.
   - Crear `frontend/tests/dashboard.test.ts` con pruebas de las cinco funciones, incluidos los bordes de hora (04:59, 05:00, 11:59, 12:00, 18:59, 19:00 en Ciudad de México) y el caso «ayer».
5. **Frontend: datos.** Crear `features/dashboard/types.ts`, `services/dashboard.service.ts` (`apiClient.get("dashboard")`) y `hooks/use-dashboard.ts` con `dashboardKeys`.
6. **Frontend: `/records` desde la URL.**
   - Agregar a `RecordsScreen` el estado inicial desde `useSearchParams` con validación.
   - Agregar `<Suspense>` en la página si hace falta.
   - Verificar a mano `/records?status=NEEDS_REVIEW` y `/records?status=CONFIRMED&year=2026&month=11`.
7. **Frontend: componentes.** Crear en `features/dashboard/components/`:
   - `dashboard-hero.tsx` (variantes Editor y Viewer);
   - `status-strip.tsx`;
   - `recent-activity.tsx` (tabla y lista);
   - `dashboard-screen.tsx` (consulta, estados de carga y error, y composición por rol).
8. **Frontend: página.**
   - Envolver `getAuthenticatedUser` en `cache()` de React.
   - Reescribir `app/(app)/dashboard/page.tsx` con el `<h1>`, el saludo calculado en el servidor y `DashboardScreen`.
9. **Revisión visual.**
   - Revisar `/dashboard` como `EDITOR` y `VIEWER`, en claro y oscuro, a 1440, 1024, 768 y 375 px, con el sidebar expandido y colapsado.
   - Comparar con las cuatro plantillas.
   - Probar el estado de carga con la red lenta, el error deteniendo el backend y la actividad vacía con la base sin registros.
   - Ajustar solo espaciados.
10. **Documentación.**
    - Agregar la sección «Dashboard Contract — SPEC 18» a `docs/backend/03_api_contract.md`.
    - Marcar `docs/frontend/features/dashboard.md` como implementado: ruta `/dashboard`, endpoint, regla de mes, sin polling y estados.
    - Documentar en `docs/frontend/features/records.md` los filtros iniciales que se leen de la URL.

## Acceptance criteria

- [ ] `GET /api/dashboard` sin sesión responde `401`.
- [ ] Como `EDITOR`, la respuesta incluye `period`, `status_counts` con las seis claves, `month_summary` y `recent_activity`.
- [ ] Como `VIEWER`, la respuesta no incluye la clave `status_counts`.
- [ ] Un registro `CONFIRMED` con `week_end_date = 2026-11-02` y `week_start_date = 2026-10-27` cuenta en `confirmed_month` de noviembre 2026 y no en octubre.
- [ ] Con la hora `2026-12-01T03:00:00Z` (30 de noviembre, 21:00 en Ciudad de México), `period.month` es `11`.
- [ ] `month_summary.return_total` es igual a `data.totals.return_total` de `GET /api/reports?year=Y&month=M` con los mismos datos.
- [ ] Sin registros confirmados en el mes, `return_total` es `"0.00"` y `confirmed_count` es `0`.
- [ ] `recent_activity` tiene como máximo 5 elementos, ordenados por `updated_at` descendente.
- [ ] `php artisan test` pasa, incluido `DashboardApiTest`.
- [ ] `getGreeting` devuelve «Buenos días» a las 05:00 y «Buenas noches» a las 04:59 y a las 19:00, en `America/Mexico_City`.
- [ ] `getReviewHeadline(0)`, `(1)` y `(4)` devuelven «No tienes registros por revisar», «Tienes 1 registro por revisar» y «Tienes 4 registros por revisar».
- [ ] `getStatusCellHref("confirmed_month", { year: 2026, month: 11 })` devuelve `/records?status=CONFIRMED&year=2026&month=11`.
- [ ] Ningún archivo del sidebar, del topbar ni de la barra inferior (`components/app-shell/`) cambia en esta spec.
- [ ] `/dashboard` muestra un solo sidebar, un solo topbar y, a 375 px, una sola barra inferior y un solo FAB «Nuevo registro».
- [ ] `/dashboard` tiene exactamente un `<h1>`, con el texto «Dashboard». Es visible a 1440 px y `sr-only` a 375 px.
- [ ] Como `EDITOR` con 4 registros `NEEDS_REVIEW`, el hero dice «Tienes 4 registros por revisar». «Ir a revisión» lleva a `/records?status=NEEDS_REVIEW`, con el filtro Estado en «Por revisar».
- [ ] Como `EDITOR` con 0 registros `NEEDS_REVIEW`, el hero dice «No tienes registros por revisar» y no aparece «Ir a revisión».
- [ ] La tira muestra seis celdas en el orden de la tabla del contrato. Pulsar «Confirmados del mes» abre `/records` con Estado, Año y Mes ya seleccionados.
- [ ] Como `VIEWER`, no aparece la tira de contadores. El hero muestra «Retorno acumulado de noviembre», el monto con `formatMoney` y el número de confirmados del mes.
- [ ] Desde `md` la actividad es una tabla. A 375 px es una lista, y en ambos casos pulsar cualquier punto de la fila abre `/records/{id}`.
- [ ] Con la base vacía se muestra «Todavía no hay actividad.» y todos los contadores en 0.
- [ ] Mientras la petición carga, no aparece ningún «0» ni «$0.00».
- [ ] Con el backend detenido, aparece «No se pudo cargar el resumen del Dashboard.» con `role="alert"`, y «Reintentar» vuelve a hacer la petición.
- [ ] Tras confirmar un registro en revisión y volver a `/dashboard`, «Por revisar» baja en uno y «Confirmados del mes» sube en uno, sin recargar la página.
- [ ] El Dashboard no hace peticiones periódicas mientras la pestaña está quieta.
- [ ] Al cargar `/dashboard`, el BFF hace una sola petición a `/api/auth/me`.
- [ ] `/records?status=FOO&month=13` carga sin filtros y sin errores.
- [ ] La consola del navegador no muestra advertencias de hidratación en `/dashboard` ni en `/records?status=NEEDS_REVIEW`.
- [ ] Los archivos de `features/dashboard/` solo usan clases de tokens (sin colores literales ni de la paleta de Tailwind).
- [ ] Todos los enlaces y celdas se alcanzan con Tab y muestran el anillo de foco de 2 px.
- [ ] `npm run lint`, `npx tsc --noEmit`, `npm test` y `npm run build` en `frontend/` pasan sin errores.
- [ ] La revisión del paso 9 se hizo en claro y oscuro, a 1440, 1024, 768 y 375 px, sin scroll horizontal de página ni texto ilegible.
- [ ] `docs/backend/03_api_contract.md`, `docs/frontend/features/dashboard.md` y `docs/frontend/features/records.md` describen el nuevo comportamiento.

## Decisions

- **Sí:** endpoint dedicado `GET /api/dashboard`. Hace una sola petición, Laravel calcula los totales oficiales y la respuesta se filtra por rol. Es lo que pide `docs/frontend/features/dashboard.md`.
- **No:** componer `/records?status=X&per_page=1` seis veces más `/reports`. Son muchas peticiones, puede quedar a medias y el error es más difícil de mostrar.
- **Sí:** «del mes» se calcula por el mes de `week_end_date`, la misma regla de Reportes. El retorno del Viewer coincide con el reporte del mes.
- **No:** «del mes» por fecha de confirmación o cancelación. Exigiría leer `History` y no coincidiría con Reportes.
- **Sí:** los registros cancelados sin `week_end_date` (por ejemplo, desde `ERROR`) no cuentan en «Cancelados del mes». No tienen periodo de negocio.
- **Sí:** el mes actual se calcula con la zona de negocio `America/Mexico_City` en `config/tpvs.php`, sin tocar `app.timezone`.
- **No:** cambiar `app.timezone`. Afectaría todos los timestamps y cálculos existentes.
- **Sí:** el saludo depende de la hora, se calcula en el servidor de Next con `America/Mexico_City` y llega como prop. Evita el error de hidratación y no depende del reloj del equipo.
- **Sí:** los estados de trabajo (`NEEDS_REVIEW`, `ERROR`, `PENDING`, `PROCESSING`) se cuentan sin filtro de mes. Son trabajo pendiente sin importar el periodo.
- **Sí:** la actividad reciente son los 5 registros con `updated_at` más reciente, con su estado actual, y Editor y Viewer ven lo mismo. `index` ya ordena por `updated_at`.
- **No:** actividad basada en `History`. Es más compleja y no es lo que muestra la plantilla.
- **Sí:** `updated_at` se expone solo en `DashboardActivityResource`. `RecordResource` no cambia.
- **Sí:** el hero del Editor es solo un titular, sin subtítulo, por decisión del usuario. Los demás estados se ven en la tira.
- **Sí:** «Ir a revisión» se oculta cuando no hay registros por revisar, para no llevar a una lista vacía.
- **Sí:** un solo `<h1>` «Dashboard», `sr-only` en móvil. La estructura de encabezados no cambia con el breakpoint, y el texto del hero es un `<p>`.
- **No:** el `<h1>` en el hero móvil, como la plantilla. El nivel del encabezado cambiaría según el ancho.
- **Sí:** las celdas y «Ir a revisión» abren `/records` ya filtrado mediante la URL. Para eso `/records` lee `status`, `year` y `month` al entrar.
- **No:** sincronizar en la URL los filtros que el usuario cambia después en `/records`. Es otra mejora y va en otra spec.
- **No:** polling, por decisión del usuario. Los datos se refrescan al cargar, al volver a la pestaña y cuando una mutación de registros invalida la caché.
- **Sí:** la clave `[...recordKeys.lists(), "dashboard"]`. Las mutaciones existentes la invalidan sin tocarlas.
- **Sí:** no se repiten en la página el buscador, «Nuevo registro» ni las notificaciones de la plantilla. Ya están en el topbar y en el FAB móvil del shell.
- **Sí:** los montos se muestran con `formatMoney`, con 2 decimales, aunque la plantilla muestre «$147,000». Es el formato oficial del resto del sistema.
- **No:** reemplazar `NeedsReviewNavBadge` por este endpoint. Funciona hoy y cambiarlo no aporta a este objetivo.
- **Sí:** `getAuthenticatedUser` se envuelve en `cache()` de React, para que la página lea el nombre sin otra consulta a `/me`.

## Risks

| Risk | Mitigation |
| --- | --- |
| `confirmed_month` del Dashboard y el total del reporte del mes divergen si las consultas difieren. | `DashboardQuery` usa el mismo criterio que `ReportQuery::base`, y un criterio de aceptación compara ambos totales. |
| `useSearchParams` en `RecordsScreen` puede exigir `<Suspense>` o volver dinámica la ruta en Next 16. | El paso 6 revisa la guía en `node_modules/next/dist/docs/` y la página ya es dinámica por la sesión. |
| Sin polling, «Procesando» puede quedar desactualizado mientras corre la IA. | Es una decisión aceptada. Se refresca al volver a la pestaña y al navegar. |
| La hora relativa se calcula en el cliente y puede diferir del servidor. | La actividad se carga con React Query en el cliente, así que no hay HTML del servidor con tiempos que deban coincidir. |
| `cache()` de React solo deduplica dentro de una misma petición. | Es justo el alcance que se necesita: el layout y la página de un mismo render. |
| La zona del navegador puede diferir de la del negocio. | El saludo, el mes y «ayer» usan `America/Mexico_City` de forma explícita, y nunca la zona del navegador. |

## What is **not** in this spec

- Gráficas o analítica.
- Buscador, notificaciones y `⌘K` funcionales.
- Polling o tiempo real.
- Actividad basada en `History`.
- Selector de periodo en el Dashboard.
- Cambio de `app.timezone`.
- Sincronizar en la URL los filtros de `/records` después de cargar la pantalla.
- Cambios al shell, al topbar o al FAB móvil.

Cada uno de esos puntos, si se hace, va en su propia spec.
