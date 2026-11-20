<?php
declare(strict_types=1);

namespace IwacSeo\Service\Sitemap;

/**
 * Atomic publication, generation invalidation and a per-document build lock.
 *
 * A fresh hit is read without the lock: store() publishes by rename(), so a
 * reader sees either the old document or the new one, never half of either.
 * Only a miss takes the lock, and re-checks under it, so that one process
 * rebuilds while the others wait — and, should the rebuild outlast the wait,
 * get the previous document rather than an error.
 */
final class XmlCache
{
    private bool $cleared = false;

    /**
     * @param float $lockWait seconds a miss waits for another process's
     *   rebuild of the same document before serving the previous one
     */
    public function __construct(
        private readonly ?string $directory,
        private readonly float $lockWait = 5.0,
    ) {
    }

    /** @param callable():string $build */
    public function remember(string $key, int $ttl, callable $build): SitemapDocument
    {
        if ($this->directory === null || $ttl <= 0) {
            return new SitemapDocument($build(), time());
        }
        $hit = $this->cached($key, $ttl);
        if ($hit !== null) {
            return $hit;
        }
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }
        $file = $this->path($key);
        $lock = @fopen($file . '.lock', 'c');
        if ($lock === false) {
            return new SitemapDocument($build(), time());
        }
        try {
            $deadline = microtime(true) + $this->lockWait;
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    // Another process is rebuilding this document: serve the
                    // copy it is replacing, if it is of the current generation.
                    $stale = $this->read($file, PHP_INT_MAX);
                    if ($stale !== null) {
                        return $stale;
                    }
                    throw new \RuntimeException('Sitemap rebuild is already running.');
                }
                usleep(50000);
            }
            $generation = $this->generation();
            clearstatcache(true, $file);
            $mtime = is_file($file) ? filemtime($file) : false;
            $cached = $mtime !== false ? @file_get_contents($file) : false;
            if ($generation !== $this->generation() || @file_get_contents($file . '.generation') !== $generation) {
                $cached = false;
            }
            if ($cached !== false && $cached !== '' && time() - $mtime < $ttl) {
                return new SitemapDocument($cached, $mtime);
            }
            try {
                $xml = $build();
            } catch (\Throwable $error) {
                if (
                    $cached !== false && $cached !== '' && $mtime !== false
                    && time() - $mtime < $ttl + 300 && $generation === $this->generation()
                ) {
                    error_log('IwacSeo: stale sitemap after generation failure: ' . $error->getMessage());
                    return new SitemapDocument($cached, $mtime);
                }
                throw $error;
            }
            $guard = @fopen($this->directory . '/generation.lock', 'c');
            if ($guard !== false) {
                try {
                    if (flock($guard, LOCK_EX) && $generation === $this->generation()) {
                        $this->store($file, $xml);
                        file_put_contents($file . '.generation', $generation);
                    }
                } finally {
                    flock($guard, LOCK_UN);
                    fclose($guard);
                }
            }
            return new SitemapDocument($xml, time());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Invalidate every document. Only the sweep of the files is debounced
     * within a request; the generation is rewritten on every call, and must
     * be: during a bulk import another process may have started a rebuild
     * between two saves, and only a new generation stops it publishing a
     * document that misses the later ones. One small file write per save is
     * the price.
     */
    public function clear(): void
    {
        if ($this->directory === null || !is_dir($this->directory)) {
            return;
        }
        $guard = @fopen($this->directory . '/generation.lock', 'c');
        if ($guard === false) {
            throw new \RuntimeException('Cannot lock sitemap invalidation.');
        }
        try {
            if (!flock($guard, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock sitemap invalidation.');
            }
            if (file_put_contents($this->directory . '/generation', bin2hex(random_bytes(16))) === false) {
                throw new \RuntimeException('Cannot invalidate sitemap generation.');
            }
            if (!$this->cleared) {
                foreach (glob($this->directory . '/*.xml') ?: [] as $file) {
                    @unlink($file);
                    @unlink($file . '.generation');
                }
            }
            $this->cleared = true;
        } finally {
            flock($guard, LOCK_UN);
            fclose($guard);
        }
    }

    public function destroy(): void
    {
        $this->cleared = false;
        $this->clear();
        if ($this->directory !== null && is_dir($this->directory)) {
            foreach (glob($this->directory . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->directory);
        }
    }

    /**
     * The cached document if it is fresh and of the current generation, read
     * without taking any lock; null on a miss. Costs no database query, which
     * is what lets a caller try it before a bounds check that does.
     */
    public function cached(string $key, int $ttl): ?SitemapDocument
    {
        if ($this->directory === null || $ttl <= 0) {
            return null;
        }
        return $this->read($this->path($key), $ttl);
    }

    /**
     * Lock-free read. The generation sidecar is read before the document and
     * is written only after the document's rename(), so a current sidecar
     * guarantees the document read next is the one it describes.
     */
    private function read(string $file, int $ttl): ?SitemapDocument
    {
        $generation = $this->generation();
        if (@file_get_contents($file . '.generation') !== $generation) {
            return null;
        }
        clearstatcache(true, $file);
        $mtime = is_file($file) ? filemtime($file) : false;
        if ($mtime === false || time() - $mtime >= $ttl) {
            return null;
        }
        $xml = @file_get_contents($file);
        if ($xml === false || $xml === '' || $this->generation() !== $generation) {
            return null;
        }
        return new SitemapDocument($xml, $mtime);
    }

    private function generation(): string
    {
        return (string) @file_get_contents($this->directory . '/generation');
    }

    private function path(string $key): string
    {
        return $this->directory . '/' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $key) . '.xml';
    }

    private function store(string $file, string $xml): void
    {
        $temporary = @tempnam((string) $this->directory, 'sitemap-');
        if ($temporary === false) {
            return;
        }
        try {
            if (file_put_contents($temporary, $xml) === strlen($xml) && @rename($temporary, $file)) {
                $this->cleared = false;
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
