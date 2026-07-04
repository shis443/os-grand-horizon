# CHANGELOG — Sea Panther Reservas

## Reused from miniCal as-is
- Auth/session (Tank_auth), CI3/HMVC routing (wiredesignz MX), i18n (`language` helper +
  English/Spanish packs already shipped), room creation (`Room_model`), booking engine
  (`booking`/`booking_block` tables, `Booking_model` state machine), currency table (EUR
  already seeded), and the company-level "business date" (`company.selling_date`) — which
  *is* the Simulator Date feature, not a new concept.
- Room housekeeping status (`room.status` Clean/Dirty/Inspected) and the existing
  auto-dirty-on-checkout automation, adapted into a card-based Cleaning Schedule.

## Adapted
- **Reservation grid** (`panther_grid`): re-presents core `booking`/`booking_block` data
  (state, color, check-in/out datetimes, customer, booking source) as a day-rows ×
  room-columns table instead of FullCalendar's resource-timeline, since the requested
  cell layout doesn't map onto the stock calendar widget. All state transitions
  (check-in time, room assignment) write to the same core tables the stock calendar uses.
- **Housekeeping** (`panther_housekeeping`): card UI + nav badge over the existing
  `room.status` engine, plus a small extension table for manual per-booking cleaning
  requests raised from the grid (core has no per-booking cleaning flag, only per-room).

## New extensions
- `panther_shell` — chassis: simulator date control (writes through to
  `company.selling_date`), active month/year + Sync to Month, dark/light theme,
  EN/ES toggle (wraps core i18n), Reset Data, nav/logout.
- `panther_room_status` — Live Room Status dashboard (derives occupancy from core
  tables, no new occupancy state), Check-In Entry action, operational stats.
- `panther_surcharges` — Extra Money Notice Fees ledger (`surcharges` table).
- `panther_cash_register` — Crew Cash Register (`cash_transactions` table),
  Collect € flow linked to open surcharges.
- `panther_audit_log` — append-only Audit Trail (`audit_log`/`audit_log_archive`
  tables); "Clear History" archives rather than deletes, and logs itself.

## New database tables (migration `002_panther_extensions.php`)
`app_state`, `cleaning_requests`, `surcharges`, `cash_transactions`, `audit_log`,
`audit_log_archive`. None alter existing core tables. All money stored as integer
cents (`amount_eur`), formatted at the view layer via `panther_eur()`.

## Deviations from the original brief (see PLAN.md §5 for the reasoning)
- "Guest nationality" is rendered as `guest name (booking source)` — e.g. "Aru (AirBNB)" —
  since miniCal has no nationality field; booking source (Airbnb/Direct/Booking.com/…)
  is the closest existing concept and matches the example given.
- Added `cleaning_requests` as a small extra table not in the original 4-table list, to
  represent a per-booking cleaning flag (core only tracks cleanliness per room).
- Simulator date has no dedicated `app_state` column — it writes through to
  `company.selling_date` so core's own auto-checkout logic stays in sync.
- Dark/light theme is scoped to the 7 panther_* pages only; existing core screens
  (settings, invoices, the stock calendar) are untouched and keep their original styling.
- "Clear History" archives all rows into `audit_log_archive` instead of deleting them,
  then logs the clear action itself — true deletion would contradict "append-only."

## Core touches (additive only, nothing existing modified beyond dependency plumbing)
- `public/application/core/Panther_controller.php` — new shared base controller for
  all panther_* extensions (chrome rendering, badges, package-path wiring).
- `public/application/migrations/002_panther_extensions.php` +
  `config/migration.php` version bump 1→2 — new tables only.
- Build/dependency fixes documented in SETUP.md (PHP 7.3→7.4, xdebug pin,
  php-cs-fixer removal, Composer platform pin) — required to get the stock repo
  running at all, unrelated to the extensions themselves.
- `.gitignore` — narrowed the blanket `public/application/extensions/` exclusion so
  our own `panther_*` extensions are tracked while marketplace drop-ins still aren't.

## Demo data
`panther_shell/reset_data` (also reachable via Settings → Reset Demo Data) seeds a
July 2026 sheet across the 3 fixed cabins: two in-house stays, a same-day turnover
on both occupied rooms, one upcoming reservation, two surcharges, two cash
transactions, and one open cleaning request.
