<?php
declare(strict_types=1);

namespace IwacSeo\Service;

/**
 * Pure text helpers used by the head-metadata pipeline. Extracted from
 * HeadMetadata so the fiddly parts (multibyte truncation, snippet parsing)
 * are unit-testable without a view or settings in play.
 */
final class Text
{
    /**
     * Whitespace-normalise and clip to $max characters on a word boundary,
     * with a trailing ellipsis when clipped (meta descriptions).
     */
    public static function truncate(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max - 1);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > 0) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }
        return rtrim($cut, " ,.;:") . '…';
    }

    /**
     * Split a dcterms:date into schema.org's startDate / endDate pair.
     *
     * 55 of IWAC's 243 event records hold an ISO 8601 *interval* — "2000/2001",
     * "1979-11-04/1981-01-20" — which schema.org expresses as two properties
     * rather than one, and which Google rejects wholesale as "not in ISO 8601
     * format" when it is handed to startDate. Either side that is not a plain
     * ISO date comes back null rather than being passed through: a date a
     * validator cannot read invalidates the node it sits in, so it is worse
     * than no date at all.
     *
     * @return array{0:?string,1:?string} [start, end]; end is null for a
     *   single date, and either may be null when the input is unusable
     */
    public static function dateRange(string $raw): array
    {
        $parts = explode('/', trim($raw), 2);
        return [
            self::isoDate($parts[0]),
            isset($parts[1]) ? self::isoDate($parts[1]) : null,
        ];
    }

    /** A year, year-month or full date (optionally with a time), else null. */
    private static function isoDate(string $value): ?string
    {
        $value = trim($value);
        return preg_match('/^\d{4}(-\d{2}(-\d{2}(T[0-9:.+\-]+Z?)?)?)?$/', $value) === 1
            ? $value
            : null;
    }

    /**
     * Normalise a stored date into the ISO 8601 *date-time* schema.org's
     * uploadDate is validated against.
     *
     * IWAC stores NumericDataTypes timestamps — 1,754 of the 1,790 videos as
     * YYYY-MM-DD, six as YYYY-MM or YYYY — and Search Console rejects every
     * one of them twice over: "incorrect date-time value" because a plain date
     * is not a date-time, and "missing time zone" because it carries no offset.
     * A date the archive records to the day therefore becomes midnight UTC.
     *
     * UTC rather than a West African zone because the offset here is a
     * formality, not a fact: the archive does not record what time of day a
     * video went up, so any wall-clock time it names is invented. UTC invents
     * the least and is what Googlebot would have assumed anyway.
     *
     * A YYYY or YYYY-MM value is completed to the first of the period. That is
     * a day the archive did not record, but uploadDate is *required*: an
     * approximate date keeps the node valid where omitting it fails outright,
     * and the precision the archive does hold is still published verbatim in
     * datePublished alongside it.
     *
     * @return ?string an offset date-time, or null when the value is not a
     *   date at all (which leaves uploadDate unset rather than invalid)
     */
    public static function uploadDate(string $raw): ?string
    {
        $value = trim($raw);
        $time = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?';
        // A date-time already: kept as it stands, or given UTC when it names
        // no offset of its own.
        if (preg_match($time . '(Z|[+\-]\d{2}:?\d{2})$/', $value) === 1) {
            return $value;
        }
        if (preg_match($time . '$/', $value) === 1) {
            return $value . '+00:00';
        }
        // Year, year-month or full date. Month and day nest because a day is
        // only meaningful under a month.
        if (preg_match('/^(\d{4})(?:-(\d{2})(?:-(\d{2}))?)?$/', $value, $m) !== 1) {
            return null;
        }
        [$year, $month, $day] = [(int) $m[1], (int) ($m[2] ?? 1), (int) ($m[3] ?? 1)];
        // A date that is not a date — 2021-02-30, month 21 — is dropped rather
        // than passed on: an unreadable uploadDate invalidates the VideoObject
        // around it, where a missing one only costs the video rich result.
        return checkdate($month, $day, $year)
            ? sprintf('%04d-%02d-%02dT00:00:00+00:00', $year, $month, $day)
            : null;
    }

    /**
     * The player URL for a YouTube link, or null for anything else.
     *
     * 1,743 of IWAC's 1,790 videos are held on YouTube and name the watch page
     * in fabio:hasURL. schema.org's embedUrl wants the player rather than the
     * page it sits on, which for YouTube is the /embed/ form of the same id.
     * Anything that is not a YouTube link — the collection also holds one
     * SoundCloud track and one Wayback capture — comes back null: a URL that
     * does not resolve to a player is worse than no embedUrl at all.
     */
    public static function youtubeEmbedUrl(string $url): ?string
    {
        $url = trim($url);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        $id = null;
        if ($host === 'youtu.be') {
            $id = ltrim($path, '/');
        } elseif (preg_match('/^(www\.)?youtube(-nocookie)?\.com$/', $host)) {
            if ($path === '/watch') {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $id = is_string($query['v'] ?? null) ? $query['v'] : null;
            } elseif (preg_match('~^/(?:embed|shorts|live|v)/([^/?#]+)~', $path, $m)) {
                $id = $m[1];
            }
        }
        // Every id in the archive is YouTube's documented 11 characters; the
        // range is loose enough to survive a format change but tight enough
        // that a stray path segment cannot become an embed URL.
        if ($id === null || preg_match('/^[A-Za-z0-9_-]{8,20}$/', $id) !== 1) {
            return null;
        }
        return 'https://www.youtube.com/embed/' . $id;
    }

    /**
     * The URL with any query string removed; unchanged when it has none.
     *
     * Cuts at the first '?' rather than going through parse_url(), which
     * answers "what is the query" but would need the URL reassembled from its
     * parts to answer "what is the URL without one" — and reassembly is where
     * a malformed URL (parse_url returns false on some) turns into a wrong
     * canonical rather than a no-op.
     */
    public static function withoutQuery(string $url): string
    {
        $mark = strpos($url, '?');
        return $mark === false ? $url : substr($url, 0, $mark);
    }

    /**
     * Accept either a full <meta …> snippet pasted from a search console or a
     * bare token, and return just the token (verification tags).
     */
    public static function extractToken(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (
            stripos($raw, '<meta') !== false
            && preg_match('/content\s*=\s*"([^"]+)"/i', $raw, $m)
        ) {
            return trim($m[1]);
        }
        // Strip accidental surrounding quotes/markup.
        return trim(strip_tags($raw), " \t\n\r\0\x0B\"'");
    }
}
