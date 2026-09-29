<?php
declare(strict_types=1);

namespace IwacSeo\Service;

/**
 * Month names for the text the module writes itself — citations and composed
 * video descriptions — in the site's two languages. Services run without a
 * translator, so the names live here once rather than in each writer.
 */
final class MonthNames
{
    /** @var array<string,array<int,string>> */
    private const FULL = [
        'en' => [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        'fr' => [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'],
    ];

    /** @var array<int,string> MLA 9 works-cited abbreviations: months longer than four letters. */
    private const MLA_EN = [1 => 'Jan.', 'Feb.', 'Mar.', 'Apr.', 'May', 'June', 'July', 'Aug.', 'Sept.', 'Oct.', 'Nov.', 'Dec.'];

    /** "December" / "décembre"; English for an unknown locale, the number for an impossible month. */
    public static function full(int $month, string $locale): string
    {
        return self::FULL[$locale][$month] ?? self::FULL['en'][$month] ?? (string) $month;
    }

    /** MLA's abbreviated English month ("Sept."). */
    public static function mla(int $month): string
    {
        return self::MLA_EN[$month] ?? (string) $month;
    }
}
