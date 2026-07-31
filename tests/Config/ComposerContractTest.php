<?php
declare(strict_types=1);

namespace IwacSeo\Test\Config;

use PHPUnit\Framework\TestCase;

/** Packaging constraints that keep the module safe inside Omeka's process. */
final class ComposerContractTest extends TestCase
{
    public function testRuntimeDependenciesDoNotDuplicateOmekaFrameworkPackages(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/composer.json');
        if ($contents === false) {
            $this->fail('composer.json could not be read.');
        }
        /** @var array{require?:array<string,string>} $composer */
        $composer = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        foreach (array_keys($composer['require'] ?? []) as $package) {
            $this->assertFalse(
                str_starts_with($package, 'laminas/') || str_starts_with($package, 'psr/'),
                sprintf('%s is supplied by Omeka core and must not be bundled by the module.', $package)
            );
        }
    }
}
