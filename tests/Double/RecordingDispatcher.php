<?php
declare(strict_types=1);

namespace IwacSeo\Test\Double;

use Omeka\Job\DispatchStrategy\StrategyInterface;
use Omeka\Job\Dispatcher;

/**
 * Records job dispatches instead of queueing them. Extends the real
 * dispatcher (the shim's stand-in in the unit suite) without calling its
 * constructor, which wants a strategy, an entity manager and a logger.
 */
final class RecordingDispatcher extends Dispatcher
{
    /** @var array<int,string> */
    public array $dispatched = [];

    /** Set to throw from dispatch(), to exercise the failure path. */
    public bool $failing = false;

    public function __construct()
    {
    }

    /**
     * @param string $class
     * @param array<string,mixed>|null $args
     */
    public function dispatch($class, $args = null, ?StrategyInterface $strategy = null)
    {
        if ($this->failing) {
            throw new \RuntimeException('dispatch failed');
        }
        $this->dispatched[] = $class;
        return null;
    }
}
