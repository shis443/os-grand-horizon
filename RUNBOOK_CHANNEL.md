# RUNBOOK_CHANNEL.md — panther_channel (Channex OTA ingestion)

Companion to PLAN_CHANNEL.md (architecture/design) and SETUP.md (base app
setup). This covers connecting a real channel, mapping the 3 cabins, running
the test suite, and triaging a failed sync.

## Architecture in one paragraph

Channex has no webhook signature — the shared secret is a custom header we
register ourselves. The webhook payload only ever carries IDs, never guest
data, so its only job is to trigger a pull from Channex's
`booking_revisions/feed`, which returns full booking objects and is the same
call our scheduled reconciliation cron makes — meaning the webhook path and
the reconciliation path run through the exact same code
(`Channel_normalizer::normalize()`). Availability changes are queued
(`channel_push_queue`) and drained by a throttled worker rather than pushed
inline, so a slow/rate-limited Channex response never blocks a booking from
being ingested.

## One-time setup: connecting a channel

1. **Get your Channex API key + property ID** from your Channex dashboard
   (staging: `https://staging.channex.io`).
2. Fill in `.env`:
   ```
   CHANNEX_BASE_URL=https://staging.channex.io/api/v1   # switch to production once you confirm that host — see PLAN_CHANNEL.md §2f
   CHANNEX_API_KEY=<your real key>
   CHANNEX_PROPERTY_ID=<your real property id>
   CHANNEX_WEBHOOK_SECRET=<generate a random string, e.g. openssl rand -hex 32>
   CRON_AUTH_SECRET=<generate a random string>
   ```
   Restart the `php` container after editing `.env` — PHP-FPM workers cache
   env vars for their process lifetime (`docker-compose restart php web`).
3. Log in, go to **Channels** in the sidebar, click **Test Connection**. This
   fetches your property from Channex and stores the connection in the
   existing `ota_manager`/`ota_properties`/`ota_x_company` tables (the same
   ones `channex_model.php` already reads — see PLAN_CHANNEL.md §1). No
   secrets are ever written to the database, only to `.env`.
4. **Register the webhook** (one-time, via Channex's API or dashboard —
   `panther_channel` doesn't do this for you, since it's a one-time setup
   action, not an ongoing sync operation):
   ```
   POST https://staging.channex.io/api/v1/webhooks
   Header: user-api-key: <your key>
   Body:
   {
     "webhook": {
       "callback_url": "https://<your-public-host>/panther_channel_public/webhook",
       "event_mask": "booking_new,booking_modification,booking_cancellation",
       "property_id": "<your property id>",
       "is_global": false,
       "headers": { "X-Panther-Webhook-Secret": "<same value as CHANNEX_WEBHOOK_SECRET in .env>" },
       "is_active": true,
       "send_data": true
     }
   }
   ```
   Note the URL is `panther_channel_public/webhook`, not `panther_channel/webhook`
   — see "Why two controllers?" below.

## Mapping the 3 cabins

On the Channels page, under **Cabin Mapping**, enter the Channex
`room_type_id` (and optionally `rate_plan_id`) for each cabin — find these in
your Channex dashboard under Room Types / Rate Plans. Sunset is its own room
type (1:1); Sunrise1 and Sunrise2 share a room type in Channex's model
(a pooled "Double Room" type) — when a booking arrives for that type,
`panther_channel` picks whichever of the two is actually free for the
requested dates (see PLAN_CHANNEL.md §6 "room-type pooling"). If both are
booked for the same dates, that's a genuine conflict — it gets logged as
`channel_conflict_detected` in the Audit Log rather than silently failing.

## Ongoing sync — cron entries

Add to your system crontab (adjust the path/port to your actual deployment):

```cron
# Drain the throttled availability push queue — keep this frequent (rate limits allow it)
* * * * * curl -s "http://localhost:8080/public/panther_channel_public/cron_push_queue?secret=$CRON_AUTH_SECRET" >/dev/null

# Reconciliation — catches anything a missed/failed webhook dropped
*/15 * * * * curl -s "http://localhost:8080/public/panther_channel_public/cron_reconcile?secret=$CRON_AUTH_SECRET" >/dev/null

# Nightly full-horizon availability re-sync (belt and suspenders against drift)
0 3 * * * curl -s "http://localhost:8080/public/panther_channel_public/cron_sync_availability?secret=$CRON_AUTH_SECRET" >/dev/null
```

`cron_test_credentials`, `cron_sync_rooms`, and `cron_sync_prices` exist as
named entry points per the original spec but `sync_rooms`/`sync_prices` are
currently no-op stubs (there's no PMS-side room-config or rate source wired
up yet to push *from* — see the controller's inline comments). The
availability push path is fully built end-to-end.

## Why two controllers? (`panther_channel` vs `panther_channel_public`)

Discovered during implementation, not anticipated in PLAN_CHANNEL.md: this
app's `MY_Controller.php` has a second access gate beyond the
`permission_model.php` whitelist — it `show_404()`s any HMVC-extension route
whenever there's no logged-in session, regardless of whitelisting. That gate
only fires for MX-routed extension controllers, never for plain core
controllers (exactly how `cron.php` already works). So:

- `panther_channel` (the extension, `extensions/panther_channel/controllers/`) —
  the admin UI, requires login, same as every other panther_* page.
- `panther_channel_public` (a **core** controller,
  `application/controllers/panther_channel_public.php`) — the webhook
  receiver and `cron_*` actions, reachable with no session. This is the
  second (previously unflagged) core touch beyond the
  `permission_model.php` whitelist entry — both are additive-only, no
  existing core file's behavior changed, and both are documented in
  PLAN_CHANNEL.md §3 / this file.

All the actual logic (`Channex_client`, `Channel_normalizer`, the
`channel_*` models) still lives entirely inside the extension — the core
controller is a thin dispatcher, same pattern as `Panther_controller.php`.

## Running the test suite

No PHPUnit in this codebase (there's no test framework anywhere in the
existing app) — tests run as a CLI-only core controller against the real
local DB, exercising the actual `Channel_normalizer` with fixture JSON that
matches Channex's confirmed schema (`extensions/panther_channel/tests/fixtures/`):

```bash
docker exec -w /app/public docker-php-1 php index.php panther_channel_tests run
```

25 assertions, safely re-runnable (cleans up its own fixture rows first).
Covers: new/modified/cancelled normalization for both Booking.com and
Airbnb, idempotent redelivery, same-day turnover → cleaning_requests,
manual-booking conflict detection, decimal→cents money conversion, and
push-queue enqueueing. Signature validation (valid/invalid/missing) and
webhook idempotency were verified directly against the running webhook
endpoint with curl during development — see PLAN_CHANNEL.md §10 for the
full test plan and what's covered vs. what needs live Channex sandbox
credentials to exercise for real (the actual outbound push/pull HTTP calls).

## Triaging a failed sync

1. **Channels page → Error Feed** — every failed API call (push or pull) is
   logged here with the raw Channex error message (`channel_sync_log`).
2. **A failed availability push is a double-booking risk** — these also
   write a `channel_push_failed` entry to the Audit Log so it's visible
   outside the Channels page too. The underlying `channel_push_queue` row
   stays `failed` with `attempts`/`last_error` populated; the next
   `cron_push_queue` run does not automatically retry a `failed` row today
   (it only drains `pending` ones) — re-running "Full Sync" from the admin
   UI is the current manual recovery path. *(Automatic backoff/retry for
   already-failed queue rows is a reasonable Phase 2.1 addition, not yet
   built.)*
3. **`429` rate-limit errors** — the client surfaces these distinctly
   (`"Rate limited (429) by Channex"`); if you see repeated 429s, check
   whether `cron_push_queue` is running more often than once/minute, or
   whether multiple crontab entries are overlapping.
4. **Unmapped room** — if Channex sends a booking for a `room_type_id` that
   isn't in your Cabin Mapping yet, it's logged as `channel_unmapped_room`
   in the Audit Log and otherwise ignored (not created locally). Map the
   room type and re-run **Pull Future Bookings**.
5. **Conflict (`channel_conflict_detected`)** — either a colliding
   modification against a non-channel-sourced local booking, or no free
   room of the mapped type for the requested dates. Both need a human to
   look at the Audit Log entry and resolve manually — by design, nothing
   here auto-overwrites.

## Known gaps / deliberately out of scope for this pass

- `sync_rooms`/`sync_prices` cron actions are stubs (see above).
- Automatic retry/backoff for already-`failed` push-queue rows.
- Production Channex base URL unconfirmed from docs (PLAN_CHANNEL.md §2f) —
  currently pointed at staging.
- Airbnb "preparation time" (turnover buffer) field name wasn't confirmed
  from the docs I could fetch — same-day turnover detection works (tested),
  but it uses the checkout date directly rather than a configurable buffer
  read from Channex. Revisit once real Airbnb sandbox payloads are available.
