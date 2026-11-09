You are an expert Laravel and Filament engineer helping maintain and extend **Fasal Babaji Mina**, a plot sales back office. Staff register plots (نمری / ځمکی), sell them to customers, track installment payments, and print sale documents.

Write clean, simple, maintainable code. Prefer clarity over clever abstractions. Match the existing Filament resource patterns before introducing new ones.

Also read:

- [app/Filament/AGENTS.md](app/Filament/AGENTS.md): admin resources, Pashto labels, navigation groups
- [docs/AGENTS.md](docs/AGENTS.md): help articles the knowledge base panel reads

---

## Agent Skills

Skills are `SKILL.md` files with phase-specific workflows. They extend this file and never replace it.

### Reading order (mandatory)

1. **`AGENTS.md`** (this file): project context, conventions, constraints
2. **The folder `AGENTS.md`** for the area you touch (`app/Filament/`, `docs/`)
3. **The relevant skill**, when one applies

### Skills

| Skill | When to load |
|-------|--------------|
| `/scope` | Turning a product idea into a spec in `docs/specs/` |
| `/architect` | Before a feature that has a real design choice |
| `/develop` | Building a feature from a spec or a clear request |
| `/debug` | A bug, a failing test, or a broken admin screen |
| `/test` | Writing PHPUnit tests for code you just built |
| `/check` | Confirming a change before merge |
| `/sync` | Last step after a change: update the `AGENTS.md` files that drifted |

### Typical workflow

```txt
New feature       → AGENTS.md → /scope or /architect → /develop → /test → /check → /sync
Something broken  → AGENTS.md → /debug → /sync
Small edit        → AGENTS.md → edit → pint → manual check in /admin
```

---

## Project Overview

**Fasal Babaji Mina** sells land plots. One Filament panel at `/admin` holds the whole back office. There is no public website: `/` redirects to `/admin/login`.

### Domains

| Domain | Model | Table | Resource |
|--------|-------|-------|----------|
| **Plots** | `Numeraha` | `numerahas` | `NumerahaResource` |
| **Customers** | `Customers` | `customers` | `CustomersResource` |
| **Sales** (customer + plot + payments + files) | `CustomerNumeraha` | `customer_numerahas` | `CustomerNumerahaResource` |
| **Income** (same sale model, money view) | `CustomerNumeraha` | `customer_numerahas` | `NumerahaIncomeResource` |
| **Finance** (expenses and ledger rows) | `Finance` | `finances` | `FinanceResource` |
| **Markets** | `Markets` | `markets` | `aMarketsResource.php` (class `MarketsResource`) |
| **Employees** | `Employees` | `employees` | `EmployeesResource` |
| **Users** | `User` | `users` | `UserResource` |
| **Transactions** | `Transactions` | `transactions` | no resource |
| **Sale documents** | — | — | `SharwaliTarifa` controller (PDF and Word) |

Treat the app as **one system**: a column rename on `numerahas` touches the plot form, the sale form placeholders, the Excel import, the PDF and Word templates, and the sidebar badges.

---

## Tech Stack

Use what is already in `composer.json` and `package.json`:

| Layer | Stack |
|-------|-------|
| **Backend** | Laravel **11**, PHP **8.2+** |
| **Admin** | **Filament 3** (forms, tables, widgets, notifications), Livewire 3 |
| **Auth** | Filament panel login, Sanctum for `routes/api.php` |
| **Front end** | Vite, Tailwind 3 with `tailwindcss-rtl`, Alpine, the Vazirmatn font |
| **Data** | MySQL, Eloquent |
| **Documents** | TCPDF (RTL PDFs), DomPDF, PhpWord `TemplateProcessor`, Laravel Invoices |
| **Import / export** | `eightynine/filament-excel-import`, `pxlrbt/filament-excel`, Maatwebsite Excel |
| **Filament plugins** | Archivable, Spotlight, Spatie Backup, PWA, Settings Hub, Themes, Knowledge Base, Edit Profile, Logger, Language Switch, Badgeable Column, Money Field |
| **Settings** | `spatie/laravel-settings` (`app/Settings/`) |

Do **not** add major libraries without user approval. Do not add a separate SPA, a second CSS framework, or a non-Filament admin.

---

## Application Structure

```txt
app/
  Filament/
    Resources/         # One resource per screen, auto-discovered
    Pages/             # Settings page (ManageFooter), Backups
    Widgets/           # NumeraChart, NumeraStatus, StatsOverview (dashboard)
  Http/Controllers/
    SharwaliTarifa.php # Sale document downloads (PDF, PDF, Word)
  Imports/             # MyNumerahaImport (Excel to numerahas)
  Models/              # Numeraha, Customers, CustomerNumeraha, Finance, …
  Observers/           # NumerahObserver (database notifications)
  Providers/
    AppServiceProvider.php            # 191 string length, language switch locales
    Filament/AdminPanelProvider.php   # Panel, plugins, sidebar sold/unsold links
  Settings/            # Spatie settings classes
config/
database/migrations/
docs/                  # Knowledge base articles (ps, en, de) and specs/
resources/views/
  pdf.blade.php          # Invoice (تعرفه) PDF
  numerahDocs.blade.php  # Sale document PDF
storage/app/templates/
  numerah_template.docx  # Word template with ${placeholders}
routes/
  web.php              # Redirect to login, three auth-only download routes
  api.php              # Sanctum /user only
```

---

## Core Patterns

### Filament resources

- A new screen is a resource in `app/Filament/Resources`. The panel discovers resources, pages, and widgets. Do not register routes by hand.
- Write every label in **Pashto** on the component with `->label(...)`. Forms are not translated through lang files.
- Navigation groups in use: `د مشتریانو مدیریت`, `مالی مدیریت`, `ډیټابیس مدیریان`. Reuse one before inventing a new group.
- Standard table actions: `ViewAction` labelled `کتل`, `EditAction` labelled `بدلون`. Bulk delete is commented out on purpose. Do not turn it back on without approval.
- Layout: `Card` plus `Grid` inside a 12-column form, with `->columnSpan(...)` splitting the form into a main column and a side column.

### Plots (`Numeraha`)

- `numera_id` is the public plot code. It defaults to `GM-` plus 8 characters of a UUID and is disabled in the form.
- `numera_type` is a fixed select list of plot sizes (`10 بسوه ای` … `بلند منزل`). Add sizes to that list, not as free text.
- Boundaries are `north`, `south`, `east`, `west`. The area is `Land_Area` (capital letters, keep them).
- `Numeraha` uses `LaravelArchivable\Archivable`. `NumerahaResource::getEloquentQuery()` removes `ArchivableScope` so `ArchivedFilter` can show archived rows. Archive with `ArchiveAction` and `UnArchiveAction`, not delete.
- "Sold" means the plot has at least one row in `customer_numerahas` (`whereHas('customers')`).

### Sales (`CustomerNumeraha`)

- One row links one customer to one plot and holds `total_price`, six installments (`first_phase` … `sixth_phase`), `payed_price`, `remarks`, and `multipleDocs` (a JSON array of file paths, max 5, PDF or image).
- `payed_price` is recomputed in the form by `updatePayedPrice()` from hidden `*_hidden` fields as each installment changes. A new installment field must follow the same pattern.
- The due amount is shown with a `Placeholder`, not stored.
- The customer select can create a customer inline through `createOptionForm`. Keep that form in step with `CustomersResource`.

### Sale documents

- Routes `download.invoice`, `download.soldDocs`, `download.word` sit behind `auth` in `routes/web.php` and take a `CustomerNumeraha` id.
- `SharwaliTarifa::saleForDownload()` loads the sale with `customer` and `numeraha` and answers 404 if any is missing. Reuse it for any new download.
- PDFs use TCPDF with `setRTL(true)` and render a Blade view. The Word file fills `storage/app/templates/numerah_template.docx` with `TemplateProcessor`. A new field must be added to the controller, the Blade view, and the `.docx` placeholder.

### Notifications

Use `Filament\Notifications\Notification::make()->title(...)->sendToDatabase($user)` with a Pashto title. The panel has `->databaseNotifications()` turned on.

### Settings

Add a class under `app/Settings/` with a settings migration, then a `SettingsPage` under `app/Filament/Pages/`. See `FooterSettings` and `ManageFooter`.

### Help articles

In-app help lives in `docs/{ps,en,de}/` and shows on the `/kb` knowledge base panel. Specs do not go there; they go in `docs/specs/NNNN-title.md`.

---

## Localization

- The default locale is `ps` (`APP_LOCALE` overrides it). The fallback is `en`. The language switch lists `per`, `en`, `ps`.
- The timezone is `Asia/Kabul` (`APP_TIMEZONE`).
- The admin is right to left. Use `tailwindcss-rtl` utilities and test layouts in RTL.
- Phone inputs convert Eastern Arabic digits to Latin with an `oninput` handler. Keep that when you copy a phone field.

---

## Feature Implementation Checklist

1. Read this file and `app/Filament/AGENTS.md`.
2. Find the nearest **sibling** resource and copy its shape.
3. Plan: migration → model `$fillable` and `$casts` → resource form → table → relation manager → widget or sidebar badge if needed.
4. Write a new migration for any schema change. Guard with `Schema::hasColumn` when live data may already differ (see `2026_11_09_120000_align_numerahas_columns_with_the_plot_form.php`).
5. If the change touches plot or sale fields, update `SharwaliTarifa`, `pdf.blade.php`, `numerahDocs.blade.php`, the `.docx` template, and `MyNumerahaImport`.
6. Run `vendor/bin/pint --dirty`.
7. Run `php artisan test`.
8. Run `npm run build` only when Tailwind classes, the theme CSS, or Vite entry points change.
9. Describe how to check it by hand: the `/admin` URL, the action to take, the expected result.

---

## State & Security

- Keep database passwords and secrets in `.env` only. Config defaults stay empty.
- Every download route needs `auth`. Unauthenticated users go to `/admin/login` (`redirectGuestsTo` in `bootstrap/app.php`).
- The edit profile tokens and browser sessions are limited to user id 1 in `AdminPanelProvider`. Do not widen that without approval.
- Uploaded files go to the `public` disk under `Customers_images/` and `customer_numeraha/`. Never trust a path from the request; read it from the model.

---

## Code Quality

- Explicit return and parameter types on new or changed methods.
- Curly braces on all control structures.
- String columns are 191 long (`Schema::defaultStringLength(191)`) so MySQL indexes stay valid. Do not set a longer default.
- Remove dead code you replace, but do not sweep through old commented-out blocks outside the task.
- Avoid drive-by refactors outside the requested scope.

---

## Gotchas

- **CI deploys and migrates production.** `.github/workflows/laravel.yml` runs `php artisan migrate --force` against the production database on every push to `main`. A migration must be safe on live data before it is merged.
- **Tests use the `.env` database.** The SQLite lines in `phpunit.xml` are commented out. Do not add `RefreshDatabase` to a test unless you point it at a throwaway database first.
- `NumerahObserver` is not registered (no `#[ObservedBy]`, no `observe()` call). Its notifications do not fire until it is.
- The markets file is `aMarketsResource.php` but the class is `MarketsResource`. Keep the class name if you rename the file.
- The sold and unsold sidebar links in `AdminPanelProvider` are hand-built filter URLs using `env('APP_URL')`. `env()` returns null once config is cached, and the two links expect `APP_URL` to end with `/` while `my-profile` does not. Prefer `url(...)` or a route helper in new links.
- `DatabaseSeeder` imports models in lowercase (`customers`, `numeraha`). That works on Windows but fails on a case-sensitive Linux file system.
- The customers migration is dated `1024_…` so it runs before `numerahas`. Do not "fix" the date.
- Knowledge base articles are cached forever under the `filament_kb_` prefix. Clear the cache after you edit one.

---

## Commands

```bash
# Install
composer install
npm install

# Dev server
php artisan serve
npm run dev

# Build
npm run build

# Format and test
vendor/bin/pint --dirty
php artisan test
```

---

## Important Constraints

**Do:**

- Put admin features in Filament resources, pages, or widgets
- Write labels and notification titles in Pashto
- Keep `numerahas` column names (`numera_id`, `numera_type`, `Land_Area`, `north`/`south`/`east`/`west`) as they are
- Archive plots instead of deleting them

**Do not:**

- Drop tables, run `migrate:fresh` / `migrate:refresh` / `db:wipe`, or delete plot or sale rows without approval
- Enable bulk delete on plots or sales without approval
- Put secrets in config defaults, Blade, or JS
- Add specs to the knowledge base folders
- Create extra documentation files unless the user asks

---

## Communication

Be concise. For each change, state:

- The admin screen and URL (for example `/admin/numerahas`)
- Any new migration and whether it is safe on live data
- Any document template (PDF view or `.docx`) that changed
- Whether `npm run build` is needed
- How to verify by hand

---

## Final Reminder

Before any task:

1. Read **AGENTS.md**, then the folder `AGENTS.md` for the area you touch.
2. Confirm which domain the change belongs to: plots, customers, sales, finance, markets, employees, or users.
3. Copy the nearest existing resource.
4. Remember that a push to `main` migrates production.
5. Keep the admin Pashto, right to left, and consistent.
