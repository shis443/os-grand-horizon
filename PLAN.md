# PLAN.md — Sea Panther Reservas on miniCal

Status: **draft, pending your sign-off**. Nothing in this plan has been built yet
beyond the SETUP.md environment fixes. Please review §5 (open questions) before
I start §6 (build order).

## 1. Architecture recap (what reuse actually means here)

miniCal is CodeIgniter 3 + the wiredesignz **MX** HMVC library. Everything under
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
branch only runs when `HTTP_HOST` is `app.minical.io`/`demo.minical.io` — never
on a self-hosted single-property install. So: create the module folder, insert
one `extensions_x_company` row (`is_active=1`) per extension for our one
company, done.

Two existing core mechanisms are directly reusable rather than reinventable:

- **`company.selling_date`** is already miniCal's business-date override — it
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
  (`RESERVATION/INHOUSE/CHECKOUT/...`), `booking_room_history.room_id` +
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
- Guest-source label ("Aru (Airbnb)"): miniCal has no "nationality" field on
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

- **DB**: none — occupancy/vacancy is derived from `room` + `booking_room_history`
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

## 5. Open questions / flagged decisions (please confirm before I build)

1. **"Nationality" label** — no nationality field exists in miniCal; I'm
   reading "Aru (Airbnb)" as `guest name (booking source)`, not a literal
   country. Confirm, or tell me you want a real nationality field added to
   the customer record (that would be a new column on the core `customer`
   table, which the constraints ask me to avoid touching destructively — I'd
   add it as a nullable extension-side lookup table keyed by customer_id
   instead).
2. **Per-booking "Cleaning Requested" flag** — not in your 4-table list, and
   no core boolean exists at the booking level (`room.status` is per-room,
   not per-booking/date). I'm proposing one small additional table,
   `cleaning_requests(id, booking_id, room, date, status, requested_at, cleared_at)`,
   owned by `panther_housekeeping`, written to by `panther_grid`'s cell toggle.
   Confirm this addition, or propose an alternative encoding.
3. **Audit Trail "Clear History"** — true deletion conflicts with "append-only."
   Proposed: archive-and-mark rather than delete (§4.6). Confirm, or accept
   that "Clear History" really does mean irreversible delete (then the
   immutability guarantee is scoped to "no edits, no deletes *except* this one
   logged admin action").
4. **Simulator date storage** — proposed write-through to `company.selling_date`
   instead of a separate `app_state.simulator_date` column, to keep one
   source of truth for "today" across old and new code. Confirm, or require a
   fully independent `app_state.simulator_date` that core's own auto-checkout
   logic would then ignore (meaning you'd lose the built-in night-audit
   automation for anything driven by simulated dates).
5. **Dark/light theme reach** — a global toggle that also re-skins existing
   core pages (full booking calendar, settings, invoices, etc.) requires one
   small, clearly-scoped core touch: a body-class/CSS-link injection point in
   `views/includes/bootstrapped_template.php` (the shared base template every
   page — including our own — already extends). Alternative: scope dark/light
   strictly to our 7 new/adapted pages and leave the rest of core in its
   existing light styling. I'd default to the **scoped** option to honor
   "leave upstream core patchable," unless you want the whole app themed.

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

## 7. Suggested build order (one feature branch, one commit per extension)

1. `panther_shell` (chassis — everything else depends on its nav/app_state/simulator date)
2. `panther_audit_log` (other extensions call into it from day one)
3. `panther_surcharges`
4. `panther_cash_register`
5. `panther_grid` (adapts core booking data + writes to surcharges/audit/cleaning flag)
6. `panther_housekeeping`
7. `panther_room_status`
8. Seed data for demo July sheet + CHANGELOG.md

I'll pause here for your go-ahead on §5 before writing any extension code.
