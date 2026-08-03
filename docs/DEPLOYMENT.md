# Levantar WpbotReserva

Todo el proyecto corre sobre Docker — no se necesita PHP, Composer, Node ni MySQL instalados en el host, ni en desarrollo ni en producción (Parte XV/docs/architecture del plan de arquitectura).

## Desarrollo local

Requisito: Docker + Docker Compose instalados.

```bash
git clone <repo> wpbotreserva
cd wpbotreserva
cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

`docker compose up -d` carga automáticamente `docker-compose.yml` + `docker-compose.override.yml` (Mailpit, phpMyAdmin, puertos de MariaDB/Redis expuestos al host, bind mounts para editar código sin reconstruir la imagen).

Verificar que todo esté sano:

```bash
docker compose ps        # todos los servicios en "healthy"
curl http://localhost:8000/health   # {"status":"ok"}
```

- App: http://localhost:8000
- phpMyAdmin: http://localhost:8090 (MariaDB del proyecto en el host: `localhost:3316` — 8080/3306 quedan libres para otros proyectos que ya corran en la máquina)
- Mailpit: http://localhost:8025

Para correr cualquier comando dentro del contenedor de la app: `docker compose exec app <comando>` (ej. `docker compose exec app php artisan test`, `docker compose exec app composer require ...`).

Para bajar el entorno: `docker compose down` (agregar `-v` solo si además querés borrar los datos de MariaDB/Redis — no usar por accidente).

## Primer despliegue en un VPS limpio

Requisito: Docker + Docker Compose instalados en el VPS, nada más.

```bash
git clone <repo> wpbotreserva
cd wpbotreserva
cp .env.example .env
# Editar .env: APP_ENV=production, APP_DEBUG=false, APP_URL real,
# DB_PASSWORD/DB_ROOT_PASSWORD/APP_KEY propios (nunca los de .env.example),
# credenciales reales de IA si aplica.
docker compose -f docker-compose.yml up -d --build   # sin el override de dev
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force
```

`-f docker-compose.yml` evita que se cargue `docker-compose.override.yml` (Mailpit/phpMyAdmin/puertos expuestos son solo de dev).

## Backups

El contenedor `scheduler` corre `backup:database` todos los días a las 03:00 (hora del contenedor) — dump comprimido con fecha en el volumen `backup_data`, con retención configurable vía `BACKUP_RETENTION_DAYS` en `.env`. Sincronizar ese volumen a almacenamiento externo (S3, etc.) es una decisión operativa aparte, no resuelta por este documento — el backup queda listo para que ese proceso lo levante.

Backup manual: `docker compose exec app php artisan backup:database`.
