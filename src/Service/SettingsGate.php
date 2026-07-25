<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use Omeka\Settings\Settings;

/**
 * Typed reads over the module's `iwac_seo_*` global settings.
 *
 * Replaces the SettingsReader trait, which read an undeclared `$this->settings`
 * property: an implicit contract PHP only enforced at call time, and one a
 * *factory* could not satisfy at all — which is exactly why the citation view
 * helper's factory had to re-implement the truthiness rule inline, with a
 * comment noting it must stay in sync. As an injectable object the same
 * definition of "on" is available to services, controllers and factories alike.
 *
 * What counts as on: the string '1', the int 1, or true. Omeka stores settings
 * as JSON, so a checkbox round-trips as either '1' or 1 depending on how it was
 * written; all three forms are accepted deliberately.
 */
final class SettingsGate
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function isOn(string $key, bool $default = false): bool
    {
        $value = $this->settings->get($key, $default ? '1' : '0');
        return $value === '1' || $value === 1 || $value === true;
    }

    /** A trimmed string setting; '' when unset. */
    public function text(string $key): string
    {
        return trim((string) ($this->settings->get($key, '') ?? ''));
    }

    /** A raw (untrimmed) string setting — for values where whitespace matters. */
    public function raw(string $key): string
    {
        return (string) ($this->settings->get($key, '') ?? '');
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->settings->get($key);
        return $value === null || $value === '' ? $default : (int) $value;
    }

    /** @return array<int,string> */
    public function list(string $key): array
    {
        $value = $this->settings->get($key, []);
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /** @param mixed $value */
    public function set(string $key, $value): void
    {
        $this->settings->set($key, $value);
    }
}
