# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repository Overview

**AICopia** is a single, standalone CodeIgniter 4 e-commerce solution (쇼핑몰 솔루션) — one CI4 project rooted at the repository root (`app/`, `public/` are directly under root). It bundles a corporate site (pages, boards, inquiry) and a full shop (products, cart, orders, PG payments) on top of an AI-assisted operations layer (AI 카테고리 추천, 리뷰 요약, 문의 자동분류/Triage, 시맨틱 검색, 재입고 제안 등).

All `php spark` / `composer` / `git` commands run **from the repository root** — there are no `default/` or `shop/` subprojects.

## Commands

```bash
php spark serve              # Start dev server (http://localhost:8080)
php spark migrate            # Run all pending migrations (creates tables + seeds)
php spark migrate:rollback   # Roll back last migration batch
php spark db:seed <Seeder>   # Run a seeder (e.g. ProductSeeder, PostSeeder)
./vendor/bin/phpunit         # Run tests
vendor/bin/phpstan analyse   # Static analysis (config: phpstan.neon)
```

### Scheduled / batch commands (`app/Commands/`)

| Command | Class | Purpose |
|---------|-------|---------|
| `php spark orders:expire [minutes]` | `ExpireOrders` | Expire `pending` orders older than N min (default 30) |
| `php spark grades:upgrade` | `UpgradeGrades` | Recalculate member grades (등급) |
| `php spark coupons:birthday` | `IssueBirthdayCoupons` | Issue birthday coupons |
| `php spark stats:purge-logs` | `PurgeAccessLogs` | Purge old access logs |
| `php spark ai:work` | `WorkAiJobs` | Process queued AI jobs (`ai_jobs` table) |

**Cron (production — register a single line):**
```
* * * * * cd /path/to/AICopia && php spark tasks:run >> /dev/null 2>&1
```
`Config/Tasks.php` reads enabled jobs from the `settings` table and registers them with the scheduler. The job→command map (`schedule_orders_expire`, `schedule_grades_upgrade`, `schedule_coupons_birthday`, `schedule_stats_purge_logs`, `schedule_ai_work`) is managed from **`/admin/schedule`** (enable/disable + interval).

## Initial Setup

```bash
composer install
cp env .env                    # then edit: DB, CI_ENVIRONMENT, AI keys, PG keys, OAuth keys, SMTP
# app/Config/App.php: set $appTimezone = 'Asia/Seoul'
php spark migrate              # creates tables + seeds default data
```

Default admin: `admin@example.com` / `admin1234!` (seeded in `2024-01-01-000002_SeedBoardData`).

Upload permission on Linux: `chmod -R 755 public/uploads writable`

## Git Workflow

**Branch model: `feature/* → dev → main`.**

- **`main`** — production / release branch. Only updated via PR from `dev`.
- **`dev`** — integration branch. **NEVER delete `dev`.** All feature work merges here first.
  - ⚠️ `dev` does not exist yet — create it once from `main`: `git checkout main && git pull && git checkout -b dev && git push -u origin dev`.
- **`feature/xxx`** — short-lived working branches, always branched **off `dev`**.

Standard flow for any change:
```bash
git checkout dev && git pull origin dev
git checkout -b feature/<short-name>      # branch off dev
# ...commit work...
git push -u origin feature/<short-name>
gh pr create --base dev --head feature/<short-name>   # PR into dev
# after review/merge, delete the feature branch (NOT dev)
```
Release: open a separate PR `dev → main` (`gh pr create --base main --head dev`).

**Rules**
- Never commit directly to `main` or `dev`; always go through a `feature/*` branch and PR.
- Never delete the `dev` branch.
- Delete a `feature/*` branch only after its PR is merged.
- Commit messages: Korean, with an emoji prefix matching the change (per project guideline). One logical task = one commit.

## Architecture

### Theme System

`ThemeView` (`app/Libraries/ThemeView.php`) replaces CI4's default renderer (wired as the shared renderer in `Config/Services.php`). View resolution order:

1. `app/Views/themes/{active_theme}/{view}.php`
2. `app/Views/themes/default/{view}.php`
3. `app/Views/{view}.php` (admin & content views — never themeable)

Active theme is stored in `settings.active_theme` (cached). Add a theme by placing files under `app/Views/themes/{name}/` and `public/themes/{name}/`, overriding only what differs from `default`. (Installable theme archives `dark.zip`, `spring.zip`, `violet.zip` live under `themes/`.)

### BaseController — Global Data Injection

Every controller extends `BaseController`. `initController()` runs on every request and injects into `$this->viewData`:

- `$settings` — site-wide key-value config (cached)
- `$menus` — navigation tree (cached)
- `$authUser` — session-based user info (id, nickname, role, loggedIn)
- `$subLeftBanners` — active sidebar banners (cached, skipped on admin routes)
- `$activePopups` — active popups for current URI (cached)
- `$cartCount` — cart item count
- `$unreadInquiries` — unread inquiry count (admin role only)

Use `$this->render('view/path', $extraData)` in controllers — it merges `$viewData` automatically.

### Controllers

- `Controllers/Front/` — `Home`, `Shop`, `Cart`, `Order`, `Payment`, `MyPage`, `Coupon`, `Promotion`, `Board`, `Page`, `Auth`, `SocialAuth`.
- `Controllers/Admin/` — `Dashboard`, `Product`, `Inventory`, `Order`, `Sales`, `Stats`, `Coupon`, `Point`, `Grade`, `Promotion`, `Supplier`, `Review`, `Qna`, `Inquiry`, `Notification`, `User`, `Banner`, `Popup`, `Menu`, `PageManager/PostManager`, `BoardManager`, `Media`, `Schedule`, `Setting`, `Welcome`.

### Auth & Routing

- Auth filter alias: `auth` → `App\Filters\AuthFilter`; usage `['filter' => 'auth:member']` / `['filter' => 'auth:admin']`.
- `StatsFilter` records access logs.
- All `/admin/*` routes require `auth:admin`.
- Cart view/edit/delete require `auth:member`; `cart/add` (POST) is open to guests (session cart).
- Catch-all dynamic page route `(:segment)` → `Front\PageController::show` must stay **last** in `Routes.php`.

### CSRF Exceptions (`Config/Filters.php`)

These receive external/server POSTs without CSRF tokens and are excluded:
- `api/*`
- `payment/callback/*` (PG server callbacks)
- `board/image-upload`
- `admin/media/upload`

### PG Payment Layer

`PGInterface` defines `buildPaymentParams()`, `confirm()`, `cancel()`. `PGFactory::create(string $provider)` resolves the adapter. Keys live in `Config/PG.php`, all read from `.env`.

| Provider key | Adapter | PG |
|--------------|---------|----|
| `bank_transfer` | `BankTransferAdapter` | 무통장입금 |
| `toss` | `TossPaymentsAdapter` | 토스페이먼츠 |
| `inicis` | `InicisAdapter` | KG이니시스 |
| `nicepay` | `NicePayAdapter` | 나이스페이 |
| `kakaopay` | `KakaoPayAdapter` | 카카오페이 |
| `naverpay` | `NaverPayAdapter` | 네이버페이 |
| `payco` | `PaycoAdapter` | PAYCO |

### Stock Management

**Rule: stock is only decremented at PG success callback (or admin bank-transfer confirm). Never at cart-add time.**

`OrderModel::confirmPaid()` / `confirmBankTransfer()` use a two-layer concurrency guard inside a transaction:
1. `SELECT stock ... FOR UPDATE` — row-level lock
2. `UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?` — conditional update; 0 affected rows = rollback

`payments.pg_tid` has a UNIQUE constraint — duplicate PG callbacks are silently rejected. Adjustments are audited in `stock_logs`.

Order status flow (single-direction, enforced in `OrderModel::updateStatus()`):
```
pending → [PG paid] → paid → preparing → shipped → delivered
pending → [bank transfer] → awaiting_payment → [admin confirm] → paid
pending (not confirmed within 30 min) → expired           (no stock was held)
paid/preparing → cancelled                                 (restores stock)
refund_requested → refunded

delivered → [member, within 7 days] → return_requested
    → [admin approve] → return_approved → [confirm refund] → refunded
    → [admin reject]  → delivered

delivered → exchange_requested
    → [admin approve] → exchange_approved → exchange_completed
```
`delivered_at` is set on transition to `delivered`; return/exchange window is 7 days from it (null = legacy orders, always allowed).

### AI Operations Layer

AI features run through `AiProviderInterface` (`app/Libraries/AiProvider/`), which exposes:
`suggestCategories`, `generateDescription`, `generateQnaAnswer`, `summarizeReviews`, `classifyInquiry`, `generateInquiryReply`, `generateSalesReport`, `generateRestockMessage`, `expandSearchQuery`.

- **Provider selection**: `settings['ai_provider']` (falls back to `env('AI_PROVIDER', 'groq')`). Supported: `groq` (`GroqProvider`), `claude` (`ClaudeProvider`), `openrouter` (`OpenRouterProvider extends GroqProvider`). API keys come from settings first, then env (`GROQ_API_KEY`, `OPENROUTER_API_KEY`, etc.).
- **Async jobs**: long-running AI work is queued in the `ai_jobs` table and processed by `php spark ai:work` via `AiJobRunner`. Registered handlers: `review_summary` (`ReviewSummaryHandler`), `inquiry_classify` (`InquiryClassifyHandler`). `AiCache` memoizes results; `AiPrompts` holds prompt templates.
- **Higher-level services** (`app/Libraries/`): `AiCategoryAdvisor`, `RecommendationService`, `SemanticSearchService`, `RestockSuggestionService`, `OrderAnomalyService`, `NaverShoppingProvider`, `SeoHelper`.

### Member Grade / Coupon / Point System

- `GradeService` — grade tiers and upgrades (`grades:upgrade` recalculates; `AddGradeSystem` migration).
- `CouponService` — coupon issue/redeem (`coupons`, `user_coupons`); birthday coupons via `coupons:birthday`.
- Points — `point_logs` (earn/use/refund/cancel/admin), `users.point_balance`.

### Social Login (OAuth)

`AbstractOAuthProvider` base with `GoogleProvider`, `NaverProvider`, `KakaoProvider`. `OAuthFactory::create(string $provider)` resolves the provider. Keys in `Config/OAuth.php` (and `Config/Naver.php`), read from `.env`.

### File Uploads

| Class | Usage |
|-------|-------|
| `FileUploader` | Board post attachments — extension whitelist, 10 MB max, random hex filenames |
| `ImageUploader` | Banner / popup / product images — image-only, size-limited |
| `MediaUploader` | Admin media library — drag-and-drop, stores path in `media` table |

### Caching Strategy

CI4 file cache is used for:
- `site_settings` — all settings key-value map (`SettingModel`)
- `nav_menus` — menu tree (`MenuModel`)
- `active_banners_{position}` — banners by position (`BannerModel`)
- `active_popups` — active popups + page URL mappings (`PopupModel`)

Model callbacks (`afterInsert/Update/Delete`) invalidate the relevant cache key on admin write. Banner/popup expiry is checked in PHP against cached data — no time-based invalidation needed.

### DB Schema Summary

```
users                — member/admin roles, social login fields, grade, point_balance
settings             — key-value site config (active_theme, ai_provider, smtp, schedule_*, etc.)
menus                — 2-level navigation tree
pages                — slug-based dynamic pages
boards / posts / post_files / post_comments   — board system
inquiries            — contact form (+ AI triage columns)
banners / popups / popup_pages                — marketing overlays
media                — media library
categories           — product categories (parent_id hierarchy)
products             — price, discount_price, stock, status, shipping_*, supplier_fk, is_featured
product_images       — multiple images per product, is_primary flag
product_options / product_skus                — option combinations & SKUs
product_reviews      — reviews (is_hidden, is_negative); AI summaries
product_qnas         — product Q&A
cart_items           — user_id OR session_id (guest carts)
wishlists            — saved products per user
orders               — header, status, shipping snapshot, delivered_at, return/exchange fields
order_items          — product snapshot at order time
order_status_logs    — status change audit (admin/member/system)
order_memos          — admin internal memos
exchange_items       — exchange line items
shipping_addresses   — saved addresses per user
payments             — pg_tid UNIQUE, raw PG response as JSON
stock_logs           — inventory adjustment audit
restock_alerts       — restock notification requests
coupons / user_coupons                         — coupon system
point_logs           — point earn/use/refund/cancel
promotions           — promotion campaigns
suppliers            — supplier/business info
access_logs / access_log_summaries             — visitor analytics
ai_jobs              — async AI job queue
```

## Coding Standards (project-wide)

- PHP 8.1+ (typed properties, match, arrow functions); PSR-12.
- Models extend `CodeIgniter\Model` with explicit `$allowedFields`.
- Views use native alternative syntax (`<?php if (): ?> … <?php endif; ?>`) and `esc()` for all output.
- Inputs via `$this->request->getPost()`; validate with `$this->validate()` before processing.
- All POST/PUT/DELETE forms include `<?= csrf_field() ?>` (except the CSRF-excluded routes above).
- DB access via Query Builder only — no raw string-concatenated SQL.
- No hardcoded secrets — use `env()` / Config classes. Never stage `.env`.
