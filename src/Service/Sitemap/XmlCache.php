<?php
declare(strict_types=1);

namespace IwacSeo\Service\Sitemap;

/** Atomic publication, generation invalidation and a per-document build lock. */
final class XmlCache
{
    private bool $cleared = false;

    public function __construct(private readonly ?string $directory)
    {
    }

    /** @param callable():string $build */
    public function remember(string $key, int $ttl, callable $build): SitemapDocument
    {
        if ($this->directory === null || $ttl <= 0) {
            return new SitemapDocument($build(), time());
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
            $deadline = microtime(true) + 5;
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
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
