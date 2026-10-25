# J-Budget — Development Notes

Personal budget tracker. University of Caloocan City, Computer Studies Department,
Information Management project. Submitted by **Daniel Z. Baladad**.

Single-account context. Currency is **PHP (₱)**. Amounts are integers only.

> Last updated: **24 Oct 2026**
> Status: **Every feature from the proposal is live**: Dashboard, Update
> Balance, History (period picker + type filter), Summary (donut + category
> breakdown), Categories endpoint, and **Goals** (list + details + invest /
> take back / edit / delete). See §14 for what remains deferred and §0 for
> session history.

---

## 0. Session handoff — read me first (23 Oct 2026)

For the agent picking this up: three sprints shipped today. The list of
*what they were* doubles as the list of *what may surprise you*.

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
- Design decision of record: **invest/take back write REAL transactions**
  (mode `-`/`+`, `goal = 1`, category `Goal Investment`, auto comment) +
  a signed `goal_investments` ledger row + the `goals.investment` aggregate,
  all in one atomic write. Take back is partial, capped at net invested.
- New table `goal_investments` (no FKs, house style). Frequent traps:
  the QA cleanup order for goals (§11) and the delete-keeps-transactions
  semantics (§6c).

### Current state at session end
- DB: `transactions` 13 rows, `users` balance 199939, **balance drift 0**;
  `goals` 4 rows (Sample Goal 4 shows the Completed path: 300/300),
  `goal_investments` 0 rows, `PREDEFINED_CATEGORIES` now includes
  `Goal Investment`; 12-ish custom categories live in `users.categories`
  (including the 8 that are really migrated labels, e.g. "Sample Transaction
  #22", "Instant Coffee"). The human was re-categorizing rows during testing —
  data composition may differ from what you saw; that is expected.
- Verified working: routes 200/302, donut math visually confirmed (1–3
  slices), picker validation (errors for non-list dates/weeks/months +
  future periods), History pagination carrying `view`/`week`/`month`/`year`/
  `type`, full goal lifecycle QA'd through real POSTs (create → invest →
  over-cap rejection → take back → auto-complete → edit → delete → cleanup,
  balance restored to baseline, drift 0), formulas for QA testing in §11.
- Test tooling: Node CDP harness at `/tmp/opencode/cdp-test.mjs` (Node 26
  built-in WebSocket + headless chromium `--remote-debugging-port=9222`) was
  used to click through the Add Category modal end-to-end. Copy it out of
  /tmp if you want it versioned; /tmp does not survive reboots.

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
├── index.php            → 302 redirect to dashboard.php
├── dashboard.php        route: dashboard
├── update-balance.php   route: transaction entry form (+ Add Category modal)
├── history.php          route: filterable, paginated history
├── summary.php          route: expense donut + category breakdown
├── categories.php       JSON endpoint: POST-only, saves a custom category
├── goals.php            route: goal list (progress cards, Set/Edit modal)
├── goal-details.php     route: goal detail (progress, ledger, invest/take-back)
├── db.php               PDO connection
├── model.php            all queries + formatting helpers
├── partials/
│   ├── header.php       <head>, asset links, <body data-bs-theme="dark">
│   ├── sidebar.php      nav, driven by $activePage
│   ├── footer.php       bundle script, closes document
│   ├── period-picker.php shared Daily/Week/Month (+All Time) picker used by
│   │                     summary.php and history.php (consumes resolve_period())
│   └── goal-modals.php  shared Set/Edit Goal + Delete Goal modals used by
│                         goals.php and goal-details.php
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

### `goals` (activated 24 Oct 2026)
`id, name, target_amount, target_date (varchar), investment`

> `target_date` is `varchar` holding junk values (`"Bukas"`, `"November 5, 2026"`).
> The proposal allows this — "No Date Validation for Goals" is a stated
> limitation. `goals.investment` is a **maintained aggregate** (net invested,
> updated by `record_goal_investment()` in the same write as the ledger).

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
  small", never red for a neutral action.
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
| `/` | done | 302 → dashboard |
| `/dashboard.php` | done | balance card, week/month expense cards, goal count, recent 5 |
| `/update-balance.php` | done | POST form, redirects to dashboard on success; category picker + Add Category modal |
| `/history.php` | done | shared period picker (All Time default + Daily/Week/Month), type filter, pagination, expandable comments |
| `/summary.php` | done | Daily/Weekly/Monthly toggle, date picker, donut + breakdown table |
| `/categories.php` | done | JSON endpoint. POST `name=` → 201/400; GET → **405** |
| `/goals.php` | done | goal list: progress cards, Completed/Ongoing badges, Set/Edit modal, Delete confirm |
| `/goal-details.php?id=N` | done | goal detail: progress card, investment ledger, Invest/Take Back (modals), Edit, Delete; no id → 302 list; unknown id → not-found card |

**No Landing Page.** Single-account context plus a populated `users` table means
the name prompt is unnecessary; `index.php` redirects to the dashboard instead.
This was a deliberate decision — revisit only if a panelist expects one.

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
  new rows — `expense_by_category()`'s NULLIF/COALESCE fallback stays only as
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
- The "Add Category" modal in update-balance posts via `fetch()` with
  **FormData** (form-urlencoded) — deliberately chosen so the endpoint is
  trivially curl-testable and the client contract ("read list on load,
  add on demand") maps 1:1 onto the future localStorage migration.

---

## 6c. Goals design (added 24 Oct 2026)

User decisions locked 24 Oct 2026:
- **Investing writes a REAL transaction** (not ledger-only): mode `-` on
  invest, `+` on take back, `goal = 1` (the existing boolean-ish int column:
  0 = not goal-related), category = fixed predefined **`Goal Investment`**
  (`GOAL_CATEGORY` in model.php), auto comment "Invested in <goal>" /
  "Taken back from <goal>". Balance moves with the row, so the drift
  invariant survives by construction. Accepted consequence: goal transfers
  appear in History and the Summary breakdown under that category.
- **Take back is partial** up to the goal's net invested amount; the cap is
  validated server-side ("You can take back at most ₱…").
- Per-goal attribution comes from the `goal_investments` ledger (signed
  rows) because `transactions.goal` is just 0/1 — see §4.
- `goals.investment` stays as a maintained aggregate, updated in the same
  atomic write as transaction + ledger row (`record_goal_investment()`).
  Pre-existing rows with `investment > 0` and empty ledger (e.g. Sample
  Goal 4, 300/300) still honour the cap because the cap reads the aggregate.
- **`create_transaction()` was refactored**: `insert_transaction()` is the
  shared core (needs an open PDO transaction, returns the new txn id);
  `create_transaction()` wraps it for update-balance.php (now returns txn id,
  optional `goal` param). The goal flows bundle txn + balance + ledger +
  aggregate in ONE transaction — four writes that partially fail together,
  never partially.
- **Delete goal**: allowed regardless of invested money. Ledger rows go with
  the goal (app-level, inside delete_goal's transaction — no FK by design);
  the recorded transactions and their balance effects STAY (history is
  history).
- Auto-complete is derived: `investment >= target_amount` → teal
  "Completed" badge (+ teal progress bar fill); otherwise neutral "Ongoing"
  badge. No status column.
- `target_date` stays free-text by design ("No Date Validation for Goals" is
  a stated proposal limitation); `peso()`, integer amounts everywhere.
- UI colours follow the house rules: progress bar = `--brand` (or teal when
  complete), badges neutral/teal, Delete is the only `.btn-outline-danger`.
  Shared modals live in `partials/goal-modals.php` (single copy consumed by
  both routes); failed POSTs re-open the offending modal via a
  DOMContentLoaded listener (the Bootstrap bundle loads after page scripts).

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

---

## 8. Validation rules

### Update Balance
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

`ctype_digit()` rejects `-5`, `10.5`, `abc`, `1e5` and `0x10` in one shot,
because none of those are decimal digit strings. That single check is why those
inputs share one message.

`mb_strlen()` (not `strlen()`) everywhere — `strlen` counts bytes, so an emoji
counts as 4 and would trip a "250 character" limit at ~62 visible characters.
The JS counter uses `Array.from(value).length` to match exactly.

### History filters
| Case | Behaviour |
|---|---|
| `type` not in `all/income/expense` | silently normalised to `all` |
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

### Goals (added 24 Oct 2026)
| Case | Behaviour |
|---|---|
| `name` blank | error: `Name is required.` (modal re-opens with state) |
| `name` > 255 chars | error: `Name must be 255 characters or fewer.` |
| `target_amount` blank / not a positive integer / too large | errors: `Target amount is required.` / `Target amount must be a whole positive number.` / `Target amount is too large.` (same integer rules as transaction amounts) |
| `target_date` | **no validation** (stated proposal limitation) — free text, ≤ 255 chars, may be blank |
| `invest` amount invalid | errors: `Amount is required.` / `Amount must be a whole positive number.` / `Amount is too large.` |
| `takeback` amount > net invested | error: `You can take back at most ₱….`, query skipped |
| unknown goal id (POST) | error: `That goal no longer exists.` — renders the not-found card, no partial writes |
| unknown `action` | error: `Choose a valid action.` |
| unknown id on GET detail (no `id`) | 302 → `/goals.php` |
| unknown id on GET detail (id present) | friendly "This goal does not exist." card, 200 |
| any goal-save error | the shared goal modal auto-reopens server-rendered with the failed values |
| any invest/take-back error | the respective modal auto-reopens with the failed amount |

> Amount rules deliberately mirror update-balance (ctype_digit, > 0, ≤
> 2147483647, ≤ 10 chars) — same error strings family, no negative or float
> amounts anywhere.

---

## 9. Bootstrap gotchas that already bit us

These are the two that cost real debugging time. Both will recur.

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

Rule of thumb: **any colour/size override on a Bootstrap `.card` or `.table`
child must be scoped to the element**, not applied to a bare class.

### 3. Shared render code uses variables the branch didn't set

During Summary, `$pickedDate` was only assigned in the `daily` branch, but the
render code evaluated `->format()` on it for every view (the Daily date picker
renders on Weekly/Monthly too, just hidden) — fatal 500 on Weekly and Monthly,
while Daily passed all checks. Fix: initialize branch-only variables **before**
the branch if any shared markup reads them. Any time you add a control that
only renders in one mode, grep the render section for everything it touches.

---

## 10. Security posture

Single-account, university project, no auth by design. Still:

- **All user input is `htmlspecialchars()`-escaped at render**, including
  `comment`, category names (a `<script>` payload stored as a
  custom category renders escaped), and every GET param reflected into
  `value=""`.
- **All queries use prepared statements** with bound params. `mode`, `start`,
  `end`, `category` are bound; `LIMIT`/`OFFSET` are cast to `int` before
  interpolation. The `GROUP BY` column in `expense_by_category()` is static SQL
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
5. **No automated tests** — though the Summary build added a repeatable CDP
   smoke-check pattern (Node 26's built-in WebSocket client driving headless
   Chromium over DevTools protocol at `/tmp/opencode/cdp-test.mjs`; copy it
   out of there if you want it versioned).
6. **`users.categories` is a stop-gap**: it is really browser-localStorage
   data. When migrating, the client contract is already endpoint-shaped
   (read list on load, add via POST) — replace `categories.php` calls with
   localStorage and mark the DB column deprecated.

---

## 14. What's left — build complete, deferred items

Goals shipped 24 Oct 2026 (see §6c for the locked design, §4 for the schema,
§8 for the validation table). With it, **every route in the proposal is
live**: Dashboard, Update Balance, History, Summary, Categories endpoint,
Goals list + detail. The wireframe's remaining screens (5–7) are now built.

### Deferred (deliberate, not bugs)
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

# routes (expect 200 everywhere except "/" = 302, categories GET = 405,
# and goal-details without an id = 302 to /goals.php)
for r in / /dashboard.php /update-balance.php /history.php "/history.php?view=weekly" \
         "/history.php?type=expense&view=monthly" /summary.php \
         "/summary.php?view=weekly" "/summary.php?view=monthly" "/summary.php?view=bogus" \
         /goals.php "/goal-details.php?id=4" "/goal-details.php?id=999999"; do
  curl -sS -o /dev/null -w "$r %{http_code}\n" "http://localhost$r"
done
curl -sS -o /dev/null -w "/categories.php GET (want 405) %{http_code}\n" "http://localhost/categories.php"

# goals state sanity: ledger net must equal net movement of goal=1 transactions;
# aggregates (goals.investment) positioned against both (all 0 on fresh data)
mysql -h 127.0.0.1 -u user jbudget -e "
SELECT (SELECT COUNT(*) FROM goals) AS goals,
       (SELECT COALESCE(SUM(amount),0) FROM goal_investments) AS ledger_net,
       (SELECT COALESCE(SUM(CASE WHEN goal=1 AND mode='-' THEN amount
                                 WHEN goal=1 THEN -amount ELSE 0 END),0)
          FROM transactions) AS goal_txn_net,
       (SELECT COUNT(*) FROM goals WHERE investment > 0) AS preexisting;"

# balance must be in sync (drift column must be 0)
mysql -h 127.0.0.1 -u user jbudget -e "
SELECT (SELECT balance FROM users WHERE id=1) AS stored,
       SUM(CASE WHEN mode='+' THEN amount ELSE -amount END) AS computed,
       (SELECT balance FROM users WHERE id=1)
         - SUM(CASE WHEN mode='+' THEN amount ELSE -amount END) AS drift
FROM transactions;"

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