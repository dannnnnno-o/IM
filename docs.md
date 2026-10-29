# J-Budget — Development Notes

Personal budget tracker. University of Caloocan City, Computer Studies Department,
Information Management project. Submitted by **Daniel Z. Baladad**.

Single-account context. Currency is **PHP (₱)**. Amounts are integers only.

> Last updated: **27 Oct 2026 (evening)** · Status: **Every feature from the
> proposal is live**: the **Landing page** (first-visit name prompt,
> Sprints 7–8) at `/`, Dashboard (Sprint 9: today-only income/expense
> stat cards + "Set a goal" deep link), "Make Transaction" (Sprint 10:
> balance floor on expenses), History (period picker +
> Income/Expense/**Goal** type filter), Summary (**sectioned**: Income
> Summary on top / Expense Summary below + Net card, two donuts),
> Categories endpoint, and **Goals** (single route `/goals.php`: On-going /
> Completed card sections, card-state-machine actions; invest floor in
> §8). The DB was wiped and re-seeded fresh 26 Oct, then loaded with the
> human's real demo data — see the Sprint 7/9 state notes. See §14 for
> what remains deferred and §0 for session history.

---

## 0. Session handoff — read me first (grown 23–26 Oct 2026)

For the agent picking this up: sprints 1–7 are the dated log of everything
that shipped, newest at the bottom. The list of *what shipped* doubles as
the list of *what may surprise you*. Start at **Sprint 10**, then
**Sprint 9**, then **Sprints 6–8** (26–27 Oct) — they changed the most —
then read **Current state at session end** below it.

### Sprint 1 — Summary route + categories
- `/summary.php` (donut + breakdown) and `/categories.php` (POST-only JSON
  endpoint, 405 on GET) are LIVE. Do not "rediscover" them as missing.
- **The transaction's label is its category.** (Sprint 2 made this literal,
  see below.) Predefined categories are a const in `model.php`
  (`PREDEFINED_CATEGORIES`); custom ones are a JSON array in
  `users.categories` — accessed ONLY via `get_custom_categories()` /
  `save_custom_category()` (a later localStorage migration is planned; keep
  access confined).

### Sprint 2 — description column REPLACED by category
- `transactions.description` is GONE. `category` is `VARCHAR(30) NOT NULL`
  and is the one logical label field. If you find old references to a
  description field anywhere, they are bugs.
- Existing rows were migrated 1:1 (each distinct old description seeded as a
  custom category through the live endpoint, then copied). Failure backup:
  `/tmp/opencode/jbudget-backup-pre-category-migration.sql`.
- **QA cleanup convention changed**: tag test rows with category `QA Test`
  and delete via `WHERE category = 'QA Test'` — never by id range (the old
  `description LIKE 'QA %'` trick is impossible now).

### Sprint 3 — period pickers (Day/Week/Month) + cache fix
- Daily/Weekly/Monthly pickers (labeled ranges like "Oct 19 – Oct 25, 2026",
  latest-first, NO future periods, expandable year navigation `‹ 2025` /
  `2026 ›`) are a SHARED component:
  - `resolve_period()` in `model.php` — single parse/validate source.
  - `partials/period-picker.php` — single markup/JS source, consumed by both
    `summary.php` and `history.php`.
  Only ONE active view's picker param gets validated per request, but the
  rendered lists come from the post-cap generation in that function. Change
  picker behavior in ONE place, verify BOTH pages.
- History previously had `From`/`To` date inputs — they were **deliberately
  removed** (user decision) and replaced by the same pickers with an
  "All Time" default. Do not reinstate a range input without asking.
- `partials/header.php` has a `CSS_VERSION` const appended to
  `/styles/global.css?v=…`. **Bump it whenever global.css changes** — a stale
  cached stylesheet made the donut "not load" for the human (curl never
  caches, so automated checks miss it).

### Sprint 4 — Goals route (24 Oct 2026)
- `/goals.php` + `/goal-details.php` are LIVE; sidebar no longer shows SOON.
  (Sprint 6 later removed `goal-details.php` — this bullet is the 24 Oct log.)
- Design decision of record: **invest/take back write REAL transactions**
  (mode `-`/`+`, `goal = 1`, category `Goal Investment`, auto comment) +
  a signed `goal_investments` ledger row + the `goals.investment` aggregate,
  all in one atomic write. Take back is partial, capped at net invested.
- New table `goal_investments` (no FKs, house style). Frequent traps:
  the QA cleanup order for goals (§11) and the delete-keeps-transactions
  semantics (§6c).

### Sprint 5 — polish pass (26 Oct 2026)
- The Update Balance page was **renamed to "Make Transaction"** in the
  sidebar tab, page heading and browser title, and on 26 Oct the route file
  itself became **`/make-transactions.php`** (git mv, all references
  updated). Grep before assuming "Update Balance" wording is a bug.
- The mode select's green/red tint (`mode-income` / `mode-expense` CSS +
  paint JS) was REMOVED by user decision: every dropdown on the site is now
  the same neutral dark style. `CSS_VERSION` bumped to `20261026-1`.

### Sprint 6 — Goals 2.0 + History Goal tab (26 Oct 2026)
- tasks.txt + `goal-components/` mockups (goal-cards, flow, modal-prompts,
  UI) drove a full goals revision: `goal-details.php` REMOVED (all actions on
  the `/goals.php` card state machine), explicit completion via Confirm
  modal (`completed` int + `date_completed` DATE, sticky), delete blocked
  while money is invested (hard stop + hover hint), edit undercut rule,
  On-going/Completed section split on the list.
- History Type filter gained the "Goal" tab (`type=goal` → `goal = 1`,
  mode-unrestricted); comment rows render auto-expanded in the Goal tab
  because goal=1 can't name the goal — comments carry attribution
  ("Invested in <name>"). Per-goal ledger UI removed.
- Summary tab split into Income Summary (top) / Expense Summary (bottom),
  the Goals-style section convention: each with its own hand-rolled donut
  ("Total Income" / "Total Expenses" centres, `--chart-1..8` tokens),
  breakdown table (`stat-income` green / `stat-expense` red), and its own
  empty state — both always render. Goal transfers included by the locked
  24 Oct design (invest = expense side, take back = income side). A Net
  card (income − expense, green/red by sign) closes the page — trial per
  user request 26 Oct, keep/remove at their review. `expense_by_category()`
  became mode-parameterised `sum_by_category()` (one shape, two calls).
  Weekly/monthly checks + Sep-2026 empty path verified; no CSS change.
  **Donut stacking regression fixed 26 Oct** (found via user screenshot
  `income_donut_bug.png`): the inherited offset recursion double-negated on
  every second slice, displacing small slices onto the main arc (read as a
  "split ring") — replaced with an explicit cumulative walk
  (`offset = -(length so far)`) plus last-slice compensation
  (`length += circumference - cumulative`) so the ring always closes
  exactly. Verified by asserting from the live DOM that offsets are
  contiguous and `Σlength == circumference` for both donuts; Daily view
  (1 slice) and the Sep-2026 empty month re-checked. Percentages render at
  **fixed two decimals** (Summary Share column + donut arias; Goals cards'
  progress text/bar/aria — `goal_share()` now returns a 2dp float) per
  user request the same evening — e.g. Lottery 99.42%, micro shares like
  Grocery 0.25% stay readable instead of flattening to 0%.
- Tooltip polish (26 Oct): all card disabled-button hints upgraded from
  native `title` to wrapper-span Bootstrap tooltips (CDP-verified hover +
  hide); the completed goal's Delete tooltip worded "This goal is already
  completed." like the card's other locked buttons (server matches), while
  ongoing cards keep the funds reason. Edit disabled on completed cards
  with the same server-side stop ("That goal is already completed.").
- Infra fix (26 Oct): server timezone skew — XAMPP's stock
  `date.timezone=Europe/Berlin` made PHP's "today" lag ~7h, so the pickers
  showed yesterday between local midnight and ~7–8am. `/opt/lampp/etc/php.ini`
  → `Asia/Manila` (both module + LAMPP CLI; MariaDB's `SYSTEM`/HKT was
  already right), Apache restarted via sudo. Full story: §9 gotcha 3.
- QA'd end-to-end through real POSTs (state machine matrix, blocked delete,
  complete flow with date, History Goal tab) and fully restored (drift 0).
- **Git state (for the next agent)**: the human committed the Goals 2.0
  work themselves (`8afdd20 finished implementing goals`); the Summary
  split + donut fix + 2-decimal shares + this evening's fixes are
  **uncommitted** in the working tree (`summary.php`, `model.php`,
  `goals.php`, `docs.md` — plus the human's own `tasks.txt` edits and
  `income_donut_bug.png`, which is the user's untracked bug screenshot,
  theirs to keep or delete). Follow the human's commit convention — commit
  their work only if asked.

### Sprint 7 — Landing page + fresh DB (26 Oct 2026, newest)
- tasks.txt's last build item — "Landing page (first visit, user gets
  prompted for name)" — is DONE; the §6 "No Landing Page" decision was
  overturned with the user's blessing. `/` is the landing **only while
  `users.username` is empty/blank**: a centered name-prompt card that
  self-POSTs, saves via `UPDATE users SET username …`, and 302s to the
  dashboard. Once named, `/` returns to the old behavior (302 straight
  to the dashboard) — even a POST to `/` redirects **before** POST
  handling, so renaming through the landing is impossible once the gate
  is closed. `dashboard.php` carries the same gate (unnamed direct visit
  → 302 `/`) so the prompt can't be URL-bypassed; other routes don't
  read the name and need no gate.
- The gate rule is single-sourced: `username_is_missing()` in model.php
  (the `resolve_period()` convention), writes only via `save_username()`
  — prepared UPDATE of `users.username` ONLY; balance/categories are
  untouched. Cap `MAX_USERNAME_LENGTH = 50` (matches `varchar(50)`);
  messages "Name is required." / "Name must be 50 characters or fewer."
  (full table in §8). Name is stored plaintext (like categories) and
  escaped at render — an XSS payload as the name was QA'd and renders
  inert in the dashboard greeting.
- **Still no sessions/cookies (§10 posture holds)**: "first visit" is
  literally the username column being empty. Re-demo trick:
  `UPDATE users SET username = '' WHERE id = 1;`
- **DB wipe + re-seed completed this session** (the human cleared the
  tables between sessions; the agent finished the job): TRUNCATE on
  `transactions` / `goals` / `goal_investments` / `archived_goals` (id
  counters reset to 1 — the DELETE-style clear had left them at
  40/9/11), the `users` row id=1 re-seeded (balance 0, username '',
  categories '[]'), and one **orphaned `goal_investments` ledger row**
  (goal 1's +₱200; its goal and transaction were both already deleted)
  removed. Two ripple findings from the half-cleared state, general
  lesson since the human will wipe again before submission: (1) the
  users row must EXIST before any transactions are created — its row is
  assumed by every route's `get_user($pdo, 1)` and by the balance
  UPDATE inside `create_transaction()` (missing row ⇒ balances silently
  stop updating and a later re-seed with balance 0 while rows exist
  manufactures instant drift); (2) the goal delete order in §11 applies
  to manual wipes too — after any wipe, re-run the §15 goals-sanity
  equation (`ledger_net` must equal `goal_txn_net`; a lone leftover
  ledger row breaks it and trips the check).
- New table `archived_goals` exists (columns mirror `goals`) — the
  human's prep for the §14 archive-optional; no PHP references it yet,
  it is inert. Don't mistake the goals model for duplicated work and
  don't "rediscover" it as a bug.
- CSS_VERSION bumped to `20261026-2` (added a `.landing-*` block to
  global.css: full-viewport centered card, no sidebar). QA'd: curl
  matrix (unnamed/named 200s+302s, empty + 51-char POSTs re-render with
  errors and no write, valid POST persists, rename-through-landing
  blocked), drift query 0, headless-Chromium screenshots of the landing
  at 1280×900 and 420×850 (both clean).

### Sprint 8 — Landing 2.0, mockup adoption (26 Oct 2026, newest)
- The human's `landing-page.png` (repo root, untracked — theirs to keep or
  delete) drove this revision: a wallet-icon tile + "J-Budget" wordmark +
  "Track · Save · Achieve" tagline above the prompt (kills the earlier
  "feels empty" note — the card now has a real identity stack), prompt
  copy "What should we call you?", an inline person glyph inside the
  input, and a **Confirm-before-lock modal**. The mockup's black wallet
  became `--brand-text` line-art on a `--bg-input` tile (user said pick
  what complements the app; the tile mirrors the dashboard's
  `.balance-icon` pattern), and the modal is the app's standard dark
  Bootstrap modal titled "J-Budget" per user decision.
- Modal behavior: Confirm opens it with the typed name interpolated via
  `textContent` (XSS-safe — never innerHTML), copy verbatim from the
  mockup ("Note that at this part of development, the name cannot be
  changed yet. Proceed with the name <N>?"), **Go Back** dismisses (input
  keeps its value), **Proceed** submits the same once-only gated POST as
  Sprint 7. Purely client-side — zero server change, no rename feature
  added; the modal just documents the first-visit lock in-product.
  Empty-name submits skip the modal and POST straight through so the
  server renders "Name is required." (§8).
- **Trap hit and fixed (26 Oct)**: the first version wired the confirm JS
  at script-parse time and touched `bootstrap.Modal` before the bundle
  (footer.php) had loaded → `ReferenceError` killed the whole listener →
  Confirm fell through to a NATIVE submit and saved the name directly
  (the exact "bundle loads after page scripts" trap documented in §6c).
  Fixed by wrapping the wiring in a DOMContentLoaded listener — the same
  house pattern as the goals pages — and creating the Modal instance
  lazily inside the submit handler. Never touch `bootstrap.*` in a
  script that runs at parse time.
- CSS_VERSION → `20261026-3`. Verified with a recreated CDP script
  (`/tmp/opencode/landing-confirm.mjs`, the §13 template rebuilt from
  scratch after the reboot): modal visible + name interpolated
  (screenshot at `/tmp/opencode/landing-modal.png`), Go Back → closed,
  still unnamed, input kept; Proceed → dashboard greeting; drift 0.
  After QA, `users.username` was restored to '' — the landing is live
  for the demo, as decided in Sprint 7. (Superseded 27 Oct: the human
  renamed the account to 'Dan' and exercised the live flow — see the
  Sprint 9 state note.)

### Sprint 9 — Dashboard stat cards + goals deep link (26–27 Oct 2026, newest)
- Dashboard stat cards (the row under the balance card) changed from
  "This Week/Month · Expenses" to **Today · Income** (now green,
  `stat-income`) and **Today · Expenses** (red, `stat-expense`), each
  dated `$today->format("F j")` — "October 27" style, **month + day
  only, no year** (user spec). The `week_range`/`month_range` calls
  left dashboard.php; Summary/History pickers keep their own logic via
  `resolve_period()`, untouched.
- Total Goals card message: the human's own `goal_msg` switch (0 goals
  → anchor "Set a goal", 1 → "Keep working towards your goal :>",
  2+ → "toward your goals :>") — kept verbatim as user-authored code.
- **"Set a goal" deep link**: the anchor targets `/goals.php?set=goal`;
  goals.php arms the existing `$reopenGoalModal` flag on that GET, so
  the Goals tab lands with the Set/Edit modal already open ("Set New
  Goal" title, empty fields). `history.replaceState` strips the param
  once the modal opens so a refresh stays quiet; unknown `set` values
  are ignored; the flag is GET-only, so a failed goal_save POST
  re-renders without the param and the two reopen paths can't interact
  (regression-verified via curl: invalid goal_save still re-opens with
  "Name is required."). No CSS change — CSS_VERSION stays `20261026-3`.
- QA straddled the reboot. On the 26th the card math was proven with
  real POSTs: seeded QA income 500 + expense 200 on top of the human's
  own +181 Allowance read Today's Income **₱681** / Expenses ₱200.
  After the human's own cleanup (QA rows + "QA Pass" username removed,
  account renamed 'Dan'), the 27th re-check showed both Today cards at
  **₱0 with "October 27"** labels while the Oct-26 rows stayed in
  Recent — the today-only semantics exactly as specced. The deep link
  was proven in-browser via CDP pre-reboot (goals page lands with the
  modal open, param stripped, fields empty); /tmp then took the
  screenshot and both scratch scripts with it — the /tmp mortality
  warning in §13 has now bitten twice; copy any test script to a
  versioned location if it should outlive a reboot.
- House QA note (27 Oct update): `peso()` USED to render `&#8369;`
  entities — greps against those still describe older screenshots and
  served-HTML facts; since the Sprint 10 fix it emits the raw ₱ glyph,
  so current QA greps match `₱NNN` instead.

### Sprint 10 — Balance floor + peso() glyph fix (27 Oct 2026 evening, newest)
- Bug from the user: an invest "input" LARGER than the current balance
  still invested and the dashboard rendered a NEGATIVE balance (the
  invest path's only cap was the goal's own target; `users.balance` was
  never considered). The fix is a stated invariant: **no write may take
  `users.balance` below zero.** Two route-level guards, one branch each:
  goals.php's invest arm (after the target-lock check) and
  make-transactions.php's expense arm (`mode '-'`; income and goal
  take-back add money and are never floored) — both read the pre-POST
  `users.balance` already loaded by `get_user()` (single account, no
  race), no model change. Card disabled states stay keyed on the goal
  state machine (the take-back cap precedent: server-side rejection IS
  the UX). Messages mirror the take-back cap family: "You can invest at
  most ₱{balance}." / "You can spend at most ₱{balance}." — the figure
  doubles as a live cap readout (balances at ₱0 now read "…₱0.").
- **peso() glyph fix (27 Oct)** — found while asserting the new floor's
  message: `peso()` used to emit the TEXT `&#8369;`, and inside
  `htmlspecialchars()`ed contexts (error alerts) that double-escapes, so
  the take-back cap message had ALWAYS rendered the literal string
  '&#8369;0.' in the browser since Sprint 6 (confirmed live byte-for-byte
  before fixing). `peso()` now returns the actual UTF-8 ₱ glyph
  (`"\u{20B1}" . number_format(...)`), which escaping passes through
  untouched; direct-HTML call sites (balance card, breakdowns) were
  already correct and stay visually identical. One line in model.php.
- QA on the live data, restore exact (§11): QA goal (target 200) —
  invest 100 (>36) → re-render "You can invest at most ₱36." NO WRITE;
  invest 36 (== balance) → 302, balance 0, ledger 36; invest 1 → "…₱0."
  NO WRITE; take back 36 → restored; MT: expense 50 → "You can spend at
  most ₱36." NO WRITE; expense 30 → saved; income 100 → saved
  (unfloored). Cleanup: goal deleted app-side first, QA rows removed by
  the category/comment markers, balance compensated by the exact QA net
  (+70), categories blob restored to `["Allowance"]`. Result: the
  human's six real rows untouched, **drift 0**.

### Current state at session end
- DB right now (27 Oct 2026 morning, after the human's own demo session):
  `users` id=1 — username **'Dan'**, balance 36, categories
  `["Allowance"]`. `transactions` 6 rows, all dated 2026-10-26: +181
  Allowance, −25 Transport, −100 Other, −20 Food, and a −250/+250 Goal
  Investment pair carrying `goal=1` with comments "Invested in / Taken
  back from GPU · GTX 1660" — the human ran the real goal flow, then
  deleted the goal, so `goal_investments` is empty while those two
  transactions stay in history; that is the documented
  delete-keeps-transactions semantics working live, and the §15 goals
  sanity equation holds at 0 = 0. `goals`, `goal_investments`,
  `archived_goals` empty. **balance drift 0**. The landing is NOT
  visible on `/` while 'Dan' is set — clear `users.username` to re-demo
  (trick documented in §6). Historical numbers in older bullets
  describe earlier states; trust the live §15 queries as the demo
  data evolves — snapshot before QA cycles and restore exactly after.
- Verified working: routes 200/302 (goal-details 404), landing gate
  (unnamed `/` 200 + dashboard 302; named, both reverse; POSTs to `/` while
  named redirect before write), donut math asserted
  from the served DOM (offsets contiguous, `Σlength == circumference`,
  both donuts, Daily 1-slice case, empty month), 2-decimal share formats
  every place percentages render (Summary Share column + donut arias,
  Goals cards' progress text/bar/aria — `goal_share()` now returns a 2dp
  float), tooltip hover/hide via CDP, picker validation (errors for non-
  listed dates/weeks/months + future periods), History pagination carrying
  `view`/`week`/`month`/`year`/`type`, full goal lifecycle QA'd through
  real POSTs (create → invest → over-cap rejection → take back → complete
  → frozen-card checks → delete-block → cleanup, balance restored to
  baseline, drift 0). Formulas for QA testing in §11.
- Test tooling: the CDP pattern is written as **`/tmp/opencode/hover-tip.mjs`**
  (Node 26 built-in WebSocket → headless Chromium `--remote-debugging-port=
  9222`: createTarget/attach → navigate → Runtime.evaluate + real mouse
  events → screenshots). It currently hovers the blocked-delete tooltip and
  is a reusable template for any hover/click/assertion check. **/tmp does
  not survive reboots** and the older `cdp-test.mjs` is gone — copy what
  you rely on to a versioned location if you want it kept.

---

## 1. Quick start

Everything is served by LAMPP at `http://localhost/`. `/` redirects to
`/dashboard.php`.

```bash
cd /opt/lampp/htdocs
php -l <file>            # lint one file
mysql -h 127.0.0.1 -u user jbudget   # DB shell (no password)
```

> **MariadB CLI note:** the `mysql` client prints a deprecation warning
> ("use /usr/bin/mariadb instead") — harmless, ignore it.

---

## 2. Tech stack

| Layer | Choice | Version |
|---|---|---|
| Front-end | HTML, Bootstrap 5, vanilla JS | Bootstrap **5.3.8** |
| Back-end | Vanilla PHP | **8.2.12** (server) / 8.5.10 (CLI) |
| Database | MariaDB via PDO | **12.3.3** |
| Node | npm, only used to install Bootstrap | 26.8.1 / npm 12.2.0 |

> **PHP version gotcha:** the Apache module is **8.2.12**, but `php -l` on the CLI
> runs **8.5.10**. Code using 8.3+ syntax will pass lint and then fatal in the
> browser. Keep to 8.2-compatible syntax.

### Bootstrap install pattern

`npm install bootstrap@5` puts Bootstrap in `node_modules/`, but **the app never
references `node_modules/`**. Only two files are copied to `vendor/bootstrap/`:

```
vendor/bootstrap/bootstrap.min.css
vendor/bootstrap/bootstrap.bundle.min.js    <- "bundle" includes Popper, needed
                                                 for dropdowns/modals/tooltips
```

`node_modules/.htaccess` contains `Require all denied` and returns **403**, so
package source is not web-exposed even though it sits inside the docroot.

To upgrade Bootstrap later:

```bash
npm install bootstrap@5
cp node_modules/bootstrap/dist/css/bootstrap.min.css   vendor/bootstrap/
cp node_modules/bootstrap/dist/js/bootstrap.bundle.min.js vendor/bootstrap/
```

`package.json` pins the version so this is reproducible.

---

## 3. File layout

```
/opt/lampp/htdocs/
├── index.php            route: Landing (name prompt) while users.username is
│                          empty (§6); 302 → dashboard.php once named
├── dashboard.php        route: dashboard
├── make-transactions.php route: "Make Transaction" entry form (+ Add Category
│                         modal) — renamed from update-balance.php 26 Oct 2026
├── history.php          route: filterable, paginated history
├── summary.php          route: expense donut + category breakdown
├── categories.php       JSON endpoint: POST-only, saves a custom category
├── goals.php            route: goal list (progress cards, Set/Edit modal)
├── goals.php            route: goal list + ALL goal actions on the cards
│                         (invest/take-back/edit/delete/complete; no detail page)
├── db.php               PDO connection
├── model.php            all queries + formatting helpers
├── partials/
│   ├── header.php       <head>, asset links, <body data-bs-theme="dark">
│   ├── sidebar.php      nav, driven by $activePage
│   ├── footer.php       bundle script, closes document
│   ├── period-picker.php shared Daily/Week/Month (+All Time) picker used by
│   │                     summary.php and history.php (consumes resolve_period())
│   └── goal-modals.php  shared Set/Edit Goal + Delete Goal modals used by
│                         goals.php
├── styles/global.css    ALL custom styling + design tokens
├── vendor/bootstrap/    the only web-served Bootstrap files
└── node_modules/        npm working dir (403-locked)
```

**Asset load order matters.** `global.css` must load *after* Bootstrap:

```html
<link rel="stylesheet" href="/vendor/bootstrap/bootstrap.min.css">
<link rel="stylesheet" href="/styles/global.css?v=<?= CSS_VERSION ?>">
```

`CSS_VERSION` (const in `partials/header.php`) is appended to the URL so
cached browsers refetch. **Bump it every time `global.css` changes** — 
a stale cached stylesheet is invisible in curl tests (curl never caches) but
breaks real browsers: the Summary donut once "vanished" this way because the
`--chart-*` tokens didn't exist in the visitor's cached copy. First seen
23 Oct 2026.

---

## 4. Database schema

### `transactions`
| Field | Type | Notes |
|---|---|---|
| `id` | int PK | |
| `date` | date | defaults `curdate()` |
| `amount` | int | whole numbers only |
| `mode` | varchar | **stores `+` or `-`**, *not* "Income"/"Expense" |
| `category` | varchar(30) NOT NULL | **the transaction's label IS its category** (replaced the old free-text `description` column, 23 Oct 2026). Must be a name from predefined ∪ custom. |
| `goal` | int | default `0` = not goal-linked |
| `comment` | text NULL | long-form note, **optional** |

### `users`
| Field | Type | Notes |
|---|---|---|
| `id`, `balance`, `username` | | `pin` column was removed; table is effectively a single-row config |
| `categories` | varchar(2500) NULL | **JSON array** of custom category names. Read/written ONLY by `get_custom_categories()` / `save_custom_category()` in `model.php`. Currently a stop-gap until categories move to browser localStorage — keep access confined to those two functions so the swap stays contained. |

### `goals` (activated 24 Oct 2026; revised 26 Oct 2026)
`id, name, target_amount, target_date (varchar), investment, completed (int, 0/1, default 0), date_completed (DATE NULL)`

> `target_date` is `varchar` holding junk values (`"Bukas"`, `"November 5, 2026"`).
> The proposal allows this — "No Date Validation for Goals" is a stated
> limitation. `goals.investment` is a **maintained aggregate** (net invested,
> updated by `record_goal_investment()` in the same write as the ledger).
> `completed` is set ONLY by the Confirm Completion flow — reaching 100%
> invested does not complete a goal; `date_completed` records the day of
> `mark_goal_complete()`.

### `goal_investments` (added 24 Oct 2026)
`id, goal_id (int), transaction_id (int), amount (int SIGNED), created_at (DATETIME default CURRENT_TIMESTAMP)`

- One row per invest (+amount) / take-back (−amount) event.
- `transaction_id` links to the REAL transaction the event wrote (mode `-` on
  invest, `+` on take back, `goal = 1`, category `Goal Investment`).
- **No foreign keys** — house style (matches `transactions.goal`). Goal delete
  removes its ledger rows app-level inside `delete_goal()`'s transaction;
  goal-related transactions deliberately stay in history.
- Deleting transactions manually (e.g. QA cleanup) can leave orphaned ledger
  rows ONLY if done wrong — follow the §11 order (goal first, then txns).

---

## 5. Design system

All colours live as CSS custom properties at the top of `styles/global.css`.
They were sampled from `wireframe.png` pixel-by-pixel, not eyeballed.

```css
--bg-app:#02182F;   --bg-sidebar:#031F3C;  --bg-card:#06203E;  --bg-input:#0A2F55;
--border-subtle:#164A7D;
--brand:#0062E0;    --brand-text:#6BB6FF;  --brand-ink:#FFFFFF;
--income:#06BB86;   --expense:#FF6B7F;     --expense-solid:#DE475A;
--balance-accent:#00D8C4; --balance-tile:#014549;
--amber:#EDAF36;   --purple:#38307B;      --text:#E8F1FA;  --text-muted:#9CB8D4;

/* Summary donut series (added 23 Oct 2026), picked round-robin by list order */
--chart-1:#EDAF36; --chart-2:#6BB6FF; --chart-3:#8B7CF6; --chart-4:#5AD0F0;
--chart-5:#F0854F; --chart-6:#5E8BEF; --chart-7:#B39DDB; --chart-8:#8CA6C9;
```

Theming works via `<body data-bs-theme="dark">` plus overriding `--bs-*`
variables. That means every Bootstrap component (modals, dropdowns, alerts)
inherits the theme without hand-styling.

**Colour rules that are now load-bearing — do not break them:**
- **Green = Income only.** Red = Expense only. Never green for "this number is
  small", never red for a neutral action. (The mode select once carried a
  green/red tint for the chosen side — removed 26 Oct 2026 by user decision;
  all form dropdowns are now the shared neutral style. Green/red still appear
  only on income/expense DATA via `.stat-income` / `.stat-expense`.)
- Balance uses the teal `--balance-accent`, which is a *different* green from
  `--income`. Intentional; reads as the same family.
- `.btn-outline-danger` (red) is reserved for destructive actions, so red never
  ambiguously means both "you spent money" and "this deletes".
- **The donut series deliberately excludes `--income`, `--expense` and
  `--balance-accent`.** Slices are expenses, but a green slice reads as
  "income" and red-family colours collide with the expense amounts in the
  breakdown table. The chart palette stays in the amber/blue/purple
  neighbourhood.

Contrast was verified to WCAG: body text 14.33:1 (AAA), muted 7.96:1 (AAA),
income 6.59:1 (AA), expense 5.97:1 (AA), balance teal 9.05:1 (AAA).
Two colours were deliberately corrected from the wireframe: brand blue
`#006FFD`→`#0062E0` and expense red `#DE475A`→`#FF6B7F`, both because the
originals failed AA as text.

---

## 6. Routes

| Route | Status | Notes |
|---|---|---|
| `/` | done | **Landing page** (Sprint 7): first-visit name prompt while `users.username` is empty; 302 → dashboard once named |
| `/dashboard.php` | done | balance card, **today** income/expense stat cards (Sprint 9), goal count + the user's Set-a-goal message, recent 5 |
| `/make-transactions.php` | done | "Make Transaction" (renamed 26 Oct 2026, ex update-balance.php). POST form → dashboard redirect; category picker + Add Category modal |
| `/history.php` | done | shared period picker (All Time default + Daily/Week/Month), type filter, pagination, expandable comments |
| `/summary.php` | done | Daily/Weekly/Monthly toggle, date picker, sectioned Income (top) / Expense (bottom) summaries — each with its own donut + breakdown table — plus a Net (income − expense) card; goal transfers included in both |
| `/categories.php` | done | JSON endpoint. POST `name=` → 201/400; GET → **405** |
| `/goals.php` | done | goal list (On-going/Completed sections) + ALL actions on cards: invest, take back, complete (confirm modal), edit, delete (blocked while invested); dashboard's "Set a goal" deep-links via `?set=goal` → Set modal opens immediately (Sprint 9) |

**Landing page (Sprint 7, 26 Oct):** the earlier "No Landing Page" decision
was overturned once the panelist expectation became concrete (tasks.txt's
final build item). Rules of record:

- `/` shows the name prompt **only while `users.username` is empty/blank**;
  after a valid POST it redirects to the dashboard, and from then on `/`
  is a plain 302 again (the same unconditional redirect as pre-Sprint 7) —
  the app's day-one behavior is unchanged after the first visit.
- `dashboard.php` gates the same way: an unnamed direct visit 302s to `/`.
  Other routes never read the name, so they intentionally stay ungated.
- The gate is `username_is_missing()` in model.php — single rule, two
  consumers (index.php + dashboard.php). Writes go through
  `save_username()` (prepared UPDATE of username only).
- Still **no sessions/cookies anywhere** (§10 posture holds): "first
  visit" is literally an empty column. To re-demo the landing:
  `UPDATE users SET username = '' WHERE id = 1;`
- POST handling runs only behind the open gate — once named, `/` redirects
  before reading POST, so the landing cannot be used to rename. Renaming
  is a SQL-only path by design (no profile/renaming feature exists).
- **Confirm-before-lock modal (Sprint 8, from `landing-page.png`)**:
  Confirm opens the app's standard dark modal with the typed name
  interpolated via `textContent` and the mockup's "cannot be changed yet"
  copy verbatim; Proceed submits the same gated POST, Go Back dismisses
  with the input kept. Client-side only — the server never sees it, and
  a client-side wrapping cannot rename anything (the POST stays once-only).
  Empty names skip the modal so the server prints "Name is required."
  The confirm JS must wire up inside DOMContentLoaded (house pattern —
  the bundle loads after page scripts; see Sprint 8's trap note).

## 6b. Categories design (updated 23 Oct 2026)

The transaction's label **is its category** — the free-text `description`
column was dropped (23 Oct 2026 migration; data preserved by seeding one
custom category per distinct old description). One string, one source of
truth:

- **Predefined categories** live in the `PREDEFINED_CATEGORIES` const in
  `model.php` (single source of truth): Food, Groceries, Transport, Bills,
  Salary, Entertainment, Health, Other.
- **Custom categories** are a JSON array in `users.categories`
  (varchar(2500)). **Only** `get_custom_categories()` and
  `save_custom_category()` may read/write that column. This is a stop-gap for
  client-side storage in browser **localStorage later** — keep access confined
  to those two functions so the swap stays contained.
- **Limits**: name ≤ 30 chars (mb, matching the column width), ≤ 20 custom
  categories, case-insensitive dupes rejected (against custom *and*
  predefined lists). All user input — validated server-side,
  `htmlspecialchars()`-escaped at render.
- **A category is REQUIRED on every transaction.** The form's select starts
  on a disabled "Choose a category…" placeholder; server-side
  `category_is_known()` rejects empty ("Category is required.") and unknown
  ("Choose a valid category.") picks. There is no "Uncategorized" path for
  new rows — `sum_by_category()`'s NULLIF/COALESCE fallback stays only as
  dead-code defence.
- **Write path**: a category is created only via `POST /categories.php`
  (dedicated endpoint, JSON response, GET → 405). The transaction-submit POST
  *never* writes `users.categories`. Transactions store the category **name
  string** (like `mode` stores `+`), no FK. Renaming a custom category will
  **not** retro-update old transactions — stated limitation, same spirit as
  "no date validation for goals".
- **Migration note (23 Oct 2026)**: old descriptions were migrated 1:1 —
  distinct labels seeded as custom categories via the endpoint, then rows
  copied before `description` was dropped. Backup at
  `/tmp/opencode/jbudget-backup-pre-category-migration.sql`.
- The "Add Category" modal in make-transactions.php posts via `fetch()` with
  **FormData** (form-urlencoded) — deliberately chosen so the endpoint is
  trivially curl-testable and the client contract ("read list on load,
  add on demand") maps 1:1 onto the future localStorage migration.

---

## 6c. Goals design (added 24 Oct 2026; revised 26 Oct 2026)

User decisions locked 24 Oct 2026 and revised the same build cycle with the
tasks.txt + `goal-components/` mockups (26 Oct):

- **Investing writes a REAL transaction** (not ledger-only): mode `-` on
  invest, `+` on take back, `goal = 1` (the existing boolean-ish int column:
  0 = not goal-related), category = fixed predefined **`Goal Investment`**
  (`GOAL_CATEGORY` in model.php), auto comment "Invested in <goal>" /
  "Taken back from <goal>". Balance moves with the row, so the drift
  invariant survives by construction. Accepted consequence: goal transfers
  appear in History and the Summary breakdown under that category.
- **Take back is partial** up to the goal's net invested amount; the cap is
  validated server-side ("You can take back at most ₱…").
- Per-goal attribution comes from the signed `goal_investments` ledger rows
  because `transactions.goal` is just 0/1 — §4. **Since 26 Oct the ledger is
  write-only infrastructure: its UI was removed** and attribution is read
  from the transaction comment in History's Goal tab (see §8). Keep writing
  it — it is free inside the atomic invest flow.
- **`create_transaction()` was refactored**: `insert_transaction()` is the
  shared core (needs an open PDO transaction, returns the new txn id);
  `create_transaction()` wraps it for make-transactions.php (now returns txn id,
  optional `goal` param). The goal flows bundle txn + balance + ledger +
  aggregate in ONE transaction — four writes that partially fail together,
  never partially.
- **`goals.investment` stays as a maintained aggregate**, updated in the same
  atomic write; the take-back cap and every completed-check read it.
- **Explicit completion (26 Oct — supersedes the earlier auto-derive):**
  reaching `investment >= target_amount` does NOT complete a goal by itself.
  A "Complete" button appears on the card at exactly 100% and a Confirm
  Completion modal ("Are you sure you want to mark <name> as complete?")
  writes `completed = 1` and `date_completed = CURDATE()` via
  `mark_goal_complete()`. Completion is sticky: no un-complete exists, and a
  completed goal is frozen (invest/take-back blocked, complete button gone;
  Edit/allowed tidy-ups excluded). The badge is keyed on `completed` only.
- **Card state machine** (all on the card now — `goal-details.php` was
  REMOVED 26 Oct and every action posts to `/goals.php`):
  | Invested | Invest | Take Back | Complete |
  |---|---|---|---|
  | ₱0 | ✓ | disabled ("Nothing has been invested yet." hover hint) | not shown |
  | X < target | ✓ | ✓ | not shown |
  | 100% | disabled ("Target reached…") | ✓ | shown → confirm modal |
  | completed | disabled | disabled | gone |

  (26 Oct revision: Edit is **also disabled** on a completed card and
  `goal_save` hard-stops it server-side — "That goal is already completed." —
  so a completed goal's target/name cannot be adjusted at all.)
- **Delete is blocked while investment > 0** (flow.png "Still has
  investments"): card button disabled with a wrapper-span **Bootstrap
  tooltip** (26 Oct: upgraded from a native `title` attr — `data-bs-toggle=
  "tooltip"` + `data-bs-placement="top"` on the wrapper `<span>` that hosts
  the disabled button, initialized with `bootstrap.Tooltip.getOrCreateInstance`
  inside a DOMContentLoaded listener because the bundle loads after page
  scripts; verified showing on hover and hiding on mouse-out via CDP. The
  wrapper-span pattern is required because disabled buttons never fire hover
  events themselves). Wording is per-cause (26 Oct revision): an **ongoing**
  card with money inside says "This goal still has investments. Take the
  funds back first." (same as the server error), while a **completed** card
  says "This goal is already completed." for every locked button including
  Delete — its server stop is "That goal is already completed." too.
  ("Sensible-message" request 26 Oct; the real unblocker for completed goals
  would be a delete-archive feature — deferred, see §14.) The card button
  label is "Take Investment" (user-relabelled 26 Oct; the server action
  name stays `takeback`). The POST
  also hard-stops ("This goal still has investments. Take the funds back
  first."). Completed goals with funds inside are therefore intentionally
  undeletable via the app — the completion freeze and the delete block meet
  head-on by design; SQL is the only escape hatch.
- **Edit undercut rule** (flow.png `Edit_Investment` catch): goal_save for an
  existing goal rejects `target_amount < investment`
  ("Target amount cannot be lower than the invested amount.").
- **History "Goal" tab (26 Oct)**: the Type filter gained a 4th option
  `goal` → `WHERE goal = 1` (mode-unrestricted), comment rows auto-expand
  there so "Invested in <name>" attribution is visible without clicks
  (`goal` can't name the goal because it is a boolean). Ledger UI removed
  per goal as a consequence (task "remove the goal-ledgers on each goals").
- `target_date` stays free-text by design ("No Date Validation for Goals" is
  a stated proposal limitation); `peso()`, integer amounts everywhere.
  Completed cards show "Date Completed" above "Target Date".
- UI colours follow the house rules (mockup greens interpreted as wireframe
  sketches, not literal styles — user confirmed): progress bar `--brand`
  while under target, `--balance-accent` teal when full/completed, badges
  neutral/teal, Complete and Invest buttons `btn-primary`, Delete the only
  `.btn-outline-danger`. All modals (edit/delete shared partial + invest/
  take-back/complete inline) post to `/goals.php` with `goal_id`; failed
  POSTs re-open the offending modal via a DOMContentLoaded listener (the
  Bootstrap bundle loads after page scripts).

---

## 7. Data flow: how balance stays correct

`users.balance` is **denormalised** — a cached copy of
`SUM(mode='+' ? amount : -amount)`. The dashboard reads it directly.

`create_transaction()` writes the transaction row (including its required
`category`) **and** the balance inside one SQL transaction
(`beginTransaction` / `commit` / `rollBack`), using a delta update
(`balance = balance ± :amount`) so a failure can't desync them. The category
column does **not** participate in the balance computation.

Current drift is **0**. Two independent queries confirm it:

```sql
SELECT (SELECT balance FROM users WHERE id=1) AS stored,
       SUM(CASE WHEN mode='+' THEN amount ELSE -amount END) AS computed,
       (SELECT balance FROM users WHERE id=1)
         - SUM(CASE WHEN mode='+' THEN amount ELSE -amount END) AS drift
FROM transactions;
```

**Always run this after touching the write path.** If it ever reads non-zero,
the transaction block was bypassed (e.g. a manual `UPDATE users`).

A second invariant (Sprint 10, 27 Oct): the **balance floor** — no write may
take `users.balance` below zero. It is enforced at the ROUTE level (an
expense cap and an invest cap against the pre-POST balance, see §8) rather
than inside the write helpers — a manual SQL insert can still violate it,
and the drift query above stays the authoritative write-block check while
the floor is a validation rule.

---

## 8. Validation rules

### Make Transaction (was "Update Balance")
| Field | Rule | Message |
|---|---|---|
| mode | `income` \| `expense` | "Choose a valid transaction mode." |
| amount | required | "Amount is required." |
| amount | `ctype_digit()` **and** `> 0` | "Amount must be a whole positive number." |
| amount | `> 2147483647` | "Amount is too large." |
| category | required (is the transaction's label) | "Category is required." |
| category | must exist in predefined ∪ custom | "Choose a valid category." |
| comment | optional | — |
| comment | `mb_strlen() > 500` | "Comment must be 500 characters or fewer." |
| expense (mode `-`) | `amount` > current `users.balance` (Sprint 10) | "You can spend at most ₱…" |

`ctype_digit()` rejects `-5`, `10.5`, `abc`, `1e5` and `0x10` in one shot,
because none of those are decimal digit strings. That single check is why those
inputs share one message.

`mb_strlen()` (not `strlen()`) everywhere — `strlen` counts bytes, so an emoji
counts as 4 and would trip a "250 character" limit at ~62 visible characters.
The JS counter uses `Array.from(value).length` to match exactly.

### Landing (first-visit name prompt, added 26 Oct Sprint 7)
| Case | Message |
|---|---|
| name blank / whitespace-only | "Name is required." |
| name > 50 chars (`mb_strlen`, matches `users.username` varchar(50)) | "Name must be 50 characters or fewer." |
| valid | saved **trimmed** → 302 to `/dashboard.php` |

> The POST only executes behind the open gate (username still empty); once
> named, `/` redirects before any POST is read. Input is stored plaintext
> (like categories) and `htmlspecialchars()`-escaped at render; an XSS
> payload as the name renders strictly inert. Sprint 8's client-side
> Confirm modal wraps valid submits (Proceed is what actually POSTs); a
> blank input then jumps straight to the server, so all rows above
> enforce. History/Summary/Goals
> validation tables follow.

### History filters
| Case | Behaviour |
|---|---|
| `type` not in `all/income/expense/goal` | silently normalised to `all` |
| `type=goal` (added 26 Oct) | `WHERE goal = 1`, mode-unrestricted (invest + take back both); count label reads "N goal record(s) found"; comment rows render **auto-expanded** so the "Invested in <name>" comment carries the attribution |
| period params (`view`, `date`, `week`, `month`, `year`) | same rules as Summary (see table below). All Time is the **default view** and shows every transaction; picking a day, week or month bounds the list |
| any filter error | query is **skipped entirely**, no DB call |

> 23 Oct 2026: the old `From`/`To` date-range inputs were **removed** and
> replaced with the shared Daily/Weekly/Monthly picker that Summary uses
> (`resolve_period()` in `model.php` + `partials/period-picker.php`). A
> deliberate capability subtraction: arbitrary custom ranges ("Sep 3 →
> Oct 12") are no longer expressible; the old "Clear Dates" button became the
> "All Time" toggle button. The picker partial is the single implementation —
> History's type filter state ("Type") rides along on every picker form and
> pagination link, and vice versa.

### Summary filters
| Case | Behaviour |
|---|---|
| `view` not in `daily/weekly/monthly` | silently normalised to `daily` (mirrors History `type`) |
| `date` blank (daily) | defaults to today |
| malformed `date` | error: "The date is not a valid date."; query skipped |
| `week` blank (weekly) | defaults to the current week |
| `week` not a Monday on the generated picker list (incl. any week after the current one) | error: "The week is not an available week."; query skipped |
| `month` blank (monthly) | defaults to the current month |
| `month` not in `Y-m` within the picker options (incl. months after the current one) | error: "The month is not an available month."; query skipped |
| `year` (picker scope) malformed or out of bounds | silently normalised to "current year only" — like `view`/`type`, it is scope control, not a data filter |
| any filter error | query is **skipped entirely**, no DB call |

Picker design (23 Oct 2026): weekly and monthly pickers are server-rendered
selects with real date-range labels, ordered **latest-first**, with **no
future periods** (the in-progress week is selectable, the next week is not;
the current month is the newest month). The `‹ <year>` / `<year> ›` buttons
expand/contract the reach back to the floor (lower of earliest transaction
year and current year − 5); expanded years list in full, only the current
year caps at today. Weekly options read "Oct 19 – Oct 25, 2026" (value = the
Monday); monthly options "October 2026" (value = `Y-m`). Out-of-list values
never snap — they error. First Monday generation uses `setISODate()`
(Jan 1 2026 belongs to 2025's ISO week 53). The Daily input carries
`max="today"` as a browser hint; a hand-crafted future date still returns
the legitimate empty state.

`resolve_period()` (model.php) is the single parse/validate/render-source for
both History and Summary period pickers: reads `view/date/week/month/year`,
validates the *active* view's picker against the generated option lists (same
messages, query skipped on error), and returns bounds, picked values, the
URL state (`view` + validated `date`/`week`/`month`/`year`), plus
`yearScope` (`min`/`oldest`/`current`). The `‹ year` / `year ›` links and the
year scope live there so both pages inherit future changes for free.

`valid_date()` uses `preg_match` **plus** `checkdate()`, so `2026-02-30` is
rejected rather than silently rolling into March.

> Earlier versions silently swapped a reversed date range. That was removed —
> it returned results for the *opposite* range while the inputs still displayed
> the originals. Never silently reinterpret user input; show the error.

### Goals (revised 26 Oct 2026 — single route `/goals.php`)
| Case | Behaviour |
|---|---|
| `name` blank | error: `Name is required.` (modal re-opens with state) |
| `name` > 255 chars | error: `Name must be 255 characters or fewer.` |
| `target_amount` blank / not a positive integer / too large | errors: `Target amount is required.` / `Target amount must be a whole positive number.` / `Target amount is too large.` (same integer rules as transaction amounts) |
| `target_date` | **no validation** (stated proposal limitation) — free text, ≤ 255 chars, may be blank |
| edit: `target_amount` < `investment` | error: `Target amount cannot be lower than the invested amount.` (flow.png Edit_Investment catch) |
| edit on a completed goal | **blocked** (Edit button disabled + hover hint): `That goal is already completed.` — target/name are frozen after completion |
| `invest` amount invalid | errors: `Amount is required.` / `Amount must be a whole positive number.` / `Amount is too large.` |
| `invest` on a 100% goal | blocked (button disabled + server stop): `The goal has reached its target amount. Mark it complete or take funds back.` |
| `invest`/`takeback` on a completed goal | blocked: `That goal is already completed.` |
| `takeback` amount > net invested | error: `You can take back at most ₱….`, query skipped |
| `invest` amount > balance (Sprint 10) | floor cap: `You can invest at most ₱…`, query skipped — the figure doubles as the balance readout |
| `takeback` with ₱0 invested | blocked on the card (button disabled, hover hint); server cap equation also rejects |
| `complete` below 100% | blocked (button not shown): `The goal has not reached its target amount yet.` |
| `complete` at 100% | confirm modal → `mark_goal_complete()`: `completed=1`, `date_completed=CURDATE()` → flash "Marked as complete." |
| `complete` on already-completed | error: `That goal is already completed.` |
| `goal_delete` with investment > 0 (ongoing) | **hard blocked** (disabled button + wrapper-span Bootstrap tooltip "This goal still has investments. Take the funds back first."); server error names the same reason |
| `goal_delete` on a completed goal | **hard blocked regardless of funds** (disabled button; tooltip "This goal is already completed." matching the card's other locked buttons); server error: `That goal is already completed.` — completed goals are intentionally undeletable via the app (delete-archive noted in §14) |
| `goal_delete` at ₱0 invested | confirm modal → ledger rows deleted app-level, recorded transactions STAY |
| unknown goal id (POST) | error: `That goal no longer exists.` — no partial writes |
| unknown `action` | error: `Choose a valid action.` |
| any goal-save error | the shared goal modal auto-reopens server-rendered with the failed values |
| any invest/take-back error | the respective modal auto-reopens with the failed amount, label and cap hint server-rendered |
| any invest/take-back error | the respective modal auto-reopens with the failed amount |

> Amount rules deliberately mirror make-transactions (ctype_digit, > 0, ≤
> 2147483647, ≤ 10 chars) — same error strings family, no negative or float
> amounts anywhere.

---

## 9. Bootstrap gotchas that already bit us

These are the three that cost real debugging time. All will recur.

### 1. `.card` is a flex container with `flex-direction: column`

```css
.card { display: flex; flex-direction: column; }
```

Overriding `display` alone does **not** undo the column. A card laid out
horizontally needs `flex-direction: row` stated explicitly, or children stack
vertically and `align-items: center` then centres them.

### 2. Table cells are out-specified by Bootstrap

```css
.table > :not(caption) > * > * { color: var(--bs-table-color-...); }
```

Specificity **(0,1,1)** — the `:not(caption)` contributes a type selector. This
beats a plain `.my-class` at **(0,1,0)** regardless of load order, so per-row
colours silently vanish inside tables.

Fix — scope to the element:

```css
.table td.stat-income { color: var(--income); }   /* (0,2,1) wins */
```

### 3. Server clocks must agree: PHP timezone vs MariaDB (bit us 26 Oct 2026)

The whole app's idea of "today" comes from PHP: every route builds
`new DateTimeImmutable("today")`, and the shared period picker clamps its
week/month options to PHP's today. SQL-side `CURDATE()`
(`transactions.date` DEFAULT, `mark_goal_complete()`'s `date_completed`)
uses MariaDB's own clock. If those two disagree, the pickers clamp to
*yesterday* between local midnight and the moment the mis-configured zone
crosses midnight — the user hit exactly this working 12am–4am Manila.

Archaeology: XAMPP ships Germany-made defaults, so
`/opt/lampp/etc/php.ini` had `date.timezone=Europe/Berlin` (+1/+2 with DST),
while the system CLI PHP (`/etc/php/php.ini`, only used for
lint/diagnostics) had none at all → UTC. MariaDB was never broken
(`time_zone = SYSTEM` → OS zone HKT/+0800).

**Fix applied 26 Oct**: `/opt/lampp/etc/php.ini` line 945 →
`date.timezone=Asia/Manila`, then `sudo /opt/lampp/lampp restartapache`
(the httpd master runs as root — this is the only step needing sudo).
Verified: module reports `Asia/Manila`, `DateTimeImmutable("today")`
matches MariaDB `NOW()`, newest date option = today.

Symptom signature, if it ever regresses: date pickers show yesterday's day
/ last week only, then "fix themselves" some hours after midnight.

Known remainder: the **system CLI PHP still has no date.timezone** (reads
UTC, `/etc/php/php.ini` is root-owned). It does not serve any page; if a
future diagnostic says the CLI's "today" is stale, this is why — fix with
`sudo sed -i 's/^;date.timezone =.*/date.timezone = Asia\/Manila/'
/etc/php/php.ini`.

Rule of thumb: **any colour/size override on a Bootstrap `.card` or `.table`
child must be scoped to the element**, not applied to a bare class.

### 3. Shared render code uses variables the branch didn't set

During Summary, `$pickedDate` was only assigned in the `daily` branch, but the
render code evaluated `->format()` on it for every view (the Daily date picker
renders on Weekly/Monthly too, just hidden) — fatal 500 on Weekly and Monthly,
while Daily passed all checks. Fix: initialize branch-only variables **before**
the branch if any shared markup reads them. Any time you add a control that
only renders in one mode, grep the render section for everything it touches.

### 4. A reboot can stale-date MariaDB's TLS cert for CLI clients (27 Oct 2026)

Overnight-reboot symptom: every `mysql` CLI call failed with
`ERROR 2026 (HY000): TLS/SSL error: certificate is not yet valid or the
system clock is incorrect` — while the system clock and NTP were both
correct (`timedatectl` sane, OS date right). The MariaDB auto-managed TLS
certificate's NotBefore had drifted into the future at boot; only the
CLI's handshake enforces the check, so **the app was never affected** (the
PDO connection kept working — `get_user()` and all routes normal).
Workaround: connect with TLS off — `mysql --ssl=0 -h 127.0.0.1 -u user
jbudget` (the §1 command needs this suffix until the cert is regenerated).
Proper fix later: regenerate the server certs or disable TLS for
localhost — the files are root-owned, so it needs sudo. Symptom
signature: CLI-only DB auth failures immediately after a reboot with a
provably correct clock.

---

## 10. Security posture

Single-account, university project, no auth by design. Still:

- **All user input is `htmlspecialchars()`-escaped at render**, including
  `comment`, category names (a `<script>` payload stored as a
  custom category renders escaped), the landing-prompt username (a
  `<script>` payload stored as the name renders escaped in the
  dashboard greeting — QA'd 26 Oct, Sprint 7), and every GET param reflected into
  `value=""`.
- **All queries use prepared statements** with bound params. `mode`, `start`,
  `end`, `category` are bound; `LIMIT`/`OFFSET` are cast to `int` before
  interpolation. The `GROUP BY` column in `sum_by_category()` (26 Oct:
  generalised from `expense_by_category()` with a bound `:mode` param —
  Summary calls it once for income `+`, once for expenses `-`) is static SQL
  text, not user data.
- The only interpolated SQL values are integers that have passed through
  `max()`/`(int)` casts, plus a table name checked against a hardcoded allowlist
  in `count_rows()`.

Tested: XSS payloads render escaped; `OR 1=1`, `UNION SELECT`, `DROP TABLE` and
`SLEEP()` attempts all return 200 with no SQL error and leave tables intact.

**Not implemented** (fine for scope, but be aware): CSRF tokens, session
handling, rate limiting. Comments are user-input and therefore XSS-capable —
if this ever leaves localhost, that escaping is what stands between you and a
stored XSS.

---

## 11. Testing notes / safety rules

**Do not test by inserting into the live database without a cleanup plan.**
During this project a cleanup used `DELETE FROM transactions WHERE id > 8`,
which silently destroyed a real user row (id 13) because its id happened to sit
above the baseline.

Safe rules for future sessions:

1. Tag every test row through a **dedicated test category** — `QA Test`, seeded
   once via `/categories.php` — since the old `description`-based tagging is
   gone (the column was dropped 23 Oct 2026).
2. Delete by that marker — `WHERE category = 'QA Test'` — **never** by id
   range.
3. Prefer asserting on **error messages and 302 status lines only**. A
   validation test should never need to accept a submission.
4. Snapshot `COUNT(*)` and `balance` before inserting anything.
5. Restore the balance explicitly after cleanup, and restore `users.categories`
   to its pre-test JSON (snapshot it — there is no removal endpoint, so the
   restore is a direct UPDATE of that blob only).

For UI verification, headless Chromium is available and driven over the DevTools
protocol — this catches things curl cannot, like a CSS specificity bug where the
HTML is correct but the computed colour is wrong:

```bash
chromium --headless --no-sandbox --disable-gpu --hide-scrollbars \
  --virtual-time-budget=4000 --window-size=1280,900 \
  --screenshot=/tmp/out.png http://localhost/history.php
```

There is **no automated test suite**. Everything above was verified by hand.
A small script asserting route status codes and the drift query would be worth
adding before submission.

### Goals QA conventions (added 24 Oct 2026)
- Tag test goals with `name = 'QA Test'` (exact), never by id range.
- **Delete order matters**: (1) delete the goal via the app (or
  `DELETE FROM goal_investments WHERE goal_id = …` then the goal row),
  (2) remove the goal's transactions, (3) **compensate the balance**.
- Goal transactions are category `Goal Investment` + comment
  "Invested in <goal>" / "Taken back from <goal>" — NOT `QA Test`. Identify
  QA ones by the comment:
  ```sql
  UPDATE users SET balance = balance - (
    SELECT s FROM (SELECT SUM(CASE WHEN mode='+' THEN amount ELSE -amount END) AS s
                   FROM transactions
                   WHERE category = 'Goal Investment'
                     AND comment LIKE '%QA Test%') AS x
  ) WHERE id = 1;
  DELETE FROM transactions
  WHERE category = 'Goal Investment' AND comment LIKE '%QA Test%';
  ```
  (The subquery-in-UPDATE wrapping is a MariaDB quirk.) Then re-run the
  drift query — it must return 0 and the balance back to baseline.

---

## 12. Accessibility state

- Exactly one `<h1>` per page; every `<label for>` resolves to a real control.
- `<main>` landmark present on all four built pages.
- `:focus-visible` ring in `--focus` blue, 2px.
- Decorative SVG is `aria-hidden`; the comment toggle has `aria-expanded`,
  `aria-controls`, and an `aria-label`. The donut is a static `role="img"`
  SVG whose `aria-label` reads out category/percent pairs; the table + legend
  carry the same data.
- The Daily/Weekly/Monthly toggle is a real button group with
  `aria-current="page"` on the active option.
- Unbuilt nav items are `<span class="nav-link is-disabled">` with **no `href`**,
  so there are no dead links — screen readers get "Goals SOON" rather than a
  404.

---

## 13. Known issues / open items

1. **History table clips at ~420px.** A 5th column was added for the comment
   toggle. It scrolls horizontally, but a card-per-transaction layout would be
   better below ~480px.
2. **`--amber` and `--purple` are now referenced by the chart palette**
   (`--chart-1` reuses amber; `--chart-3` is a lightened purple since the
   original `--purple` fails AA as a slice on `--bg-card`). They stay defined
   as semantic tokens for the Summary chart family.
3. **`int(255)` display widths** on `amount` / `target_amount` / `investment` —
   cosmetic DB nit, `(255)` is display width, not storage. Harmless.
4. **`transactions.goal` has no foreign key** and relies on `0` as a
   "not linked" sentinel. It is now actively written by the goal invest /
   take-back flows (always `1` on goal rows); still no FK by design.
5. **No automated tests** — but a repeatable CDP smoke-check file exists at
   `/tmp/opencode/hover-tip.mjs` (Node 26's built-in WebSocket client
   driving headless Chromium: createTarget/attach → navigate →
   Runtime.evaluate + `Input.dispatchMouseEvent` hover/click → screenshot).
   **/tmp dies on reboot** — copy it somewhere versioned if a test suite is
   wanted; it is the working template for hover/click/DOM-assertion checks.
6. **`users.categories` is a stop-gap**: it is really browser-localStorage
   data. When migrating, the client contract is already endpoint-shaped
   (read list on load, add via POST) — replace `categories.php` calls with
   localStorage and mark the DB column deprecated.

---

## 14. What's left — build complete, deferred items

Goals shipped 24 Oct 2026 (see §6c for the locked design, §4 for the schema,
§8 for the validation table); the **Landing page shipped 26 Oct** (Sprint 7
— gates + rules in §6, validation table in §8). With it, **every route in
the proposal is live**: Landing, Dashboard, Make Transaction (ex Update
Balance), History, Summary, Categories endpoint, Goals. The wireframe's
remaining screens (5–7) are now built — **the build is complete**.

### Deferred (deliberate, not bugs)
- **Delete-archive for goals (user idea, 26 Oct)**: user noted that making
  Delete possible even for funded/completed goals is attainable if deleted
  goals go to an archive instead of vanishing — out of scope for now; until
  then completed goals stay undeletable via the app (see §6c / §8).
- Category rename/delete UI (add-only so far; removal is safe because stored
  categories on transactions are strings).
- Real category editing on existing transactions (must edit via SQL today).
- Automated test suite (curl + CDP patterns in this doc are the manual path).
- `transactions.goal` is 0/1, not a goal id — a future "per-goal spend"
  report would need a goal id column or ledger join (`goal_investments`
  already provides per-goal attribution for money moved through investing).
- Users/categories localStorage migration (§6b) and multi-user anything.

---

## 15. Reconstructing the current state

```bash
cd /opt/lampp/htdocs

# lint everything
for f in *.php partials/*.php; do php -l "$f"; done

# routes (expect 200 everywhere except: categories GET = 405, and
# goal-details.php must be GONE (404) after the 26 Oct removal).
# "/" and /dashboard.php are conditional on users.username (first-visit
# gate, Sprint 7 / §6):
#   unnamed:  "/" 200 (landing form)  /  dashboard.php 302 -> /
#   named:    "/" 302 -> /dashboard.php  /  dashboard.php 200
for r in /dashboard.php /make-transactions.php /history.php "/history.php?view=weekly" \
         "/history.php?type=expense&view=monthly" "/history.php?type=goal" /summary.php \
         "/summary.php?view=weekly" "/summary.php?view=monthly" "/summary.php?view=bogus" \
         /goals.php "/goal-details.php?id=4"; do
  curl -sS -o /dev/null -w "$r %{http_code}\n" "http://localhost$r"
done
curl -sS -o /dev/null -w "/ (unnamed) %{http_code}\n" "http://localhost/"
curl -sS -o /dev/null -w "/categories.php GET (want 405) %{http_code}\n" "http://localhost/categories.php"

# landing flow, while unnamed: invalid POSTs re-render 200 with their message
# and write nothing; a valid name 302s to the dashboard. Restore with:
#   UPDATE users SET username = '' WHERE id = 1;
# POST while named -> 302 (gate closes before POST handling; no rename path).

# goals state sanity: ledger net should EQUAL net movement of goal=1
# transactions (every goal txn comes from an app invest/take-back flow)
mysql -h 127.0.0.1 -u user jbudget -e "
SELECT (SELECT COUNT(*) FROM goals) AS goals,
       (SELECT COUNT(*) FROM goals WHERE completed=1) AS completed,
       (SELECT COALESCE(SUM(amount),0) FROM goal_investments) AS ledger_net,
       (SELECT COALESCE(SUM(CASE WHEN goal=1 AND mode='-' THEN amount
                                 WHEN goal=1 THEN -amount ELSE 0 END),0)
          FROM transactions) AS goal_txn_net,
       (SELECT COUNT(*) FROM goals WHERE investment > 0) AS preexisting;"

# balance must be in sync (drift column must be 0; COALESCE variant because
# SUM() over an EMPTY transactions table reads NULL and would frame "no rows"
# as drift NULL instead of the legit fresh-DB 0)
mysql -h 127.0.0.1 -u user jbudget -e "
SELECT (SELECT balance FROM users WHERE id=1) AS stored,
       COALESCE((SELECT SUM(CASE WHEN mode='+' THEN amount ELSE -amount END)
                 FROM transactions),0) AS computed,
       (SELECT balance FROM users WHERE id=1)
         - COALESCE((SELECT SUM(CASE WHEN mode='+' THEN amount ELSE -amount END)
                     FROM transactions),0) AS drift;"

# custom categories JSON must parse
mysql -h 127.0.0.1 -u user jbudget -N -e "
SELECT JSON_VALID(categories) AS categories_ok, JSON_LENGTH(categories) AS count
FROM users WHERE id=1;"

# quick picker sanity: no future months/weeks offered (today = 2026-10-23)
curl -sS "http://localhost/summary.php?view=monthly" | grep -c 'value="2026-1[12]"'   # want 0
curl -sS "http://localhost/summary.php?view=weekly" | grep -c 'value="2026-11'       # want 0

# visual smoke test
chromium --headless --no-sandbox --disable-gpu --hide-scrollbars \
  --virtual-time-budget=4000 --window-size=1280,800 \
  --screenshot=/tmp/out.png "http://localhost/summary.php?view=monthly"
```

### Known verification-via-screenshot gotchas (recap)
- curl NEVER caches: a browser-only CSS staleness bug will pass curl checks.
  If a human reports "X doesn't render", suspect stale CSS before suspecting
  the markup — check `CSS_VERSION` was bumped.
- Headless Chromium screenshots catch CSS issues curl cannot (specificity,
  cached styles, donut SVG paint).