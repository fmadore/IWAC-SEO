# IWAC SEO

Omeka S module supplying the SEO, citation and sitemap layer for the Islam West
Africa Collection (islam.zmo.de). `README.md` covers the architecture, every
setting, and the schema.org / Zotero field conventions — read the relevant
section before changing behaviour. This file is only the things that bite.

## There is no PHP on this machine

Neither `php` nor `composer` is on PATH, and `vendor/` is never committed — so
**the test suite cannot be run locally**. The same was true when 0.7.0 was
refactored, which is why PHPStan and PHP_CodeSniffer are configured and
runnable but have never actually been run against this codebase (see the status
block at the top of `ROADMAP.md`).

Consequence: changes are verified by reading, and CI is the only gate that
actually executes anything. Don't report a change as tested. For the class of
mistake PHP would have caught for free — a typo'd method, a wrong arity, a
renamed constant — re-read the call sites instead.

## CI is the only place the checks actually run

`.github/workflows/ci.yml` has two jobs, together covering `composer check`:
`test` (a `php -l` sweep plus PHPUnit on 8.2–8.4) and `quality` (`i18n:check`,
`lint`, `analyse`, on 8.2 only). The `quality` steps carry `if: ${{ !cancelled() }}`
so one push reports all three categories at once instead of revealing them a
re-run at a time — worth preserving, since there's no local run to fall back on.

`phpstan-baseline.neon` records 59 findings that predate enforcement — 56
missing array value types plus three judgement calls. Level 6 applies in full
to anything new; the baseline is a ledger to shrink, not a suppression list.
Delete entries as they are fixed. Do not regenerate it to make a new error go
away, and note that regenerating while it sits in `includes` would emit a
baseline covering only the *new* errors and silently drop the recorded debt.

The check most likely to trip you is the translation template, and it is
stricter than it looks: its `#:` references carry line numbers, so removing a
blank line anywhere above a `// @translate` string fails the gate with no
string having changed. `composer i18n` regenerates it — but that needs PHP, so
in practice the `quality` job does it for you and uploads the result as an
artefact when the gate fails. Download it, commit it.

## Gotchas

- **Dispatch is by resource *class* id, never by template.** IWAC's templates
  aren't 1:1 with classes (template 8 held both `bibo:Article` and `bibo:Issue`),
  so both the schema.org and the citation maps key off class id. README, "Why
  dispatch on resource class, not template".
- **`config/module.config.php` is wiring; `config/instance.config.php` is IWAC
  data** — the class→`@type` map, the class→citation-kind map, and the 33
  hreflang `page_pairs`. They're combined with `+`, not `array_merge_recursive`,
  so the top-level keys must stay disjoint. Adding or renaming a static page
  needs a `page_pairs` entry; the dashboard's coverage report exists precisely
  because that drifts.
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
