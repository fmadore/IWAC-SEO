<?php
declare(strict_types=1);

/**
 * Minimal stand-ins for the Omeka S classes the module type-hints.
 *
 * These are NOT reimplementations — each carries only the surface the module
 * actually touches, so a test that needs more than this is a test that should
 * be exercising the real application instead. Loaded from tests/bootstrap.php
 * and guarded by class_exists() so the real Omeka always wins when present.
 */

namespace Omeka\Settings {
    if (!class_exists(Settings::class)) {
        /** In-memory key/value store matching Omeka's global settings surface. */
        class Settings
        {
            /** @param array<string,mixed> $values */
            public function __construct(private array $values = [])
            {
            }

            /** @return mixed */
            public function get(string $id, $default = null)
            {
                return array_key_exists($id, $this->values) ? $this->values[$id] : $default;
            }

            /** @param mixed $value */
            public function set(string $id, $value): void
            {
                $this->values[$id] = $value;
            }

            public function delete(string $id): void
            {
                unset($this->values[$id]);
            }

            /** @return array<string,mixed> Test helper: the whole store. */
            public function all(): array
            {
                return $this->values;
            }
        }
    }
}

namespace Omeka\Job {
    if (!class_exists(Dispatcher::class)) {
        /** Records dispatches instead of queueing them. */
        class Dispatcher
        {
            /** @var array<int,string> */
            public array $dispatched = [];

            /** Set to throw from dispatch(), to exercise the failure path. */
            public bool $failing = false;

            /** @param array<string,mixed> $args */
            public function dispatch(string $class, $args = null)
            {
                if ($this->failing) {
                    throw new \RuntimeException('dispatch failed');
                }
                $this->dispatched[] = $class;
                return null;
            }
        }
    }
}
