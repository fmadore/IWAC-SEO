<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/** Durable outbox. Versioned leases make acknowledgement safe during concurrent edits. */
final class PingRepository implements PingOutboxInterface
{
    /**
     * Attempts after which a URL stops being sent: it is "failed" on the
     * dashboard until retryFailed() re-queues it. Retryable failures reach it
     * through backoff; a refusal is set to it at once.
     */
    public const MAX_ATTEMPTS = 5;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function install(): void
    {
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS iwac_seo_ping (
            id CHAR(64) PRIMARY KEY, url TEXT NOT NULL, version INTEGER NOT NULL,
            token CHAR(32) NOT NULL, lease_until BIGINT NOT NULL, available_at BIGINT NOT NULL,
            attempts INTEGER NOT NULL)');
        if (!isset($this->connection->getSchemaManager()->listTableIndexes('iwac_seo_ping')['iwac_seo_ping_due'])) {
            $this->connection->executeStatement('CREATE INDEX iwac_seo_ping_due ON iwac_seo_ping (available_at, lease_until, attempts)');
        }
    }

    public function push(string $url): void
    {
        if (MetadataValue::url($url) === null) {
            return;
        }
        $id = hash('sha256', $url);
        try {
            $this->connection->insert('iwac_seo_ping', ['id' => $id, 'url' => $url, 'version' => 1,
                'token' => '', 'lease_until' => 0, 'available_at' => time(), 'attempts' => 0]);
        } catch (UniqueConstraintViolationException $e) {
            $this->connection->executeStatement(
                'UPDATE iwac_seo_ping SET version = version + 1,
                token = :token, lease_until = 0, available_at = :now, attempts = 0 WHERE id = :id',
                ['token' => '', 'now' => time(), 'id' => $id]
            );
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function claim(int $limit = 200, ?int $now = null): array
    {
        $now ??= time();
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM iwac_seo_ping
            WHERE available_at <= :now AND lease_until <= :now AND attempts < :cap
            ORDER BY available_at, id LIMIT ' . max(1, min($limit, 200)), ['now' => $now, 'cap' => self::MAX_ATTEMPTS]);
        $claimed = [];
        foreach ($rows as $row) {
            $token = bin2hex(random_bytes(16));
            $changed = $this->connection->executeStatement(
                'UPDATE iwac_seo_ping SET token = :token,
                lease_until = :lease WHERE id = :id AND version = :version AND lease_until <= :now',
                ['token' => $token, 'lease' => $now + 600, 'id' => $row['id'], 'version' => $row['version'], 'now' => $now]
            );
            if ($changed === 1) {
                $row['token'] = $token;
                $claimed[] = $row;
            }
        }
        return $claimed;
    }

    /**
     * Every write is conditioned on the lease's token and the row's version,
     * so a URL edited again while its batch was in flight keeps the newer
     * edit's fresh state whatever the outcome of the older submission.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    public function finish(array $rows, PingOutcome $outcome, ?int $now = null): void
    {
        $now ??= time();
        foreach ($rows as $row) {
            $where = ['id' => $row['id'], 'token' => $row['token'], 'version' => $row['version']];
            if ($outcome === PingOutcome::Accepted) {
                $this->connection->delete('iwac_seo_ping', $where);
                continue;
            }
            $attempts = $outcome === PingOutcome::Refused
                ? self::MAX_ATTEMPTS
                : (int) $row['attempts'] + 1;
            $this->connection->update('iwac_seo_ping', ['token' => '', 'lease_until' => 0,
                'attempts' => $attempts, 'available_at' => $now + min(86400, 60 * (2 ** $attempts))], $where);
        }
    }

    /** Whether any URL is due for submission now (not leased, not backing off, not exhausted). */
    public function hasDue(?int $now = null): bool
    {
        $now ??= time();
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM iwac_seo_ping WHERE available_at <= :now AND lease_until <= :now AND attempts < :cap LIMIT 1',
            ['now' => $now, 'cap' => self::MAX_ATTEMPTS]
        );
    }

    /**
     * `failed` covers both ways a URL stops being sent: retries exhausted, and
     * a submission IndexNow refused.
     *
     * @return array{pending:int,failed:int}
     */
    public function counts(): array
    {
        $cap = ['cap' => self::MAX_ATTEMPTS];
        return ['pending' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM iwac_seo_ping WHERE attempts < :cap', $cap),
            'failed' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM iwac_seo_ping WHERE attempts >= :cap', $cap)];
    }

    /** Re-queue every failed URL — after fixing whatever made it fail. */
    public function retryFailed(): void
    {
        $this->connection->executeStatement(
            'UPDATE iwac_seo_ping SET attempts = 0, available_at = 0 WHERE attempts >= :cap',
            ['cap' => self::MAX_ATTEMPTS]
        );
    }
}
