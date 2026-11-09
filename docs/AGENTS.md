# Help articles

## Overview

These markdown files are the in app help. Filament Knowledge Base reads them and shows them on the `/kb` panel. They are not the architecture specs.

## Key files

| File | Owns |
|---|---|
| `config/filament-knowledge-base.php` | Docs folder, panel id `knowledge-base`, public path `kb` |
| `docs/ps/` | Pashto articles |
| `docs/en/` | English folder. Some articles in here are still written in Pashto |
| `docs/de/` | German folder, same article shape |

## Conventions

- Each article starts with front matter: `title` and `icon` (a Heroicon name).
- Put the file in a locale folder (`ps`, `en`, or `de`), then a section folder, then the article.
- Write the body in markdown. The panel shows the `title` from the front matter.

## Gotchas

- Specs for this repo do not belong here. Specs go in `docs/specs/` when `/scope` creates them. This folder is user help only.
- The cache prefix is `filament_kb_` and the default lifetime is forever. After you edit an article, clear the app cache if the panel still shows the old text.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
