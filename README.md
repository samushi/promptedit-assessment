# PromptEdit × Pixelforge: image generation with exact billing

PromptEdit's Senior Product Engineer take-home. Signed-in users describe an
image, see an estimated price **before** submitting, and receive the result
from **Pixelforge**, an asynchronous image-generation API. Every successful
generation is billed exactly once, at Pixelforge's cost plus a 25% markup.
Failed generations cost nothing.

- **Live demo:** _URL to be added once the deployment is confirmed to be publicly reachable._
- **Stack:** Laravel 13 (PHP 8.4) · Inertia 3 · Vue 3 + TypeScript · Tailwind CSS 4 · Vite · PostgreSQL 17 · Pest. Built on the official Laravel Vue starter kit (Fortify authentication, Wayfinder).

**Contents:** [Running it locally](#running-it-locally-docker) ·
[Tests](#tests) · [Architecture](#architecture) · [Billing](#billing) ·
[Pixelforge integration](#pixelforge-integration) ·
[Admin report](#admin-report) · [Security](#security) ·
[Deployment](#deployment) · [Verification](#verification) ·
[Trade-offs](#trade-offs-and-next-steps)

## Requirements

| Requirement                                                                                                                                                                     | Status                                                                       |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------- |
| **Generate**: prompt, size, quality and up to 4 reference-image URLs, with the price shown before submitting                                                                    | ✅ [Estimates](#estimates)                                                   |
| **Pricing**: Pixelforge cost + 25%. The up-front price is labelled as an estimate, the actual cost comes from usage at the generation's own rate snapshot, and failures cost $0 | ✅ [Billing](#billing)                                                       |
| **History**: users see only their own generations, with live status, estimated and actual cost, and the image (marked once its link expires)                                    | ✅ [Lifecycle](#lifecycle-of-a-generation)                                   |
| **Webhooks**: signature verified on the raw body, deduplicated, safe when duplicated or out of order, never billed twice                                                        | ✅ [Webhooks](#webhooks)                                                     |
| **Deployment**                                                                                                                                                                  | Laravel Cloud, see [Deployment](#deployment). Public URL pending (see above) |
| Stretch: **admin report** per UTC day and user, reconciled with `GET /v1/usage`                                                                                                 | ✅ [Admin report](#admin-report)                                             |
| Stretch: **credit balances** with "Buy credits"; unaffordable generations are blocked                                                                                           | ✅ [Credit balances](#credit-balances)                                       |

## Running it locally (Docker)

Only Docker (with the Compose plugin) is needed on the host. `compose.yaml`
defines the development stack:

| Service        | Role                                                                    |
| -------------- | ----------------------------------------------------------------------- |
| `laravel.test` | the app (`php artisan serve` on PHP 8.4), plus Node for building assets |
| `pgsql`        | PostgreSQL 17, with a separate `promptedit_test` database for the tests |
| `redis`        | queue and cache                                                         |
| `horizon`      | queue worker (dashboard at `/horizon`, operators only)                  |
| `scheduler`    | runs the `pixelforge:reconcile` sweep every minute                      |
| `tunnel`       | optional (`--profile tunnel`): a Cloudflare quick tunnel for webhooks   |

```bash
cp .env.example .env    # then set WWWUSER and WWWGROUP (see Docker-only settings)
docker compose build
docker compose run --rm laravel.test composer install
docker compose run --rm laravel.test php artisan key:generate
docker compose run --rm laravel.test php artisan migrate
docker compose run --rm laravel.test npm ci
docker compose run --rm laravel.test npm run build
docker compose up -d
```

Open http://localhost:8000, register, top up with **Buy credits** (test mode,
no payment) and generate an image.

### Pixelforge credentials and webhooks

Add a Pixelforge API key and its webhook signing secret to `.env`. Never
commit real values.

```dotenv
PIXELFORGE_API_KEY=            # from the Pixelforge dashboard
PIXELFORGE_WEBHOOK_SECRET=     # the whsec_… signing secret of the same key
```

Use a separate key, with its own webhook URL and secret, for each environment.
Each environment then receives only its own events, and `/v1/usage` matches
its own records, which the admin report relies on.

Webhooks need a public URL. The optional tunnel provides one: set
`https://<tunnel-host>/webhooks/pixelforge` as the key's webhook URL.

```bash
docker compose --profile tunnel up -d tunnel
docker compose logs tunnel | grep trycloudflare.com
```

The tunnel exposes the app to the internet, so set `APP_DEBUG=false` (and
restart the containers) while it runs. Without webhooks everything still
works, only more slowly: recovery
checks ask Pixelforge for the status instead (see
[Recovery](#recovery-when-webhooks-or-jobs-are-lost)).

### Day to day

```bash
docker compose exec -u sail laravel.test php artisan users:make-admin you@example.com  # operator: /admin/report, /horizon
docker compose exec -u sail laravel.test npm run dev     # optional: Vite dev server with hot reload
docker compose restart horizon scheduler                 # after changing PHP code or .env
```

`-u sail` runs a command as the container's application user (your
`WWWUSER`), so files it creates in the mounted project belong to you, not
root. Horizon and the scheduler are long-running, so they only pick up code
or `.env` changes after a restart. Stop the Vite dev server with Ctrl+C; if
pages then stay blank, delete the leftover `public/hot` file.

### Docker-only settings

The frontend is built with Vite: `npm run build` writes the production assets
to `public/build`, and `npm run dev` serves them with hot reload during
development. The variables below only configure the local Docker stack. The
application doesn't read them, and production doesn't use them.

| Variable             | Default | Used for                                                                                     |
| -------------------- | ------- | -------------------------------------------------------------------------------------------- |
| `APP_PORT`           | `8000`  | host port of the app                                                                         |
| `FORWARD_DB_PORT`    | `54329` | host port of PostgreSQL, e.g. for a database client                                          |
| `FORWARD_REDIS_PORT` | `63790` | host port of Redis                                                                           |
| `VITE_PORT`          | `5174`  | port of the Vite dev server, published by Compose                                            |
| `WWWUSER`            | `1000`  | user id the containers run as: set it to the output of `id -u`                               |
| `WWWGROUP`           | `1000`  | group id built into the image: set it to the output of `id -g` before `docker compose build` |

The default ports are chosen not to clash with other local projects; change
them if they do.

## Tests

```bash
docker compose exec -u sail laravel.test composer ci:check   # format + lint, vue-tsc, Pint, PHPStan (level 7), Pest
docker compose exec -u sail laravel.test php artisan test    # Pest only
```

The suite runs against the `promptedit_test` PostgreSQL database, never
SQLite, because money relies on `NUMERIC` columns, unique keys and row locks.
`Http::preventStrayRequests()` keeps every test away from the real API. GitHub
Actions runs the same `composer ci:check` on pushes to `main` and on pull
requests, with PostgreSQL as a service container. Coverage is summarised under
[Verification](#verification).

## Architecture

A modular monolith organised by business capability, with three PSR-4 roots:

- `app/App`: only the service providers (App, Fortify, Horizon).
- `app/Support`: shared building blocks, including `ActionFactory`
  (`XAction::run(...)` resolves an action from the container and calls
  `handle()`), request DTOs (`DataObjects`), a repository base, `Money` and
  `MoneyCast`, the Inertia controller base and the shared middleware.
- `app/Domain`: one folder per capability. Each domain registers its own
  routes, commands, schedule and bindings through its service provider, so
  `bootstrap/app.php` wires no domain by hand.

| Domain       | Responsibility                                                                                                                                       |
| ------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Generation` | request → Pixelforge → status → exact price: the actions, the Pixelforge client, pricing and webhook verifier, the queued jobs and the webhook inbox |
| `Billing`    | the append-only credit ledger and credit purchases                                                                                                   |
| `Reports`    | the operator report, reconciled with `GET /v1/usage`                                                                                                 |
| `User`       | accounts and settings (Fortify, two-factor, passkeys) and `users:make-admin`                                                                         |

A thin controller validates with a `FormRequest`, builds a DTO and calls an
action, for example `CreateGenerationAction::run($user, $dto)`. Actions hold
the business logic, repositories hold every query, services wrap Pixelforge
and the pricing maths, and jobs only do queue mechanics.
`ApplyProviderSnapshotAction` is the only place where a generation's status
advances and money settles: the POST response, webhooks and status checks all
go through it. Each domain's `web.php` serves Inertia pages (session and
CSRF). The webhook lives in `app/Domain/Generation/api.php`, with no session
or CSRF; its signature middleware authenticates it.

**Frontend.** Vue 3 + TypeScript pages rendered through Inertia (no separate
JSON API), styled with Tailwind CSS 4 and bundled by Vite (`vp`, the Vite+
toolchain the starter kit uses). Wayfinder generates typed route helpers. The
main screens are `resources/js/pages/generations/Index.vue` (form and live
history) and `resources/js/pages/admin/Report.vue`.
`resources/js/lib/money.ts` formats decimal strings with BigInt, never floats.

### Lifecycle of a generation

```
Browser ── POST /generations ──► StoreGenerationRequest (validates like Pixelforge) → CreateGenerationDto
                                   └► CreateGenerationAction, in one transaction:
                                        lock user row · server-side quote · save row (pending)
                                        with Idempotency-Key and the exact request body ·
                                        hold credits · queue SubmitGenerationJob after commit
Queue ── SubmitGenerationJob → SubmitGenerationAction ── POST /v1/generations (same key + body every attempt)
   ├ 202 ............................ ApplyProviderSnapshotAction (usually → queued)
   ├ 400/401/409/422/429 daily ...... RejectGenerationAction ($0, reason shown)
   ├ 429 rate/concurrency, 503 ...... job released for Retry-After / backoff, same key
   └ timeout, bare 5xx .............. retry, same key; never assumed "not created"

Pixelforge ── POST /webhooks/pixelforge ──► VerifyPixelforgeSignature (raw-body HMAC)
                                              → RecordWebhookEventAction: insert-or-ignore(event id) → 204
                                              └► queue: ProcessPixelforgeEventJob → ApplyProviderSnapshotAction

Recovery ── SyncGenerationJob → RecoverGenerationAction, queued by: follow-ups 2, 5 and 10 min after
            acceptance · the history page for rows that look stuck · (locally) the every-minute sweep
   ├ accepted, quiet ................ GET /v1/generations/{id} (authoritative) → ApplyProviderSnapshotAction
   └ pending, quiet ................. resubmit with the same key (closed at $0 after 24 h)

Browser ◄── reloads history + balance every 3 s while anything is in flight and the tab is visible
            (Inertia partial reload; pauses after 15 minutes without changes)
```

## Billing

```
provider_cost = Σ over the 4 token types: tokens × rate_per_1M / 1,000,000    (rates = the generation's own snapshot)
customer_cost = provider_cost × 1.25        profit = customer_cost − provider_cost        failed / rejected = 0
```

- **Exact decimals end to end.** `brick/math` `BigDecimal` in PHP,
  `NUMERIC(24,12)` in PostgreSQL, decimal **strings** in JSON and Inertia
  props, and BigInt string maths in the UI. `MoneyCast` runs under
  `strict_types`, so assigning a float is a `TypeError`.
- **No silent rounding.** Storage uses `toScale(12, RoundingMode::Unnecessary)`,
  which throws rather than drop a digit; Pixelforge's 9-decimal costs × 1.25
  need at most 11. Charges are exact sub-cent amounts, shown in full (for
  example $0.3818875): rounding up to a cent would overcharge, and rounding to
  the nearest cent would break "exactly cost + 25%". Only estimates are
  rounded for display.
- **Markup, not margin.** The user pays cost × 1.25, so profit is 20% of the
  price.
- **Rate snapshots.** Each token type is billed at its own rate, from the
  `rates` snapshot Pixelforge returned for that generation (stored once, never
  overwritten), never at today's `/v1/pricing`. `total_tokens` is only a
  consistency check.
- **Golden example (unit-tested).** 12 / 765 / 6,450 / 3,184 tokens at
  5.00 / 10.00 / 40.00 / 12.50 per 1M give **0.305510000** to Pixelforge,
  **0.381887500** to the user and **0.076377500** profit.

### Estimates

Estimates are computed **on the server**. The form calls
`POST /generations/estimate` (debounced; stale responses are discarded), and
submitting re-quotes independently. The browser never computes a price, and a
price sent by the client is rejected as an unknown field.

| Token type  | Estimate                                                        | Range shown                  |
| ----------- | --------------------------------------------------------------- | ---------------------------- |
| Input text  | `ceil(characters / 4)`, counting Unicode characters (not bytes) | ±15%, upper bound rounded up |
| Input image | 765 per reference URL (exact)                                   | exact                        |
| Output      | by quality × size, from the pricing table (exact)               | exact                        |
| Reasoning   | **midpoint** of the quality's range (an assumption)             | the range's min–max          |

Pricing comes from `GET /v1/pricing`, validated against the documented shape
and cached for 10 minutes. If Pixelforge is unreachable, the last good copy is
used for up to 24 hours; with none, the form says so and generating is
disabled, because the app never invents a price. A credit hold reserves the
**maximum**: for the docs' worked example, $0.373875 from Pixelforge and
$0.46734375 to the user.

### Credit balances

- Credits are USD in an **append-only ledger** (`credit_transactions`:
  purchase, hold, release, charge). A balance is the sum of the user's rows,
  so there is no stored balance to drift.
- **Buy credits** adds a fixed package ($5, $10 or $25): a simulated purchase,
  idempotent per click through a unique reference.
- **Reservation.** The true price is only known at the end, so submitting
  **holds the most the generation can cost** (prompt text +15%, maximum
  reasoning tokens). If the balance can't cover it, the request is refused
  with a clear message, in the UI and on the server. The check runs under the
  user's row lock, so concurrent submissions can't overspend.
- **Settlement** happens in the same transaction as the final status: the
  hold is released and, on success, the exact price is charged. Unique keys
  make a double hold, release or charge impossible.

## Pixelforge integration

### Submitting: idempotency and retries

- **The form is idempotent.** Each submission carries a `client_request_id`
  (`UNIQUE(user_id, client_request_id)`), so a double click or a resent
  request creates a single generation.
- **Pixelforge requests are idempotent.** The row, its `idempotency_key` and
  the exact JSON body are saved before the first POST and replayed verbatim on
  every attempt. A timeout can't create a second generation, and a retry can't
  become a "different request" (`409`).
- **Branching follows Pixelforge's documented `error.code`**, not just the
  HTTP status:

| Pixelforge answer                                             | What happens                                                                                                                                                                                                                            |
| ------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `202`, new or `Idempotent-Replayed`                           | Snapshot applied through the same path as webhooks (a replay may already be final)                                                                                                                                                      |
| `400 content_policy_violation`, `422 validation_failed`       | Rejected at $0 with the reason shown; no retry                                                                                                                                                                                          |
| `401 invalid_api_key`, `409 idempotency_key_reused`           | Rejected at $0 and logged as critical (our configuration or a bug); no retry                                                                                                                                                            |
| `429 daily_limit_exceeded`                                    | Rejected at $0 ("resets at midnight UTC"); no retry                                                                                                                                                                                     |
| `429 concurrency_limit_exceeded`                              | Waits up to **10 minutes** for a free slot, retrying after max(Retry-After, backoff). Shown as "Waiting for a free Pixelforge slot" and not counted as a failure                                                                        |
| `429 rate_limit_exceeded`, `503 overloaded`                   | Up to 6 failures, each retried after max(Retry-After, 2ⁿ s) with the backoff (never Retry-After) capped at 60 s; then rejected at $0, since Pixelforge created nothing                                                                  |
| Timeout, connection error, 5xx without a code, unreadable 2xx | Retried the same way but **never rejected**, because Pixelforge may have created it. The row stays pending (flagged `submission_ambiguous`) and recovery resends it with the same key; it is closed as unconfirmed ($0) only after 24 h |

All calls share the account's **60 requests/minute**. Each client call is a
single request (no HTTP retries stacked on job retries), status checks touch
only quiet in-flight generations in small batches, and a `429` during a check
skips it. Queue delays are capped at 15 minutes: a `Retry-After: 3600` waits
900 s, then asks again.

### Webhooks

1. **Verify first.** `VerifyPixelforgeSignature` checks the
   `Pixelforge-Signature` header (`t=…,v1=…`) as
   HMAC-SHA256(`"{t}.{raw body}"`, signing secret) with `hash_equals`, within
   ±300 s, **before** any JSON decoding. Several `v1` values are accepted, for
   secret rotation. A bad signature gets `400`. A missing secret is a `500`,
   so Pixelforge keeps retrying until it's configured.
2. **Store once.** Events are deduplicated on the **signed** body's `id` (not
   the unsigned `Pixelforge-Event-Id` header) with a `UNIQUE` index and
   `INSERT … ON CONFLICT DO NOTHING`. The inbox row is committed before the
   `204` and processed by a job queued after the commit. A redelivery gets
   `204` and changes nothing; `ping` and unknown types are stored and closed.
3. **Apply by status, not arrival order.** Every POST carries
   `metadata.generation_id`, so even a webhook that beats the POST response
   finds its row. An event for a row already linked to another Pixelforge id
   is refused and logged. `GenerationStatus::canAdvanceTo()` only moves status
   forward (pending < queued < processing < succeeded | failed). Final states
   never change, and a contradictory final event is logged and ignored.
   `rejected` is only our inference, so Pixelforge's evidence may override it.
4. **Bill exactly once.** Money settles only on the transition into a final
   status, inside the transaction that holds the generation's row lock.
   Racing webhooks, duplicates, status checks and replayed POSTs are
   serialised: whichever comes second finds a final status and changes
   nothing. The ledger's `UNIQUE(generation_id, type)` backs this up.

### Recovery when webhooks or jobs are lost

Webhooks are the fast path. Recovery needs no recurring scheduler, so an idle
deployment can scale to zero. Each check runs `RecoverGenerationAction`:

- An **accepted** generation that has gone quiet is fetched with
  `GET /v1/generations/{id}` (authoritative) and applied through the same
  snapshot path, so it can't bill twice.
- A **pending** one with no submission activity for 3 minutes is resubmitted
  with the same key; a live submission job holds a unique lock, so this never
  doubles it. If Pixelforge hasn't confirmed it within 24 hours, it is closed
  at $0.

Checks are queued by:

- **Follow-ups**, 2, 5 and 10 minutes after Pixelforge accepts a generation
  (however its id is first seen: POST response, webhook or `GET`). They stop
  once it is final, and every delay fits a managed queue's 15-minute limit.
- **Read repair.** Rendering the history queues a check for rows that look
  stuck: at most once per 2 minutes per generation (claimed atomically, so
  polling tabs can't pile up) and at most 3 per page view, since every check
  shares the 60 requests/minute.
- **The sweep** (`pixelforge:reconcile`): every minute locally, off in
  production unless `PIXELFORGE_RECONCILE_SCHEDULE` sets a cron. It also
  re-runs webhook events still unprocessed after 2 minutes, up to 10 times.

### Images

Pixelforge's signed URL is stored with its expiry. Once it expires, the card
says "Image link expired" instead of showing a broken image, and an `onerror`
fallback covers other load failures. Long-term image storage is a next step.

## Admin report

`/admin/report` (operators only) shows, for each **UTC day of completion**
(the day Pixelforge bills, not the submission day) and each user, the billed
generations, what users were charged, Pixelforge's cost and the profit, plus
per-user totals. Every day, and the total, is compared with `GET /v1/usage`:
costs to the last of their 9 decimal places, and token totals too. Mismatches
are flagged with the exact difference. A report covers up to 92 days
(Pixelforge's limit). Pixelforge's daily `generations` count turned out to
include only billed (succeeded) generations, and the report counts the same
way. The figures match exactly only when the environment is the sole user of
its API key, which is why each environment gets its own key.

## Security

- Secrets live only in the environment. They never appear in Inertia props,
  responses, logs or Git, and a test asserts the key and secret never reach
  the page.
- History is always scoped through `$user->generations()`, and there is no
  per-generation endpoint to guess ids against. The admin report and the
  Horizon dashboard are for operators only (`is_admin`).
- Server-side validation mirrors Pixelforge: prompts of up to 4,000 Unicode
  characters, the allowed sizes and qualities, at most 4 http(s) reference
  URLs, and unknown fields rejected. Reference URLs are passed through and
  never fetched by the app.
- Throttling: 20 submissions, 240 estimates and 10 credit purchases per minute
  per user, and 10 sign-ups per hour per IP address.
- Daily caps protect the shared Pixelforge account on a public demo: 30
  generations per user and 200 in total per UTC day
  (`PIXELFORGE_USER_DAILY_LIMIT`, `PIXELFORGE_DAILY_LIMIT`). A resent form
  still gets its generation.
- The Horizon dashboard only opens when jobs run on Redis, which is only the
  case locally.
- Email verification is deliberately off: the demo has no mail delivery, and
  reviewers must be able to sign up immediately.

## Deployment

Production runs on **Laravel Cloud** as a single environment. It needs no
Redis, Horizon, recurring scheduler, Docker Compose or Vite dev server: assets
are built once during the deploy, and recovery from lost webhooks runs on the
queue (follow-ups and read repair) rather than on a schedule.

|                       | Local (Docker Compose)                  | Production (Laravel Cloud)                                       |
| --------------------- | --------------------------------------- | ---------------------------------------------------------------- |
| Web                   | `laravel.test` (`php artisan serve`)    | Flex compute that scales to zero                                 |
| Frontend assets       | `npm run build`, or the Vite dev server | built during the deploy; no dev server                           |
| Database              | `pgsql` container                       | Serverless PostgreSQL that scales to zero                        |
| Queue                 | Redis, worked by Horizon                | one Standard managed queue (Cloud sets `QUEUE_CONNECTION=cloud`) |
| Cache / sessions      | Redis / database                        | database / database                                              |
| Scheduler             | `pixelforge:reconcile` every minute     | none                                                             |
| Lost-webhook recovery | follow-ups, read repair and the sweep   | follow-ups and read repair                                       |
| Webhook URL           | optional Cloudflare quick tunnel        | `https://<app-domain>/webhooks/pixelforge`                       |
| Pixelforge key        | the local key                           | a separate key with its own signing secret                       |

- **Build commands:** `composer install --no-dev`, `npm ci --audit false`,
  `npm run build`, `php artisan optimize`.
- **Deploy command:** `php artisan migrate --force`.

| Environment variable                                    | Production value                                                                                            |
| ------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| `APP_NAME`, `VITE_APP_NAME`                             | `PromptEdit`. Vite reads `VITE_APP_NAME` at build time for the page title                                   |
| `APP_ENV`                                               | `production`                                                                                                |
| `APP_DEBUG`                                             | `false`                                                                                                     |
| `APP_KEY`                                               | generated once (`php artisan key:generate --show`) and kept stable                                          |
| `APP_URL`                                               | the public URL                                                                                              |
| `CACHE_STORE`, `SESSION_DRIVER`                         | `database`                                                                                                  |
| `SESSION_SECURE_COOKIE`                                 | `true`                                                                                                      |
| `PIXELFORGE_API_KEY`, `PIXELFORGE_WEBHOOK_SECRET`       | secrets: the production key and its signing secret                                                          |
| `PIXELFORGE_USER_DAILY_LIMIT`, `PIXELFORGE_DAILY_LIMIT` | optional; default `30` and `200` generations per UTC day                                                    |
| `PIXELFORGE_RECONCILE_SCHEDULE`                         | optional; unset keeps the sweep off in production, and a cron expression turns it on where a scheduler runs |

Laravel Cloud provides `QUEUE_CONNECTION` and the `DB_*` connection settings,
so don't set them. `REDIS_*` and the [Docker-only settings](#docker-only-settings)
aren't used. Cloud's load balancer is trusted automatically. On another host
behind a proxy, add `$middleware->trustProxies(at: …)` in `bootstrap/app.php`
so HTTPS URLs are generated correctly.

## Verification

### Automated tests

- **Money:** the golden example, sub-cent amounts, markup rather than margin,
  storage that refuses to round, the docs' maximum-cost example and Unicode
  character counting.
- **Access and validation:** guests are blocked, users see only their own
  history, no secret reaches the page, Pixelforge's limits are mirrored,
  unknown fields and browser-sent prices are rejected, and a double submit
  creates one generation.
- **Submission:** the exact request body with `metadata.generation_id` and a
  stable `Idempotency-Key`, every documented error code, Retry-After and
  backoff, bounded attempts, timeouts retried with the same key, and ambiguous
  outcomes never rejected.
- **Webhooks:** valid, wrong-secret, tampered, malformed, stale and missing
  signatures; no CSRF or session; duplicates billed once; out-of-order events
  that never regress; a webhook that beats the POST response; billing at the
  snapshotted rates; contradictory finals; unknown or mismatched generations.
- **Recovery:** settling without any webhook, a webhook and a status check
  racing without double billing, resubmission with the same key, the 24-hour
  cut-off, follow-ups (anchored at acceptance, started once, stopped when
  final), read-repair limits, and every queue delay and job timeout within a
  managed queue's limits (15 minutes, 90 seconds).
- **Abuse guards, report and balances:** sign-up throttling and daily caps;
  UTC completion-day bucketing; an exact match and a one-nano-dollar mismatch
  against `/v1/usage`; idempotent purchases, holds of the maximum, and no
  double charge across duplicate events, a replayed POST and a status check.

### Live runs against Pixelforge (local, 2026-11-18)

These runs used a dedicated local API key and real queue workers, and the
browser flow (register → buy credits → submit → live updates) was driven end
to end. Webhooks came through the tunnel unless noted otherwise. Pixelforge
simulates outcomes from markers in the prompt (`[succeed]`, `[fail]`,
`[duplicate]`, `[slow]`, `[blocked]`, `[overloaded]`), so each path can be
reproduced with a real key.

| Scenario                                                                 | Outcome                                                                                                                                                                                                        |
| ------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Dashboard "Send test event"                                              | `ping` verified, stored once and closed with a `204`                                                                                                                                                           |
| `[succeed]`                                                              | Settled by webhooks. The cost recomputed from `GET /v1/generations/{id}` equalled the stored cost (0.0505575, charged 0.063196875)                                                                             |
| `[duplicate] [succeed]`                                                  | 9 deliveries arrived and 6 distinct events were stored; charged exactly once                                                                                                                                   |
| `[fail]`                                                                 | `failed` (`generation_failed`), $0, hold released                                                                                                                                                              |
| `[blocked]`                                                              | Not accepted (`content_policy_violation`), no retry, $0                                                                                                                                                        |
| `[overloaded]`                                                           | 6 attempts with backoff (~66 s), then not accepted, $0                                                                                                                                                         |
| 5 × `[slow] [succeed]` at once                                           | 3 ran immediately; 2 waited for a free slot and were accepted as slots freed. All succeeded                                                                                                                    |
| Webhook endpoint answering `500`                                         | The reconcile sweep settled the generation from `GET`; the late webhooks were then accepted without a second charge                                                                                            |
| Real form, no webhooks: landscape, high quality, 2 reference URLs        | Estimate ~$0.4121 (range $0.3574–$0.4668); the hold was the maximum, 0.46678125. The sweep settled it from `GET`, charging exactly 0.39193125 (Pixelforge 0.313545 × 1.25)                                     |
| No scheduler, webhooks unreachable: 5 × `[slow] [succeed]` from a script | Each settled by its first follow-up check about 2 minutes after acceptance (two after waiting for a slot), with one hold, release and charge each, at exactly cost × 1.25, for example 0.0174725 → 0.021840625 |
| `CACHE_STORE=database` as in production, no scheduler, no webhooks       | Settled by read repair about 63 s after acceptance (0.3845 = 0.3076 × 1.25). The 2-minute follow-up then found it final and stopped                                                                            |
| Admin report vs `GET /v1/usage`                                          | 16 billed generations, Pixelforge cost **0.8948475** on both sides, difference **0**, token totals equal. Earlier checks at 8, 9 and 11 generations also matched exactly                                       |

Two more checks used fresh clones. The deployment's build and deploy commands
ran with `APP_ENV=production` and database cache and sessions: no dev packages
were installed, every migration ran on an empty database, registration (with
production password rules), buying credits, logout and login worked, and no
task was scheduled. The [local setup commands](#running-it-locally-docker)
ran verbatim with only the host ports moved: every service started, the app
answered, and `php artisan test` passed.

**Not exercised live:**

- Covered by automated tests only:
    - Pixelforge's `rate_limit_exceeded` and `daily_limit_exceeded` 429s;
    - real network timeouts, bare 5xx responses and `validation_failed`;
    - generations completing across a UTC midnight;
    - the 5- and 10-minute follow-ups, which no live generation needed.
- Anything on Laravel Cloud. These runs were local, so the managed queue's
  `cloud` driver (dispatching, release delays, the 90-second job limit) and
  cold starts were not exercised.

## Trade-offs and next steps

- **Polling over WebSockets.** A 3-second Inertia partial reload is simple and
  cheap, because at most 3 generations can be in flight per Pixelforge
  account. It runs only while something is in flight and the tab is visible,
  and pauses after 15 minutes without changes, so an open tab can't keep a
  scale-to-zero deployment awake. Reverb would replace it if traffic grows.
- **Images expire after 24 h.** The next step is copying each PNG to object
  storage when the generation succeeds.
- **An unconfirmed submission is given up after 24 h.** That's a judgement
  call; if Pixelforge later proves it succeeded, it is still billed correctly.
- **A charge can exceed its hold** only if Pixelforge bills more than its
  documented maximum. The exact cost is still charged, and the balance can dip
  slightly below zero.
- **One queue for everything.** Under load, webhook processing could get its
  own higher-priority queue.
- **Jobs are queued after commit.** Neither Redis nor a managed queue is part
  of the database transaction, so a crash between commit and dispatch is
  recovered by read repair or the sweep, not instantly.
- **No recurring scheduler in production.** Recovery is bounded on purpose:
  follow-ups end 10 minutes after acceptance. After that, a generation whose
  webhooks were all lost is fixed the next time its owner opens the history,
  or when an operator runs `pixelforge:reconcile`, and keeps its credit hold
  until then. That gap is the price of letting the app and database sleep; an
  hourly `PIXELFORGE_RECONCILE_SCHEDULE` would close it at the cost of regular
  wake-ups.
- **The admin report is computed on demand.** That's fine at this scale; it
  could be materialised per day later.
