# PLAN.md — Sea Panther Reservas on OS Grand Horizon

Status: **built and verified locally** (all 7 extensions, running end-to-end at
`http://localhost:8080/public` per SETUP.md). The 5 open questions in §5 were
resolved with the proposed defaults (told to proceed rather than wait) — each is
called out below and in CHANGELOG.md. Revisit any of them if you want different
behavior; none are hard to change.

## 1. Architecture recap (what reuse actually means here)

OS Grand Horizon is CodeIgniter 3 + the wiredesignz **MX** HMVC library. Everything under
`public/application/extensions/<name>/` is a self-contained HMVC module:

```
extensions/<name>/
  config/config.php     # required — presence of this file is what makes MY_Controller
                         # discover the module at all (scans module_location = extensions/)
  config/route.php       # optional — defines $extension_route[...] = 'controller/method',
                          # picked up by application/config/routes.php and prefixed as
                          # <name>/controller/method
  config/menu.php        # optional — $module_menu, merged into the sidebar
  config/autoload.php    # optional — helpers/libraries to autoload when active
  hooks/actions.php, hooks/filters.php   # optional — autoloaded as CI packages when active
  controllers/ models/ views/ helpers/
```

Activation is **pure local DB**, no marketplace/network dependency:
`extensions_x_company(extension_name, company_id, is_active)`, checked by
`Permission->is_extension_active()` (`libraries/permission.php` →
`permission_model.php:565`). `controllers/extensions.php`'s marketplace-fetch
branch only runs when `HTTP_HOST` is `app.osgrandhorizon.io`/`demo.osgrandhorizon.io` — never
on a self-hosted single-property install. So: create the module folder, insert
one `extensions_x_company` row (`is_active=1`) per extension for our one
company, done.

Two existing core mechanisms are directly reusable rather than reinventable:

- **`company.selling_date`** is already OS Grand Horizon's business-date override — it
  drives auto-checkout (`Booking_model::auto_check_out_guests`), occupancy
  math, and is read everywhere as "today" (`MY_Controller.php:283`,
  `booking.php` throughout). This *is* the "Simulator Date" feature, not a new
  concept — see §4.7.
- **`room.status`** (`Clean|Dirty|Inspected`) plus company-level
  `housekeeping_auto_dirty_is_enabled`/`housekeeping_auto_clean_is_enabled`
  settings already implement auto-queued checkout cleaning. Reuse this engine
  for §4.2 rather than rebuilding it.

## 2. Feature → classification map

| # | Feature | Classification | Notes |
|---|---|---|---|
| 1 | Reservation grid | **(b) adapt** | Reuse `Booking_model`, `Booking_room_history_model`, state constants (`config/constants.php`), color rules. New presentation layer (see §4.1) — the requested day-rows/room-columns/sub-cell layout doesn't map onto FullCalendar's resource-timeline without a rewrite, so this ships as a new extension view that calls into the existing data/business layer. |
| 2 | Housekeeping / Cleaning Schedule | **(b) adapt** | Reuse `Room_model` (`get_housekeeping_report`, `update_room_status`, `set_rooms_clean`) and the existing auto-dirty-on-checkout automation. New card-based view + nav badge (core's `room_status.php` is a table, no badge). |
| 3 | Live Room Status dashboard | **(c) new extension** | `panther_room_status` |
| 4 | Surcharges ledger | **(c) new extension** | `panther_surcharges` |
| 5 | Crew Cash Register | **(c) new extension** | `panther_cash_register` (depends on `panther_surcharges`) |
| 6 | Audit Trail Log | **(c) new extension** | `panther_audit_log` |
| 7 | Simulator Date + active sheet | **(b) adapt + (c) new** | Write-through UI over `company.selling_date` (adapt) + new `active_month`/`active_year` state (no core equivalent) |
| 8 | Branding / settings | **(b) adapt** | Sidebar nav, EN/ES toggle reuse core i18n (`language` helper, `application/language/{english,spanish}` already ship); dark/light theme and "Reset Data" are new |
| — | 3 rooms (Sunset/Sunrise1/Sunrise2) | **(a) reuse as-is** | Created via existing Settings → Rooms UI (`Room_model::create_room`), no code needed |
| — | Currency EUR | **(a) reuse as-is** | `company.default_currency_id` → EUR row in seeded `currency` table (already has EUR) |
| — | Languages EN/ES | **(a) reuse as-is** | Both language packs already ship in `application/language/` |

Everything in row 3–8 lives under one umbrella "chassis" extension
(`panther_shell`) plus five feature extensions — see below.

## 3. Extension inventory

```
extensions/
  panther_shell/          # app_state, simulator date control, month/year nav,
                           # sidebar order, theme toggle, i18n toggle, reset data
  panther_grid/           # adapted reservation grid + guest search/source label
  panther_housekeeping/   # adapted Cleaning Schedule cards + nav badge
  panther_room_status/    # Live Room Status dashboard
  panther_surcharges/     # Extra Money Notice Fees ledger
  panther_cash_register/  # Crew Cash Register
  panther_audit_log/      # append-only Audit Trail Log
```

`panther_shell` has no feature of its own beyond providing the shared
`app_state` row, the simulator-date/month-year header partial (included by the
other six), and the base nav/theme/i18n chrome. The other extensions load its
model to read current sim date / active month.

## 4. Per-extension detail

### 4.1 `panther_grid` (adapts Booking/Booking_room_history)

- **Reused as-is**: `Booking_model` queries for a date range, `booking.state`
  (`RESERVATION/INHOUSE/CHECKOUT/...`), `booking_block.room_id` (model class is named Booking_room_history_model, but the actual table is `booking_block`) +
  `check_in_date`/`check_out_date`, `booking.color`, `booking.housekeeping_notes`.
- **New DB**: none required for the grid itself. The "cleaning-flagged" purple
  status and the extra-money badge need small linking tables — see the flagged
  question in §5.2.
- **Routes**: `panther_grid/month/(:num)/(:num)` → `Grid::show($year, $month)`;
  AJAX: `panther_grid/cell_action` (check-in time edit / room assign / toggle
  cleaning flag / log extra money — the last one calls into
  `panther_surcharges`'s model directly, see §4.4).
- **Views**: `views/grid.php` (month table: rows 1–31, one column-group per
  room with 3 sub-cells), `views/partials/cell.php`.
- Guest-source label ("Aru (Airbnb)"): OS Grand Horizon has no "nationality" field on
  customers; it does have `booking_source` (Direct/Airbnb/Booking.com/...).
  **Proposed interpretation**: render `<guest first name> (<booking source
  name>)`, reusing the existing `booking_source` table/relationship rather
  than adding a nationality column. Flagged in §5.1 for confirmation — this
  reads differently from a literal "nationality."

### 4.2 `panther_housekeeping` (adapts Room controller/model)

- **Reused**: `Room_model::get_housekeeping_report`, `update_room_status`,
  `set_rooms_clean`, the company auto-dirty-on-checkout settings.
- **New**: card view (room/date/guest/status/"Mark Clean" button) replacing
  the stock table view, nav badge showing open-cleaning count. Reads the
  cleaning-request flag from the small table proposed in §5.2 for
  grid-originated requests, unioned with rooms already `Dirty` from the
  built-in auto-flagging.
- **Routes**: `panther_housekeeping/index`, `panther_housekeeping/mark_clean/(:num)`,
  `panther_housekeeping/badge_count` (AJAX, for the nav badge).

### 4.3 `panther_room_status` (new)

- **DB**: none — occupancy/vacancy is derived from `room` + `booking_block` (the actual table behind Booking_room_history_model)
  joined against `panther_shell`'s simulator date; no need to duplicate state.
- **Routes**: `panther_room_status/index`, `panther_room_status/check_in/(:num)`
  (writes into the existing booking check-in flow — reuses `Booking_model`
  state transition rather than a parallel one).
- **Views**: one card per room (Vacant/Occupied + occupant), side panel with
  operational stats (vacant/occupied counts, unpaid-surcharge count pulled
  from `panther_surcharges`), and the live simulator date from `panther_shell`.

### 4.4 `panther_surcharges` (new)

- **DB**: `surcharges(id, booking_id, room, guest_name, date, amount_eur INT, cleared_bool TINYINT, created_at, cleared_at)`.
  `amount_eur` stored in integer cents throughout (money constraint).
  FK `booking_id` → `booking.booking_id`.
- **Routes**: `panther_surcharges/index` (ledger + search/filter), `panther_surcharges/clear/(:num)`,
  `panther_surcharges/log` (AJAX, called from `panther_grid` cell modal AND from this
  extension's own "+Add" button).
- **Nav badge**: running SUM of uncleared `amount_eur`.

### 4.5 `panther_cash_register` (new, depends on `panther_surcharges`)

- **DB**: `cash_transactions(id, type ENUM('in','out'), amount_eur INT, date, customer,
  category, handled_by, notes, source_surcharge_id NULL FK → surcharges.id, created_at)`.
- **Routes**: `panther_cash_register/index` (summary cards + master ledger + search/type filter),
  `panther_cash_register/log_income`, `panther_cash_register/log_expense`,
  `panther_cash_register/collect/(:num)` (surcharge id — records income row + clears the surcharge
  in one transaction).
- Summary cards computed from `SUM(amount_eur) WHERE type='in'` minus `type='out'`.

### 4.6 `panther_audit_log` (new)

- **DB**: `audit_log(id, type, room, user_id, utc_timestamp, message)`. Model exposes
  only `add()` and `get_all()` — no `update()`/`delete()` methods exist at all, so
  immutability is enforced by the absence of the capability, not by a runtime check.
- **"Clear History"**: see §5.3 — literal deletion contradicts "append-only." Proposed:
  clear = insert a `clear_history` audit row, then move existing rows to
  `audit_log_archive` (same schema) instead of deleting — history still exists, just
  off the live view, and the clear action itself is permanently logged.
- Other extensions call `$this->load->model('panther_audit_log/Audit_log_model')`
  (cross-extension model load, standard MX pattern) to append entries — check-in,
  check-out, extra fee logged, cleaning marked done.
- **Routes**: `panther_audit_log/index` (view, admin-only `panther_audit_log/clear`).

### 4.7 `panther_shell` (new — cross-cutting)

- **DB**: `app_state(id, company_id, active_month, active_year, theme, lang)`.
  Deliberately **excludes** `simulator_date` as a separate column — see below.
- **Simulator date**: writes straight to `company.selling_date` via
  `Company_model` (already exists) rather than storing a second, potentially
  divergent "fake today." This guarantees the auto-checkout/occupancy logic
  everywhere in core stays in sync with what the header control shows, for
  free. Flagged as a deviation from the literal spec (`app_state.simulator_date`)
  in §5.4 — reusing `company.selling_date` is less code and can't drift, but
  it means the simulator date is company-wide state, not extension-private
  state.
- **Active Month/Year + Sync to Month**: genuinely new — no core equivalent.
  Stored on `app_state`, read by `panther_grid`/`panther_room_status`/`panther_housekeeping`.
- **Theme (dark/light)**: core already has a *different* per-company concept
  (`company.ui_theme`, 3 preset numbered CSS files) — not a light/dark toggle.
  New `app_state.theme` (`dark`/`light`), applied as a body class. See the
  core-touch note in §5.5.
- **i18n toggle**: thin wrapper over the existing `$this->session->userdata('language')`
  + `$this->lang->load(...)` mechanism — both English and Spanish language
  packs already ship. `app_state.lang` just mirrors the session value for the
  header widget's benefit.
- **Reset Data**: admin-only action that truncates our 4 extension tables and
  re-runs a bundled `panther_shell/seed/demo_july.sql` (see deliverables) — does
  **not** touch core booking data by default, to avoid data loss surprises.
- **Sidebar nav / Logout**: nav order is a config array in `config/menu.php`
  merged across all 7 extensions plus this shell; Logout is a plain link to
  the existing `auth/logout` route — zero new code.

## 5. Open questions / flagged decisions — RESOLVED (proceeded with the proposed default on each; all built and verified)

1. **"Nationality" label** — RESOLVED as `guest name (booking source)`, e.g.
   "Aru (AirBNB)" (capitalization is OS Grand Horizon's own `COMMON_BOOKING_SOURCES`
   constant, not ours). No customer schema change made. Revisit if you want a
   literal nationality field instead.
2. **Per-booking "Cleaning Requested" flag** — RESOLVED: added
   `cleaning_requests(id, company_id, booking_id, room_id, request_date, status, requested_at, cleared_at)`
   (migration `002_panther_extensions.php`), written by `panther_grid`'s toggle,
   read by `panther_housekeeping`. Verified: toggling from the grid correctly
   surfaces the room on the Cleaning Schedule and clears on "Mark Clean."
3. **Audit Trail "Clear History"** — RESOLVED as archive-and-mark
   (`Audit_log_model::archive_all()`): live rows move to `audit_log_archive`,
   nothing is deleted, and the clear action itself is logged as the first new
   entry. Verified live: clearing 3 entries left exactly 1 (the clear-history
   entry itself) in `audit_log`, 3 in `audit_log_archive`.
4. **Simulator date storage** — RESOLVED as write-through to
   `company.selling_date` (`App_state_model::set_simulator_date`/`get_simulator_date`).
   `app_state` has no `simulator_date` column. Verified: setting it via the
   header control updates `company.selling_date` directly.
5. **Dark/light theme reach** — RESOLVED as scoped: only the 7 panther_* pages
   respect the dark/light toggle (`data-panther-theme` + CSS variables in
   `panther_shell/assets/panther.css`); no existing core view was modified for
   theming. Revisit if you want the whole app (booking calendar, settings,
   invoices) themed too — that would need the `bootstrapped_template.php`
   injection point described in the original draft of this question.

## 6. Constraints checklist

- No core table is altered destructively; the one core-adjacent write is
  `company.selling_date` via the model's existing setter (already a public,
  intended mutation point — not a schema change).
- The only proposed literal core-file edit is the theming injection point in
  §5.5, only if you want app-wide dark mode.
- All money stored as `INT` cents (`amount_eur`), formatted with a shared
  `format_eur()` helper at the view layer.
- Tests: PHPUnit for `Surcharges_model`/`Cash_transactions_model` cent-math
  and for `Audit_log_model` (assert no update/delete methods exist / archive
  behavior on clear).

## 7. Build order — COMPLETE

1. `panther_shell` ✅
2. `panther_audit_log` ✅
3. `panther_surcharges` ✅
4. `panther_cash_register` ✅
5. `panther_grid` ✅
6. `panther_housekeeping` ✅
7. `panther_room_status` ✅
8. Demo July seed data + CHANGELOG.md ✅

All 7 pages verified end-to-end via authenticated HTTP requests against the local
Docker stack (see SETUP.md): grid renders check-in/reserved/checkout/turnover
cells correctly with extra-money badges; housekeeping/room-status/surcharges/
cash-register nav badges reflect live data; check-in, mark-clean, collect-€,
theme/language toggle, simulator-date write-through, sync-to-month, and
clear-history (archive) actions were all exercised and confirmed against the
database, not just rendered.

One known follow-up: a fresh admin account created via the streamlined
registration path has a blank first/last name until you fill out your profile —
the audit log falls back to email, then to `#<user_id>`, so this doesn't block
anything.
