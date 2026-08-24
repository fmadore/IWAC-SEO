<?php
declare(strict_types=1);

/**
 * Checks `iwac_seo.hreflang.page_pairs` against the live Omeka S installation.
 *
 * The pair table is a hand-maintained copy of something the site already
 * knows: the Internationalisation module records each page's counterpart and
 * exposes it on the REST API as `o-module-internationalisation:related_page`.
 * That module is the single source of truth — this script only asks whether
 * the committed table still agrees with it.
 *
 * Why a checker rather than reading the module at request time: Hreflang is
 * deliberately pure config, so it costs nothing on a page render (no API, no
 * database). Drift is introduced when someone renames or adds a *page*, not
 * when someone changes this repo, so the check belongs on a schedule rather
 * than in the render path. `composer check` stays hermetic; this is the one
 * check that needs the network.
 *
 * Three kinds of drift are reported, and any of them fails the run:
 *   • missing — the module pairs two pages, page_pairs does not
 *   • wrong   — page_pairs pairs a page with something other than the
 *               module's counterpart (this emits a 404 alternate, which is
 *               worse for SEO than emitting none)
 *   • stale   — page_pairs names a page that no longer exists
 *
 * Pages with no counterpart are listed as notes, not failures: a page that is
 * genuinely untranslated should emit no alternate, which is what an absent
 * row already does.
 *
 * With --write the table is not audited but *generated* from that mapping, so
 * it stops being hand-maintained: the module stays the only place a pairing is
 * authored, and this file becomes a build product that happens to be committed
 * (which is what keeps the render path free of any lookup). Rows are sorted and
 * aligned deterministically, so a run that finds nothing rewrites nothing.
 *
 * Usage: php .github/scripts/check-hreflang-pairs.php [--base-url=URL] [--write] [--quiet]
 *   --base-url  installation to query (default https://islam.zmo.de)
 *   --write     rewrite page_pairs from the module's mapping instead of
 *               reporting drift; exits 0 when it changed the file, 0 when
 *               there was nothing to change
 *   --quiet     print only drift, not the per-site summary
 */

const RELATED_PAGE_TERM = 'o-module-internationalisation:related_page';
const PER_PAGE = 100;

$baseUrl = 'https://islam.zmo.de';
$quiet = false;
$write = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--base-url=')) {
        $baseUrl = rtrim(substr($arg, 11), '/');
    } elseif ($arg === '--quiet') {
        $quiet = true;
    } elseif ($arg === '--write') {
        $write = true;
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(2);
    }
}

/**
 * GET a JSON endpoint. Prefers curl, falling back to the stream wrapper so the
 * script runs wherever PHP does (ext-curl is not a module requirement).
 *
 * @return array<mixed>
 */
function fetchJson(string $url): array
{
    $body = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'iwac-seo-hreflang-check',
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false || $status < 200 || $status >= 300) {
            fwrite(STDERR, sprintf("Request failed (%d) %s %s\n", $status, $url, $error));
            exit(2);
        }
    } else {
        $context = stream_context_create(['http' => [
            'timeout' => 30,
            'header' => "User-Agent: iwac-seo-hreflang-check\r\n",
        ]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            fwrite(STDERR, "Request failed: {$url}\n");
            exit(2);
        }
    }

    /** @var mixed $data */
    $data = json_decode((string) $body, true);
    if (!is_array($data)) {
        fwrite(STDERR, "Response was not a JSON array: {$url}\n");
        exit(2);
    }
    return $data;
}

/**
 * Every page of a paginated Omeka API collection.
 *
 * @return array<int,array<string,mixed>>
 */
function fetchAll(string $baseUrl, string $resource, string $query): array
{
    $out = [];
    for ($page = 1;; $page++) {
        $url = sprintf('%s/api/%s?%s&per_page=%d&page=%d', $baseUrl, $resource, $query, PER_PAGE, $page);
        $batch = fetchJson($url);
        foreach ($batch as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }
        if (count($batch) < PER_PAGE) {
            return $out;
        }
    }
}

/** Canonical key for a pair, so config rows and live relations compare directly. */
function pairKey(array $pair, array $siteSlugs): string
{
    $parts = [];
    foreach ($siteSlugs as $slug) {
        $parts[] = $slug . ':' . ($pair[$slug] ?? '');
    }
    return implode('|', $parts);
}

/**
 * Rewrite the `page_pairs` rows in place from $pairs.
 *
 * Deterministic on purpose — sorted by the first configured site's slug and
 * aligned to one column — so regenerating an already-correct table is a no-op
 * and a real change produces a diff of only the rows that moved.
 *
 * @param array<string,array<string,string>> $pairs
 * @param string[]                           $siteSlugs
 */
function writePairs(string $path, array $pairs, array $siteSlugs): bool
{
    $first = $siteSlugs[0];
    $rows = array_values($pairs);
    usort($rows, static fn(array $a, array $b): int => strcmp($a[$first] ?? '', $b[$first] ?? ''));

    $original = (string) file_get_contents($path);
    $lines = explode("\n", $original);

    $start = null;
    $indent = '';
    foreach ($lines as $i => $line) {
        if (str_contains($line, "'page_pairs' => [")) {
            $start = $i;
            $indent = substr($line, 0, strlen($line) - strlen(ltrim($line)));
            break;
        }
    }
    if ($start === null) {
        fwrite(STDERR, "Could not find 'page_pairs' => [ in {$path}\n");
        exit(2);
    }

    $end = null;
    for ($i = $start + 1, $n = count($lines); $i < $n; $i++) {
        if (rtrim($lines[$i]) === $indent . '],') {
            $end = $i;
            break;
        }
    }
    if ($end === null) {
        fwrite(STDERR, "Could not find the end of the page_pairs block in {$path}\n");
        exit(2);
    }

    // Two segments per row so the second site's key lines up in one column.
    $rowIndent = $indent . '    ';
    $heads = [];
    foreach ($rows as $row) {
        $heads[] = sprintf("['%s' => '%s',", $first, $row[$first] ?? '');
    }
    $width = $heads === [] ? 0 : max(array_map('strlen', $heads));

    $rendered = [];
    foreach ($rows as $index => $row) {
        $tail = [];
        foreach (array_slice($siteSlugs, 1) as $slug) {
            $tail[] = sprintf("'%s' => '%s'", $slug, $row[$slug] ?? '');
        }
        $rendered[] = $rowIndent . str_pad($heads[$index], $width + 1) . implode(', ', $tail) . '],';
    }

    $updated = implode("\n", array_merge(
        array_slice($lines, 0, $start + 1),
        $rendered,
        array_slice($lines, $end)
    ));

    if ($updated === $original) {
        return false;
    }
    file_put_contents($path, $updated);
    return true;
}

$configPath = dirname(__DIR__, 2) . '/config/instance.config.php';
$config = require $configPath;
$hreflang = $config['iwac_seo']['hreflang'] ?? [];
$sites = is_array($hreflang['sites'] ?? null) ? $hreflang['sites'] : [];
$configuredPairs = is_array($hreflang['page_pairs'] ?? null) ? $hreflang['page_pairs'] : [];
$siteSlugs = array_map('strval', array_keys($sites));

if (count($siteSlugs) < 2) {
    echo "hreflang covers fewer than two sites; nothing to check.\n";
    exit(0);
}

// Site slug => Omeka site id, for the configured sites only.
$siteIds = [];
foreach (fetchAll($baseUrl, 'sites', 'sort_by=id') as $site) {
    $slug = (string) ($site['o:slug'] ?? '');
    if (in_array($slug, $siteSlugs, true)) {
        $siteIds[$slug] = (int) ($site['o:id'] ?? 0);
    }
}
$unknown = array_diff($siteSlugs, array_keys($siteIds));
if ($unknown !== []) {
    fwrite(STDERR, sprintf("Configured site(s) not found on %s: %s\n", $baseUrl, implode(', ', $unknown)));
    exit(2);
}

// Page id => [slug, site slug], and the live slug set per site.
$pageById = [];
$slugsBySite = array_fill_keys($siteSlugs, []);
$pagesBySite = [];
foreach ($siteIds as $slug => $id) {
    $pages = fetchAll($baseUrl, 'site_pages', 'site_id=' . $id);
    $pagesBySite[$slug] = $pages;
    foreach ($pages as $page) {
        $pageSlug = (string) ($page['o:slug'] ?? '');
        $pageById[(int) ($page['o:id'] ?? 0)] = ['slug' => $pageSlug, 'site' => $slug];
        $slugsBySite[$slug][$pageSlug] = true;
    }
}

// Pairs the Internationalisation module actually records.
$expected = [];
$untranslated = [];
foreach ($pagesBySite as $slug => $pages) {
    foreach ($pages as $page) {
        $pageSlug = (string) ($page['o:slug'] ?? '');
        $related = is_array($page[RELATED_PAGE_TERM] ?? null) ? $page[RELATED_PAGE_TERM] : [];
        $partners = 0;
        foreach ($related as $relation) {
            $target = $pageById[(int) ($relation['o:id'] ?? 0)] ?? null;
            if ($target === null || $target['site'] === $slug) {
                continue; // outside the configured sites, or same-site
            }
            $partners++;
            $pair = [$slug => $pageSlug, $target['site'] => $target['slug']];
            $expected[pairKey($pair, $siteSlugs)] = $pair;
        }
        if ($partners === 0) {
            $untranslated[] = "{$slug}:{$pageSlug}";
        }
    }
}

if (!$quiet) {
    foreach ($siteSlugs as $slug) {
        printf("%-16s %d pages\n", $slug, count($slugsBySite[$slug]));
    }
    printf(
        "page_pairs rows: %d   module-recorded pairs: %d\n\n",
        count($configuredPairs),
        count($expected)
    );
}

if ($write) {
    if ($expected === []) {
        fwrite(STDERR, "Refusing to write: the API returned no page relations at all.\n");
        exit(2);
    }
    $changed = writePairs($configPath, $expected, $siteSlugs);
    if (!$changed) {
        printf("page_pairs already matches the module (%d pairs); nothing written.\n", count($expected));
        exit(0);
    }
    printf(
        "Rewrote page_pairs from the Internationalisation module: %d pairs (was %d rows).\n",
        count($expected),
        count($configuredPairs)
    );
    exit(0);
}

// Compare.
$configured = [];
$stale = [];
$wrong = [];
foreach ($configuredPairs as $pair) {
    if (!is_array($pair)) {
        continue;
    }
    $pair = array_map('strval', $pair);
    $key = pairKey($pair, $siteSlugs);
    $configured[$key] = $pair;

    $absent = [];
    foreach ($siteSlugs as $slug) {
        $pageSlug = $pair[$slug] ?? '';
        if ($pageSlug === '' || !isset($slugsBySite[$slug][$pageSlug])) {
            $absent[] = "{$slug}:{$pageSlug}";
        }
    }
    if ($absent !== []) {
        $stale[] = ['pair' => $pair, 'absent' => $absent];
        continue;
    }
    if (!isset($expected[$key])) {
        // Both pages exist but the module pairs at least one of them elsewhere.
        foreach ($siteSlugs as $slug) {
            foreach ($expected as $expectedPair) {
                if (($expectedPair[$slug] ?? null) === $pair[$slug]) {
                    $wrong[] = ['pair' => $pair, 'actual' => $expectedPair];
                    continue 3;
                }
            }
        }
        $wrong[] = ['pair' => $pair, 'actual' => null];
    }
}
$missing = array_diff_key($expected, $configured);

$describe = static function (array $pair) use ($siteSlugs): string {
    $parts = [];
    foreach ($siteSlugs as $slug) {
        $parts[] = sprintf("'%s' => '%s'", $slug, $pair[$slug] ?? '');
    }
    return '[' . implode(', ', $parts) . ']';
};

if ($missing !== []) {
    printf("MISSING — the module pairs these, page_pairs does not (%d):\n", count($missing));
    foreach ($missing as $pair) {
        echo '  ' . $describe($pair) . ",\n";
    }
    echo "\n";
}

if ($wrong !== []) {
    printf("WRONG — page_pairs disagrees with the module (%d):\n", count($wrong));
    foreach ($wrong as $row) {
        echo '  ' . $describe($row['pair']) . ' — module says '
            . ($row['actual'] === null ? 'no counterpart' : $describe($row['actual'])) . "\n";
    }
    echo "\n";
}

if ($stale !== []) {
    printf("STALE — page_pairs names pages that do not exist (%d):\n", count($stale));
    foreach ($stale as $row) {
        echo '  ' . $describe($row['pair']) . ' — missing ' . implode(', ', $row['absent']) . "\n";
    }
    echo "\n";
}

if (!$quiet && $untranslated !== []) {
    printf(
        "note: %d page(s) have no counterpart, so they correctly emit no alternate:\n  %s\n\n",
        count($untranslated),
        implode("\n  ", $untranslated)
    );
}

$drift = count($missing) + count($wrong) + count($stale);
if ($drift === 0) {
    echo "page_pairs matches the Internationalisation module.\n";
    exit(0);
}

fwrite(STDERR, sprintf(
    "hreflang drift: %d missing, %d wrong, %d stale. Run `composer hreflang:fix` to regenerate the table.\n",
    count($missing),
    count($wrong),
    count($stale)
));
exit(1);
