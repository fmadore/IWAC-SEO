<?php
declare(strict_types=1);

namespace IwacSeo\Service;

/**
 * Formats a normalized {@see CitationData} record as a Chicago, APA or MLA
 * reference, returning escaped HTML (italics via <em>). Hand-rolled — no CSL
 * processor dependency — because IWAC's item kinds are a small, known set and
 * the module carries no bundled vendor/.
 *
 * Bilingual: connective words and month names are resolved from internal EN/FR
 * maps (the site is strictly EN/FR), so a citation on the French site reads in
 * French ("Dans", "sous la dir. de", "7 décembre 2018") without a CSL locale
 * file. Unknown locales fall back to English.
 *
 * Title treatment follows each style's rule:
 *   • part-of works (newspaper, magazine, journal article, review, chapter,
 *     blog post, communication) — title in quotes (Chicago/MLA) or plain (APA),
 *     container in italics;
 *   • standalone works (book, thesis, report, audiovisual, photograph, document)
 *     — title in italics.
 *
 * Coverage is precise for the common kinds; rarer kinds fall back to a sensible
 * "author. title. container/publisher, year. url" shape. Multi-author handling
 * lists all names for Chicago/APA and uses "et al." beyond two for MLA, matching
 * each style; the corpus is overwhelmingly 0–3 authors.
 */
final class CitationFormatter
{
    public const STYLES = ['chicago', 'apa', 'mla'];

    /** Kinds whose title is set in quotes/plain with an italic container. */
    private const PART_KINDS = ['newspaper', 'magazine', 'article', 'review', 'chapter', 'post', 'communication'];

    /** @var array<string,array<string,string>> Connectives per locale. */
    private const STR = [
        'en' => [
            'and' => 'and', 'et_al' => 'et al.', 'in' => 'In', 'eds' => 'edited by',
            'no' => 'no.', 'vol' => 'vol.', 'pp' => 'pp.', 'p' => 'p.',
            'phd' => 'PhD diss.', 'video' => 'Video', 'photograph' => 'Photograph',
            'presentation' => 'Presentation', 'untitled' => 'Untitled',
        ],
        'fr' => [
            'and' => 'et', 'et_al' => 'et al.', 'in' => 'Dans', 'eds' => 'sous la dir. de',
            'no' => 'n°', 'vol' => 'vol.', 'pp' => 'p.', 'p' => 'p.',
            'phd' => 'thèse de doctorat', 'video' => 'Vidéo', 'photograph' => 'Photographie',
            'presentation' => 'communication', 'untitled' => 'Sans titre',
        ],
    ];

    /** @var array<string,array<int,string>> */
    private const MONTHS = [
        'en' => [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        'fr' => [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'],
    ];

    /**
     * @param array<string,mixed> $record a {@see CitationData::build()} record
     */
    public function format(array $record, string $style, string $locale = 'en'): string
    {
        $style = in_array($style, self::STYLES, true) ? $style : 'chicago';
        $locale = isset(self::STR[$locale]) ? $locale : 'en';

        return match ($style) {
            'apa' => $this->apa($record, $locale),
            'mla' => $this->mla($record, $locale),
            default => $this->chicago($record, $locale),
        };
    }

    // ─── Chicago (notes–bibliography, bibliography entry) ────────────────────

    private function chicago(array $record, string $locale): string
    {
        $kind = (string) $record['kind'];
        $parts = [];

        $authors = $this->authors($record, $locale, 'chicago');
        if ($authors !== '') {
            $parts[] = $this->terminate($authors);
        }

        $parts[] = $this->titleSegment($record, $locale, 'chicago');

        // Container / publication segment.
        switch ($kind) {
            case 'article':
            case 'review':
                $seg = $this->italic($record['container']);
                $vi = $this->volumeIssue($record, $locale);
                if ($vi !== '') {
                    $seg = trim($seg . ' ' . $vi);
                }
                $year = $this->year($record);
                if ($year !== null) {
                    $seg .= ' (' . $this->esc($year) . ')';
                }
                $pages = CitationData::pageRange($record);
                if ($pages !== null) {
                    $seg .= ': ' . $this->esc($pages);
                }
                $parts[] = $this->terminate($seg);
                break;

            case 'chapter':
                $seg = $this->str($locale, 'in') . ' ' . $this->italic($record['bookTitle'] ?: $record['title']);
                $pages = CitationData::pageRange($record);
                if ($pages !== null) {
                    $seg .= ', ' . $this->esc($pages);
                }
                $parts[] = $this->terminate($seg);
                $parts[] = $this->terminate($this->publisherYear($record, $locale));
                break;

            case 'newspaper':
            case 'magazine':
            case 'post':
                $seg = $this->italic($record['container']);
                $date = $this->fullDate($record, $locale, 'chicago');
                if ($date !== '') {
                    $seg = $seg !== '' ? $seg . ', ' . $date : $this->ucfirst($date);
                }
                $parts[] = $this->terminate($seg);
                break;

            case 'thesis':
                $seg = $this->str($locale, 'phd');
                $inst = $this->esc($record['publisher']);
                if ($inst !== '') {
                    $seg .= ', ' . $inst;
                }
                $year = $this->year($record);
                if ($year !== null) {
                    $seg .= ', ' . $this->esc($year);
                }
                $parts[] = $this->terminate($seg);
                break;

            case 'communication':
                $seg = $this->str($locale, 'presentation');
                $seg = $this->ucfirst($seg);
                $year = $this->year($record);
                if ($year !== null) {
                    $seg .= ', ' . $this->esc($year);
                }
                $parts[] = $this->terminate($seg);
                break;

            default: // book, report, av, photo, document, item
                $py = $this->publisherYear($record, $locale);
                if ($py !== '') {
                    $parts[] = $this->terminate($py);
                }
                break;
        }

        $parts[] = $this->linkSegment($record);
        return $this->join($parts);
    }

    // ─── APA (7th edition, reference list entry) ─────────────────────────────

    private function apa(array $record, string $locale): string
    {
        $kind = (string) $record['kind'];
        $parts = [];

        // Date in parentheses; periodicals carry the full date.
        $date = in_array($kind, ['newspaper', 'magazine', 'post'], true)
            ? $this->fullDate($record, $locale, 'apa')
            : ($this->year($record) !== null ? $this->esc((string) $this->year($record)) : '');
        $dateSeg = '(' . ($date !== '' ? $date : 'n.d.') . ').';

        // "Author. (Date). Title." — but with no author the title takes the
        // author slot and the date follows it ("Title. (Date)."), per APA.
        $authors = $this->authors($record, $locale, 'apa');
        $title = $this->titleSegment($record, $locale, 'apa');
        if ($authors !== '') {
            $parts[] = $this->terminate($authors);
            $parts[] = $dateSeg;
            $parts[] = $title;
        } else {
            $parts[] = $title;
            $parts[] = $dateSeg;
        }

        switch ($kind) {
            case 'article':
            case 'review':
                $seg = $this->italic($record['container']);
                $vol = $this->esc($record['volume']);
                if ($vol !== '') {
                    $seg .= ', ' . $this->italic($record['volume']);
                    if (($record['issue'] ?? null)) {
                        $seg .= '(' . $this->esc($record['issue']) . ')';
                    }
                }
                $pages = CitationData::pageRange($record);
                if ($pages !== null) {
                    $seg .= ', ' . $this->esc($pages);
                }
                $parts[] = $this->terminate($seg);
                break;

            case 'chapter':
                $seg = $this->str($locale, 'in') . ' ' . $this->italic($record['bookTitle'] ?: $record['title']);
                $pages = CitationData::pageRange($record);
                if ($pages !== null) {
                    $seg .= ' (' . $this->str($locale, 'pp') . ' ' . $this->esc($pages) . ')';
                }
                $parts[] = $this->terminate($seg);
                if ($this->esc($record['publisher']) !== '') {
                    $parts[] = $this->terminate($this->esc($record['publisher']));
                }
                break;

            case 'newspaper':
            case 'magazine':
            case 'post':
                $parts[] = $this->terminate($this->italic($record['container']));
                break;

            case 'thesis':
                $inst = $this->esc($record['publisher']);
                $label = $this->str($locale, 'phd');
                $seg = '[' . $this->ucfirst($label) . ($inst !== '' ? ', ' . $inst : '') . ']';
                $parts[] = $this->terminate($seg);
                break;

            default: // book, report, av, photo, document, communication, item
                if ($this->esc($record['publisher']) !== '') {
                    $parts[] = $this->terminate($this->esc($record['publisher']));
                }
                break;
        }

        $parts[] = $this->linkSegment($record);
        return $this->join($parts);
    }

    // ─── MLA (9th edition, works-cited entry) ────────────────────────────────

    private function mla(array $record, string $locale): string
    {
        $kind = (string) $record['kind'];
        $parts = [];

        $authors = $this->authors($record, $locale, 'mla');
        if ($authors !== '') {
            $parts[] = $this->terminate($authors);
        }

        $parts[] = $this->titleSegment($record, $locale, 'mla');

        switch ($kind) {
            case 'article':
            case 'review':
                $seg = $this->italic($record['container']);
                if (($record['volume'] ?? null)) {
                    $seg .= ', ' . $this->str($locale, 'vol') . ' ' . $this->esc($record['volume']);
                }
                if (($record['issue'] ?? null)) {
                    $seg .= ', ' . $this->str($locale, 'no') . ' ' . $this->esc($record['issue']);
                }
                $year = $this->year($record);
                if ($year !== null) {
                    $seg .= ', ' . $this->esc($year);
                }
                $pages = CitationData::pageRange($record);
                if ($pages !== null) {
                    $seg .= ', ' . $this->str($locale, 'pp') . ' ' . $this->esc($pages);
                }
                $parts[] = $this->terminate($seg);
                break;

            case 'chapter':
                $seg = $this->italic($record['bookTitle'] ?: $record['title']);
                $py = $this->publisherYear($record, $locale); // publisher, year
                if ($py !== '') {
                    $seg .= ', ' . $py;
                }
                $pages = CitationData::pageRange($record);
                if ($pages !== null) {
                    $seg .= ', ' . $this->str($locale, 'pp') . ' ' . $this->esc($pages);
                }
                $parts[] = $this->terminate($seg);
                break;

            case 'newspaper':
            case 'magazine':
            case 'post':
                $seg = $this->italic($record['container']);
                $date = $this->fullDate($record, $locale, 'mla');
                if ($date !== '') {
                    $seg = $seg !== '' ? $seg . ', ' . $date : $this->ucfirst($date);
                }
                $parts[] = $this->terminate($seg);
                break;

            default: // book, thesis, report, av, photo, document, communication, item
                $py = $this->publisherYear($record, $locale);
                if ($py !== '') {
                    $parts[] = $this->terminate($py);
                }
                break;
        }

        $parts[] = $this->linkSegment($record);
        return $this->join($parts);
    }

    // ─── Title ───────────────────────────────────────────────────────────────

    private function titleSegment(array $record, string $locale, string $style): string
    {
        $title = ($record['title'] ?? null) ?: $this->str($locale, 'untitled');
        $kind = (string) $record['kind'];

        if ($style === 'apa') {
            // APA: only standalone works are italic; parts stay plain. A thesis
            // is not a "part", so it italicises — correct for APA.
            $italic = !in_array($kind, self::PART_KINDS, true);
            return $this->terminate($italic ? $this->italic($title) : $this->esc($title));
        }

        // Chicago / MLA: parts AND an unpublished thesis go in quotation marks
        // (period inside); other standalone works are italic.
        $quoted = in_array($kind, self::PART_KINDS, true) || $kind === 'thesis';
        if ($quoted) {
            return '“' . $this->esc($title) . '.”';
        }
        return $this->terminate($this->italic($title));
    }

    // ─── Authors ─────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $record
     */
    private function authors(array $record, string $locale, string $style): string
    {
        $people = $record['authors'] ?? [];
        if (!$people) {
            return '';
        }
        $n = count($people);

        // Rendered forms: the first author is inverted (Family, Given / Family, I.),
        // the rest are natural order — except APA, which inverts every name.
        $first = $this->name($people[0], $style, true);
        if ($n === 1) {
            return $first;
        }

        if ($style === 'mla' && $n >= 3) {
            return $first . ', ' . $this->str($locale, 'et_al');
        }

        $rest = [];
        for ($i = 1; $i < $n; $i++) {
            $rest[] = $this->name($people[$i], $style, $style === 'apa');
        }

        $sep = $style === 'apa' ? '&' : $this->str($locale, 'and');
        if ($n === 2) {
            $glue = $style === 'apa' ? ', ' . $sep . ' ' : ', ' . $sep . ' ';
            return $first . $glue . $rest[0];
        }
        // 3+ (Chicago/APA list all): "A, B, and/& C"
        $last = array_pop($rest);
        return $first . ', ' . implode(', ', $rest) . ', ' . $sep . ' ' . $last;
    }

    /**
     * @param array{family:?string,given:?string,literal:string,isInstitution:bool} $person
     */
    private function name(array $person, string $style, bool $inverted): string
    {
        if (!empty($person['isInstitution']) || ($person['family'] ?? null) === null) {
            return $this->esc($person['literal']);
        }
        $family = (string) $person['family'];
        $given = (string) ($person['given'] ?? '');

        if ($style === 'apa') {
            $initials = $this->initials($given);
            return $this->esc($family) . ($initials !== '' ? ', ' . $this->esc($initials) : '');
        }
        if ($given === '') {
            return $this->esc($family);
        }
        return $inverted
            ? $this->esc($family) . ', ' . $this->esc($given)
            : $this->esc($given) . ' ' . $this->esc($family);
    }

    /** "Frédérick" → "F.", "Jean-Paul" → "J.-P.", "Muriel Anne" → "M. A." */
    private function initials(string $given): string
    {
        $out = [];
        foreach (preg_split('/\s+/', trim($given)) ?: [] as $word) {
            if ($word === '') {
                continue;
            }
            $bits = array_map(
                fn (string $p) => $p !== '' ? mb_strtoupper(mb_substr($p, 0, 1)) . '.' : '',
                explode('-', $word)
            );
            $out[] = implode('-', array_filter($bits));
        }
        return implode(' ', $out);
    }

    // ─── Shared segment builders ─────────────────────────────────────────────

    private function volumeIssue(array $record, string $locale): string
    {
        $out = '';
        if (($record['volume'] ?? null)) {
            $out .= $this->esc($record['volume']);
        }
        if (($record['issue'] ?? null)) {
            $out .= ($out !== '' ? ', ' : '') . $this->str($locale, 'no') . ' ' . $this->esc($record['issue']);
        }
        return $out;
    }

    /** "Publisher, Year" (Chicago/MLA book-like). $trailingYear=false drops the year. */
    private function publisherYear(array $record, string $locale, bool $trailingYear = true): string
    {
        $seg = $this->esc($record['publisher'] ?? ($record['container'] ?? null));
        $year = $this->year($record);
        if ($trailingYear && $year !== null) {
            $seg = $seg !== '' ? $seg . ', ' . $this->esc($year) : $this->esc($year);
        }
        return $seg;
    }

    private function linkSegment(array $record): string
    {
        $doi = $record['doi'] ?? null;
        $url = $record['url'] ?? null;
        $href = $doi ? 'https://doi.org/' . $doi : $url;
        if (!$href) {
            return '';
        }
        $hrefEsc = $this->esc($href);
        return '<a href="' . $hrefEsc . '">' . $hrefEsc . '</a>.';
    }

    // ─── Dates ───────────────────────────────────────────────────────────────

    private function year(array $record): ?string
    {
        $year = $record['issued']['year'] ?? null;
        return $year ? (string) $year : ($record['issued']['literal'] ?? null);
    }

    /**
     * Full date in the style's order:
     *   Chicago  → "December 7, 2018" / "décembre 2018"
     *   APA      → "2018, December 7"
     *   MLA      → "7 December 2018"
     * Falls back to the year (or the raw literal) when month/day are absent.
     */
    private function fullDate(array $record, string $locale, string $style): string
    {
        $y = $record['issued']['year'] ?? null;
        $m = $record['issued']['month'] ?? null;
        $d = $record['issued']['day'] ?? null;
        if (!$y) {
            $lit = $record['issued']['literal'] ?? null;
            return $lit !== null ? $this->esc($lit) : '';
        }
        if (!$m) {
            return $this->esc((string) $y);
        }
        $month = self::MONTHS[$locale][$m] ?? self::MONTHS['en'][$m] ?? (string) $m;

        return match ($style) {
            'apa' => $this->esc($y . ', ' . $month . ($d ? ' ' . $d : '')),
            'mla' => $this->esc(($d ? $d . ' ' : '') . $month . ' ' . $y),
            default => $locale === 'fr'
                ? $this->esc(($d ? $d . ' ' : '') . $month . ' ' . $y)          // 7 décembre 2018
                : $this->esc($month . ($d ? ' ' . $d . ',' : '') . ' ' . $y),   // December 7, 2018
        };
    }

    // ─── Primitives ──────────────────────────────────────────────────────────

    private function str(string $locale, string $key): string
    {
        return self::STR[$locale][$key] ?? self::STR['en'][$key] ?? $key;
    }

    private function esc(?string $text): string
    {
        return $text === null ? '' : htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    /** Escape $text and wrap non-empty in <em>. */
    private function italic(?string $text): string
    {
        $esc = $this->esc($text);
        return $esc !== '' ? '<em>' . $esc . '</em>' : '';
    }

    /** Append a period unless the segment already ends in terminal punctuation. */
    private function terminate(string $segment): string
    {
        $segment = trim($segment);
        if ($segment === '') {
            return '';
        }
        return preg_match('/[.!?]$/u', strip_tags($segment)) ? $segment : $segment . '.';
    }

    private function ucfirst(string $text): string
    {
        if ($text === '') {
            return '';
        }
        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    /** Join non-empty segments with single spaces. */
    private function join(array $parts): string
    {
        return implode(' ', array_filter(array_map('trim', $parts), static fn ($p) => $p !== ''));
    }
}
