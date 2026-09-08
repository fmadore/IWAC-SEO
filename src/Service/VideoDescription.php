<?php
declare(strict_types=1);

namespace IwacSeo\Service;

/**
 * A catalogue-style description for a video that has none of its own.
 *
 * schema.org's VideoObject *requires* description, and 310 of IWAC's 1,790
 * video records hold no abstract, summary or description at all — Search
 * Console reports every one of them as an error, and had reached 199 when
 * this was written. 1.0.4 chose to leave the field empty rather than invent
 * prose from the timecoded machine transcript. This is the middle ground:
 * not prose, and not invented, but the record's own facts — who made it, who
 * published it, when, how long it runs, in what language, where and about
 * what — read out as one sentence in the page's language, the way a library
 * catalogue describes a recording it has not summarised.
 *
 * It is a fallback only: a record that carries any descriptive text keeps
 * it, and a record that carries no fact beyond its title and its collection
 * gets nothing rather than a sentence that says nothing.
 *
 * Localised through a per-locale string table like {@see CitationFormatter},
 * because the module's services run without a translator; IWAC is strictly
 * EN/FR and anything not French reads in English.
 */
final class VideoDescription
{
    /** @var array<string,array<string,string>> */
    private const STR = [
        'en' => [
            'head'      => 'Video recording',
            'by'        => 'by',
            'published' => 'published by',
            'on_day'    => 'on',
            'in_period' => 'in',
            'in_lang'   => 'in',
            'and'       => 'and',
            'places'    => 'Places:',
            'subjects'  => 'Subjects:',
        ],
        'fr' => [
            'head'      => 'Enregistrement vidéo',
            'by'        => 'par',
            'published' => 'publié par',
            'on_day'    => 'le',
            'in_period' => 'en',
            'in_lang'   => 'en',
            'and'       => 'et',
            'places'    => 'Lieux :',
            'subjects'  => 'Sujets :',
        ],
    ];

    /** @var array<string,array<int,string>> */
    private const MONTHS = [
        'en' => [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        'fr' => [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'],
    ];

    /**
     * @param array{
     *   authors?: string[],
     *   publisher?: ?string,
     *   date?: ?string,
     *   duration?: ?string,
     *   language?: ?string,
     *   places?: string[],
     *   subjects?: string[],
     *   collection?: ?string
     * } $facts the record's own values: a NumericDataTypes date (YYYY,
     *   YYYY-MM or YYYY-MM-DD), an ISO 8601 duration, and display labels for
     *   the rest, already in the page's language where the archive has one
     * @return ?string one sentence per group of facts, or null when the
     *   record holds nothing worth saying beyond its collection
     */
    public static function compose(array $facts, string $locale = 'en'): ?string
    {
        $locale = isset(self::STR[$locale]) ? $locale : 'en';
        $str = static fn (string $key): string => self::STR[$locale][$key];

        $authors = self::clean($facts['authors'] ?? []);
        $publisher = self::text($facts['publisher'] ?? null);
        $date = self::date(self::text($facts['date'] ?? null), $locale);
        $duration = self::duration(self::text($facts['duration'] ?? null));
        $language = self::text($facts['language'] ?? null);
        $places = self::clean($facts['places'] ?? []);
        $subjects = self::clean($facts['subjects'] ?? []);
        $collection = self::text($facts['collection'] ?? null);

        if (
            !$authors && $publisher === null && $date === null && $duration === null
            && $language === null && !$places && !$subjects
        ) {
            return null;
        }

        $sentence = $str('head');
        if ($duration !== null) {
            $sentence .= ' (' . $duration . ')';
        }
        if ($authors) {
            $sentence .= ' ' . $str('by') . ' ' . self::nameList($authors, $str('and'));
        }
        if ($publisher !== null) {
            $sentence .= ($authors ? ', ' : ' ') . $str('published') . ' ' . $publisher;
        }
        if ($date !== null) {
            // "published by X on 15 April 2022" reads as a clause; without a
            // publisher the date is just the next fact in the list.
            $sentence .= $publisher !== null
                ? ' ' . $str($date[1] ? 'on_day' : 'in_period') . ' ' . $date[0]
                : ', ' . $date[0];
        }
        if ($language !== null) {
            // French writes language names in lower case ("en haoussa"); the
            // linked label arrives capitalised because it is a record title.
            if ($locale === 'fr') {
                $language = mb_strtolower(mb_substr($language, 0, 1)) . mb_substr($language, 1);
            }
            $sentence .= ', ' . $str('in_lang') . ' ' . $language;
        }
        $sentences = [self::stop($sentence)];

        if ($places) {
            $sentences[] = self::stop($str('places') . ' ' . implode(', ', $places));
        }
        if ($subjects) {
            $sentences[] = self::stop($str('subjects') . ' ' . implode(', ', $subjects));
        }
        if ($collection !== null) {
            $sentences[] = self::stop($collection);
        }

        return implode(' ', $sentences);
    }

    /**
     * A stored date read out in words: "15 April 2022" / "15 avril 2022",
     * "April 2022", or "2022" — whatever precision the archive holds.
     *
     * @return ?array{0:string,1:bool} [text, names a day] or null when the
     *   value is not a date the archive's convention can produce
     */
    private static function date(?string $raw, string $locale): ?array
    {
        if ($raw === null || preg_match('/^(\d{4})(?:-(\d{2})(?:-(\d{2}))?)?/', $raw, $m) !== 1) {
            return null;
        }
        $year = $m[1];
        $month = isset($m[2]) ? (int) $m[2] : 0;
        $day = isset($m[3]) ? (int) $m[3] : 0;
        if ($month < 1 || $month > 12) {
            return [$year, false];
        }
        $name = self::MONTHS[$locale][$month];
        if ($day < 1 || $day > 31) {
            return [$name . ' ' . $year, false];
        }
        return [$day . ' ' . $name . ' ' . $year, true];
    }

    /**
     * An ISO 8601 duration as the archive records it — PT2M49S, PT181M,
     * PT1H2M3S — read out as "2 min 49 s", "181 min", "1 h 2 min 3 s". Units
     * are kept as stored rather than normalised (181 min stays 181 min): the
     * value is a fact of the record, not an arithmetic exercise. Anything that
     * is not a time duration comes back null.
     */
    private static function duration(?string $iso): ?string
    {
        if ($iso === null || preg_match('/^P(?:[^T]*)T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)(?:\.\d+)?S)?$/i', $iso, $m) !== 1) {
            return null;
        }
        $parts = [];
        foreach ([1 => 'h', 2 => 'min', 3 => 's'] as $index => $unit) {
            $n = isset($m[$index]) && $m[$index] !== '' ? (int) $m[$index] : 0;
            if ($n > 0) {
                $parts[] = $n . ' ' . $unit;
            }
        }
        return $parts ? implode(' ', $parts) : null;
    }

    /**
     * "A", "A and B", "A, B and C".
     *
     * @param string[] $names
     */
    private static function nameList(array $names, string $and): string
    {
        if (count($names) === 1) {
            return $names[0];
        }
        $last = array_pop($names);
        return implode(', ', $names) . ' ' . $and . ' ' . $last;
    }

    /** Whitespace-normalised, or null when empty. */
    private static function text(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) preg_replace('/\s+/', ' ', $value));
        return $value === '' ? null : $value;
    }

    /**
     * Non-empty, de-duplicated labels in document order.
     *
     * @param string[] $values
     * @return string[]
     */
    private static function clean(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $text = self::text($value);
            if ($text !== null) {
                $out[$text] = $text;
            }
        }
        return array_values($out);
    }

    /** End with exactly one full stop, whatever the last label ended with. */
    private static function stop(string $sentence): string
    {
        return rtrim($sentence, " \t.") . '.';
    }
}
