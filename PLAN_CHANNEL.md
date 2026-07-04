# PLAN_CHANNEL.md — `panther_channel`: Channex OTA ingestion for Sea Panther Reservas

Status: **built and verified locally** (approved, all §11 open questions
resolved with the proposed defaults). This reconciles both project briefs
you gave me against (a) what's actually already in this codebase and (b)
what the real Channex docs say — several assumptions in both briefs turned
out to be wrong; each is called out below with the correction, matching the
"confirm from docs, do not assume" instruction.

One thing discovered *during* implementation, not anticipated here: a
**second core touch** beyond §3's `permission_model.php` whitelist was
required — a new core controller file
(`application/controllers/panther_channel_public.php`) for the webhook/cron
endpoints, since `MY_Controller.php` has an additional gate that 404s any
HMVC-extension route without a session, independent of the
permission_model.php whitelist. See RUNBOOK_CHANNEL.md "Why two
controllers?" for the full explanation. All actual logic still lives in the
extension; that file is a thin dispatcher, same pattern as
`Panther_controller.php`.

25/25 automated tests pass (`RUNBOOK_CHANNEL.md` "Running the test suite"),
covering normalization, idempotency, turnover, conflict detection, and
money conversion against the real local DB with fixture data matching
Channex's confirmed schema.

## 1. What already exists (reuse this, don't rebuild it)

`Channex_model` (`public/application/models/channex_model.php`, 953 lines) is
**100% local database CRUD — zero HTTP/curl calls to Channex anywhere in it**.
It's a complete persistence layer with nothing connecting it to the internet.
Full method inventory and exact table schemas below; the short version:

| Existing table | Columns | Maps to your spec's... |
|---|---|---|
| `ota_manager` | id, ota_id→`otas.id`, email, password, `meta_data` TEXT, company_id | connection identity (not secrets — see §5) |
| `ota_properties` | ota_manager_id, company_id, `channex_property_data` LONGTEXT | cached Channex property object |
| `ota_x_company` | **PK** ota_x_company_id, company_id, ota_manager_id, `ota_property_id`, is_active | **your proposed `channel_settings`** — already exists |
| `ota_room_types` | ota_x_company_id, `ota_room_type_id`, **`osgrandhorizon_room_type_id`**, company_id | **your proposed `channel_mappings` (rooms half)** — already exists, columns already correctly renamed |
| `ota_rate_plans` | ota_x_company_id, `ota_rate_plan_id`, **`osgrandhorizon_rate_plan_id`**, ota_room_type_id, company_id | **your proposed `channel_mappings` (rates half)** — already exists |
| `ota_bookings` | id, `ota_booking_id`, ota_type, booking_type, pms_booking_id, check_in/out_date, xml_out | partial `channel_reservations` — missing guest/amount/status fields, see §4 |
| `ota_xml_logs` | xml_log_id, xml_in, xml_out, datetime, request_type, response_type, ota_id, ota_property_id | generic log table, already used for PCI card-deletion logs |
| `otas` | id, key, name | static OTA lookup — **row `id=1, key='channex', name='Channex'` already seeded**, nothing to add |

Key `Channex_model` methods we will call directly (not reimplement):
- `get_related_pms_booking_ids($ota_booking_id, $booking_type=null)` (line 523) —
  our idempotency lookup. Keyed by `ota_booking_id` string; returns a flat array
  of `pms_booking_id`s (empty array if none — never null).
- `get_osgrandhorizon_room_type_id()` / `get_osgrandhorizon_rate_plan_id()` (424, 449) —
  the exact reverse-mapping lookups our normalization service needs.
- `save_logs($data)` → `ota_xml_logs` — **we will NOT reuse this for our new sync
  log** (see §4, `channel_sync_log` reasoning) but it stays untouched for its
  existing PCI-deletion-logging purpose.

**A pre-existing bug I found and will route around, not fix**:
`cancel_booking()` (line 567) builds `"CONCAT('Cancellation fee: $x,\n', booking_notes)"`
as a PHP string and passes it to `$this->db->update()` — CI's query builder binds
this as a literal value, so the `CONCAT(...)` text is stored verbatim rather than
evaluated as SQL. `panther_channel` will write its own minimal, correct
cancellation update directly (avoids inheriting the bug **and** avoids touching
`channex_model.php`, keeping it a pure core-file-untouched situation).

## 2. Corrections to both briefs, confirmed from https://docs.channex.io

### 2a. Channex has no HMAC webhook signature — this changes the whole security design
Both briefs assume a signature/HMAC scheme validated with `CHANNEX_WEBHOOK_SECRET`.
**Channex does not sign webhook payloads at all.** Their own docs: *"Channex
webhooks currently do not include a built-in HMAC signature or cryptographic
signing mechanism similar to Stripe/GitHub."* The actual mechanism: when you
register a webhook (`POST /webhooks`), you supply a `headers: {}` object of
your own custom request headers, and Channex echoes those exact headers back
on every delivery. So `CHANNEX_WEBHOOK_SECRET` becomes a **shared-secret header
value we choose** (e.g. `X-Panther-Webhook-Secret: <secret>`), registered once
via the API, and verified with a constant-time string comparison on every
incoming request — not a signature computed over the request body. There is
also no delivery-id/idempotency header from Channex; the payload's `revision_id`
is what we key on instead (see 2b).

### 2b. The webhook payload carries almost no data — the docs explicitly warn against trusting it
Both briefs assume the webhook payload contains guest name, dates, amounts,
etc. It doesn't. The actual payload is:
```json
{"event": "booking_new", "payload": {"booking_id": "...", "property_id": "...", "revision_id": "..."}, "user_id": null, "timestamp": "..."}
```
That's it — three IDs and a timestamp. Channex's own docs: *"Webhooks may
arrive out of order; PMS integrations should treat webhooks as triggers to
pull fresh data rather than relying on payload values."* Their recommended
pattern (from the PMS Integration Guide) is the **Booking Revision Feed**:
`GET /booking_revisions/feed` returns full booking objects for all
not-yet-acknowledged revisions; after processing each one, you
`POST /booking_revisions/:id/ack` so it won't be redelivered. This is a much
better-designed dedupe mechanism than anything we could build ourselves.

**Design consequence**: the webhook receiver's only real job is "wake up the
feed-puller sooner." The actual normalization work always happens by pulling
full booking objects from the feed — which means **the webhook path and the
cron reconciliation path become the exact same code**, satisfying "a single
well-tested service that ALL sources call" even more literally than either
brief proposed. See §6.

### 2c. Money: pull side is decimal, push side is already integer cents
- **Pulled** booking objects (`GET /bookings/:id`, the revision feed) represent
  money as **decimal strings**, e.g. `"amount": "220.00"`. We convert to cents
  (`round(amount * 100)`) when writing our tables — same as everywhere else in
  this codebase.
- **Pushed** availability/restrictions (`POST /availability`, `POST /restrictions`)
  already use **integer minimum-currency-unit** values (their example: `"rate": 30000`
  for €300.00) — no conversion needed on the push side, it already matches our
  cents convention.
- Core's `charge`/`payment` tables (via `Channex_model::insert_charges()`/
  `insert_payments()`) are `DECIMAL(10,2)` — if we ever write actual charge/payment
  rows (not required for Phase 1), that's a cents→decimal conversion boundary,
  the reverse direction from the pull side.

### 2d. Card data: confirmed nothing to build here
The booking object's `guarantee` field only ever contains masked card data
(`"card_number": "string (masked)"`) — full PAN never transits this API. Real
card retrieval/charging already happens via Channex's separate PCI vault
(`pci.channex.io`), which core already integrates with in
`cron.php::delete_channex_credit_cards()`, reading `customer_card_detail.customer_meta_data`
for the stored token. Our normalization service will not parse or store the
`guarantee` object at all — nothing to build, nothing to touch.

### 2e. Rate limits — confirmed numbers
20 ARI requests/minute total; 10 restrictions requests/minute/property; 10
availability requests/minute/property. `429 Too Many Requests` /
`"code": "http_too_many_requests"` on breach. Channex's own recommendation:
batch all pending changes for a property into one combined call every
30–60 seconds, back off 1 minute on any error. This drives the queue design
in §7.

### 2f. Base URL
Confirmed sandbox: `https://staging.channex.io/api/v1`. **Production base URL
is not stated anywhere in the docs I could fetch** — I'll need you to confirm
it from your Channex account/dashboard (commonly `https://app.channex.io/api/v1`
for this class of product, but I'm not going to guess a hostname into `.env`).

## 3. One core touch that's unavoidable (flagging per your instructions)

`permission_model.php`'s `is_public()` (line 13–65) is CI's global
auth-bypass whitelist — it already contains a whole-controller entry for
`cron` (line 23) and one dangling, dead entry for a controller that doesn't
exist: `channex_bookings::channex_get_bookings` (line 40–45, orphaned —
no such controller file exists anywhere in the repo). Our webhook endpoint
and CLI/cron-triggered sync actions are new, unauthenticated-by-necessity
routes (Channex's webhook caller and a system crontab entry aren't logged-in
PMS users) — with **no whitelist entry, they 302-redirect to the login page**
instead of returning 200/401, which would silently break webhook delivery.

**Proposed fix**: one line, additive, replacing the dead entry rather than
adding net-new risk surface:
```php
// was: $controller_name === "channex_bookings" && $function_name === 'channex_get_bookings'
$controller_name === "panther_channel"
```
This whitelists our whole extension controller the same way `cron` already is
— each individual action still does its own explicit secret check internally
(webhook: shared-secret header; CLI actions: a `CRON_AUTH_SECRET`-style query
param, reusing that exact existing env var name for consistency with
`cron.php`'s own pattern), so it's defense-in-depth, not "wide open." This is
the **only** core file this project needs to touch. Everything else —
including the two items below — stays inside `extensions/panther_channel/`.

**Deliberately NOT completing**: `cron.php::get_channex_bookings()` (line 25)
loops every company and self-curls `base_url()/cron/channex_get_bookings/{id}`
— a route implemented nowhere (confirmed by full-repo search). Completing it
would require adding a method to the real core `cron.php` file (CI/MX can't
attach a method to an existing core controller class from an extension), and
it's legacy multi-tenant scaffolding that doesn't fit a single-property
deployment anyway. I'm proposing to leave this dead code alone and give our
reconciliation job its own clean route entirely inside `panther_channel`
(§6, §9) — flag if you'd rather I wire the old hook up instead.

## 4. New tables (migration `004_panther_channel.php`)

Only what genuinely doesn't already exist:

```sql
webhook_events(
  id, event_type, channex_booking_id, revision_id,
  secret_header_valid TINYINT, processed TINYINT,
  received_at, UNIQUE KEY (channex_booking_id, revision_id)
)
```
Lightweight receipt log — real dedupe authority is Channex's own feed/ack
system (§2b) plus `get_related_pms_booking_ids()`; this table exists so a
webhook redelivery doesn't re-trigger a redundant feed-pull, and so the admin
UI has something to show ("last webhook received at...").

```sql
channel_reservations(
  id, local_booking_id, channex_booking_id, ota_reservation_code,
  channel_code, status, guest_name, guest_email_alias,
  occupancy_json, arrival_date, departure_date,
  gross_amount_cents, payout_cents, currency,
  raw_payload_json, received_at, updated_at
)
```
The rich record your spec wants (`ota_bookings` is missing guest/amount/status
entirely). **We also write a companion row to the existing `ota_bookings`**
(same `pms_booking_id`/`ota_booking_id`) alongside this, purely so
`get_related_pms_booking_ids()` keeps working as the shared dedupe check and
any future core code that scans `ota_bookings` still sees our data — belt and
suspenders, not a redesign of that table.

```sql
channel_push_queue(
  id, room_id, date_from, date_to, reason,
  status[pending|sent|failed], attempts, last_error,
  scheduled_at, sent_at, created_at
)
```
Not in either brief's table list explicitly, but required by "queued,
throttled jobs" (Phase 2) — same pattern as `cleaning_requests` being an
addition beyond your original Sea Panther Reservas table list last time.
One row per (room, dirty date range); the throttled worker (§7) coalesces
overlapping pending rows per room before each batched push.

```sql
channel_sync_log(
  id, direction[in|out], operation, status, error,
  payload_json, created_at
)
```
Purpose-built rather than reusing `ota_xml_logs`/`save_logs()`: that table's
retention job (`menu.php::automatic_alert()` → `delete_ota_xml_logs()`) already
has undocumented-elsewhere assumptions baked in about its `request_type` codes
(15-day retention for code `2`, 3 days for everything else) tuned for
whatever legacy caller originally used it — mixing our new traffic into it
risks interacting with that job in ways I can't fully verify from the code
alone. A dedicated table with clear columns also gives the admin "error feed"
(per your Admin UI spec) a much better shape to render than generic
xml_in/xml_out blobs.

Nothing here alters `booking`, `booking_block`, `room`, `customer`, or any
other core table.

## 5. Configuration

Per instruction, secrets ONLY in `.env`, never DB, never logs:
```
CHANNEX_API_KEY=
CHANNEX_PROPERTY_ID=
CHANNEX_BASE_URL=https://staging.channex.io/api/v1   # switch to production once you confirm the host (§2f)
CHANNEX_WEBHOOK_SECRET=                                # our own chosen value, registered as a custom header
```
Single-property deployment (per your "3 fixed cabins" reality) means no
per-company secret storage table is needed — `ota_x_company`/`ota_properties`
hold the non-secret property/mapping metadata (§1), `.env` holds the one
account's credentials. `channel_sync_log`/`webhook_events` payload columns
will have card/API-key-shaped fields stripped before storage (defense in
depth beyond "Channex already doesn't send us that data," §2d).

## 6. Normalization service — single entry point

`Channel_normalizer` (in `panther_channel`, extends nothing, plain service
class instantiated by both the webhook controller and the cron controller):

```
normalize($channex_booking_object, $trigger_source)
```//
1. Look up `get_related_pms_booking_ids($booking['id'])` → existing local
   booking, or none.
2. Resolve room: `get_osgrandhorizon_room_type_id($room['room_type_id'], ...)`
   per room in `booking.rooms[]` (our 3 cabins mean this is almost always
   exactly one room per booking, but the schema supports more).
3. Branch on `status` (`new` / `modified` / `cancelled` — confirmed exact
   values from the docs, not `NEW`/`MODIFICATION`/`CANCELLATION` as either
   brief assumed):
   - **new**: no existing local booking → create `booking` + `booking_block`
     row(s), `source` = `SOURCE_BOOKING_DOT_COM`/`SOURCE_AIRBNB` (resolved
     from `ota_name`, not left as generic `SOURCE_CHANNEX` — this is what
     makes the grid render "Guest (Airbnb)" correctly), state = `RESERVATION`.
   - **modified**: existing local booking found → update dates/guest/amount
     in place. **Conflict check required by your "never silently overwrite a
     manual local block" instruction**: if the existing local booking's
     `source` is NOT already an OTA source (i.e. it's a manual/direct
     booking that happens to collide), do NOT overwrite — write an
     `audit_log` entry `channel_conflict_detected` and leave it for manual
     review, per your explicit requirement.
   - **cancelled**: existing local booking found → our own minimal
     `UPDATE booking SET state = CANCELLED` (not the buggy shared method,
     §1) + audit entry; never delete the row.
4. Turnover: if the resolved room already has an adjacent stay ending the
   same day the new stay begins, this is the grid's "Check-Out & Check-In"
   state (`panther_grid`'s existing turnover detection, reused as-is) — and
   we insert a `cleaning_requests` row (existing `panther_housekeeping`
   table) using Channex/Airbnb's turnover-time field as the buffer, per your
   requirement. *(Need to confirm exact field name for Airbnb prep-time —
   not surfaced in the booking object schema I could fetch; likely lives on
   the room-type/channel-mapping config rather than per-booking. Flagging as
   a Phase-1 implementation detail to verify against sandbox data rather
   than guessing the field name here.)*
5. Write `channel_reservations` + companion `ota_bookings` row (§4).
6. Write `audit_log` (existing `panther_audit_log` model, reused as-is):
   type = `channel_booking_new`/`channel_booking_modified`/`channel_booking_cancelled`,
   room = resolved room name, user = null (renders as "system" per existing
   `Audit_log` view fallback), message includes channel + external id.
7. Flip Live Room Status: no code needed — `panther_room_status` already
   derives occupancy live from `booking`/`booking_block`, so writing step 3
   correctly is sufficient.
8. Enqueue `channel_push_queue` row for the affected room/date range (Phase 2
   picks this up asynchronously — normalization never pushes synchronously).

## 7. Availability push-back (Phase 2)

- Every local mutation that can affect saleable nights — normalizer step 8
  above, plus a hook into `panther_grid`'s existing check-in-time/room-assign/
  cleaning-toggle actions, plus manual booking edits in core — inserts a
  `channel_push_queue` row instead of calling the Channex API inline.
- A single cron-triggered worker (`panther_channel/cron_push_queue`, secret-gated
  per §3) runs on a short interval (e.g. every minute via crontab), and per
  run: coalesces pending queue rows by room into the minimal set of date
  ranges, sends **at most one `/availability` call and one `/restrictions`
  call per property per invocation** (well inside the 10/min/property limits,
  respecting Channex's "batch into one call every 30-60s" guidance), marks
  rows `sent` or `failed`+`last_error`, and on any error backs the whole
  property off for 1 minute (matches Channex's own recommendation) — surfaced
  as an alert in the Channels admin page (a failed push is a double-booking
  risk, per your instruction).
- Rolling ~365-day horizon: on each full-sync/first-connect, we push the
  entire year; incremental pushes after that only touch the specific
  affected date ranges from the queue.

## 8. Admin UI — "Channels" page

New nav entry via `panther_shell` (same pattern as the other 7 panther_*
pages): connection status card (reads `ota_x_company.is_active` +
`ota_properties.channex_property_data` for property name/currency/timezone),
per-cabin mapping table (`ota_room_types`/`ota_rate_plans` joined to `room`,
editable inline), last-webhook/last-sync timestamps (`webhook_events`,
`channel_sync_log`), an error feed (`channel_sync_log` where status=failed),
and buttons: **Test Connection** (GET property by id), **Pull Future Bookings**
(one-shot feed pull + ack), **Full Sync** (push queue seeded for the full
365-day horizon across all 3 cabins).

## 9. CLI/cron actions

All under `panther_channel`, secret-gated like `cron.php`'s existing pattern:
- `panther_channel/cron_test_credentials` — GET property, confirms API key + property id valid
- `panther_channel/cron_sync_rooms` / `cron_sync_prices` — one-shot push of room/rate config
- `panther_channel/cron_sync_availability` — seeds `channel_push_queue` for full horizon
- `panther_channel/cron_push_queue` — the throttled worker (§7), meant to run every minute
- `panther_channel/cron_reconcile` — pulls `booking_revisions/feed`, runs each through
  `Channel_normalizer` (§6), acks each — this is both "first-connect backfill" and
  "ongoing reconciliation," since it's the exact same call either way; a missed
  webhook is caught the next time this runs regardless
- `panther_channel/webhook` — the receiver (§2a/2b): validates the shared-secret
  header, writes a `webhook_events` row, returns 200 immediately, and triggers
  `cron_reconcile` (fire-and-forget — if that fails for any reason, the next
  scheduled `cron_reconcile` run catches it regardless, so there's no scenario
  where a dropped trigger causes permanent data loss)

## 10. Test plan

- Shared-secret header: valid / invalid / missing → 200 / 401 / 401
- Idempotency: same `booking_revisions/feed` entry processed twice (simulated
  by not acking) → one `channel_reservations` row, one `booking` row
- Normalization fixtures: hand-built `new`/`modified`/`cancelled` JSON payloads
  shaped exactly like the schema in §2b/Bookings API, for both a
  `Booking.com` and `Airbnb` `ota_name` → assert correct `booking`/`booking_block`
  rows, correct `source` constant, correct grid turnover state, correct
  `cleaning_requests` row, correct `audit_log` entry
- Manual-block conflict: seed a manual (non-OTA) booking, ingest a colliding
  `modified` event → assert NO overwrite, assert `channel_conflict_detected`
  audit entry
- Push-back: ingest a new OTA booking → assert a `channel_push_queue` row
  exists for the right room/date range (not that it was actually sent — that's
  mocked/stubbed against the real Channex client in these tests)
- Reconciliation drift repair: manually delete a `channel_reservations` row
  while `ota_bookings`/`booking` still reference it (simulated drift) →
  assert `cron_reconcile` detects and repairs it against a stubbed feed response

## 11. Open questions (please confirm before I build)

1. **Production Channex base URL** (§2f) — I couldn't find it documented;
   need it from your account, or confirm building against staging only until
   you provide it.
2. **Airbnb preparation-time field name** (§6 step 4) — not present in the
   booking object schema I could fetch from the docs; I'll need to inspect
   real sandbox payloads (or the Airbnb channel-mapping guide specifically)
   once we're building, rather than guess the field name now. OK to treat as
   a Phase-1 implementation detail rather than a blocking question?
3. **Old dangling `cron/get_channex_bookings` hook** (§3) — leave dead and
   build `panther_channel`'s own reconciliation route (recommended), or do
   you want that legacy self-curl loop actually completed too?
4. **`ota_bookings` companion-row writing** (§4) — confirm the "write both
   `channel_reservations` (rich) and a companion `ota_bookings` row (thin,
   for compatibility)" approach, versus using `channel_reservations` alone
   and accepting that `get_related_pms_booking_ids()` would need to be
   swapped for our own equivalent lookup instead.
5. **Webhook trigger vs. always-poll** (§2b/§9) — confirm the "webhook is
   just a low-latency trigger, `cron_reconcile` via the revision feed is the
   only place normalization actually runs" design, rather than trying to
   normalize directly from the (data-sparse) webhook payload.

I'll pause here for your sign-off on §11 before writing any extension code.
