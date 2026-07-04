# SETUP.md — Running miniCal locally (verified working steps)

This documents the exact steps used to get the upstream miniCal PMS running
locally via Docker on 2026-07-04, including three upstream bugs that had to be
patched to make `composer install` and the install wizard actually work.
Stack confirmed: **PHP (CodeIgniter 3 + wiredesignz MX/HMVC), MySQL-compatible
(MariaDB), Composer, .env config**.

## Prerequisites

- Docker + Docker Compose (Docker Desktop on macOS is sufficient).
- No local PHP/MySQL/Composer install is required — everything runs in containers.

## 0. Upstream fixes applied (already committed in this checkout)

These were required to get `composer install`/`update` and the app itself to run.
Without them the stock repo does not start.

1. **`docker/PHP.Dockerfile`** — bumped base image from `php:7.3.33-fpm` to
   `php:7.4.33-fpm`. Reason: `composer.json` requires `rinvex/countries: ^7.3`,
   and every 7.3.x release of that package itself requires PHP `^7.4`. The
   README/Dockerfile said PHP 7.3, which cannot satisfy that dependency. CI3
   fully supports 7.4, so this is a safe minimal bump.
2. **`docker/PHP.Dockerfile`** — pinned `pecl install xdebug-2.9.8` (was
   unpinned `pecl install xdebug`). Reason: unpinned pecl now resolves to the
   latest Xdebug (3.4+), which requires PHP ≥ 8.0 and fails the build.
3. **`composer.json`**:
   - Removed `"friendsofphp/php-cs-fixer": "^3.0"` from `require`. It's a
     dev-only lint tool, not referenced anywhere in the app's runtime code
     (verified via grep), but was pinned in `require` with an open-ended `^3.0`
     range that resolves to a release requiring PHP ≥ 8.4. It was also the
     reason `composer.lock` was out of sync with `composer.json` in the first
     place (the lock was missing `rinvex/countries`, forcing a full
     `composer update` rather than `install`).
   - Added `"config": {"platform": {"php": "7.4.33"}}`. Reason: the
     `composer` service in `docker-compose.yaml` uses the `composer:latest`
     image, which itself bundles a modern PHP (8.5 at time of writing) purely
     to *run* Composer. Without a pinned platform, Composer's resolver
     silently picks package versions compatible with *that* PHP instead of the
     app's actual PHP 7.4.33 runtime — this is what caused dependencies
     (guzzle, symfony/deprecation-contracts, etc.) to jump to versions
     requiring PHP 8.1+.
   - Regenerated `composer.lock` via `composer update` under the corrected
     platform config (see step 2 below).

None of these touch application/business logic — only build/dependency plumbing.

## 1. Environment file

```bash
cp docker/.env.example .env
```

Uses `DATABASE_HOST=db`, `DATABASE_USER=root`, `DATABASE_PASS=MiniCalPwd`,
`DATABASE_NAME=minical`, `PROJECT_URL=http://localhost:8080/public`,
`API_URL=http://web/api`. Adjust `PROJECT_URL`/`API_URL` if not running on
`localhost` (the docker README notes this matters for the app's internal API
calls, e.g. Room Inventory).

## 2. Build and start the stack

```bash
cd docker
docker-compose up -d      # builds nginx (web), php-fpm (php), mariadb (db), phpmyadmin
docker-compose run --rm composer update
```

Services: `web` (nginx, :8080), `php` (php-fpm 7.4), `db` (MariaDB, :3306),
`phpmyadmin` (:8888). `docker-compose.yaml` mounts the repo root into `/app`
for both `web` and `php`.

Use `update` rather than `install` — the checked-in `composer.lock` predates
the `rinvex/countries` entry in `composer.json` and is out of sync.

## 3. Create the database schema (CodeIgniter migrations)

The install wizard's "seed" step (`public/install/minical-seed.sql`) is
**data-only** (currencies, lookups, etc.) — it contains zero `CREATE TABLE`
statements and assumes the schema already exists. The schema itself comes
from `public/application/migrations/001_create_base.php`, run through CI's
migration library via the `Migrate` controller. Also note: any request before
the `sessions` table exists gets silently redirected to
`/install/index.php` (see `MY_Session.php`) *unless* you pass
`MIGRATION_REQUEST=1`:

```bash
curl "http://localhost:8080/public/migrate?MIGRATION_REQUEST=1"
# → " Migrated successfully "
```

This creates all 112 core tables.

## 4. Seed lookup data

```bash
curl -X POST http://localhost:8080/public/install/db_verification.php   # sanity check, DB connectivity
curl -X POST http://localhost:8080/public/install/database_seeding.php  # imports minical-seed.sql (currencies etc.)
```

`database_seeding.php` processes the ~1MB seed file in time-boxed chunks
(3s/request) and tracks progress in a `minical_installation_meta` table; call
it repeatedly until the JSON response contains `"success":true`. In practice
it completed in a single call against the local MariaDB container.

Equivalently, you can just drive the wizard UI at
`http://localhost:8080/public/install/index.php` in a browser — it calls the
same two endpoints (`db_verification.php`, `database_seeding.php`) via AJAX in
a loop.

## 5. Verify

```bash
curl http://localhost:8080/public/auth/login     # 200
curl http://localhost:8080/public/auth/register   # 200 — create the first admin account here
```

Then open `http://localhost:8080/public/auth/register` in a browser, create
the admin/company account, and log in. From there, `Properties → Rooms` is
where the 3 cabins (Sunset, Sunrise1, Sunrise2) get configured, and
`Settings → Extensions` is where custom extensions (see PLAN.md) get toggled
on per-company once installed under `public/application/extensions/`.

## Useful side tools

- phpMyAdmin: `http://localhost:8888` (user `root`, pass `MiniCalPwd`, or
  `minical`/`MiniCalPwd`).
- Direct DB shell: `docker exec -it docker-db-1 mariadb -uroot -pMiniCalPwd minical`.

## Tearing down / resetting

```bash
cd docker
docker-compose down          # keep volumes (DB data persists)
docker-compose down -v       # also wipe the mariadb volume for a clean slate
```
