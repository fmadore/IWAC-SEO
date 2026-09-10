<?php
declare(strict_types=1);

namespace IwacSeo\Service;

final class PageIndexability
{
    public const ROBOTS = ['', 'index, follow', 'noindex, follow'];

    public static function allows(?string $robots): bool
    {
        return !preg_match('/(?:^|[\s,])noindex(?:$|[\s,])/i', $robots ?? '');
    }
}
