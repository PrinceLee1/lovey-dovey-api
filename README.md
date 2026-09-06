# LoveyDovey — API

Laravel 12 backend for **LoveyDovey**: a real-time game app for couples and friend groups. Token-based auth (Sanctum), server-authoritative couple game sessions, host-relayed group lobby games, AI-generated party content (trivia/charades), Stripe subscriptions with a 14-day free trial, and an admin dashboard's worth of endpoints.

Pairs with the [lovey-dovey](../lovey-dovey) React frontend, which talks to this API over REST + Pusher-backed WebSockets.

## Tech stack

- **PHP 8.2+**, **Laravel 12**
- **Laravel Sanctum** — bearer-token auth (no cookies/CSRF; the SPA sends `Authorization: Bearer <token>`)
- **Broadcasting** — Pusher (or Pusher-protocol-compatible) for real-time; a `Log`-based driver for local dev without a Pusher account
- **Database** — SQLite by default (`.env.example`), MySQL/Postgres in production
- **Mail** — a custom `sendlib` transport (see `App\Mail\Transport\SendlibTransport`) driving [Sendlib](https://sendlib.samueltuoyo.com/docs/send)
- **Stripe** — subscription checkout + webhook for Plus
- **OpenAI** (Structured Outputs / JSON schema) — trivia questions, charades cards, truth/dare prompts for AI-generated content
- **Sanctum + rate limiting** — see below

## Getting started

```bash
composer install
cp .env.example .env
php artisan key:generate

touch database/database.sqlite   # if using the default sqlite connection
php artisan migrate --seed

php artisan serve   # http://127.0.0.1:8000
```

Run the test suite:

```bash
composer test
# or directly:
php artisan test
```

### Environment variables

The committed `.env.example` is annotated in-place with the two gotchas that have actually bitten this project before — worth reading if something silently doesn't work:

| Variable | Notes |
|---|---|
| `DB_CONNECTION=sqlite` | Default for local dev. Switch to `mysql`/`pgsql` + the usual `DB_HOST`/`DB_DATABASE`/etc. for anything beyond local. |
| `BROADCAST_CONNECTION` | **This** is what selects the broadcaster in Laravel 11+ — not `BROADCAST_DRIVER`, which is a pre-11 name silently ignored by `config/broadcasting.php`. Use `log` for local dev with no real-time (events are written to the log, not delivered), `pusher` once you have real Pusher credentials. |
| `QUEUE_CONNECTION=sync` | Queued mail/notifications run **inline**, in the same request, no `queue:work` needed. This is why every notification send in this codebase is wrapped in try/catch — a transport hiccup would otherwise 500 the triggering HTTP request instead of just failing to send an email. Switch to `database` + a running `php artisan queue:work` once you want sends off the request path. |
| `MAIL_MAILER=sendlib` + `SENDLIB_API_KEY` | Custom mail transport registered in `AppServiceProvider`. Set `MAIL_MAILER=log` locally if you don't have a Sendlib key — mail gets written to `storage/logs/laravel.log` instead of sent. |
| `PUSHER_APP_ID` / `PUSHER_APP_KEY` / `PUSHER_APP_SECRET` / `PUSHER_APP_CLUSTER` / `PUSHER_SCHEME` | Only required when `BROADCAST_CONNECTION=pusher`. |
| `OPENAI_API_KEY` | Powers `GameAiController` (trivia, charades, truth/dare prompt generation). Without a real key, those three endpoints will fail — everything else works fine. |
| `FRONTEND_URL` | Used to build links in emails (password reset, couple game invites) and as the redirect target after email verification. |
| `SANCTUM_STATELESS_DOMAINS`, `SESSION_DOMAIN` | Only relevant if you ever switch the SPA to cookie-based Sanctum auth; the current frontend uses bearer tokens exclusively. |
| `STRIPE_*` (in `config/services.php`, not shown in `.env.example`) | Needed for `/subscribe/checkout` and the Stripe webhook. |

### After pulling changes

Two things that bite in practice, especially on shared hosting (cPanel) where a deploy is just `git pull`:

```bash
php artisan migrate --force   # new migrations aren't applied automatically
php artisan route:clear       # if route caching is on, a new route silently 404s otherwise
```

## Domain model

| Model | Purpose |
|---|---|
| `User` | `is_plus` is a **computed accessor**, not the raw stored value — it's true if the raw `is_plus` column is true (a real paying subscriber) **or** `trial_ends_at` is still in the future (the 14-day free trial every new signup gets). Admin revenue/subscriber stats query the raw column directly via the query builder, so trial users never inflate those numbers. |
| `Partner` | A couple pairing (`user_a_id`/`user_b_id`, symmetric — check both sides). `status`: `active`/`ended`. |
| `PartnerInvite` | Short-lived invite codes for linking two accounts. |
| `GameSession` | A **couple** game (Truth or Dare, Spice Dice, Emoji Chat, Memory Match…). `state` is a JSON blob holding the entire game state; `status`: `waiting → active → ended/aborted`. |
| `Lobby` / `LobbyGameSession` / `LobbyMessage` | Group lobbies: membership (`lobby_members` pivot), one-off game rounds within a lobby, and lobby chat. |
| `GameHistory` | XP ledger + play history, one row per user per game played (couple, standalone, and lobby games all write here). |
| `Games` | The seeded game catalog shown on the dashboard (title, category, player count, `partner_required`, `is_plus`). |
| `DailyChallenge` | One bonus-XP task per day. |
| `Feedback` | User-submitted feedback (category: bug/idea/praise/other), reviewable in admin. |
| `FeatureAnnouncement` | A record of one admin-sent "Features & Tips" email blast (subject, body, recipient/sent/failed counts). |
| `GamePrompts` | (Legacy/auxiliary prompt storage — most AI content generation now goes through `GameAiController` + cache rather than this table.) |

## Couple game sessions — server-authoritative

`CoupleSessionController` owns the entire lifecycle:

1. `POST /sessions {kind}` — **idempotent per (partner pair, kind)**: if the pair already has a `waiting`/`active` session of that kind, it's returned as-is rather than creating a new one. This is what makes the frontend's "click a game tile" safe to call repeatedly (e.g. after a browser back button) without ever wiping out an in-progress game.
2. `POST /sessions/{code}/accept` — the invited partner joins; the session becomes `active`.
3. `GET /sessions/{code}` — fetch current state (used both on page load and by the frontend's 3s polling fallback).
4. `POST /sessions/{code}/action` — the only way state changes. The server validates whose turn it is (except `type: finish` and `type: chat`, which either side can always do) and applies the kind-specific rule:
   - **`truth_dare` / `truth_dare_erotic` / `spice_dice`** — spin → prompt → done/skip, drawing from `TruthDarePrompts` (a curated static bank, not AI — deliberately, to keep a core multiplayer action endpoint free of an external-API failure point). Spice Dice always draws a dare.
   - **`memory_match`** — server deals and holds the deck; a match keeps the turn, a mismatch passes it.
   - **`emoji_chat`** — no turns; messages are validated server-side to be emoji-only; a timer set at `accept()` ends the round automatically.
   - **`chat`** (any kind) — the persistent side-chat panel; free text, never turn-gated, stored separately from `emoji_chat`'s own messages.
5. Every mutation broadcasts `CoupleSessionUpdated` (or `…Created`/`…Invited`) on the presence channel `couple-session.{code}`, authorized in `routes/channels.php` to only the two people on the session.

## Lobby (group) games — host-relayed

Trivia, Charades AI, Hot Seat, Would You Rather, and a group version of Spice Dice. Unlike couple sessions, **the server does not compute game state** — it's a relay:

- `POST /lobbies/{code}/games/start` — host only, creates a `LobbyGameSession`.
- `POST /lobbies/{code}/games/{id}/action` — any player posts `{type, data}`; it's broadcast to everyone on `lobby-game.{id}` via `LobbyGameUpdate`. The host's client applies player actions (votes, buzzes, guesses) and re-broadcasts the resulting full state; everyone else just mirrors whatever the host last sent.
- `POST /lobbies/{code}/games/{id}/end` — host only. `result` is `nullable|array` (an empty `{}` from an early-quit is valid — this used to 422 before the `required|array` → `nullable|array` fix). **Every current lobby member** (host included) is credited the reported `xpEarned`, gets a `GameHistory` row, and a streak bump — group XP is a shared/team result, not attributed per-player.
- **Known limitation**: mid-round state isn't persisted anywhere — only the final `result` is saved on the `LobbyGameSession` row. A client that reloads mid-round re-initializes from scratch; there's no server-side "resume" for lobby games the way there is for couple sessions.

### AI content caching

`GameAiController` generates trivia/charades/truth-dare content via OpenAI Structured Outputs and caches it (6h TTL) to avoid re-hitting the API on every request. Content **rotates through 3 cached batches per (category, difficulty, count)** key rather than one shared entry — otherwise every lobby playing the same category within the TTL window got back the exact same set, which read as "questions repeat." Each request still benefits from caching; it just draws from 3x the pool before anything actually repeats.

## Real-time broadcasting

`App\Support\Broadcasting::fire()` wraps every `broadcast()` call in the app in a try/catch. This exists because of a genuine Laravel internal gotcha: for `ShouldBroadcastNow` events, the actual network call happens in `PendingBroadcast::__destruct()`, not in the `broadcast()` call itself — so the pending broadcast variable has to go out of scope (or be explicitly `unset()`) *inside* the try block for the catch to actually catch a transport failure. A broadcast failure should never fail the HTTP request that triggered it (the underlying action already succeeded); it's logged as a warning instead.

Presence/private channel authorization lives in `routes/channels.php`:

- `lobby.{code}` — presence channel for a group lobby. **Currently allows any authenticated user into any lobby by code** (a `// For development` comment marks this as intentionally permissive) — tighten this to check actual lobby membership before treating any lobby as private/sensitive in production.
- `user.{id}` — private per-user channel (couple invites, etc.) — only that user.
- `couple-session.{code}` — private channel for a couple session — only the two participants.

## Rate limiting

Two `RateLimiter`s registered in `AppServiceProvider`:

- **`api`** (120/min, keyed by user ID or IP) — applied to every `api.php` route via `$middleware->throttleApi()` in `bootstrap/app.php`. General abuse/DoS backstop.
- **`auth`** (5/min by IP) — applied directly to `register`, `login`, `forgot-password`, `reset-password`. These are unauthenticated and the classic brute-force/credential-stuffing/email-bombing targets, so they get a much tighter limit than the rest of the API.

## Scheduled jobs

- `app:send-weekly-summary-digest` (`SendWeeklySummaryDigest`, scheduled Monday 9am) — emails the past week's games/streak summary to every active user with `weekly_summary` enabled, covering **both** couple games and lobby games. Skips anyone with nothing to report.

Admin-triggered (not scheduled): `SendFeatureAnnouncement` — dispatched when an admin sends a "Features & Tips" email via `/admin/announcements`; chunks through every active user with `email_news` enabled, sends individually with per-user try/catch, and records the final sent/failed counts back onto the `FeatureAnnouncement` row. On the default `sync` queue this runs inline before the HTTP response returns.

## Notifications

| Notification | Trigger | Audience |
|---|---|---|
| `WelcomeEmail` | Registration | The new user |
| `CoupleGameInvite` | `CoupleSessionController::invite()` | The invited partner — names the actual game kind (not hardcoded to "Truth or Dare") |
| `PublicLobbyCreated` | A public lobby is created | Other active users with `email_reminders` on |
| `WeeklySummaryDigest` | Scheduled command | Users with `weekly_summary` on |
| `FeatureAnnouncementMail` | Admin sends an announcement | Users with `email_news` on |

Every one of these is wrapped in try/catch at the call site — a mail-transport failure must never fail the underlying action (registration, invite, etc.).

## API reference

All routes below except registration/login/password-reset require `Authorization: Bearer <token>` (Sanctum).

<details>
<summary>Auth &amp; account</summary>

| Method | Path | Notes |
|---|---|---|
| POST | `/register` | Rate-limited (`auth`). Starts the 14-day Plus trial. |
| POST | `/login` | Rate-limited (`auth`). Records `last_login_at`. |
| POST | `/forgot-password`, `/reset-password` | Rate-limited (`auth`). |
| GET | `/me`, `/user` | Current user |
| PUT | `/user`, `/user/prefs` | Update profile / notification prefs |
| POST | `/user/avatar`, `/user/password` | |
| GET | `/account/sessions` | Active login sessions/devices |
| DELETE | `/account/sessions/{id}` | Revoke a session |
| POST | `/logout`, `/logout-others` | |
| DELETE | `/user` | Delete account |

</details>

<details>
<summary>Partner linking</summary>

| Method | Path |
|---|---|
| POST | `/partner/invite` |
| GET | `/partner/invites`, `/partner/lookup/{code}`, `/partner/status` |
| POST | `/partner/accept/{code}`, `/partner/reject/{code}` |
| POST | `/partner/unpair/request`, `/partner/unpair/confirm`, `/partner/unpair/cancel` |

</details>

<details>
<summary>Couple game sessions</summary>

| Method | Path |
|---|---|
| POST | `/sessions` |
| POST | `/sessions/{code}/accept` |
| GET | `/sessions/{code}` |
| POST | `/sessions/{code}/action` |

</details>

<details>
<summary>Lobbies (group)</summary>

| Method | Path |
|---|---|
| GET | `/lobbies/public`, `/lobbies/mine`, `/lobbies/{code}` |
| POST | `/lobbies` |
| POST | `/lobbies/{code}/join`, `/lobbies/{code}/leave`, `/lobbies/{code}/close` |
| DELETE | `/lobbies/{id}` |
| GET | `/lobbies/{code}/members` |
| POST | `/lobbies/{code}/reactions` |
| GET | `/lobbies/{code}/messages` |
| POST | `/lobbies/{code}/messages` |
| GET | `/lobbies/{code}/sessions` |
| POST | `/lobbies/{code}/games/start` |
| POST | `/lobbies/{code}/games/{id}/update` |
| POST | `/lobbies/{code}/games/{id}/end` |
| POST | `/lobbies/{code}/games/{sessionId}/action` |

</details>

<details>
<summary>Games, AI content, history</summary>

| Method | Path |
|---|---|
| GET | `/games`, `/games/{game}` |
| POST | `/ai/truth-dare`, `/ai/trivia`, `/ai/charades` |
| GET | `/history` |
| POST | `/history` |

</details>

<details>
<summary>Progression</summary>

| Method | Path |
|---|---|
| GET | `/daily-challenge` |
| POST | `/daily-challenge/complete` |
| GET | `/leaderboard`, `/streaks`, `/me/progress`, `/me/weekly-summary` |

</details>

<details>
<summary>Feedback &amp; subscriptions</summary>

| Method | Path |
|---|---|
| POST | `/feedback` |
| POST | `/subscribe/checkout` |
| POST | `/broadcasting/auth` |
| POST | `/webhooks/stripe` *(no auth — signature-verified in the controller)* |

</details>

<details>
<summary>Admin (requires <code>is_admin</code>, prefix <code>/admin</code>)</summary>

| Method | Path |
|---|---|
| GET | `/stats`, `/reports` |
| GET/PATCH/DELETE | `/users`, `/users/{id}` |
| POST | `/users/bulk-deactivate`, `/users/bulk-delete` |
| GET/POST/PUT/PATCH/DELETE | `/games`, `/games/{id}` |
| GET | `/games/recent`, `/games/sessions` |
| GET/POST | `/settings` |
| GET/PATCH | `/feedback`, `/feedback/{id}` |
| GET/POST | `/announcements` |

</details>

## Testing

```bash
php artisan test
```

25 test files across `tests/Feature` and `tests/Unit`, covering auth, rate limiting, partner linking, couple session lifecycle + turn-gating + resume behavior, lobby XP crediting, lobby notifications, the AI content cache rotation, feedback, admin announcements, daily challenges, streaks/progress, and weekly summaries. `phpunit.xml` runs against an in-memory-friendly SQLite setup with `MAIL_MAILER=array`, `BROADCAST_CONNECTION=log`, and `CACHE_STORE=array` so tests never hit real mail/broadcast/cache infrastructure.

Four tests under `tests/Feature/Auth/AuthenticationTest.php` and `EmailVerificationTest.php` are Laravel Breeze scaffold defaults left over from project setup — they test session-cookie auth and email-verification routes this app doesn't actually use (the SPA is bearer-token-only and doesn't wire up `verification.verify`). They're expected to fail and are safe to ignore or delete.

## Deployment notes

- Run `php artisan migrate --force` after every deploy that includes new migrations — nothing does this automatically.
- If route caching is enabled (`php artisan route:cache`), re-run it (or at least `route:clear`) after any deploy that adds/changes routes, or new routes will 404 despite being in the code.
- Set `BROADCAST_CONNECTION=pusher` (not `BROADCAST_DRIVER`) and real Pusher credentials for real-time to work in production.
- Set `QUEUE_CONNECTION=database` and run `php artisan queue:work` (via Supervisor or similar) once you want mail/notification sends off the request path — `sync` is fine for low traffic but blocks the HTTP response on every send.
