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
