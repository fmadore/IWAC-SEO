# IWAC SEO

Omeka S module supplying the SEO, citation and sitemap layer for the Islam West
Africa Collection (islam.zmo.de). `README.md` covers the architecture, every
setting, and the schema.org / Zotero field conventions — read the relevant
section before changing behaviour. This file is only the things that bite.

## The whole suite runs locally

`vendor/` is installed and **every check CI runs can be run here** — including
PSR-12 and the real-Omeka integration suite. Three things on this machine make
the obvious commands fail:

- `php` on PATH is **PHP 8.5** (winget `PHP.PHP.8.5_…`), but `composer` /
  `composer.bat` still forwards to the old PHP 8.2 install, and its `@php`
  scripts then fail the 8.4 platform check. Run the phar with the PHP on PATH;
  the phar itself does not care which.
- `composer test` (and so `composer check`) calls `phpunit`, which has no
  `.bat` shim in `vendor/bin` — Composer writes those only for the packages it
  installed on Windows (here phpcs and phpcbf). Run `php vendor/bin/phpunit`.
- The integration suite needs `OMEKA_PATH` (below).

```bash
C=C:/Users/frede/AppData/Local/Microsoft/WinGet/Packages/PHP.PHP.8.2_Microsoft.Winget.Source_8wekyb3d8bbwe/composer.phar
php $C i18n:check && php $C lint && php $C analyse   # composer check, bar the tests
php vendor/bin/phpunit
OMEKA_PATH=C:/Users/frede/GitHub/IWAC-SEO/.integration/omeka-s \
  php tests/Integration/run.php --configuration phpunit.integration.xml.dist
```

A shell started before an install will not see the new PHP — that looks
exactly like "PHP is not installed", so open a new one before concluding
anything. `php --ini` names the ini in use. Three of its settings are
load-bearing; if a tool fails oddly, check these first:

- `extension_dir` uncommented, plus `mbstring`, `openssl`, `curl`, `zip`,
  `fileinfo`, `intl`, `sqlite3`, `pdo_sqlite` and `pdo_mysql` (Omeka's own
  `composer install` refuses to resolve without the last one).
- `memory_limit = 1G`. PHPStan's parallel workers OOM at the stock 128M and
  report it as a crash, not as an analysis failure.
- `curl.cainfo` / `openssl.cafile` pointing at a `cacert.pem` (one sits beside
  the 8.2 `php.exe`). The 8.5 ini sets neither; without them
  `composer hreflang:check` cannot verify islam.zmo.de's certificate and dies
  on a TLS error that looks like a site outage.

If PHPStan reports a permission error on its result cache in `%TEMP%`, another
project's run is sharing it: run it again.

`tests/Integration` needs `OMEKA_PATH` set to a real Omeka S tree. A v4.2.1
checkout lives in the gitignored `.integration/omeka-s` — the same path CI's
integration job uses:

```bash
git clone --depth 1 --branch v4.2.1 https://github.com/omeka/omeka-s.git .integration/omeka-s
composer install --working-dir=.integration/omeka-s --no-dev
```

Consequence: **run the checks before pushing, and say which ones you ran.**
v1.0.3 shipped with two stale test expectations because they were only ever
checked in CI, and the tag published before CI went red. There is no longer any
excuse for that, and none for reporting a change as verified by reading.

## CI still sees things a local run cannot

`.github/workflows/ci.yml` has two jobs, together covering `composer check`:
`test` (a `php -l` sweep plus PHPUnit on 8.4 and production 8.5) and
`quality` (strict Composer validation, `i18n:check`, `lint`, `analyse`, on 8.4
only). Composer downloads are cached in both jobs. The `quality` checks carry
`if: ${{ !cancelled() }}` so one push reports every category at once instead of
revealing them a re-run at a time.

A local run is now a genuine gate rather than a rehearsal, but it is one PHP
version: 8.5, as in production. CI is what covers the 8.4 floor. Watch it before tagging: a tag
is not retractable. The release workflow now runs the `quality` gates and
PHPUnit on the tag before it builds the zip (and publishes a `.sha256` beside
it), but only at the 8.4 floor and without the integration suite — it is a last
gate, not a substitute for a green CI run.

`phpstan-baseline.neon` records 59 findings that predate enforcement — 56
missing array value types plus three judgement calls. Level 6 applies in full
to anything new; the baseline is a ledger to shrink, not a suppression list.
Delete entries as they are fixed. Do not regenerate it to make a new error go
away, and note that regenerating while it sits in `includes` would emit a
baseline covering only the *new* errors and silently drop the recorded debt.

The translation template tracks *strings*, not layout: its `#:` references
carry file paths without line numbers, precisely so that moving a string does
not invalidate it. Adding or removing a `// @translate` string still does, and
`composer i18n` regenerates it — locally now, so regenerate and commit rather
than waiting for the `quality` job to upload one as an artefact. References are
written with forward slashes whatever the OS, and `.integration/` is skipped;
both were fixed in 1.0.4, without which the check could not pass on Windows and
the template filled with Omeka's own strings.

## Gotchas

- **Dispatch is by resource *class* id, never by template.** IWAC's templates
  aren't 1:1 with classes (template 8 held both `bibo:Article` and `bibo:Issue`),
  so both the schema.org and the citation maps key off class id. README, "Why
  dispatch on resource class, not template".
- **`config/module.config.php` is wiring; `config/instance.config.php` is IWAC
  data** — the class→`@type` map, the class→citation-kind map, and the 38
  hreflang `page_pairs`. They're combined with `+`, not `array_merge_recursive`,
  so the top-level keys must stay disjoint. `page_pairs` is *generated* from the
  Internationalisation module, not authored: adding or renaming a static page
  invalidates it, and the fix is the `hreflang drift` workflow (weekly, or
  `gh workflow run "hreflang drift"`), never a hand-edit — a hand-edit is
  reverted by the next regeneration. The dashboard's coverage report exists
  precisely because that drifts.
- **`view.show.after` discards listener return values.** `Module::handleResourceShow()`
  therefore `echo`s the unAPI `<abbr>` markup straight into the show template's
  output buffer. It isn't an oversight — don't convert it to a return.
- **Head signals are two-phase.** Resource and page listeners write first, the
  `view.layout` listener gap-fills afterwards; resource values win because they
  ran earlier. `HeadWriter` owns the placeholder setters and tracks what the
  request already set — write through it, not through the head helpers directly.
- **Tests run against shims, not Omeka.** `tests/Shim/omeka.php` carries only the
  narrow surface the module touches, guarded by `class_exists()` so a real
  installation always wins. A test that needs more than the shim is a test that
  belongs against a real installation, not a bigger shim.
- **No runtime dependencies, and no bundled `vendor/`** — deliberate, and
  `composer.json` should stay that way. `composer install` pulls dev tools only.
- The module folder must be named `IwacSeo` inside Omeka's `modules/`; the
  namespace resolution depends on it.

## Conventions

PSR-12, 4-space, LF. British English in prose and comments (`optimisation`,
`serialiser`, `normalise`) — except where an identifier mirrors a vocabulary
term, which keeps its source spelling (schema.org `Organization`, `foaf:Organization`).
Translatable strings are marked with a trailing `// @translate` in PHP and
`$this->translate()` in `.phtml`.
