<?php
declare(strict_types=1);

namespace IwacSeo\Service\Sitemap;

/**
 * TTL file cache for the generated sitemap documents.
 *
 * Every failure mode falls back to live generation: an unwritable directory, a
 * vanished file, a partial read. Caching a sitemap is an optimisation, never a
 * correctness requirement, so nothing here may throw.
 *
 * Keys are namespaced by site id. They deliberately do NOT include the host:
 * the generated XML embeds the request-derived canonical host, so the first
 * request's host is baked in for the TTL. That is fine for IWAC's single-host
 * deployment; add the host to the key if the instance ever answers on several.
 */
final class XmlCache
{
    /** Whether clear() has already run this request — see clear(). */
    private bool $cleared = false;

    public function __construct(private readonly ?string $directory)
    {
    }

    /**
     * Return the cached document for $key if it is younger than $ttl, else
     * build, store and return a fresh one.
     *
     * @param callable():string $build
     */
    public function remember(string $key, int $ttl, callable $build): SitemapDocument
    {
        if ($this->directory === null || $ttl <= 0) {
            return new SitemapDocument($build(), time());
        }

        $file = $this->path($key);
        try {
            $mtime = is_file($file) ? filemtime($file) : false;
            if ($mtime !== false && (time() - $mtime) < $ttl) {
                $cached = file_get_contents($file);
                if ($cached !== false) {
                    return new SitemapDocument($cached, $mtime);
                }
            }
        } catch (\Throwable $e) {
            // fall through to a live build
        }

        $xml = $build();
        $this->store($file, $xml);
        return new SitemapDocument($xml, time());
    }

    /**
     * Drop every cached document.
     *
     * Debounced per request: the API listener calls this on every content
     * change, so a bulk import of N items would otherwise run N glob-and-unlink
     * cycles to achieve what the first one already did. Writing a document back
     * to the cache re-arms it, so a later clear in the same request still bites.
     */
    public function clear(): void
    {
        if ($this->cleared || $this->directory === null || !is_dir($this->directory)) {
            return;
        }
        $this->cleared = true;
        foreach (glob($this->directory . '/*.xml') ?: [] as $file) {
            @unlink($file);
        }
    }

    /** Clear the cache and remove the directory itself (uninstall). */
    public function destroy(): void
    {
        $this->cleared = false; // uninstall must always sweep, debounce or not
        $this->clear();
        if ($this->directory !== null && is_dir($this->directory)) {
            @rmdir($this->directory);
        }
    }

    private function path(string $key): string
    {
        // Keys are built from an int site id and a fixed set of type names, so
        // they are already filename-safe; the filter is belt and braces.
        return $this->directory . '/' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $key) . '.xml';
    }

    private function store(string $file, string $xml): void
    {
        if ($this->directory === null) {
            return;
        }
        try {
            if (!is_dir($this->directory)) {
                @mkdir($this->directory, 0775, true);
            }
            if (is_dir($this->directory) && is_writable($this->directory)) {
                file_put_contents($file, $xml, LOCK_EX);
                $this->cleared = false;
            }
        } catch (\Throwable $e) {
            // caching is best-effort
        }
    }
}
