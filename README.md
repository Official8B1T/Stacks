# Stacks

Sdílená automatika pro všechny naše stacky (katalog a rozhodnutí: `Templates\Workflow\STACKS.md`). Jeden stack = jedna oprava na jednom místě, ne kopie v každém projektu.

## Struktura

GitHub dovolí sdílené workflow jen přímo v `.github/workflows/`, proto nesou předponu stacku. Všechno ostatní žije ve složce stacku.

| Cesta | Co to je |
|---|---|
| `.github/workflows/web.yml` | Web Stack: audit závislostí, nastartování webu a průchod všemi adresami, sloučení bezpečné aktualizace od Dependabotu |
| `.github/workflows/web-test.yml` | testy Web Stacku: každý verdikt auditu na známém vstupu |
| `web/audit/` | audit, který blokuje jen to, co změna **přidává**; advisory známá už v základu jen varuje |

## Web Stack: napojení webu

`.github/workflows/web.yml` v repozitáři webu:

```yaml
name: Kontrola závislostí a webu
on:
  pull_request:
  push:
    branches: [main]
  schedule:
    - cron: "0 3 * * *"
  workflow_dispatch:
permissions:
  contents: write
  pull-requests: write
jobs:
  web:
    uses: Official8B1T/Stacks/.github/workflows/web.yml@main
    # with:
    #   php-extensions: intl, pdo_sqlite
    #   site-tests: php tests/run.php
    #   min-assets: 3
```

Parametry webu jsou jen vstupy `with:`. Co se mezi weby liší jinak, je nález a patří sem, ne do kopie u webu.

## Pravidla

- Změna tady platí okamžitě pro všechny weby (`@main`). Proto ji musí nejdřív projít `web-test.yml`.
- Kontrola musí umět selhat. Žádná úprava nesmí oslabit test, aby běh zezelenal.
- Přístup: Settings → Actions → General → Access → *Accessible from repositories owned by the user*.
