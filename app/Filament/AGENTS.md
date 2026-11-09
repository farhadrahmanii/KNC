# Filament admin

## Overview

This is the whole back office. Staff manage plots, customers, sales, and money here. The panel lives at `/admin` and is registered in `app/Providers/Filament/AdminPanelProvider.php`.

## Key files

| File | Owns |
|---|---|
| `app/Providers/Filament/AdminPanelProvider.php` | Panel path, plugins, and the sidebar links for sold and unsold plots |
| `app/Filament/Resources/NumerahaResource.php` | Plot list and the plot form |
| `app/Filament/Resources/CustomerNumerahaResource.php` | Sale record: customer, plot, payments, and files |
| `app/Filament/Resources/NumerahaIncomeResource.php` | The same sale model, shown under money |
| `app/Filament/Resources/aMarketsResource.php` | Markets screen. The class name is `MarketsResource` |

## Conventions

- Put a new screen in `app/Filament/Resources`. The panel discovers it. You do not add a route by hand.
- Write field labels in Pashto on the component with `->label(...)`. A lang file does not translate these forms.
- Navigation groups already in use: `د مشتریانو مدیریت`, `مالی مدیریت`, and `ډیټابیس مدیریان`.
- A plot is `Numeraha`. A sale is `CustomerNumeraha`. The income screen reuses the sale model on purpose.
- `Numeraha` uses the archive trait. The list query turns the archive scope off so the archive filter can show hidden rows too.
- Plot boundaries are `north`, `south`, `east`, and `west`. The type field is `numera_type`. The public code is `numera_id`.

## Gotchas

- The markets file is named `aMarketsResource.php` while the class is `MarketsResource`. Keep that class name if you rename the file, or the page folder and discovery fall out of step.
- The sold and unsold sidebar links are filter URLs on the plot list. The badges count plots that do, or do not, have customers.
- Document downloads are not Filament pages. `routes/web.php` sends them to `SharwaliTarifa`, and a guest is sent to `/admin/login`.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
