<?php
declare(strict_types=1);

namespace IwacSeo\Test\Double;

use Omeka\Settings\Settings;

/**
 * Omeka's global settings over an array instead of the database. Extends the
 * real class (the shim's stand-in in the unit suite) without calling its
 * constructor, so it type-checks as an Omeka\Settings\Settings either way.
 */
final class InMemorySettings extends Settings
{
    /** @param array<string,mixed> $values */
    public function __construct(private array $values = [])
    {
    }

    /**
     * @param string $id
     * @param mixed $default
     * @return mixed
     */
    public function get($id, $default = null)
    {
        return array_key_exists($id, $this->values) ? $this->values[$id] : $default;
    }

    /**
     * @param string $id
     * @param mixed $value
     */
    public function set($id, $value): void
    {
        $this->values[$id] = $value;
    }

    /** @param string $id */
    public function delete($id): void
    {
        unset($this->values[$id]);
    }
}
