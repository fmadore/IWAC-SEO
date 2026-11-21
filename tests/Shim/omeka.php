<?php
declare(strict_types=1);

/**
 * Bare declarations of the two Omeka S classes the unit suite's doubles
 * extend (tests/Double/InMemorySettings, RecordingDispatcher), so the suite
 * runs without Omeka installed. The behaviour lives in the doubles, which
 * PHPStan checks against the real classes; these carry none. Loaded from
 * tests/bootstrap.php and guarded by class_exists() so the real Omeka always
 * wins when present.
 */

namespace Omeka\Settings {
    if (!class_exists(Settings::class)) {
        class Settings
        {
        }
    }
}

namespace Omeka\Job {
    if (!class_exists(Dispatcher::class)) {
        class Dispatcher
        {
        }
    }
}
