<?php
declare(strict_types=1);

namespace IwacSeo\Test\Double;

use IwacSeo\Service\PingOutboxInterface;

/** Records what the queue asks of the outbox, for dispatch-policy tests. */
final class InMemoryPingOutbox implements PingOutboxInterface
{
    /** @var string[] */
    public array $pushed = [];

    /** @var array<int,array<string,mixed>> */
    public array $rows = [];

    public ?int $lastLimit = null;

    /** @var array<int,array{rows:array<int,array<string,mixed>>,success:bool}> */
    public array $finished = [];

    public function push(string $url): void
    {
        $this->pushed[] = $url;
    }

    public function claim(int $limit = 200): array
    {
        $this->lastLimit = $limit;
        return array_slice($this->rows, 0, $limit);
    }

    public function finish(array $rows, bool $success): void
    {
        $this->finished[] = ['rows' => $rows, 'success' => $success];
    }
}
