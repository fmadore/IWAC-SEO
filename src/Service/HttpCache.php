<?php
declare(strict_types=1);

namespace IwacSeo\Service;

/**
 * Cache validators for a generated download: a strong ETag over the body and
 * the record's last change as Last-Modified, and whether a conditional request
 * already holds the current copy (RFC 9110 §13: If-None-Match wins over
 * If-Modified-Since).
 *
 * The ETag is the authoritative validator. A citation also reads linked
 * records — an author's authority record, the newspaper's item set — and a
 * static page's citation fields live in the SEO table, not on the page, so an
 * edit to either changes the body without changing the record's modified date.
 * A client that sends If-None-Match, as browsers and shared caches do once
 * they hold an ETag, is answered from the body; If-Modified-Since alone is
 * honoured as the fallback the protocol makes it.
 *
 * Ported from DRE-SEO, which shares the rules.
 */
final class HttpCache
{
    /** How long a shared cache or a browser may reuse a download without asking. */
    public const MAX_AGE = 300;

    public static function etag(string $body): string
    {
        return '"' . substr(hash('sha256', $body), 0, 32) . '"';
    }

    public static function lastModified(?\DateTimeInterface $modified): ?string
    {
        if ($modified === null) {
            return null;
        }
        return \DateTimeImmutable::createFromInterface($modified)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('D, d M Y H:i:s') . ' GMT';
    }

    /**
     * Whether the client's copy is current, so a 304 answers the request.
     *
     * @param string|null $ifNoneMatch     the request's If-None-Match header value
     * @param string|null $ifModifiedSince the request's If-Modified-Since header value
     */
    public static function notModified(
        string $etag,
        ?\DateTimeInterface $modified,
        ?string $ifNoneMatch,
        ?string $ifModifiedSince
    ): bool {
        if ($ifNoneMatch !== null && trim($ifNoneMatch) !== '') {
            foreach (explode(',', $ifNoneMatch) as $candidate) {
                $candidate = trim($candidate);
                // If-None-Match compares weakly, so W/"x" matches "x".
                if ($candidate === '*' || preg_replace('/^W\//', '', $candidate) === $etag) {
                    return true;
                }
            }
            return false;
        }
        if ($modified === null || $ifModifiedSince === null || trim($ifModifiedSince) === '') {
            return false;
        }
        $since = strtotime($ifModifiedSince);
        return $since !== false && $modified->getTimestamp() <= $since;
    }
}
