# SPEC 26 — Entorno Docker profesional e integración de Ollama

> **Status:** Approved
> **Depends on:** SPEC 06, SPEC 08
> **Date:** 2026-11-24
> **Objective:** Reorganizar los archivos Docker en una base compartida con overrides de desarrollo y producción, endurecer la seguridad del entorno local y conectar Ollama a Laravel mediante un perfil opcional.

## Why this spec exists

La revisión del 2026-11-24 encontró estos problemas en el entorno Docker actual:

- El PHP del contenedor no tiene `pcntl`. Sin esa extensión, `queue:work --timeout=330` no se aplica y el worker no maneja `SIGTERM`. Por eso la cadena de timeouts documentada (300 < 330 < 360) no se cumple por completo.
- `docker compose stop` mata el worker a los 10 s. Un job de IA en curso se pierde hasta que vence `retry_after` (360 s).
- El contenedor exporta `DB_DATABASE=tpvs`. Un `php artisan test` sin `-e DB_DATABASE=tpvs_test` borra la base de desarrollo. Pasó el 2026-10-29 y el 2026-11-09.
- PostgreSQL (5432) y Laravel (8000) se publican en `0.0.0.0`, abiertos a la LAN.
- `docker-compose.yml` usa como respaldo un `APP_KEY` que está en git, y fija la IP de LAN `192.168.3.240` como valor por defecto.
- El backend y el worker repiten unas 25 variables de entorno. Además hay dos fuentes de configuración: el `.env` raíz y `backend/.env`.
- Los volúmenes `vendor` y `node_modules` se llenan una sola vez y se quedan viejos cuando cambian los lockfiles.
- Todos los contenedores corren como `root`, y los archivos escritos en el bind mount quedan con dueño `root` en el host.
- `queue:work` no toma los cambios de código sin reiniciar el contenedor.
- `artisan serve` atiende una petición a la vez.
- Ollama vive aislado en el perfil `ai-spike` y no está conectado a Laravel.
- No existe una imagen de producción para el despliegue en la LAN de la oficina (roadmap 8.18).

Es un solo SPEC global por decisión del usuario, aunque toque varias áreas. Como en SPEC 25, cada fase del plan se puede ejecutar en una sesión nueva leyendo solo este SPEC y los archivos de esa fase.

## Scope

**In:**

- Extensiones PHP `pcntl`, `intl` y `bcmath` en la imagen del backend.
- Guardia en `backend/tests/TestCase.php` que aborta si la base conectada no es `tpvs_test`.
- Script de inicialización de PostgreSQL que crea `tpvs_test`.
- `Makefile` en la raíz con los comandos habituales, incluido un `make test` que siempre usa `tpvs_test`.
- `compose.yaml` (base compartida) y `compose.override.yaml` (desarrollo, se carga solo). Reemplazan a `docker-compose.yml`.
- `name: tpvs` en la base. Los servicios conservan sus nombres `tpvs-*`.
- Anclas YAML para la configuración compartida de Laravel.
- Redes `edge`, `data` y `ai`.
- Servicio de un solo uso `tpvs-migrate`. Las migraciones salen del entrypoint.
- Dockerfiles multi-stage para el backend (`base`, `dev`, `prod`, `web`) y el frontend (`deps`, `dev`, `build`, `prod`).
- Usuarios no-root con `UID`/`GID` configurables.
- Sincronización de dependencias por hash del lockfile en los entrypoints de desarrollo.
- Worker de desarrollo con `queue:listen`, y `stop_grace_period: 340s`.
- Puertos de desarrollo 8000 y 5432 solo en `127.0.0.1`. El 3000 sigue abierto a la LAN.
- `APP_KEY` obligatorio, sin valor de respaldo en Compose.
- El `.env` raíz como única fuente de configuración bajo Docker.
- Perfil `ai` con `tpvs-ollama` y `tpvs-ollama-pull`, conectado a Laravel y al worker por la red `ai`. Reemplaza al perfil `ai-spike`.
- `compose.prod.yaml`: php-fpm, nginx (`tpvs-web`), Next.js `standalone`, `queue:work` y sin bind mounts.
- `compose.ai-host.yaml`: archivo independiente para el PC de IA, solo con Ollama en CPU.
- `output: "standalone"` en `frontend/next.config.ts`.
- Actualizar `README.md`, `CLAUDE.md`, `docs/backend/configuration-operations.md`, `docs/backend/testing.md`, `docs/roadmap.md` y `docs/frontend/verification-log.md`.

**Out of scope (for future specs):**

- HTTPS y certificados. `compose.prod.yaml` sirve HTTP en el puerto 80. El login en modo producción requiere HTTPS (`SESSION_SECURE_COOKIE`), así que queda para el despliegue 8.18.
- El despliegue real en la LAN de la oficina (8.18 sigue `Pending`).
- Soporte de GPU (NVIDIA o AMD). El PC de IA no tiene GPU.
- Redis, Horizon, Octane o FrankenPHP.
- Renombrar los servicios a nombres cortos.
- CI/CD, registro de imágenes, respaldos automatizados y monitoreo.
- Reglas de firewall del PC de IA. Solo se documentan.
- Cambios en el prompt de Ollama, el modelo, los reintentos o los timeouts de la aplicación.
- Cambios de esquema, del contrato HTTP o de pantallas.

## Data model

Esta spec no introduce tablas, columnas ni migraciones. Las estructuras nuevas son archivos de infraestructura.

### Archivos

```text
compose.yaml                         # base: servicios, redes, volúmenes, healthchecks, anclas
compose.override.yaml                # desarrollo (se carga solo con `docker compose`)
compose.prod.yaml                    # servidor de aplicación (-f compose.yaml -f compose.prod.yaml)
compose.ai-host.yaml                 # PC de IA, independiente (-f compose.ai-host.yaml)
Makefile
backend/Dockerfile                   # targets: base, dev, prod, web
backend/docker/entrypoint-dev.sh     # reemplaza a docker-entrypoint.sh
backend/docker/queue-listen.sh       # envoltorio de queue:listen con parada ordenada (desarrollo)
backend/docker/entrypoint-prod.sh
backend/docker/php/uploads.ini       # existe, sin cambios
backend/docker/php/opcache-prod.ini
backend/docker/nginx/default.conf    # :80 → Next.js, :8080 → php-fpm
frontend/Dockerfile                  # targets: deps, dev, build, prod
frontend/docker/entrypoint-dev.sh
docker/postgres/init/01-create-test-db.sh
```

`docker-compose.yml` y `backend/docker-entrypoint.sh` se borran.

### Servicios

| Servicio | Base | Desarrollo | Producción | Redes |
| --- | --- | --- | --- | --- |
| `tpvs-postgres` | `postgres:17-alpine`, healthcheck | `127.0.0.1:5432` | sin puerto | `data` |
| `tpvs-migrate` | `migrate --force`, una vez | target `dev` | target `prod` | `data` |
| `tpvs-backend` | Laravel | target `dev`, `artisan serve`, `PHP_CLI_SERVER_WORKERS=4`, `127.0.0.1:8000` | target `prod`, php-fpm :9000 | `edge`, `data`, `ai` |
| `tpvs-queue-worker` | Laravel | `queue:listen database --timeout=330` | `queue:work database --timeout=330 --max-time=3600` | `data`, `ai` |
| `tpvs-frontend` | Next.js | target `dev`, `0.0.0.0:3000` | target `prod`, sin puerto | `edge` |
| `tpvs-web` | — | — | target `web` (nginx), `0.0.0.0:80` | `edge` |
| `tpvs-ollama` | perfil `ai` | `127.0.0.1:${OLLAMA_PORT:-11435}` | no existe | `ai` |
| `tpvs-ollama-pull` | perfil `ai`, una vez | — | no existe | `ai` |

Reglas comunes:

- `tpvs-backend` y `tpvs-queue-worker` dependen de `tpvs-migrate` con `condition: service_completed_successfully`.
- `tpvs-queue-worker` depende de `tpvs-ollama-pull` con `required: false`. Sin el perfil `ai`, el stack arranca igual.
- `tpvs-queue-worker` usa `stop_grace_period: 340s`.
- Todos los servicios de larga duración usan `restart: unless-stopped`.
- `tpvs-frontend` tiene un healthcheck HTTP.
- En producción, `LARAVEL_API_URL=http://tpvs-web:8080`. En desarrollo sigue siendo `http://tpvs-backend:8000`.
- Los volúmenes conservan sus claves actuales. Con `name: tpvs`, los nombres reales (`tpvs_tpvs-postgres-data`, etc.) no cambian y no se pierden datos.

### Ancla compartida de Laravel

```yaml
x-laravel-env: &laravel-env
  APP_ENV: ${APP_ENV:-local}
  APP_KEY: ${APP_KEY:?APP_KEY is required}
  DB_HOST: tpvs-postgres
  DB_DATABASE: ${DB_DATABASE:-tpvs}
  AI_OLLAMA_URL: ${AI_OLLAMA_URL:-}
  # ...todas las variables que Laravel lee en tiempo de ejecución
```

Compose define todas las variables que Laravel lee, incluidas `APP_NAME`, `CACHE_STORE`, `FILESYSTEM_DISK` y `LOG_CHANNEL`. Así `backend/.env` no aporta nada bajo Docker. El entrypoint de desarrollo muestra un aviso si encuentra `backend/.env`.

### Variables nuevas del `.env` raíz

| Variable | Uso | Valor en `.env.example` |
| --- | --- | --- |
| `UID`, `GID` | Usuario de los contenedores de desarrollo | `1000` |
| `OLLAMA_IMAGE_TAG` | Versión fija de `ollama/ollama` | La versión estable probada al implementar. Nunca `latest`. |
| `OLLAMA_PORT` | Puerto de host para el script del spike | `11435` |
| `OLLAMA_BIND` | IP donde `compose.ai-host.yaml` publica 11434 | `0.0.0.0` |
| `AI_OLLAMA_URL` | Ya existe. Cambia el valor de ejemplo | `http://tpvs-ollama:11434` |

`FRONTEND_ORIGIN`, `SANCTUM_STATEFUL_DOMAINS` y `NEXT_ALLOWED_DEV_ORIGINS` dejan de tener la IP de LAN como respaldo en Compose. La IP solo aparece en `.env.example`.

### Ollama

- Variables del contenedor: `OLLAMA_NUM_PARALLEL=1`, `OLLAMA_MAX_LOADED_MODELS=1` y `OLLAMA_KEEP_ALIVE=30m`.
- `mem_limit: 8g` en desarrollo.
- Healthcheck: `["CMD", "ollama", "list"]`.
- `tpvs-ollama-pull` ejecuta `ollama pull ${AI_OLLAMA_MODEL}` con `OLLAMA_HOST=tpvs-ollama:11434`. Depende de `tpvs-ollama` con `condition: service_healthy`.
- El volumen sigue siendo `tpvs-ollama-data`. Los modelos ya descargados se conservan.

### Guardia de tests

`Tests\TestCase::createApplication()` crea la aplicación y lanza una excepción si `config('database.connections.pgsql.database')` no es `tpvs_test`. La comprobación va en `createApplication()` y no en `setUp()`, porque `RefreshDatabase` corre dentro de `parent::setUp()` y ya habría borrado la base.

### Sincronización de dependencias (desarrollo)

El entrypoint compara el SHA-256 de `composer.lock` con `vendor/.composer-lock.sha256`. Si difieren, corre `composer install` y guarda el hash nuevo. El frontend hace lo mismo con `package-lock.json`, `node_modules/.package-lock.sha256` y `npm ci`.

## Implementation plan

Cada paso es **un commit** en la rama `spec-26-docker-environment`. Al cerrar cada paso se cumple esto:

- `docker compose up -d --build` deja todos los servicios `healthy` o `running`.
- La suite del backend pasa en `tpvs_test`.
- `http://localhost:3000` muestra el login.

**Fase A — Seguridad**

0. **Línea base (sin commit).** Correr la suite en `tpvs_test` y anotar el número de tests. Anotar `docker compose ps`. Comprobar si existe `tpvs_test` con `psql -l`.
1. **Extensiones PHP.** Agregar `pcntl`, `intl` y `bcmath` a `backend/Dockerfile`. Verificar con `php -m`.
2. **Guardia de tests y base de pruebas.** Agregar la guardia en `TestCase::createApplication()`. Crear `docker/postgres/init/01-create-test-db.sh`, idempotente, y montarlo en `/docker-entrypoint-initdb.d/`. Verificar que `php artisan test` sin `-e` aborta sin tocar `tpvs`.
3. **Puertos y secretos.** Publicar 8000 y 5432 en `127.0.0.1`. Cambiar `APP_KEY` a `${APP_KEY:?APP_KEY is required}`. Quitar los respaldos con la IP de LAN. Agregar `stop_grace_period: 340s` al worker.

**Fase B — Estructura**

4. **Base y override.** Renombrar `docker-compose.yml` a `compose.yaml` con `git mv` y agregar `name: tpvs`. Crear las anclas y las redes `edge` y `data`. Mover a `compose.override.yaml` los bind mounts, los puertos y los comandos de desarrollo. Verificar que los volúmenes existentes se reutilizan.
5. **Servicio de migraciones.** Crear `tpvs-migrate` y quitar `RUN_MIGRATIONS`. El backend y el worker dependen de `tpvs-migrate`.
6. **Dockerfile del backend.** Crear los targets `base` y `dev`. Usar el usuario `www-data` remapeado a `UID`/`GID`. Dejar de copiar `.env.example` a `.env`. Crear `backend/docker/entrypoint-dev.sh` con la creación de directorios, la sincronización de `vendor` y el aviso de `backend/.env`. Borrar `backend/docker-entrypoint.sh`. Agregar `PHP_CLI_SERVER_WORKERS=4`.
7. **Dockerfile del frontend.** Leer antes `frontend/AGENTS.md`. Crear los targets `deps` y `dev` con el usuario `node`. Crear `frontend/docker/entrypoint-dev.sh` con la sincronización de `node_modules`. Agregar el healthcheck del frontend.
8. **Worker de desarrollo y Makefile.** Cambiar el worker de desarrollo a `queue:listen`. Agregar `restart: unless-stopped`. Crear el `Makefile` con `up`, `up-ai`, `down`, `ps`, `logs`, `shell`, `test`, `pint`, `migrate`, `ai-pull` y `prod-config`.

**Fase C — Ollama**

9. **Perfil `ai`.** Renombrar el servicio `ollama` a `tpvs-ollama` y el perfil `ai-spike` a `ai`. Crear la red `ai`, el healthcheck, las variables de ajuste y `mem_limit`. Fijar la imagen con `OLLAMA_IMAGE_TAG`.
10. **Descarga del modelo y conexión.** Crear `tpvs-ollama-pull`. Agregar al worker `depends_on` con `required: false`. Cambiar `AI_OLLAMA_URL` en `.env.example` a `http://tpvs-ollama:11434`. Verificar una extracción real: subir una imagen de `test-images/` y ver el registro pasar a `NEEDS_REVIEW`.

**Fase D — Producción**

11. **Imagen de producción del backend.** Crear el target `prod` sobre `php:8.3-fpm` con `composer install --no-dev --optimize-autoloader`, `opcache-prod.ini`, usuario no-root y `entrypoint-prod.sh`, que corre `php artisan optimize`. Crear el target `web` sobre `nginx:alpine` con `default.conf` y la carpeta `public/` copiada desde `prod`.
12. **Imagen de producción del frontend.** Leer `frontend/AGENTS.md` y la guía de `output` en `node_modules/next/dist/docs/`. Agregar `output: "standalone"` a `next.config.ts`. Crear los targets `build` y `prod`, que corre `node server.js` como `node`.
13. **`compose.prod.yaml`.** Usar los targets `prod` y `web`, sin bind mounts. Exigir `APP_ENV=production`, `APP_DEBUG=false` y `AI_OLLAMA_URL` con `:?`. Publicar solo `tpvs-web:80`. Usar `queue:work` con `--max-time=3600` y `restart: unless-stopped` en todo. Verificar con `make prod-config` y con un arranque local.
14. **`compose.ai-host.yaml`.** Crear `tpvs-ollama` y `tpvs-ollama-pull` en CPU, con publicación en `${OLLAMA_BIND:-0.0.0.0}:11434`. Verificar que arranca y que `curl http://<ip>:11434/api/tags` responde.

**Fase E — Documentación**

15. **Documentación y checkpoint.**
    - Reescribir la sección Docker de `docs/backend/configuration-operations.md`: archivos, perfiles, redes, `make`, producción y el PC de IA, incluida la nota de firewall para el puerto 11434.
    - Actualizar `README.md` (árbol y arranque rápido) y `docs/backend/testing.md` (guardia y `make test`).
    - Agregar `make test` a `CLAUDE.md` sin quitar el comando explícito.
    - Agregar SPEC 26 a la fila 8.2 de `docs/roadmap.md`. La fila 8.18 sigue `Pending`.
    - Hacer un checkpoint de humo en el navegador en desarrollo: login, registro manual, subir una imagen con el perfil `ai` y llegar a `NEEDS_REVIEW`. Registrar el resultado en `docs/frontend/verification-log.md`.
    - Marcar el SPEC `Implemented`.

## Acceptance criteria

- [ ] `docker compose exec -T tpvs-backend php -m` lista `pcntl`, `intl` y `bcmath`.
- [ ] `docker compose exec -T tpvs-backend php artisan test` (sin `-e`) termina con error antes de migrar, y la base `tpvs` conserva sus filas.
- [ ] `make test` pasa, y el número de tests es igual al de la línea base más la prueba de la guardia, si se agrega una.
- [ ] Con un volumen de PostgreSQL nuevo, `tpvs_test` existe después del primer arranque.
- [ ] `docker-compose.yml` y `backend/docker-entrypoint.sh` no existen.
- [ ] `docker compose config` sin `.env` falla con el mensaje `APP_KEY is required`.
- [ ] `grep -n "192.168" compose*.yaml` no devuelve resultados.
- [ ] `ss -ltn` en el host muestra 5432 y 8000 solo en `127.0.0.1`, y 3000 en `0.0.0.0`.
- [ ] `docker compose ps` muestra los contenedores como `tpvs-tpvs-*`, y los volúmenes `tpvs_tpvs-postgres-data` y `tpvs_tpvs-ollama-data` existentes se reutilizan sin pérdida de datos.
- [ ] `docker compose exec tpvs-backend id -u` y `docker compose exec tpvs-frontend id -u` devuelven el `UID` del `.env`, no `0`.
- [ ] Los archivos nuevos de `backend/storage/logs` en el host pertenecen al usuario del host.
- [ ] Al cambiar `composer.lock` o `package-lock.json` y reiniciar el servicio, las dependencias se reinstalan sin `--build`.
- [ ] Un cambio en `backend/app` se aplica en el siguiente job del worker sin reiniciarlo.
- [ ] `docker compose stop tpvs-queue-worker` con un job de IA en curso espera a que termine, hasta 340 s.
- [ ] `docker compose up -d` sin perfil arranca todo sin `tpvs-ollama`.
- [ ] `docker compose --profile ai up -d` deja `tpvs-ollama` `healthy`, y `tpvs-ollama-pull` termina con código 0 y el modelo listado.
- [ ] Con el perfil `ai`, una imagen subida desde la UI produce un registro en `NEEDS_REVIEW`.
- [ ] `tpvs-frontend` no puede resolver `tpvs-ollama` ni `tpvs-postgres`.
- [ ] La imagen de `tpvs-ollama` usa un tag fijo, no `latest`.
- [ ] `docker compose -f compose.yaml -f compose.prod.yaml config` no tiene bind mounts y solo publica el puerto 80.
- [ ] El stack de producción arranca en local, `http://localhost/` muestra el login y `/up` responde 200 a través de `tpvs-web:8080` desde la red interna.
- [ ] `docker compose -f compose.ai-host.yaml up -d` arranca Ollama y `/api/tags` responde desde otra máquina de la LAN.
- [ ] Las comprobaciones del frontend pasan: `npm test`, `npm run lint`, `npx tsc --noEmit` y `npm run build`.
- [ ] `vendor/bin/pint --test` no reporta cambios en `tests/TestCase.php`.
- [ ] `docs/backend/configuration-operations.md` ya no menciona `ai-spike` ni `docker-compose.yml`.
- [ ] El checkpoint de humo está registrado en `docs/frontend/verification-log.md`.

## Decisions

- **Sí:** un solo SPEC global con fases ejecutables por separado. Lo decidió el usuario, aunque cubra desarrollo, Ollama y producción. Cada fase se puede hacer en una sesión nueva.
- **Sí:** `compose.yaml` más `compose.override.yaml`, que Compose carga solo. **No:** mantener `docker-compose.yml` con un archivo de producción aparte, porque duplicaría lo compartido.
- **Sí:** conservar los nombres `tpvs-*` y agregar `name: tpvs`. **No:** nombres cortos, porque cambiarían el comando de tests de `CLAUDE.md`, la documentación y la memoria.
- **Sí:** Ollama en un perfil opcional `ai`. **No:** siempre encendido, porque ocupa entre 4 y 8 GB de RAM en una máquina sin GPU.
- **Sí:** `AI_OLLAMA_URL` sigue viniendo del `.env`. **No:** fijar la URL en Compose, porque en producción Ollama vive en otro PC. El código es el mismo en los dos casos.
- **Sí:** guardia en `TestCase`, script de init y `make test`. **No:** solo la parte de Docker, porque `php artisan test` seguiría siendo peligroso.
- **Sí:** la guardia va en `createApplication()`. **No:** en `setUp()`, porque `RefreshDatabase` corre antes.
- **Sí:** php-fpm más nginx en producción. **No:** FrankenPHP u Octane, por ser más nuevos y por el riesgo de fugas de estado. **No:** `artisan serve` en producción.
- **Sí:** `tpvs-web` (nginx) como única entrada en producción, con :80 hacia Next.js y :8080 interno hacia php-fpm. Así HTTPS solo tocará nginx cuando llegue.
- **Sí:** `queue:listen` en desarrollo y `queue:work` en producción. **No:** `queue:work` con reinicio manual en desarrollo.
- **Sí:** en desarrollo `queue:listen` corre dentro de `backend/docker/queue-listen.sh`. Laravel no instala manejadores de señales en `queue:listen` ni en su hijo `queue:work --once`, así que sin el envoltorio `docker compose stop` esperaba siempre los 340 s. El envoltorio congela el listener y espera al job en curso. El target `dev` fija `STOPSIGNAL SIGTERM`, porque `php:8.3-fpm` trae `SIGQUIT`. Lo decidió el usuario durante el paso 8.
- **No:** healthcheck para el worker, porque no hay una señal fiable. Lo cubre `restart: unless-stopped`.
- **Sí:** 8000 y 5432 solo en `127.0.0.1` en desarrollo. **No:** dejarlos sin publicar, porque se siguen usando curl y clientes de base de datos desde el host.
- **Sí:** el `.env` raíz como única fuente bajo Docker. `backend/.env.example` queda solo para correr Laravel fuera de Docker. **No:** borrar el `backend/.env` del usuario. Solo se avisa.
- **No:** HTTPS en este SPEC. Lo decidió el usuario. En consecuencia, el login en modo producción no se verifica aquí.
- **No:** `compose.gpu.yaml`. El PC de IA no tiene GPU.
- **Sí:** `compose.ai-host.yaml` como archivo independiente para el PC de IA. **No:** solo documentación.
- **No:** Redis. La cola en base de datos alcanza para la carga del MVP, aunque `architecture.md` lo mencione.
- **Sí:** volúmenes con las mismas claves, para no perder la base ni los modelos ya descargados.
- **Sí:** la rama `spec-26-docker-environment` se creó junto con el SPEC, a pedido del usuario.

## Risks

| Riesgo | Mitigación |
| --- | --- |
| Renombrar el archivo o el proyecto crea volúmenes nuevos y la base aparece vacía | `name: tpvs` y las mismas claves de volumen. Verificar con `docker volume ls` antes y después del paso 4. Hacer `pg_dump` de `tpvs` antes de la fase B. |
| El remapeo de `UID` deja `storage` o `vendor` con permisos que el usuario nuevo no puede escribir | El entrypoint corrige los permisos de los volúmenes nombrados al arrancar. Verificar con un upload y un log nuevo. |
| El script de init solo corre con un volumen nuevo, y la base actual no tiene `tpvs_test` | `make test` crea `tpvs_test` si falta antes de correr la suite. |
| `queue:listen` en desarrollo se comporta distinto que `queue:work` en producción | Los dos usan el mismo `--timeout` y la misma conexión. La fase D prueba `queue:work` en el stack de producción local. |
| `required: false` en `depends_on` necesita Compose 2.20 o posterior | El host tiene 2.40.3. Documentar la versión mínima en `configuration-operations.md`. |
| Ollama en CPU tarda más de 300 s con imágenes grandes | Se conserva `AI_OLLAMA_TIMEOUT=300` y `OLLAMA_KEEP_ALIVE=30m` evita recargar el modelo. Si se excede, el job falla con un error transitorio y se reintenta, como hoy. |
| `output: "standalone"` cambia algo del comportamiento de `next dev` | Correr las cuatro comprobaciones del frontend y el checkpoint de humo en desarrollo. |
| Publicar 11434 en el PC de IA expone Ollama sin autenticación | La documentación exige un firewall que solo permita la IP del servidor de aplicación. `OLLAMA_BIND` permite publicar en una sola interfaz. |
| El login no funciona en el stack de producción por falta de HTTPS | Está fuera de alcance y documentado. La verificación de producción se limita a la carga del login, `/up` y los healthchecks. |

## What is **not** in this spec

- HTTPS, certificados y el despliegue real en la LAN (8.18).
- Soporte de GPU.
- Redis, Horizon, Octane o FrankenPHP.
- Renombrar los servicios.
- CI/CD, registro de imágenes, respaldos y monitoreo.
- Reglas de firewall automatizadas.
- Cambios en el prompt, el modelo, los reintentos o los timeouts de la IA.
- Cambios de esquema, del contrato HTTP o de la UI.

Cada uno de estos temas, si llega, va en su propio SPEC.
