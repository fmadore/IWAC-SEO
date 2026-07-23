<?php
declare(strict_types=1);

namespace IwacSeo\Service\Concern;

/**
 * Typed readers over the module's `iwac_seo_*` settings, for classes that
 * hold the global settings service in a `$settings` property. Consolidates
 * the boolSetting() copies (and the subtly different inline variants) that
 * used to live in HeadMetadata and each controller, so "what counts as on"
 * is defined once: the string '1', the int 1, or true.
 */
trait SettingsReader
{
    private function boolSetting(string $key, bool $default = false): bool
    {
        $value = $this->settings->get($key, $default ? '1' : '0');
        return $value === '1' || $value === 1 || $value === true;
    }

    private function stringSetting(string $key): string
    {
        return (string) ($this->settings->get($key, '') ?? '');
    }
}
