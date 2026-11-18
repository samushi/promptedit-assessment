# PromptEdit × Pixelforge: image generation with exact billing

PromptEdit's Senior Product Engineer take-home. Signed-in users describe an
image, see what it will cost **before** submitting, and get the result from
**Pixelforge**, an asynchronous image-generation API. Every generation is
billed at exactly Pixelforge's cost plus a 25% markup, only once, and failed
generations are free.

- **Deployed app:** _to be added after deployment_ (prepared for Laravel Cloud: [LARAVEL_CLOUD_DEPLOYMENT.md](LARAVEL_CLOUD_DEPLOYMENT.md))
- **Stack:** Laravel 13.43 · Inertia v3 (inertia-laravel 3.7, @inertiajs/vue3 3.11) · Vue 3.5 · TypeScript · Tailwind 4 · PostgreSQL 17 · Redis 7 + Horizon · PHP 8.4 · Pest 5, all in Docker (Laravel Sail runtime) for development. Production target: Laravel Cloud with Flex compute, Serverless Postgres and one managed queue (no Redis, no scheduler). Built on the official Laravel Vue starter kit (Fortify auth, Wayfinder, Vite+).

## What's implemented

| Requirement                                                                                                                                                                                                         | Status                                                                                                                                                              |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Generating**: prompt, size, quality, up to 4 reference-image URLs; price estimate shown before submitting                                                                                                         | ✅                                                                                                                                                                  |
| **Pricing**: Pixelforge cost + 25% markup; the up-front price is labelled as an estimate (with its range); the exact cost is recorded from actual usage at the generation's own rate snapshot; failed = $0          | ✅                                                                                                                                                                  |
| **History**: each user sees only their own generations, with live status (queued / processing / succeeded / failed), estimated and actual cost, and the image (clearly marked once Pixelforge's signed URL expires) | ✅                                                                                                                                                                  |
| **Webhooks**: signature verified on the raw body (HMAC + timestamp), deduplicated by event id with a DB unique key, safe when duplicated or out of order, never billed twice                                        | ✅                                                                                                                                                                  |
| **Deployment**                                                                                                                                                                                                      | ⏳ Not deployed yet. Prepared for Laravel Cloud ([guide](LARAVEL_CLOUD_DEPLOYMENT.md)); verified live locally ([Live run](#live-run-against-pixelforge-2026-11-18)) |
| Stretch: **admin report** per UTC day and user (charged, Pixelforge cost, profit), reconciled against `GET /v1/usage`                                                                                               | ✅                                                                                                                                                                  |
| Stretch: **balances** with "Buy credits" (fake purchase) and blocking unaffordable generations                                                                                                                      | ✅                                                                                                                                                                  |

## Running it locally (Docker)

Everything runs in Docker (`compose.yaml`, Laravel Sail's PHP 8.4 runtime in
`docker/8.4`): the app, PostgreSQL 17, Redis, **Horizon** (the queue worker) and
the **scheduler** (runs the `pixelforge:reconcile` sweep every minute). Only
Docker is needed on the host.

```bash
cp .env.example .env              # on macOS also set WWWUSER=$(id -u) and WWWGROUP=$(id -g)
docker compose build              # PHP 8.4 + Node image
docker compose run --rm laravel.test composer install
docker compose run --rm laravel.test php artisan key:generate
docker compose run --rm laravel.test php artisan migrate
docker compose run --rm laravel.test npm ci
docker compose run --rm laravel.test npm run build
docker compose up -d              # app, pgsql, redis, horizon, scheduler
```

Then open http://localhost:8000, register, click **Buy credits** and generate.
Host ports (`APP_PORT=8000`, Postgres `54329`, Redis `63790`, Vite `5174`) are
set in `.env` and chosen not to clash with other local projects.

Configure Pixelforge in `.env` (never commit real values). Use a **separate API
key, webhook URL and signing secret per environment**, as Pixelforge
recommends, so each environment only receives its own events and `/v1/usage`
matches its own records:

```dotenv
PIXELFORGE_BASE_URL=https://pixelforge.promptedit.dev/v1
PIXELFORGE_API_KEY=            # from the Pixelforge dashboard (local key)
PIXELFORGE_WEBHOOK_SECRET=     # whsec_... of that same key
```

Webhooks need a public URL. The optional `tunnel` service starts a Cloudflare
quick tunnel; set `https://<tunnel>/webhooks/pixelforge` as the local key's
webhook URL in the dashboard:

```bash
docker compose --profile tunnel up -d tunnel
docker compose logs tunnel | grep trycloudflare.com
```

While the tunnel is up the app is reachable from the internet, so set
`APP_DEBUG=false` (and restart the containers) to keep error pages generic.
Without webhooks everything still works, just slower: follow-up checks (and,
locally, the every-minute sweep) ask Pixelforge about quiet generations.

`compose.yaml` is a development stack (`artisan serve`, source bind-mounted);
see [Deployment](#deployment) for production.

Day to day:

```bash
docker compose exec laravel.test php artisan users:make-admin you@example.com   # operator: /admin/report and /horizon
docker compose restart horizon scheduler    # after changing PHP code or .env (they are long-running)
docker compose exec laravel.test composer ci:check
```

### Local (Docker) vs production (Laravel Cloud)

|                       | Local (`compose.yaml`)                                   | Laravel Cloud                                                                                       |
| --------------------- | -------------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| Web                   | `artisan serve` in the Sail container                    | Flex compute that scales to zero                                                                    |
| Database              | PostgreSQL 17 container                                  | Serverless Postgres that scales to zero                                                             |
| Queue                 | Redis + Horizon                                          | one managed **standard** queue (`QUEUE_CONNECTION=cloud`, set by Cloud); Flex workers scale to zero |
| Cache / sessions      | Redis / database                                         | database / database (no Redis or Valkey to pay for)                                                 |
| Scheduler             | `schedule:work`: `pixelforge:reconcile` every minute     | off: no recurring wake-ups (the command can be run by hand)                                         |
| Lost-webhook recovery | the sweep, plus the follow-up checks and the page repair | follow-up checks 2, 5 and 10 minutes after acceptance, plus the page repair                         |
| Webhook URL           | optional Cloudflare quick tunnel                         | `https://<app>.laravel.cloud/webhooks/pixelforge`                                                   |
| Pixelforge key        | the local key                                            | a separate deployment key (its own webhook secret and `/v1/usage`)                                  |

## Architecture

A modular monolith organised by business capability, following the same
domain-oriented structure as my other Laravel projects, adapted from an API to
Inertia pages. Three PSR-4 roots:

- `App\` → `app/App`: only the service providers (App, Fortify, Horizon).
- `App\Support\` → `app/Support`: the shared building blocks below.
- `App\Domain\` → `app/Domain`: one folder per business capability.

```
app/
├── Support/
│   ├── Actions/ActionFactory.php      XAction::run(...) resolves the action from the container and calls handle()
│   ├── DataObjects.php                request DTOs: validated snake_case input → typed camelCase properties
│   ├── Repositories.php               generic repository base (find, lockForUpdate, insertOrIgnore)
│   ├── AbstractServiceProvider.php    a domain's provider; loads the domain's web.php and api.php
│   ├── DomainServiceProvider.php      discovers every domain's provider and Database/Migrations
│   ├── InertiaControllers.php         page controller base (render, toast)
│   ├── Middleware/                    HandleInertiaRequests (props shared by every page), HandleAppearance
│   ├── FormRequest.php · ReadModel.php (UUID models) · Money.php · Casts/MoneyCast.php
│   └── Enums/Concerns/Arrayable.php · Helpers/DomainHelpers.php
└── Domain/
    ├── User/         accounts: Actions/Fortify (registration, password reset), Controllers/Settings and
    │                 Requests/Settings (profile, password, two-factor, passkeys), Concerns (validation rules),
    │                 Models, Repository, Commands (users:make-admin), Database, Providers, web.php
    ├── Generation/   requests → Pixelforge → status → exact price
    │   ├── Actions/        CreateGenerationAction, EstimateGenerationCostAction, GetGenerationsPageAction,
    │   │                   SubmitGenerationAction (POST + retry policy), ApplyProviderSnapshotAction
    │   │                   (the only place status advances and money settles), RejectGenerationAction,
    │   │                   RecordWebhookEventAction, ProcessWebhookEventAction, SyncGenerationAction,
    │   │                   ReconcileGenerationsAction
    │   ├── Middleware/     VerifyPixelforgeSignature (raw-body HMAC)
    │   ├── WebhookEvents/  GenerationWebhookEvent, chosen by the WebhookEventType registry
    │   ├── Jobs/           SubmitGenerationJob, ProcessPixelforgeEventJob, SyncGenerationJob (queue mechanics only)
    │   ├── Repository/     GenerationRepository, PixelforgeWebhookEventRepository (every query lives here)
    │   ├── Services/       PixelforgeClient, PixelforgePricing, PixelforgeWebhookVerifier, CostCalculator, CostEstimator
    │   ├── Commands/ Controllers/ Database/ Dto/ Enums/ Exceptions/ Models/ Providers/ Requests/ Resources/
    │   ├── web.php         pages (session, CSRF)
    │   └── api.php         the Pixelforge webhook (stateless, signature-authenticated)
    ├── Billing/      credit ledger: PurchaseCreditsAction, GetCreditSummaryAction, CreditLedger,
    │                 CreditTransactionRepository, ...
    └── Reports/      operator report: GetAdminReportAction (reads billed totals through
                      GenerationRepository), AdminReportRequest, ...

resources/js/
├── pages/generations/Index.vue         form + live history
├── pages/admin/Report.vue              operator report
├── components/generations/             GenerationForm, CostEstimatePanel, GenerationCard, StatusBadge, CreditsBar
├── composables/useCostEstimate.ts      debounced server-side estimate
└── lib/money.ts                        formats decimal strings with BigInt, never floats
```

**The flow:** a thin controller validates with a `FormRequest`, builds a DTO
and calls an action (`CreateGenerationAction::run($user, $dto)`). Actions hold
the business logic, use repositories for every query and services for
Pixelforge and the pricing maths. Jobs only do queue mechanics: for example,
`SubmitGenerationJob` releases itself for as long as `SubmitGenerationAction`
asks. Each domain registers itself through its `Providers/*ServiceProvider`
(routes, commands, the reconcile schedule, container bindings), so
`bootstrap/app.php` wires no domain by hand. Migration file names were kept as
they were, so moving them into the domains needed no data migration.

Compared with an API codebase, the domain route files are split by intent:
`web.php` for Inertia pages (session and CSRF) and `api.php` for the webhook (no
session, no CSRF, no Sanctum; a signature middleware authenticates it). There
is no multi-tenancy, so there is no Central/Tenant split, query-filter
pipeline or Blade discovery. Users keep integer ids, because the Fortify and
passkey tables depend on them; generations use UUIDs (`ReadModel`).

### Lifecycle of a generation

```
Browser ── POST /generations ──► StoreGenerationRequest (validates like Pixelforge) → CreateGenerationDto
                                   └► CreateGenerationAction, in one transaction:
                                        lock user row · server-side quote · save row (pending)
                                        with Idempotency-Key and the exact request body ·
                                        hold credits · queue SubmitGenerationJob after commit
Horizon ── SubmitGenerationJob → SubmitGenerationAction ── POST /v1/generations (same key + body every attempt)
   ├ 202 ............................ ApplyProviderSnapshotAction (usually → queued)
   ├ 400/401/409/422/429 daily ...... RejectGenerationAction ($0, reason shown)
   ├ 429 rate/concurrency, 503 ...... job released for Retry-After / backoff, same key
   └ timeout, bare 5xx .............. retry, same key; never assumed "not created"

Pixelforge ── POST /webhooks/pixelforge ──► VerifyPixelforgeSignature (raw-body HMAC)
                                              → RecordWebhookEventAction: insert-or-ignore(event id) → 204
                                              └► Horizon: ProcessPixelforgeEventJob → WebhookEvents → ApplyProviderSnapshotAction

Recovery ── SyncGenerationJob → RecoverGenerationAction, queued by: follow-ups 2, 5 and 10 min after
            acceptance · the history page for rows that look stuck · (locally) the every-minute sweep
   ├ accepted, quiet ................ GET /v1/generations/{id} (authoritative) → ApplyProviderSnapshotAction
   └ pending, quiet ................. resubmit with the same key (closed at $0 after 24 h)

Browser ◄── reloads history + balance every 3 s while anything is in flight and the tab is visible
            (Inertia partial reload; pauses after 15 minutes without changes)
```

## Money

```
provider_cost = Σ over the 4 token types: tokens × rate_per_1M / 1,000,000    (rates = the generation's own snapshot)
customer_cost = provider_cost × 1.25        profit = customer_cost − provider_cost        failed / rejected = 0
```

- **Exact arithmetic only.** `brick/math` `BigDecimal` in PHP, `NUMERIC(24,12)`
  in Postgres, decimal **strings** in JSON and Inertia props, and BigInt string
  maths in the UI (`resources/js/lib/money.ts`). No float ever touches money:
  `MoneyCast` runs with `strict_types`, so assigning a float is a `TypeError`.
- **Nothing is rounded silently.** Storage uses
  `toScale(12, RoundingMode::Unnecessary)`, which throws rather than lose a
  digit. Pixelforge's 9-decimal costs × 1.25 need at most 11.
- **Each token type is billed at its own rate.** `total_tokens` is only a
  consistency check.
- **Rates come from the generation itself.** Each generation is billed at the
  `rates` snapshot Pixelforge returned for it (stored once, never overwritten),
  never at today's `/v1/pricing`.
- **Fractions of a cent.** Users are charged the exact sub-cent amount
  (credits are USD held to 12 decimal places). Rounding each charge up to a
  cent would overcharge, and rounding to the nearest cent would break
  "exactly cost + 25%". Charged amounts are shown exactly ($0.3818875); only
  estimates are rounded for display (~$0.0723).
- **Golden example (unit-tested).** 12 / 765 / 6450 / 3184 tokens at
  5.00 / 10.00 / 40.00 / 12.50 per 1M give **0.305510000** to Pixelforge,
  **0.381887500** to the user, **0.076377500** profit.

### Estimates

Estimates are computed **on the server**: the form calls
`POST /generations/estimate` (debounced; stale responses are aborted and
discarded), and `store` re-quotes independently. The browser never computes a
price, and a price sent by the client is rejected as an unknown field.

| Token type  | Estimate                                                        | Range shown                  |
| ----------- | --------------------------------------------------------------- | ---------------------------- |
| Input text  | `ceil(characters / 4)`, counting Unicode characters (not bytes) | ±15%, upper bound rounded up |
| Input image | 765 per reference URL (exact)                                   | exact                        |
| Output      | by quality × size, from the pricing table (exact)               | exact                        |
| Reasoning   | **midpoint** of the quality's range (assumption)                | the range's min–max          |

Pricing comes from `GET /v1/pricing` (validated against the documented shape,
cached for 10 minutes). If Pixelforge is unreachable, the last good copy is used
for up to 24 hours. With no copy at all, the form says so and generating is
disabled: we never invent a price. The **maximum** (for the docs' worked
example, $0.373875 from Pixelforge, $0.46734375 to the user) is what a credit
hold reserves.

## Reliability

### Submitting: idempotency and retries

- **Our form is idempotent.** Each form submission carries a
  `client_request_id` with `UNIQUE(user_id, client_request_id)`, so a double
  click or a resent request creates a single generation.
- **Pixelforge requests are idempotent.** The row, its `idempotency_key` and
  the exact JSON body (`provider_request`) are saved before the first POST, and
  every attempt replays them verbatim. A timeout can therefore never produce a
  second generation, and a retry can never become a "different request" (409).
- **Branching is on Pixelforge's documented `error.code`**, not the HTTP status:

| Pixelforge answer                                             | What happens                                                                                                                                                                                                                                                                  |
| ------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `202`, new or `Idempotent-Replayed`                           | Snapshot applied through the same path as webhooks (a replay may already be final)                                                                                                                                                                                            |
| `400 content_policy_violation`, `422 validation_failed`       | Rejected at $0 with the reason shown; no retry                                                                                                                                                                                                                                |
| `401 invalid_api_key`, `409 idempotency_key_reused`           | Rejected at $0 and `Log::critical` (our configuration or bug); no retry                                                                                                                                                                                                       |
| `429 daily_limit_exceeded`                                    | Rejected at $0: "quota resets at midnight UTC"; no retry                                                                                                                                                                                                                      |
| `429 concurrency_limit_exceeded`                              | Waits for a free slot for up to **10 minutes**, retrying after max(Retry-After, backoff); the card shows "Waiting for a free Pixelforge slot". These waits don't count as failures                                                                                            |
| `429 rate_limit_exceeded`, `503 overloaded`                   | Up to 6 failures per generation, each retried after max(Retry-After, 2ⁿ s), where the backoff (never Retry-After) is capped at 60 s; then rejected at $0 (Pixelforge says nothing was created)                                                                                |
| Timeout, connection error, 5xx without a code, unreadable 2xx | Retried the same way, but **never rejected**: Pixelforge may have created it. The row stays pending and is flagged `submission_ambiguous`; recovery resends it later with the same key, and a webhook can arrive at any time. It is only marked "unconfirmed" ($0) after 24 h |

All Pixelforge calls share the account's **60 requests/minute**. Each client
call is a single request (no HTTP-level retries stacked on job retries), polls
only touch quiet in-flight generations in small batches, and a 429 while
polling simply skips that tick.

### Webhooks

1. **Verify first.** The route lives in the domain's `api.php` (no session or
   CSRF), behind the `VerifyPixelforgeSignature` middleware. The
   `Pixelforge-Signature` header `t=…,v1=…` is checked as
   HMAC-SHA256(`"{t}.{raw body}"`, signing secret) with `hash_equals`, within
   ±300 s, **before** any JSON decoding. Multiple `v1` values are accepted
   (secret rotation). A missing secret is a 500, so Pixelforge keeps retrying
   until it's configured.
2. **Store once.** Events are deduplicated on the **signed** body `id` (not the
   unsigned `Pixelforge-Event-Id` header), using a `UNIQUE` index and
   `INSERT … ON CONFLICT DO NOTHING`. The inbox row is committed before we
   answer `204`, and its processing job is queued right after the commit
   (`afterCommit`). If that job is lost or keeps failing, the generation's
   follow-up checks still settle it from Pixelforge's `GET`, and the reconcile
   sweep re-runs events left unprocessed. A redelivery gets `204` and does
   nothing. `ping` and unknown event types are stored and closed.
3. **Apply in order of status, not of arrival.** A queued job finds the
   generation through `metadata.generation_id`, which we send on every POST,
   so even a webhook that beats the POST response finds its row. If our row is
   already linked to a different Pixelforge id, the event is refused and
   logged. `GenerationStatus::canAdvanceTo()` only lets status move forward
   (pending < queued < processing < succeeded | failed); final states never
   change, and a contradictory final event is logged and ignored.
   `rejected` is only our inference, so Pixelforge evidence may override it.
4. **Bill exactly once.** Money is settled only on the transition into a final
   status, inside the transaction that holds the generation's row lock. A
   webhook, a duplicate webhook, a reconciliation poll and a replayed POST
   racing on the same generation are serialised: whoever comes second finds a
   final status and changes nothing. The credit ledger also has
   `UNIQUE(generation_id, type)`.

### Recovery when webhooks or jobs are lost

Webhooks are the fast path. Recovery never needs a recurring scheduler, so a
scale-to-zero deployment can sleep. Each check is `RecoverGenerationAction`:
an accepted generation that went quiet is fetched with
`GET /v1/generations/{id}` (authoritative, through the same snapshot path, so it
can't bill twice); a pending one with no submission activity for 3 minutes is
resubmitted with the same key (a live submission job holds a unique lock, so
this never doubles it), and is closed at $0 if Pixelforge never confirmed it
within 24 hours. Checks are queued by:

- **Follow-ups**: when Pixelforge accepts a generation (the first time we hear
  its id, from the POST response, a webhook or a `GET`), a fixed ladder of
  checks runs 2, 5 and 10 minutes after acceptance and stops as soon as the
  generation is final. Every delay fits a managed queue's 15-minute limit.
- **Read repair**: showing the history queues a check for rows that look
  stuck. It runs at most once per 2 minutes per generation, whatever the result
  (no change, 429, timeout), claimed atomically so polling tabs can't pile up,
  and at most 3 per page view, because every check shares Pixelforge's
  60 requests/minute.
- **The sweep** (`pixelforge:reconcile`): scheduled every minute locally, off
  in production unless `PIXELFORGE_RECONCILE_SCHEDULE` sets a cron. It also
  re-runs webhook events still unprocessed after 2 minutes (up to 10 times).

### Images

Pixelforge's signed URL is stored with its expiry. Once it has expired, the
card says "Image link expired" instead of showing a broken image (an `onerror`
fallback covers other load failures). Long-term image storage is optional and
not implemented.

## Stretch goals

### Admin report (`/admin/report`, operators only)

For each **UTC day of completion** (the day Pixelforge bills, not the
submission day) and each user: generations, what users were charged,
Pixelforge's cost and profit, plus per-user totals for the range. Every day,
and the total, is compared with `GET /v1/usage` for the same dates: costs to
the last of their 9 decimal places, and token totals too. Mismatches are
flagged with the exact difference. Up to 92 days per report (Pixelforge's
limit). This only matches exactly when the deployed environment is the sole
user of its API key, which is why each environment gets its own key.

### Balances

- Credits are USD in an **append-only ledger** (`credit_transactions`:
  purchase / hold / release / charge). The balance is the sum of a user's rows,
  so there is no stored balance to drift.
- **Buy credits** adds a fixed package ($5 / $10 / $25). It's a fake purchase,
  and each click is idempotent through a unique reference.
- **Reservation policy.** The true price is only known at the end, so
  submitting **holds the maximum the generation can cost** (prompt text +15%,
  maximum reasoning tokens). If the balance can't cover that, the request is
  refused with a clear message, both in the UI and on the server. The balance
  check runs under the user's row lock, so concurrent submits can't overspend.
- **Settlement** happens in the same transaction as the final status: release
  the hold, then charge the exact price on success. Failed and rejected
  generations release the hold and cost $0. Unique keys make any double hold,
  release or charge impossible.

## Security

- Secrets live only in the environment. They are never in Inertia props,
  responses, logs or Git (a test asserts the key and secret never reach the
  page).
- History is always scoped through `$user->generations()`, and there is no
  per-generation endpoint to guess ids against. The admin report and the
  Horizon dashboard are for operators only (`is_admin`), in every environment.
- Server-side validation mirrors Pixelforge: prompts up to 4,000 Unicode
  characters, allowed sizes and qualities, at most 4 http(s) reference URLs,
  and unknown fields rejected. Reference URLs are passed through and never
  fetched by this app.
- Throttling: submit 20/min, estimate 240/min, credits 10/min per user;
  sign-ups 10/hour per IP address.
- Daily caps for a public demo: 30 generations per user and 200 in total per
  UTC day (`PIXELFORGE_USER_DAILY_LIMIT`, `PIXELFORGE_DAILY_LIMIT`), so scripts
  can't run the deployment's bill up. A resent form still gets its generation.
- Horizon's dashboard only opens when jobs run on Redis (locally); on Laravel
  Cloud's managed queue it is closed.
- Email verification is deliberately turned off: the demo has no mail
  delivery and reviewers must be able to sign up immediately.

## Tests

```bash
docker compose exec laravel.test composer ci:check   # formatting + lint, vue-tsc, Pint, PHPStan (level 7), Pest
docker compose exec laravel.test php artisan test    # just the tests
```

Tests need PostgreSQL (NUMERIC money, unique keys and row locks), which the
`pgsql` service provides with a separate `promptedit_test` database. In CI
(GitHub Actions), Postgres runs as a service container instead.

Tests run against the `promptedit_test` Postgres database, never SQLite (which
would store NUMERIC as floating point). `Http::preventStrayRequests()` is on
for every test, so nothing reaches the real API. Coverage includes:

- **Money:** the golden example; fractions of a cent; 25% markup, not margin; storage refuses to round; the docs' "max cost" example; Unicode character counting.
- **Access:** guests are blocked; users see only their own history; no secrets in the page.
- **Validation:** Unicode length, sizes, qualities, URL schemes, at most 4 references, unknown fields, browser-sent prices rejected, double submit creates one generation.
- **Submission:** bearer auth, the exact JSON body with `metadata.generation_id`, and a stable `Idempotency-Key`; every documented error code; Retry-After and backoff; bounded attempts; concurrency waits measured in minutes; a timeout retried with the same key, creating only one generation; ambiguous outcomes never rejected; a replayed final response is settled.
- **Webhooks:** valid, wrong-secret, tampered, malformed, stale and missing signatures; no CSRF or session; ping; a duplicate delivery stored and billed once; out-of-order events never regress; async failure at $0; an early webhook before the POST response; billing at the snapshotted rates; contradictory finals; mismatched ids; unknown generations; malformed metadata.
- **Recovery:** finalising without any webhook; a webhook and a poll racing without double billing; skipping a rate-limited tick; resubmitting with the same key; giving up after 24 h; re-running stuck events. Follow-ups: anchored at acceptance (accepted after a 9-minute wait for a slot), started once however acceptance is reported, stopped when final, continued when Pixelforge is unreachable, the next rung queued while the current one holds its unique lock. Read repair: once per window per generation across polls and tabs, at most 3 per view, own rows only. The schedule: every minute locally, off in production, configurable.
- **Managed-queue limits:** every delay at most 15 minutes (a `Retry-After: 3600` waits 900 s, then asks again); every job's timeout well under a Flex worker's 90 seconds.
- **Abuse guards:** sign-ups throttled per IP without affecting other auth pages; per-user and total daily caps, reset at midnight UTC.
- **Admin report:** UTC completion-day bucketing (submitted 23:59:50, completed 00:00:10 counts on the next day); exact match and a one-nano-dollar mismatch against `/v1/usage`; outage handling; range validation.
- **Balances:** idempotent purchases; holds of the maximum; insufficient balance; reservations counted; exact charge plus release on success; release on failure or rejection; no double charge across duplicate events, a replayed POST and a poll; a rejected generation later proven to have succeeded.

**Manual fault-injection checklist** (with a real key, on the deployed app):
`[succeed]`, `[fail]` ($0), `[duplicate]` (one charge), `[slow]` (processing
for 60–90 s, then polled or webhooked), `[blocked]` ("Not accepted", $0),
`[overloaded]` (retries, then "Not accepted", $0), more than 3 at once (the
4th waits for a slot), then compare `/admin/report` with the Pixelforge
dashboard.

### Live run against Pixelforge (2026-11-18)

Run locally with a dedicated local API key, with webhooks delivered through a
cloudflared tunnel. The browser flow (register → buy credits → submit → live
updates) was also driven end to end through a real queue worker.

| Scenario                            | What happened                                                                                                                                                                          |
| ----------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Dashboard "Send test event"         | `ping` verified, stored once and closed with a 204 (earlier attempts failed while the secret wasn't loaded; Pixelforge's retry then succeeded)                                         |
| `[succeed]`                         | Succeeded via webhooks. The cost recomputed from `GET /v1/generations/{id}` equalled the stored cost (0.0505575, charged 0.063196875)                                                  |
| `[duplicate] [succeed]`             | 9 generation-event deliveries arrived in this batch and 6 distinct events were stored; the duplicate was charged exactly once                                                          |
| `[fail]`                            | `failed` / `generation_failed`, $0, hold released                                                                                                                                      |
| `[blocked]`                         | Not accepted (`content_policy_violation`) with no retry, $0                                                                                                                            |
| `[overloaded]`                      | 6 attempts with backoff (~66 s), then not accepted, $0                                                                                                                                 |
| 5 × `[slow] [succeed]` at once      | 3 ran immediately; 2 showed "waiting for a free slot" and were accepted the moment slots freed. All succeeded, and the retry budget was untouched                                      |
| Webhooks deliberately failing (500) | The reconciler finalised the generation by polling `GET`; the late webhooks were then accepted without a second charge                                                                 |
| Admin report vs `GET /v1/usage`     | 8 billed generations. Pixelforge cost **0.15182** on both sides, difference **0**, token totals equal. Charged 0.189775, profit 0.037955; the user's balance was 25 − 0.189775 exactly |

Pixelforge's daily `generations` count turned out to include only billed
(succeeded) generations, the same as the report's count.

A final production-like check used cached config and routes
(`php artisan optimize`) and the **real form** with the real key: landscape,
high quality and 2 reference URLs, with the tunnel closed. The form estimated
~$0.412 (range $0.3573–$0.4667). The reconciler alone finalised it from
`GET`: `input_image_tokens: 1530`, Pixelforge cost 0.33741, charged 0.4217625.
The report then matched `/v1/usage` exactly for 9 generations (0.48923).

**After restructuring into domains and moving to Docker + Redis/Horizon**, the
same checks were repeated inside the containers:

- a ping and a `generation.*` event, signed with the configured secret: both 204,
  processed by Horizon from Redis, and a redelivery still left exactly one row;
- the real UI: log in, **Buy credits** ($5), then landscape + high + 2 reference
  URLs. The estimate was ~$0.4121 (range $0.3574–$0.4668) and the hold was the
  maximum, 0.46678125. Horizon sent it and the reconciler finalised it with no
  webhook. It was charged exactly 0.39193125 (Pixelforge 0.313545 × 1.25) and the
  balance moved by exactly that amount;
- the report: 11 billed generations, Pixelforge cost **0.81832** on both sides,
  difference **0**, token totals equal;
- the `/horizon` gate (tests: guests and regular users get 403, even in `local`).

The setup steps above were also followed in a fresh clone (`docker compose
build`, `composer install` into an empty `vendor`, …, `up -d`), where
`composer ci:check` passed in the container. The CI job was reproduced on a
machine without the Redis PHP extension, as on GitHub's runner, and also
passed.

**Preparing for Laravel Cloud (recovery without a scheduler):**

- **Recovery drill.** The scheduler was stopped and the local key's webhook
  URL was unreachable. Five `[slow] [succeed]` generations were submitted from
  a script rather than the page, so the page's repair couldn't help:
    - three were accepted at once and two after waiting for a slot (1:19 and
      2:21 later);
    - each was settled by its own follow-up check about 2 minutes after
      acceptance, with no webhook (0 events received) and no failed job;
    - each was charged once (hold, release and charge, one each), at exactly
      Pixelforge's cost × 1.25, for example 0.0174725 → 0.021840625.
- **Report:** 16 billed generations, Pixelforge cost **0.8948475** on both
  sides, difference **0**, token totals equal.
- **Laravel Cloud's build, locally.** In a throwaway clone:
    - commands: `composer install --no-dev`, `npm ci --audit false`,
      `npm run build`, `php artisan optimize`, then `migrate --force` on an empty
      database;
    - settings: `APP_ENV=production`, database cache and sessions;
    - result: no dev packages, 10 migrations, registration (production password
      rules), "Buy credits", logout and login all worked, and `schedule:list` was
      empty.
- **The real UI with bounded polling:** the "Updating live" indicator showed
  while the generation was in flight and disappeared once it was final. It was
  charged 0.3879125, and the balance moved by exactly that amount.
- **With `CACHE_STORE=database`, as on Cloud** (scheduler stopped, no
  webhooks):
    - a generation submitted through the form was settled by the history page's
      repair about 63 seconds after acceptance;
    - the repair's claim was a row in the `cache` table, and the ledger shows
      one hold, one release and one charge (0.3845 = 0.3076 × 1.25);
    - the 2-minute rung then found it final, released its `cache_locks` row and
      stopped.
- **Coverage of the ladder:** all five drill generations settled at the first
  rung. The later rungs, and queuing the next rung while a lock is held, are
  covered by automated tests only.
- **CI:** the CI job was reproduced on the final commit on a machine without
  the Redis PHP extension, as on GitHub's runner, and passed.

**Not exercised live** (covered by automated tests only):

- Pixelforge's `rate_limit_exceeded` and `daily_limit_exceeded` 429s;
- real network timeouts, bare 5xx responses and `validation_failed`;
- generations completing across a UTC midnight;
- anything on Laravel Cloud: the managed queue's `cloud` driver (dispatching,
  release delays, the 90-second limit), cold starts and costs;
- a deployed environment.

## Deployment

The target is **Laravel Cloud on the Starter plan**, kept as small as possible:
one Flex app that scales to zero, one Serverless Postgres that scales to zero,
and one managed **standard** queue with Flex workers that scale to zero. There is
no Redis, no Horizon process, no scheduler and no object storage. The full,
step-by-step guide, including the budget limits and their caveats, is
[LARAVEL_CLOUD_DEPLOYMENT.md](LARAVEL_CLOUD_DEPLOYMENT.md).

In short:

- **Build commands:** `composer install --no-dev`, `npm ci --audit false`,
  `npm run build`, `php artisan optimize`.
- **Deploy command:** `php artisan migrate --force`.
- **Variables:** `APP_KEY`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`,
  `CACHE_STORE=database`, `SESSION_DRIVER=database`,
  `SESSION_SECURE_COOKIE=true`, and the **deployment** key's
  `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET`.
- **Leave to Cloud:** `QUEUE_CONNECTION` (`cloud`) and the `DB_*` variables.
- **Webhook URL:** `https://<your-app>.laravel.cloud/webhooks/pixelforge`.

Laravel Cloud's load balancer is trusted automatically. On another host behind
a proxy, add `$middleware->trustProxies(at: …)` in `bootstrap/app.php` so HTTPS
URLs are generated correctly.

## Trade-offs and next steps

- **Polling over WebSockets.** A 3-second Inertia partial reload is simple and
  cheap here, because at most 3 generations can be in flight per account. It
  only runs while something is in flight and the tab is visible, and pauses
  after 15 minutes without changes, so an open tab can't keep a scale-to-zero
  deployment awake. Reverb would replace it when traffic justifies it.
- **Images expire after 24 h.** The next step is copying each PNG to object
  storage when the generation succeeds.
- **An unconfirmed submission is given up after 24 h.** That's a judgement
  call: if Pixelforge later proves the generation succeeded, it is still
  billed correctly.
- **A charge can exceed its hold** only if Pixelforge ever bills more than its
  documented maximum. In that case we still charge the exact cost, and the
  balance can dip slightly below zero.
- **One queue for everything.** Webhook processing could get its own
  high-priority Horizon supervisor under load.
- **Queued jobs after commit.** Neither Redis nor a managed queue is part of
  the database transaction, so jobs are queued after commit. A crash in
  between is recovered by the history page's repair or the sweep, not
  instantly.
- **No recurring scheduler in production.** Recovery is bounded on purpose:
  follow-ups end 10 minutes after acceptance, and after that a generation
  whose webhooks were all lost is fixed the next time its owner opens the
  history (or when an operator runs `pixelforge:reconcile`). Until then it
  keeps its credit hold. That is the price of letting the app and the database
  sleep. A low-frequency schedule (for example hourly, via
  `PIXELFORGE_RECONCILE_SCHEDULE`) would close the gap at the cost of regular
  wake-ups.
- **The admin report is computed on demand.** That's fine at this scale; it
  could be materialised per day later.
