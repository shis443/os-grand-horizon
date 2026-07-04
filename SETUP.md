# SETUP.md — Running OS Grand Horizon locally (verified working steps)

This documents the exact steps used to get the upstream OS Grand Horizon PMS running
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

4. **`docker/nginx.conf`** — bumped `fastcgi_buffers`/`fastcgi_buffer_size`
   from `8 16k`/`32k` to `16 32k`/`64k`. Reason: this app caches a fair
   amount of per-user state (menus, permissions, enabled languages, panther_*
   theme/state) in the session, and on heavier pages (e.g. `/booking`) the
   resulting `Set-Cookie` header can push the combined response header size
   past the default buffer, which nginx reports as `upstream sent too big
   header while reading response header from upstream` and returns as a 502 —
   surfaced once session data had accumulated across enough requests/features.

## 1. Environment file

```bash
cp docker/.env.example .env
```

Uses `DATABASE_HOST=db`, `DATABASE_USER=root`, `DATABASE_PASS=OSGrandHorizonPwd`,
`DATABASE_NAME=osgrandhorizon`, `PROJECT_URL=http://localhost:8080/public`,
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

The install wizard's "seed" step (`public/install/osgrandhorizon-seed.sql`) is
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
curl -X POST http://localhost:8080/public/install/database_seeding.php  # imports osgrandhorizon-seed.sql (currencies etc.)
```

`database_seeding.php` processes the ~1MB seed file in time-boxed chunks
(3s/request) and tracks progress in a `osgrandhorizon_installation_meta` table; call
it repeatedly until the JSON response contains `"success":true`. In practice
it completed in a single call against the local MariaDB container.

Equivalently, you can just drive the wizard UI at
`http://localhost:8080/public/install/index.php` in a browser — it calls the
same two endpoints (`db_verification.php`, `database_seeding.php`) via AJAX in
a loop.

## 5. Create the admin account

Open `http://localhost:8080/public/auth/register` in a browser and register
(email + password). This creates a company with 10 default rooms via
`auth::new_register_AJAX`. To match the fixed 3-cabin config, either use
Settings → Rooms in the UI, or do it once via SQL (what this build used):

```sql
UPDATE company SET name='Sea Panther Reservas', default_currency_id=49 WHERE company_id=1; -- 49 = EUR
UPDATE room_type SET name='Master Bedroom', acronym='MBR' WHERE id=1;
INSERT INTO room_type (name, company_id, acronym, is_deleted, max_occupancy, min_occupancy, max_adults, max_children, can_be_sold_online, sort)
  VALUES ('Double Room', 1, 'DBL', 0, 2, 1, 2, 0, 1, 2);
UPDATE room SET room_name='Sunset', room_type_id=1 WHERE room_id=1;
UPDATE room SET room_name='Sunrise1', room_type_id=2 WHERE room_id=2;
UPDATE room SET room_name='Sunrise2', room_type_id=2 WHERE room_id=3;
DELETE FROM room WHERE room_id IN (4,5,6,7,8,9,10);
UPDATE company SET number_of_rooms=3 WHERE company_id=1;
```

## 6. Migrate + activate the Sea Panther Reservas extensions

The panther_* extension tables ship as migration `002_panther_extensions.php`
(bumps `config/migration.php`'s `migration_version` to 2) — re-run the same
migrate call from step 3 to pick it up:

```bash
curl "http://localhost:8080/public/migrate?MIGRATION_REQUEST=1"
```

Activation is pure local DB (see PLAN.md §1) — insert one row per extension
per company:

```sql
INSERT INTO extensions_x_company (extension_name, company_id, is_active, is_favourite) VALUES
('panther_shell', 1, 1, 0), ('panther_audit_log', 1, 1, 0), ('panther_surcharges', 1, 1, 0),
('panther_cash_register', 1, 1, 0), ('panther_grid', 1, 1, 0),
('panther_housekeeping', 1, 1, 0), ('panther_room_status', 1, 1, 0);
```

Then visit `http://localhost:8080/public/panther_shell` (the console's home —
linked nowhere in core's own nav, since core's sidebar is a separate DB-driven
system; see PLAN.md §5 dark/light note for why we built our own chrome instead
of fighting it) and click **Reset Demo Data** to load the demo July sheet, or
call it directly:

```bash
curl -X POST http://localhost:8080/public/panther_shell/reset_data --cookie <your-session-cookie>
```

## 7. Verify

```bash
curl http://localhost:8080/public/panther_shell           # 200 — chassis/settings
curl http://localhost:8080/public/panther_grid             # 200 — reservation grid
curl http://localhost:8080/public/panther_housekeeping     # 200
curl http://localhost:8080/public/panther_room_status      # 200
curl http://localhost:8080/public/panther_surcharges       # 200
curl http://localhost:8080/public/panther_cash_register    # 200
curl http://localhost:8080/public/panther_audit_log        # 200
```
(all require the authenticated session cookie from login)

## Useful side tools

- phpMyAdmin: `http://localhost:8888` (user `root`, pass `OSGrandHorizonPwd`, or
  `osgrandhorizon`/`OSGrandHorizonPwd`).
- Direct DB shell: `docker exec -it docker-db-1 mariadb -uroot -pOSGrandHorizonPwd osgrandhorizon`.

## Rebranding notes (Minical → OS Grand Horizon)

All "Minical" branding was removed from display text, code identifiers,
filenames, and DB-seeded content, and replaced with "OS Grand Horizon" (see
CHANGELOG.md for the full breakdown). Two DB-level things aren't captured by
a simple file diff, in case you're setting this up fresh from the seed SQL
rather than inheriting an already-migrated database:

- `whitelabel_partner` id=0's `name`/`username`/`logo` columns are seeded data
  (from `osgrandhorizon-seed.sql`, née `minical-seed.sql`) — this build fixed
  the live row directly via SQL rather than the seed file (the seed file's
  raw bytes are unchanged upstream data).
- `config/config.php` now defines `$config['branding_name'] = 'OS Grand Horizon'`
  — this key was referenced in 6 view files (login/register headers, browser
  title fallback, the online booking template) but was never actually
  defined anywhere upstream, so it silently rendered blank. Defining it here
  both fixes a latent upstream bug and applies our branding in the one place
  the app already intended for exactly this.
- If you rename the database itself (as this build did, `minical` →
  `osgrandhorizon`), remember MariaDB has no `RENAME DATABASE` — use
  `RENAME TABLE minical.\`t\` TO osgrandhorizon.\`t\`` per table (or dump/restore),
  and separately `ALTER USER 'root'@'%'`/`'root'@'localhost' IDENTIFIED BY '...'`
  if you also change `DATABASE_PASS` — the MariaDB volume persists the
  original password from first container init regardless of what
  `docker-compose.yaml`'s `MYSQL_ROOT_PASSWORD` says on a later run.

## Tearing down / resetting

```bash
cd docker
docker-compose down          # keep volumes (DB data persists)
docker-compose down -v       # also wipe the mariadb volume for a clean slate
```
