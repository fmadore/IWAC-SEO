# IWAC SEO

Omeka S module supplying the SEO, citation and sitemap layer for the Islam West
Africa Collection (islam.zmo.de). `README.md` covers the architecture, every
setting, and the schema.org / Zotero field conventions — read the relevant
section before changing behaviour. This file is only the things that bite.

## PHP yes, Composer no

`php` **is** on PATH (8.5.8, winget), so `php -l` works and the unit suite can
be run. `composer` is not, and `vendor/` is never committed, so the dev tools
have to be borrowed from a sibling checkout that has them installed —
`../IwacSearch/vendor` carries PHPUnit 11 and PHPStan 2, both matching this
module's constraints. PHP_CodeSniffer is in neither, so PSR-12 is still
read-checked and gated in CI.

The winget build loads no `php.ini`, so `mbstring` (which PHPUnit requires) and
`fileinfo` have to be switched on per invocation:

```bash
php -d extension_dir="$LOCALAPPDATA/Microsoft/WinGet/Packages/PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe/ext" -d extension=mbstring -d extension=fileinfo ../IwacSearch/vendor/phpstan/phpstan/phpstan.phar analyse -c phpstan.neon.dist --no-progress
```

PHPUnit needs a bootstrap that stands in for the missing Composer autoloader:
require `../IwacSearch/vendor/autoload.php`, `spl_autoload_register` the two
PSR-4 roots (`IwacSeo\` → `src/`, `IwacSeo\Test\` → `tests/`), then
`tests/Shim/omeka.php`. Keep that runner in a scratch directory — it is a local
convenience, not part of the module.

**`tests/Integration` still cannot run here**: it needs a real Omeka S install
(its CI job downloads and boots one), so those tests remain CI-verified only.
Say which suite you actually ran; don't call a change tested on the strength of
the unit suite when the assertion lives in the integration one.

## CI is still the gate

`.github/workflows/ci.yml` has two jobs, together covering `composer check`:
`test` (a `php -l` sweep plus PHPUnit on 8.2–8.5, including production 8.5) and
`quality` (strict Composer validation, `i18n:check`, `lint`, `analyse`, on 8.2
only). Composer downloads are cached in both jobs. The `quality` checks carry
`if: ${{ !cancelled() }}` so one push reports every category at once instead of
revealing them a re-run at a time — worth preserving: the local runs above
cover neither PSR-12 nor the integration suite, and only CI sees 8.2.

`phpstan-baseline.neon` records 59 findings that predate enforcement — 56
missing array value types plus three judgement calls. Level 6 applies in full
to anything new; the baseline is a ledger to shrink, not a suppression list.
Delete entries as they are fixed. Do not regenerate it to make a new error go
away, and note that regenerating while it sits in `includes` would emit a
baseline covering only the *new* errors and silently drop the recorded debt.

The translation template tracks *strings*, not layout: its `#:` references
carry file paths without line numbers, precisely so that moving a string does
not invalidate it. Adding or removing a `// @translate` string still does, and
`composer i18n` regenerates it — but that needs PHP, so in practice the
`quality` job does it for you and uploads the result as an artefact when the
gate fails. Download it, commit it.

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
