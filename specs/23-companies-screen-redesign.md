# SPEC 23 — Rediseño de la pantalla de Empresas

> **Status:** Draft
> **Depends on:** SPEC 03, SPEC 15, SPEC 16, SPEC 19
> **Date:** 2026-11-18
> **Objective:** Rediseñar `/companies` con las plantillas `Companies`, `CompaniesNew` y `CompaniesMobile` y los tokens de shadcn, dentro del shell existente, agregando solo `created_at` al recurso de empresas.

## Why this spec exists

SPEC 03 dejó `/companies` con una UI básica a propósito. Tiene una tabla de dos columnas con `min-w-[28rem]`, un input con estilos literales y un modal hecho a mano con su propio *focus trap*. El diseño oficial quedó para después.

Las plantillas de `docs/design/templates/` cubren esta pantalla:

- `Companies.dc.html`: escritorio, diálogo cerrado.
- `CompaniesNew.dc.html`: el mismo marcado con `dialogo="abierto"`.
- `CompaniesMobile.dc.html`: el mismo contenido a 390 px, con FAB y *bottom sheet*.

Las tres son un solo componente con dos props: `rol` (`editor` / `viewer`) y `dialogo` (`cerrado` / `abierto` / `error`). Esta spec implementa ese componente una vez.

Como en SPEC 19, las plantillas pintan dentro de la página el sidebar, el usuario, las notificaciones y la barra inferior. Todo eso ya existe en el shell (SPEC 16) y no se repite. Esta spec toma de las plantillas solo el **contenido** de la página.

La plantilla muestra la fecha de creación de cada empresa. La API no la devuelve hoy. Es el único cambio de backend de esta spec.

## Scope

**In:**

- Agregar `created_at` (ISO 8601) a `CompanyResource` en las respuestas de listado, detalle y alta.
- Rediseño de `CompaniesScreen` y de sus piezas en `frontend/src/features/companies/components/`.
- Traducción de los colores literales de las plantillas a los tokens de SPEC 15. Esta spec no agrega tokens.
- Encabezado con `<h1>`, un subtítulo según el rol, el buscador y el botón «Nueva empresa» (solo `EDITOR`, desde `md`).
- Aviso de éxito «Creaste la empresa {nombre}.» con botón para cerrarlo.
- Lista de empresas:
  - tabla en contenedores anchos, con las columnas `Empresa · Confirmados · Creada · Acción`;
  - tarjetas en contenedores angostos;
  - fila completa clicable hacia `/records?company_id={id}`.
- Insignia «Nueva» y fondo resaltado en la fila de la empresa recién creada, si aparece en la página actual.
- Pie con el conteo total, la nota «Los nombres no se pueden cambiar ni eliminar» y la paginación existente.
- Alta de empresa:
  - con el `Dialog` de shadcn desde `md`;
  - con el `Sheet` inferior de shadcn por debajo de `md`, abierto desde un FAB «Nueva empresa» (solo `EDITOR`).
- Se elimina el modal hecho a mano de `create-company-dialog.tsx`.
- Mensajes del formulario en español. El duplicado del backend se traduce en el frontend.
- Estados de carga (skeleton), error con reintento, catálogo vacío y búsqueda sin resultados con «Limpiar búsqueda».
- Utilidades puras con pruebas unitarias.
- Documentar en `docs/backend/03_api_contract.md`, `docs/frontend/features/companies.md`, `docs/frontend/05_ui_and_accessibility.md` y `docs/10_ui_ux.md` §11.

**Out of scope (for future specs):**

- Renombrar o eliminar empresas (decisión del MVP).
- Contar registros en estados distintos de `CONFIRMED`, o agregar un conteo «por revisar».
- Traducir los mensajes de validación en el backend.
- Colapsar los espacios internos del nombre al crear. La plantilla lo hace, el backend no; la regla sigue siendo la de SPEC 03.
- Ordenar por columna o cambiar el orden por nombre.
- Sincronizar la búsqueda o la página en la URL.
- Una pantalla de detalle de empresa (`/companies/{id}`).
- Cambios al sidebar, al topbar, a `AppMobileTopbar`, a `AppBottomNav` y al FAB «Nuevo registro».
- Cambios en `/records` (ya lee `company_id` de la URL desde SPEC 19).

## Data model

No se agregan tablas ni migraciones. La tabla `companies` ya tiene `timestamps()`.

### Backend — `backend/app/Http/Resources/CompanyResource.php`

Se agrega un campo, siempre presente:

```php
'created_at' => $this->created_at?->toISOString(),
```

- `records_count` sigue igual: solo aparece en el listado y cuenta solo los `CONFIRMED`.
- Ejemplo de un elemento del listado:

```json
{ "id": 1, "name": "Company ABC", "records_count": 24, "created_at": "2026-01-12T17:04:11.000000Z" }
```

- El detalle y el alta devuelven `{ "data": { "id", "name", "created_at" } }`.

### Tipos — `frontend/src/features/companies/types.ts`

```ts
type Company = { id: number; name: string; records_count: number; created_at: string };
type CompanyDetail = { id: number; name: string; created_at: string };
```

### Esquema — `frontend/src/features/companies/schemas/company.schema.ts`

- Vacío tras recortar: «Escribe el nombre de la empresa.» (texto de la plantilla; reemplaza «El nombre de la empresa es obligatorio.»).
- Más de 255 caracteres: «El nombre no puede superar los 255 caracteres.» (sin cambio).

### Formato — `frontend/src/lib/format.ts`

```ts
export function formatShortDate(value: string | null): string; // instante ISO → "12/01/26"
```

- Usa `Intl.DateTimeFormat("es-MX", { day: "2-digit", month: "2-digit", year: "2-digit" })` en la zona horaria del navegador. `created_at` es un instante, no una fecha `Y-m-d`.
- `null` o un valor inválido devuelven «—».

### Utilidades puras — `frontend/src/features/companies/company-list-utils.ts`

```ts
export const COMPANY_DUPLICATE_MESSAGE = "Ya existe una empresa con ese nombre.";

export function getCompaniesSubtitle(role: UserRole): string;
export function getCompanyCountLabel(total: number): string;        // "1 empresa" · "6 empresas"
export function getConfirmedCountLabel(count: number): string;      // "1 confirmado" · "24 confirmados"
export function getCompanyMetaLine(company: Company): string;       // "24 confirmados · creada 12/01/26"
export function getCompanyRowLabel(company: Company): string;       // aria-label del enlace
export function getCompanyRecordsHref(companyId: number): string;   // "/records?company_id=1"
export function getCompanyNameErrorMessage(messages: string[]): string;
```

- `getCompaniesSubtitle`: «Catálogo de empresas para tus registros» para `EDITOR` y «Catálogo de empresas» para `VIEWER`.
- `getCompanyRowLabel`: «Ver registros de {nombre}».
- `getCompanyRecordsHref` reutiliza `recordListHref` de `features/records/record-filters.ts`.
- `getCompanyNameErrorMessage`:
  - si algún mensaje es «A company with this name already exists.», devuelve `COMPANY_DUPLICATE_MESSAGE`;
  - si no, devuelve el primer mensaje;
  - si la lista está vacía, devuelve «Revisa el nombre de la empresa.».

### Props

- `CompaniesScreen` deja de recibir `canCreate: boolean` y recibe `role: "EDITOR" | "VIEWER"`.
- `app/(app)/companies/page.tsx` pasa `user.role`. Conserva `export const instant = false` y el `redirect` sin sesión.
- `CreateCompanyDialog` se reemplaza por `CreateCompanyForm` (el formulario, sin contenedor) más el contenedor `Dialog` o `Sheet` en `CompaniesScreen`.
  - `onCreated` recibe el `CompanyDetail` creado, no solo el nombre.
  - El *focus trap* manual y el manejo de `Escape` se eliminan, porque los cubre el primitivo.

### Estado local de la pantalla

```ts
const [searchInput, setSearchInput] = useState("");
const [search, setSearch] = useState("");          // debounce de 300 ms, como hoy
const [page, setPage] = useState(1);
const [isCreateOpen, setIsCreateOpen] = useState(false);
const [successMessage, setSuccessMessage] = useState<string | null>(null);
const [createdCompanyId, setCreatedCompanyId] = useState<number | null>(null);
```

- `useResetOnHide` limpia `isCreateOpen`, `successMessage` y `createdCompanyId`, según las reglas de estado de pantalla documentadas bajo `cacheComponents`.
- `PAGE_SIZE = 15` y la llamada a `useCompanies` no cambian.

## UI contract

### Fusión con el layout existente

El contenido se renderiza dentro del contenedor de `AppShell`. La página no agrega margen exterior ni fondo propio.

| Elemento de la plantilla | Qué se hace |
| --- | --- |
| Sidebar, marca, usuario y «Cerrar sesión» | Se descarta. Se usa `AppSidebar`. |
| Contador «4» junto a «Registros» | Se descarta. Se usa `NeedsReviewNavBadge`. |
| Botón de notificaciones del encabezado | Se descarta. Sigue en `AppTopbar`. |
| Topbar móvil y barra inferior | Se descartan. Se usan `AppMobileTopbar` y `AppBottomNav`. |
| `<h1>`, subtítulo, buscador y «Nueva empresa» del encabezado | Se implementan en la página. |
| FAB «Nueva empresa» (móvil) | Se implementa en la página. `AppBottomNav` no cambia. |
| Aviso, tabla, tarjetas, estado vacío, pie, diálogo y *sheet* | Se implementan aquí. |

### Encabezado

- Fila con `flex-wrap`:
  - a la izquierda, `<h1 id="companies-heading">Empresas</h1>` (24/32 px, 700, `tracking-[-0.01em]`) y debajo `getCompaniesSubtitle(role)` en `text-muted-foreground`;
  - a la derecha, el buscador y, desde `md` y solo para `EDITOR`, el `Button` «Nueva empresa» con icono `Plus`.
- Se elimina el subtítulo «Consulta y administra el catálogo de empresas.».
- Buscador:
  - píldora `rounded-full border-input bg-card` con icono `Search`, placeholder «Buscar empresa…», `aria-label="Buscar empresas"` y `maxLength={255}`;
  - foco con anillo `ring` de 2 px;
  - 44 px de alto y máximo 360 px de ancho desde `md`;
  - por debajo de `md`, 48 px de alto y ancho completo, debajo del `<h1>`. El subtítulo se oculta en móvil, como en la plantilla.
- 24 px de separación con el contenido desde `md`, 16 px en móvil.

### Aviso de éxito

- Se muestra tras una alta correcta: «Creaste la empresa {nombre}.».
- `role="status"`, `rounded-md`, `bg-status-confirmed-bg text-status-confirmed`, icono `CircleCheck` y botón `X` con `aria-label="Cerrar aviso"`.
- Se cierra con el botón, al abrir de nuevo el alta o al salir de la pantalla.

### Lista — escritorio (contenedor ≥ 40 rem)

- Tarjeta `rounded-lg border bg-card shadow-card overflow-hidden`, con `aria-label="Lista de empresas"`.
- Tabla semántica con el primitivo `table` de shadcn:
  - sin `min-w` ni scroll horizontal;
  - `<caption class="sr-only">Empresas y registros confirmados</caption>`;
  - encabezados de 11/16 px, 600, mayúsculas, `tracking-[0.08em]`, sobre `bg-muted`.
- Columnas:

| Columna | Contenido |
| --- | --- |
| Empresa | Insignia de 40 px `rounded-md bg-accent text-accent-foreground` con icono `Building2` (`aria-hidden`), el nombre en 600 con `truncate` (enlace) y, si es la recién creada, la insignia «Nueva». |
| Confirmados | `records_count` alineado a la derecha, 600, `tabular-nums`. Con `0`, `text-muted-foreground` y peso 500. |
| Creada | `formatShortDate(created_at)` en `text-muted-foreground`, `tabular-nums`. |
| Acción | Encabezado `sr-only` «Acción». En la celda, «Ver registros» en 13/16 px 600 `text-accent-foreground` con `ChevronRight`. |

- Fila de 64 px mínimo, borde inferior y hover `bg-muted/50`.
- Fila clicable:
  - el nombre es un `Link` a `getCompanyRecordsHref(id)` con `after:absolute after:inset-0` y `relative` en la `<tr>`;
  - el foco muestra un anillo de 2 px hacia dentro de la fila;
  - el enlace lleva `aria-label` con `getCompanyRowLabel`.
- Fila recién creada: `bg-accent`, y su insignia del icono pasa a `bg-card`.
- Insignia «Nueva»: píldora de 24 px `bg-status-confirmed-bg text-status-confirmed`, 12/16 px 600.

### Lista — móvil (contenedor < 40 rem)

- `<ul aria-label="Lista de empresas">` con 12 px de separación.
- Cada `<li>` es una tarjeta `rounded-lg border bg-card p-4 shadow-card`, con hover `bg-muted/50`. La recién creada usa `bg-accent`.
- Contenido de cada tarjeta:
  - la insignia del icono;
  - el nombre (enlace de 15/24 px, 600) y debajo `getCompanyMetaLine` en 12/16 px `text-muted-foreground`;
  - a la derecha, «Nueva» si aplica.
- La tarjeta completa es clicable, con el mismo pseudo-elemento que la tabla.
- Encima de la lista va «**N** empresas» (`getCompanyCountLabel(meta.total)`) en 13 px.
- Se renderizan la tabla y la lista, y CSS oculta una de las dos con `display: none`, como en SPEC 19.

### Pie y paginación

- En escritorio, barra `bg-muted` con borde superior dentro de la tarjeta:
  - a la izquierda, «**N** empresas» (`meta.total`);
  - a la derecha, «Los nombres no se pueden cambiar ni eliminar».
- En móvil, la nota va debajo de la lista en 12/16 px `text-muted-foreground`.
- Si `meta.last_page > 1`, se muestra «Página X de Y» con los botones «Anterior» y «Siguiente».
  - Están dentro de `<nav aria-label="Paginación de empresas">`.
  - Conservan sus reglas de deshabilitado y miden al menos 44 px de alto en móvil.
- Mientras `isFetching`, la lista conserva los datos anteriores.

### Alta de empresa

- Se abre con «Nueva empresa» (encabezado, desde `md`) o con el FAB (por debajo de `md`).
- Contenedor:
  - si `useIsMobile()` es `false`, se usa el `Dialog` de shadcn, de 440 px de ancho máximo, `rounded-xl` y 24 px de padding;
  - si es `true`, se usa el `Sheet` con `side="bottom"`, `rounded-t-xl`, una barra de agarre decorativa (`aria-hidden`) y 24 px de padding inferior más `env(safe-area-inset-bottom)`.
- Contenido común (`CreateCompanyForm`):
  - título «Nueva empresa» (20/28 px, 700) y descripción «Agrégala al catálogo para poder asignarle registros.»;
  - campo «Nombre», con input de 44 px `rounded-md border-input`, placeholder «Ej. Company Centro», `autoComplete="off"`, `maxLength={255}` y foco inicial;
  - ayuda «Después de crearla no podrás cambiar el nombre.» cuando no hay error;
  - con error, borde y texto `status-error`, icono `CircleAlert`, `aria-invalid` y `aria-describedby`; el error reemplaza la ayuda;
  - acciones:
    - en el `Dialog`, «Cancelar» (`outline`) y «Crear empresa» alineados a la derecha;
    - en el `Sheet`, «Crear empresa» y «Cancelar» apilados a ancho completo.
- Mientras se envía, el input y los botones se deshabilitan, el botón dice «Creando…» y el contenedor no se cierra con `Escape` ni con clic fuera.
- Al escribir se borra el error del campo.
- Errores:
  - validación local: los mensajes del esquema;
  - `422` con errores en `name`: `getCompanyNameErrorMessage(messages)` en el campo;
  - cualquier otro error: el mensaje de `toCompaniesApiError` en un texto `role="alert"` sobre las acciones. El contenedor sigue abierto.
- Alta correcta:
  - se cierra el contenedor y se limpia el formulario;
  - se limpian la búsqueda y la página vuelve a 1;
  - se guarda `createdCompanyId` y se muestra el aviso de éxito;
  - el listado se refresca (la invalidación de `useCreateCompany` no cambia);
  - si la empresa aparece en la página 1, lleva «Nueva»; si no aparece, solo se ve el aviso.
- Al abrir de nuevo el alta, el formulario empieza vacío y el aviso anterior se cierra.

### FAB móvil «Nueva empresa»

- Solo para `EDITOR` y solo por debajo de `md`.
- Mismo aspecto y posición que el FAB de `AppBottomNav` (`fixed right-4 bottom-[calc(5.25rem+env(safe-area-inset-bottom))] h-12 rounded-full bg-primary shadow-popover`), con icono `Plus`.
- Es un `<button>` que abre el `Sheet`, no un enlace.
- El FAB «Nuevo registro» no aparece en `/companies` (`shouldShowNewRecordFab` no cambia), así que no se encuentran.

### Estados

- **Cargando:** se muestran el encabezado y el buscador, y en lugar de la lista aparecen cinco filas o tarjetas `Skeleton`. `aria-live="polite"` con «Cargando empresas…» (`sr-only`).
- **Error:** tarjeta con `role="alert"`, «No se pudieron cargar las empresas.» y un `Button` «Reintentar» que llama `refetch()`.
- **Catálogo vacío** (sin búsqueda): la misma insignia de 48 px con `Building2` y el texto «Todavía no hay empresas en el catálogo.». El `EDITOR` ve además el botón «Nueva empresa».
- **Sin resultados de búsqueda:** insignia de 48 px, título «No encontramos empresas con ese nombre» (16/24 px, 600), texto «Revisa la ortografía o prueba con otra palabra.» y botón «Limpiar búsqueda» (`bg-accent text-accent-foreground`). El botón vacía el buscador y vuelve a la página 1.
- **Datos en caché con error al refrescar:** se mantienen los datos y no se muestra la alerta.

### Diferencias por rol

| Elemento | EDITOR | VIEWER |
| --- | --- | --- |
| Lista, búsqueda y paginación | Sí | Sí |
| «Ver registros» (fila clicable) | Sí | Sí |
| Subtítulo | «Catálogo de empresas para tus registros» | «Catálogo de empresas» |
| «Nueva empresa» (encabezado y FAB) | Sí | No |
| Botón «Nueva empresa» en el catálogo vacío | Sí | No |

El backend sigue siendo la autoridad. Un `VIEWER` que llama `POST /api/companies` recibe `403`.

## Implementation plan

1. **Backend: `created_at`.**
   - Agregar `created_at` a `CompanyResource`.
   - Extender `backend/tests/Feature/CompaniesApiTest.php` para que listado, detalle y alta lo incluyan en ISO 8601.
   - Ejecutar la suite con `-e DB_DATABASE=tpvs_test`.
   - Actualizar `docs/backend/03_api_contract.md`.
2. **Tipos, formato y utilidades.**
   - Agregar `created_at` a `Company` y `CompanyDetail`.
   - Agregar `formatShortDate` a `lib/format.ts` con pruebas en `frontend/tests/format.test.ts`.
   - Crear `company-list-utils.ts` con pruebas en `frontend/tests/companies.test.ts`.
   - Cambiar el mensaje de vacío del esquema y su prueba.
   - Verificar con `npx tsc --noEmit`. Todavía no hay cambio visible.
3. **Formulario de alta.**
   - Crear `CreateCompanyForm` con la lógica de envío actual, `getCompanyNameErrorMessage` y la ayuda y los errores de la plantilla.
   - En `CompaniesScreen`, envolverlo en `Dialog` o `Sheet` según `useIsMobile()`, y agregar el FAB.
   - Eliminar `create-company-dialog.tsx`.
   - Prueba manual: crear, duplicar (ver «Ya existe una empresa con ese nombre.»), cancelar y `Escape`, en escritorio y a 390 px.
4. **Encabezado, aviso y lista.**
   - Cambiar la prop a `role`.
   - Implementar el encabezado, el buscador, el aviso, la tabla, las tarjetas y el resaltado «Nueva».
   - Prueba manual: la fila lleva a `/records?company_id={id}` con el filtro de empresa ya aplicado.
5. **Estados, pie y paginación.**
   - Implementar el skeleton, el error, el catálogo vacío, la búsqueda sin resultados, el pie y la paginación con el aspecto nuevo.
6. **Documentación.**
   - Actualizar `docs/frontend/features/companies.md` y `docs/frontend/05_ui_and_accessibility.md`.
   - En `docs/10_ui_ux.md` §11, describir el layout implementado y que el conteo es de confirmados.
7. **Verificación.**
   - Ejecutar `npm test`, `npm run lint`, `npx tsc --noEmit` y `npm run build`.
   - Revisar en navegador con `EDITOR` y `VIEWER`, en escritorio y a 390 px, en tema claro y oscuro.

## Acceptance criteria

- [ ] `GET /api/companies`, `GET /api/companies/{id}` y `POST /api/companies` incluyen `created_at` en ISO 8601. `records_count` no cambia.
- [ ] La suite backend pasa usando `tpvs_test`.
- [ ] `/companies` no tiene colores literales. Solo usa tokens de SPEC 15 y primitivos de `components/ui`.
- [ ] La página no repite el sidebar, el topbar, la barra inferior ni las notificaciones del shell.
- [ ] Desde un contenedor de 40 rem se ve una tabla `Empresa · Confirmados · Creada · Acción` sin scroll horizontal. Por debajo se ven tarjetas.
- [ ] A 390 px la página no tiene scroll horizontal.
- [ ] La fecha de creación se muestra como `dd/mm/aa`.
- [ ] Una empresa con `records_count = 0` muestra «0» en `text-muted-foreground`.
- [ ] Hacer clic en cualquier parte de una fila o tarjeta navega a `/records?company_id={id}`, y Registros muestra el filtro de esa empresa.
- [ ] Con Tab se llega una vez a cada fila, con un anillo de foco visible y `aria-label` «Ver registros de {nombre}».
- [ ] Escribir en el buscador filtra tras 300 ms y vuelve a la página 1.
- [ ] Sin resultados se ve «No encontramos empresas con ese nombre», y «Limpiar búsqueda» restablece la lista.
- [ ] Con más de 15 empresas aparece «Página X de Y». «Anterior» y «Siguiente» se deshabilitan en los extremos y mientras se carga.
- [ ] El pie muestra «N empresas» con `meta.total` (singular con 1) y «Los nombres no se pueden cambiar ni eliminar».
- [ ] `VIEWER` no ve «Nueva empresa» en el encabezado, ni el FAB, ni en el catálogo vacío. Su subtítulo es «Catálogo de empresas».
- [ ] Desde `md`, «Nueva empresa» abre un `Dialog`. Por debajo de `md`, el FAB abre un `Sheet` inferior.
- [ ] El input recibe el foco al abrir. Al cerrar, el foco vuelve al botón que abrió el alta.
- [ ] Enviar vacío muestra «Escribe el nombre de la empresa.» sin llamar a la API.
- [ ] Crear un nombre duplicado (con otras mayúsculas o espacios externos) muestra «Ya existe una empresa con ese nombre.» en el campo, y el contenedor sigue abierto.
- [ ] Mientras se envía, el botón dice «Creando…» y ni `Escape` ni un clic fuera cierran el contenedor.
- [ ] Una alta correcta cierra el contenedor, limpia la búsqueda, vuelve a la página 1 y muestra «Creaste la empresa {nombre}.».
- [ ] Si la nueva empresa está en la página visible, su fila tiene fondo `bg-accent` y la insignia «Nueva».
- [ ] El aviso se cierra con su botón `X`.
- [ ] Al salir de `/companies` y volver, el alta está cerrada, no hay aviso y ninguna fila lleva «Nueva».
- [ ] La carga muestra skeletons y nunca «0 empresas».
- [ ] Un error muestra «No se pudieron cargar las empresas.» y «Reintentar» vuelve a consultar.
- [ ] `create-company-dialog.tsx` ya no existe y no queda ningún *focus trap* manual en `features/companies`.
- [ ] Las utilidades de `company-list-utils.ts` y `formatShortDate` tienen pruebas unitarias que pasan.
- [ ] Pasan `npm test`, `npm run lint`, `npx tsc --noEmit` y `npm run build`.
- [ ] La pantalla se lee bien en tema claro y oscuro.

## Decisions

- **Sí:** agregar `created_at` a `CompanyResource`. Es aditivo, la columna ya existe y la plantilla lo muestra.
- **No:** quitar la columna «Creada» para no tocar el backend. Se pierde información que el diseño pide por un cambio mínimo.
- **Sí:** el conteo sigue siendo solo de `CONFIRMED` y se rotula «Confirmados». Es la cifra que alimenta los Reportes, y el texto no promete más de lo que cuenta.
- **No:** agregar «por revisar» por empresa. Ese trabajo ya tiene lugar en el Dashboard, el badge del sidebar y el filtro de Registros. Además requería un cambio de backend.
- **No:** rotular «Registros» como la plantilla. El número no cuadraría con la lista que abre «Ver registros», que incluye todos los estados.
- **Sí:** «Ver registros» lleva a `/records?company_id={id}` con todos los estados. `parseRecordListQuery` ya lo admite, así que no hay cambios en Registros.
- **No:** agregar `status=CONFIRMED` al enlace. Ocultaría los registros pendientes de esa empresa.
- **Sí:** traducir el duplicado en el frontend (`getCompanyNameErrorMessage`). Así el backend no cambia más allá de `created_at`.
- **No:** traducir los mensajes del backend en esta spec.
- **Sí:** `Dialog` desde `md` y `Sheet` inferior por debajo, con los primitivos de shadcn que ya existen. Es fiel a las dos plantillas y elimina el *focus trap* manual.
- **No:** un solo `Dialog` en todos los anchos. Es menos fiel al diseño móvil.
- **Sí:** se elige el contenedor con `useIsMobile()` (768 px, el mismo corte que `md`), para montar uno solo.
- **Sí:** conservar la paginación de 15 en 15. La plantilla no la muestra, pero cargar todo no escala.
- **No:** `per_page=100` para listar todas las empresas.
- **Sí:** resaltar la nueva empresa solo si aparece en la página visible. El aviso de éxito siempre la nombra. El resaltado se olvida al salir.
- **No:** poner el nombre nuevo en el buscador para forzar que se vea. Contradice la plantilla, que limpia la búsqueda.
- **Sí:** el FAB «Nueva empresa» lo pinta la página y abre el `Sheet`. El estado del alta vive en la pantalla, y `AppBottomNav` no cambia.
- **Sí:** «Creada» con `dd/mm/aa` en la zona horaria del navegador, como la plantilla. `formatDate` no sirve porque espera `Y-m-d` y otro formato.
- **Sí:** el texto de vacío del esquema pasa a «Escribe el nombre de la empresa.», como la plantilla.
- **No:** colapsar los espacios internos del nombre. Cambiaría la regla de unicidad de SPEC 03.

## Risks

| Riesgo | Mitigación |
| --- | --- |
| El backend cambia el texto en inglés del duplicado y la traducción deja de coincidir. | Una prueba del API fija el texto. Si no coincide, se muestra el mensaje original, que sigue siendo legible. |
| `useIsMobile()` devuelve `false` en el render del servidor y el contenedor cambia al hidratar. | El alta solo se monta al abrirse, por una acción del usuario y ya en el cliente. |
| Las pruebas frontend que comparan respuestas completas fallan por el campo nuevo `created_at`. | Se actualizan los fixtures en el paso 2, junto con los tipos. |
| La fecha en la zona del navegador puede diferir un día de la fecha UTC. | Es lo esperado para un instante. Se documenta en `docs/frontend/features/companies.md`. |

## What is **not** in this spec

- Renombrar o eliminar empresas.
- Conteos de registros en otros estados.
- Traducción de los mensajes del backend.
- Colapsar espacios internos del nombre.
- Orden por columna o búsqueda en la URL.
- Pantalla de detalle de empresa.
- Cambios al shell, a `AppBottomNav` o a `/records`.

Cada uno de ellos, si llega, va en su propia spec.
