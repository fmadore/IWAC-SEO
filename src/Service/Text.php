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
     * Accept either a full <meta …> snippet pasted from a search console or a
     * bare token, and return just the token (verification tags).
     */
    public static function extractToken(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (stripos($raw, '<meta') !== false
            && preg_match('/content\s*=\s*"([^"]+)"/i', $raw, $m)
        ) {
            return trim($m[1]);
        }
        // Strip accidental surrounding quotes/markup.
        return trim(strip_tags($raw), " \t\n\r\0\x0B\"'");
    }
}
