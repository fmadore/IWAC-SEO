<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/** Durable outbox. Versioned leases make acknowledgement safe during concurrent edits. */
final class PingRepository
{
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
            WHERE available_at <= :now AND lease_until <= :now AND attempts < 5
            ORDER BY available_at, id LIMIT ' . max(1, min($limit, 200)), ['now' => $now]);
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

    /** @param array<int,array<string,mixed>> $rows */
    public function finish(array $rows, bool $success, ?int $now = null): void
    {
        $now ??= time();
        foreach ($rows as $row) {
            $where = ['id' => $row['id'], 'token' => $row['token'], 'version' => $row['version']];
            if ($success) {
                $this->connection->delete('iwac_seo_ping', $where);
            } else {
                $attempts = (int) $row['attempts'] + 1;
                $this->connection->update('iwac_seo_ping', ['token' => '', 'lease_until' => 0,
                    'attempts' => $attempts, 'available_at' => $now + min(86400, 60 * (2 ** $attempts))], $where);
            }
        }
    }

    /** @return array{pending:int,failed:int} */
    public function counts(): array
    {
        return ['pending' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM iwac_seo_ping WHERE attempts < 5'),
            'failed' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM iwac_seo_ping WHERE attempts >= 5')];
    }

    public function retryFailed(): void
    {
        $this->connection->executeStatement('UPDATE iwac_seo_ping SET attempts = 0, available_at = 0 WHERE attempts >= 5');
    }
}
