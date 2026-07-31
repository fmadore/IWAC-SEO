<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

exit((new PHPUnit\TextUI\Application())->run($_SERVER['argv']));
