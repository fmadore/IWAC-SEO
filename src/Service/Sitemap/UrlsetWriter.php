<?php
declare(strict_types=1);

namespace IwacSeo\Service\Sitemap;

/**
 * Renders the sitemaps.org XML: a `<sitemapindex>` of child documents, or a
 * `<urlset>` of URLs with optional lastmod, changefreq, priority, hreflang
 * alternates and image entries.
 *
 * Deliberately string-built rather than DOM/XMLWriter — the output is a fixed,
 * shallow shape and the module carries no bundled vendor/ — but every value
 * that reaches the output goes through esc(), including the config-sourced
 * changefreq and priority. Those two used to be interpolated raw: safe in
 * practice, since they come from config rather than content, but the asymmetry
 * meant a future config edit could emit XML no crawler would parse.
 *
 * Pure: no database, no filesystem, no clock. This is the class that produces
 * the actual protocol output, and now the one that can be asserted against.
 */
final class UrlsetWriter
{
    private const XMLNS = 'http://www.sitemaps.org/schemas/sitemap/0.9';
    private const XMLNS_XHTML = 'http://www.w3.org/1999/xhtml';
    private const XMLNS_IMAGE = 'http://www.google.com/schemas/sitemap-image/1.1';

    /**
     * A sitemap index listing child documents, all stamped with $lastmod.
     *
     * @param string[] $childUrls absolute URLs of the child sitemaps
     */
    public function renderIndex(array $childUrls, string $lastmod): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<sitemapindex xmlns="' . self::XMLNS . '">' . "\n";
        foreach ($childUrls as $url) {
            $xml .= '  <sitemap><loc>' . $this->esc($url) . '</loc>'
                . '<lastmod>' . $this->esc($lastmod) . '</lastmod></sitemap>' . "\n";
        }
        return $xml . '</sitemapindex>' . "\n";
    }

    /**
     * @param array<array{
     *     loc:string,
     *     lastmod?:?string,
     *     changefreq?:?string,
     *     priority?:?string,
     *     alternates?:array<array{hreflang:string,href:string}>,
     *     image?:?string
     * }> $urls
     */
    public function renderUrlset(array $urls): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="' . self::XMLNS . '"' . $this->namespaces($urls) . '>' . "\n";

        foreach ($urls as $url) {
            $xml .= '  <url><loc>' . $this->esc((string) $url['loc']) . '</loc>';
            foreach (['lastmod', 'changefreq', 'priority'] as $tag) {
                if (!empty($url[$tag])) {
                    $xml .= '<' . $tag . '>' . $this->esc((string) $url[$tag]) . '</' . $tag . '>';
                }
            }
            foreach ($url['alternates'] ?? [] as $alternate) {
                $xml .= '<xhtml:link rel="alternate" hreflang="' . $this->esc((string) $alternate['hreflang'])
                    . '" href="' . $this->esc((string) $alternate['href']) . '"/>';
            }
            if (!empty($url['image'])) {
                $xml .= '<image:image><image:loc>' . $this->esc((string) $url['image'])
                    . '</image:loc></image:image>';
            }
            $xml .= '</url>' . "\n";
        }

        return $xml . '</urlset>' . "\n";
    }

    /**
     * A database datetime as a W3C timestamp, or null when absent/unparseable.
     * Stored values are UTC.
     */
    public static function w3cDate(?string $dbDatetime): ?string
    {
        if (!$dbDatetime) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($dbDatetime, new \DateTimeZone('UTC')))->format('c');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** The current moment as a W3C timestamp. */
    public static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');
    }

    /** Declare the xhtml/image namespaces only when the document uses them. */
    private function namespaces(array $urls): string
    {
        $hasAlternates = false;
        $hasImages = false;
        foreach ($urls as $url) {
            $hasAlternates = $hasAlternates || !empty($url['alternates']);
            $hasImages = $hasImages || !empty($url['image']);
            if ($hasAlternates && $hasImages) {
                break;
            }
        }
        return ($hasAlternates ? ' xmlns:xhtml="' . self::XMLNS_XHTML . '"' : '')
            . ($hasImages ? ' xmlns:image="' . self::XMLNS_IMAGE . '"' : '');
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
