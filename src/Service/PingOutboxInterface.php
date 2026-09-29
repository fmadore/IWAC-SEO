<?php
declare(strict_types=1);

namespace IwacSeo\Service;

/**
 * Where changed URLs wait for IndexNow: pushed on save, leased in batches by
 * the job, acknowledged or released for retry. {@see PingRepository} is the
 * durable table; the interface keeps {@see PingQueue}'s dispatch policy
 * testable without a database.
 */
interface PingOutboxInterface
{
    /** Queue a URL, or bump an already queued one so a newer edit survives. */
    public function push(string $url): void;

    /**
     * Lease up to $limit due URLs.
     *
     * @return array<int,array<string,mixed>> the leased rows
     */
    public function claim(int $limit = 200): array;

    /**
     * Acknowledge accepted rows, or release failed ones for a later retry.
     *
     * @param array<int,array<string,mixed>> $rows rows returned by claim()
     */
    public function finish(array $rows, bool $success): void;
}
