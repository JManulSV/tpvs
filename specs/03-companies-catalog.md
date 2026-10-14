# SPEC 03 — Catálogo de empresas

> **Status:** Implemented
> **Depends on:** SPEC 02
> **Date:** 2026-10-14
> **Objective:** Implementar el catálogo de empresas de extremo a extremo con listado paginado, búsqueda, consulta individual y creación autorizada desde la aplicación web.

## Scope

**In:**

- Implementar `GET /api/companies`, `GET /api/companies/{id}` y `POST /api/companies`.
- Permitir lectura a usuarios autenticados `EDITOR` y `VIEWER`; solo `EDITOR` puede crear.
- Validar y normalizar nombres, rechazando duplicados sin distinguir mayúsculas.
- Añadir una restricción PostgreSQL reversible que proteja la unicidad de nombres normalizados.
- Implementar `/companies` con listado, búsqueda, paginación y diálogo de creación en español.
- Mantener la UI/UX básica y funcional, enfocada en los flujos de esta fase y usando los componentes y estilos existentes.
- Mostrar el conteo de registros confirmados por empresa.
- Agregar un enlace a Companies en el shell autenticado.
- Cubrir persistencia, permisos, validación, búsqueda, paginación y experiencia frontend con pruebas y verificaciones documentadas.
- Actualizar el contrato API, la guía frontend y el roadmap tras pasar el checkpoint.

**Out of scope (para fases posteriores):**

- Renombrar o eliminar empresas.
- Crear empresas automáticamente durante la extracción de IA.
- Implementar creación o edición de Records desde la interfaz de Companies.
- Incluir en esta fase los flujos de Reports que utilizan `company_id`.
- Añadir endpoints, formularios o navegación del BFF específicos para empresas.
- Realizar el diseño visual oficial, pulido visual o alineación completa con UI/UX; se abordará en la fase 8.15.

## Data model

No se añaden entidades ni columnas. Se reutilizan `Company`, `Record` y su relación existente.

La restricción de unicidad se basa en el valor normalizado:

```text
LOWER(BTRIM(companies.name))
```

El listado devuelve un conteo de Records en estado `CONFIRMED`; no se añade ni persiste una columna de conteo.

### Contrato de API

`GET /api/companies?search={texto}&page={número}&per_page={número}` requiere autenticación. `search` es opcional; `page` comienza en 1, y `per_page` es 15 por defecto, con un máximo de 100. La búsqueda recortada encuentra coincidencias parciales sin distinguir mayúsculas. El orden es ascendente por nombre. Parámetros inválidos devuelven `422`.

La respuesta usa la estructura paginada de Laravel (`data`, `links`, `meta`):

```json
{
  "data": [
    {
      "id": 1,
      "name": "Acme",
      "records_count": 24
    }
  ],
  "links": {},
  "meta": {}
}
```

`GET /api/companies/{id}` requiere autenticación y devuelve `404` si no existe:

```json
{
  "data": {
    "id": 1,
    "name": "Acme"
  }
}
```

`POST /api/companies` acepta un `name` requerido de hasta 255 caracteres y devuelve `201`:

```json
{
  "data": {
    "id": 1,
    "name": "Acme"
  }
}
```

El backend recorta espacios externos. Nombres equivalentes después de recortar y comparar sin distinción de mayúsculas producen `422`. La base de datos mantiene esa misma garantía ante escrituras concurrentes.

### Estructuras frontend

```ts
type Company = {
  id: number;
  name: string;
  records_count: number;
};

type CompanyListResponse = {
  data: Company[];
  links: Record<string, string | null>;
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
};

type CompanyDetail = {
  id: number;
  name: string;
};
```

## Implementation plan

1. Actualizar el contrato backend y la guía de Companies para fijar respuestas, parámetros, validación, permisos y estados de interfaz antes de depender de ellos.
2. Crear una migración reversible que reemplace la unicidad exacta por el índice sobre `LOWER(BTRIM(name))`. Si detecta empresas existentes equivalentes, debe fallar con un mensaje claro sin fusionar ni cambiar datos.
3. Implementar las rutas autenticadas, `CompanyPolicy`, requests de listado y creación, controlador y recurso. Añadir pruebas feature para ambos roles, respuestas, `404`, parámetros inválidos, duplicados, búsqueda, paginación y conteo de confirmados.
4. Implementar servicio, tipos, esquema de formulario y hooks de Companies usando el cliente HTTP y TanStack Query existentes. Las llamadas del navegador deben ir por el BFF same-origin.
5. Implementar `/companies` y el enlace del shell. Mostrar tabla, búsqueda con pausa breve, controles de paginación y diálogo de creación. Ocultar “Crear” a `VIEWER`; Laravel conserva la autorización efectiva. Incluir estados de carga, error, lista vacía y búsqueda sin resultados. Mantener la presentación básica; no aplicar todavía el diseño oficial.
6. Al crear correctamente, cerrar el diálogo, limpiar el formulario, reiniciar búsqueda y página, actualizar el listado y mostrar confirmación. Ante error de validación, conservar el diálogo y mostrar el error del campo.
7. Ejecutar pruebas backend, pruebas frontend, lint, TypeScript y build. Verificar con PostgreSQL que la migración puede revertirse y aplicarse de nuevo; documentar una aceptación en navegador con `EDITOR` y `VIEWER`.
8. Actualizar el estado de las guías y marcar completa la fase 8.5 en el roadmap solo después de demostrar la creación, consulta y asociación de una empresa con un Record.

## Acceptance criteria

- [x] Los tres endpoints existen y requieren sesión autenticada.
- [x] `EDITOR` y `VIEWER` pueden listar y consultar empresas; solo `EDITOR` puede crear.
- [x] Una creación válida devuelve `201` con `{data:{id,name}}`.
- [x] La entrada vacía, demasiado larga o duplicada devuelve `422`; las duplicaciones cubren diferencias de mayúsculas y espacios externos.
- [x] La migración protege la unicidad normalizada, no modifica migraciones aplicadas y falla claramente ante conflictos existentes.
- [x] El listado busca coincidencias parciales sin distinguir mayúsculas, ordena por nombre, pagina con los valores acordados y devuelve conteo de Records confirmados.
- [x] La consulta individual devuelve `{data:{id,name}}` y `404` para IDs inexistentes.
- [x] La interfaz es básica, funcional, está en español y ofrece estados de carga, error, vacío, búsqueda sin resultados y envío pendiente.
- [x] La interfaz ofrece paginación y reinicia a la primera página cuando cambia la búsqueda.
- [x] Solo `EDITOR` ve la acción de crear; un `VIEWER` recibe `403` si intenta crear directamente.
- [x] La creación exitosa cierra y limpia el diálogo, reinicia filtros y paginación, actualiza el listado y muestra confirmación.
- [x] La creación de una empresa, su consulta y su asociación con un Record quedan probadas sin añadir el flujo de Records a la interfaz.
- [x] Pasan la suite backend, `npm test`, lint, TypeScript y build de producción del frontend.
- [x] La verificación de navegador cubre los flujos de `EDITOR` y `VIEWER` a través del BFF.
- [x] La UI/UX oficial y el pulido visual quedan para la fase 8.15.

## Decisions

- **Sí:** un spec vertical que incluye backend y frontend; permite definir y verificar un único contrato de extremo a extremo.
- **No:** un spec aparte para el BFF; el proxy genérico existente cubre las solicitudes de esta función.
- **Sí:** paginación con `page=1`, `per_page=15`, máximo 100 y orden ascendente por nombre.
- **Sí:** búsqueda parcial sin distinguir mayúsculas, tras recortar espacios.
- **Sí:** `records_count` representa solo Records confirmados y se devuelve en el listado, no en el detalle.
- **Sí:** recortar espacios externos y rechazar nombres equivalentes sin distinguir mayúsculas.
- **Sí:** reforzar esa regla en PostgreSQL con una migración nueva y reversible; detectar conflictos previos sin fusionar datos.
- **Sí:** diálogo de creación en `/companies` y navegación al módulo desde el shell autenticado.
- **Sí:** interfaz básica y funcional para completar los flujos de la fase 8.5.
- **No:** adelantar el diseño visual oficial o el pulido completo; corresponde a la fase 8.15.
- **No:** renombrar o eliminar empresas; se mantiene la inmutabilidad definida para el MVP.

## Risks

| Riesgo | Mitigación |
| --- | --- |
| Hay nombres existentes que colisionan con la nueva normalización. | La migración detecta el conflicto y detiene el cambio con una explicación; la resolución es manual. |
| Dos altas equivalentes llegan simultáneamente. | La restricción de PostgreSQL protege la unicidad; el API convierte el conflicto en una respuesta de validación. |

## What is **not** in this spec

- Edición o eliminación de empresas.
- Creación automática de empresas por IA.
- Flujos de registros en la interfaz.
- Implementaciones específicas del BFF.
- Reportes filtrados por empresa.
- Diseño UI/UX oficial o pulido visual, que se realizará en la fase 8.15.
