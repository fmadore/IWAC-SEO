<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use IwacSeo\Service\Citation\CitationRecord;
use IwacSeo\Service\Citation\Creator;

/**
 * Formats a {@see CitationRecord} as a Chicago, APA or MLA
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

    /** Kinds whose citation carries a full publication date, not just a year. */
    private const PERIODICAL_KINDS = [CitationKind::Newspaper, CitationKind::Magazine, CitationKind::PeriodicalIssue,
        CitationKind::Post, CitationKind::Av, CitationKind::Audio, CitationKind::Communication];

    /** @var array<string,array<string,string>> Connectives per locale. */
    private const STR = [
        'en' => [
            'and' => 'and', 'et_al' => 'et al.', 'in' => 'In', 'eds' => 'edited by',
            'no' => 'no.', 'vol' => 'vol.', 'pp' => 'pp.', 'p' => 'p.',
            'phd' => 'Thesis', 'video' => 'Video', 'audio' => 'Audio recording', 'photograph' => 'Photograph',
            'presentation' => 'Presentation', 'untitled' => 'Untitled',
        ],
        'fr' => [
            'and' => 'et', 'et_al' => 'et al.', 'in' => 'Dans', 'eds' => 'sous la dir. de',
            'no' => 'n°', 'vol' => 'vol.', 'pp' => 'p.', 'p' => 'p.',
            'phd' => 'thèse', 'video' => 'Vidéo', 'audio' => 'Enregistrement audio', 'photograph' => 'Photographie',
            'presentation' => 'communication', 'untitled' => 'Sans titre',
        ],
    ];

    /** @var array<string,array<int,string>> */
    private const MONTHS = [
        'en' => [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        'fr' => [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'],
    ];

    public function format(CitationRecord $record, string $style, string $locale = 'en'): string
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

    private function chicago(CitationRecord $record, string $locale): string
    {
        $kind = $record->kind;
        $parts = [];

        // Creator slot: chapter → its own author only (the book's editors go in
        // the container as "edited by …"); everything else → authors, or the
        // editors as editors for an edited volume.
        $creator = $kind === CitationKind::Chapter
            ? $this->nameList($record->authors, $locale, 'chicago')
            : $this->creators($record, $locale, 'chicago');
        if ($creator !== '') {
            $parts[] = $this->terminate($creator);
        }

        $parts[] = $this->titleSegment($record, $locale, 'chicago');

        // Container / publication segment.
        switch ($kind) {
            case CitationKind::Article:
            case CitationKind::Review:
                $seg = $this->italic($record->container);
                $vi = $this->volumeIssue($record, $locale);
                if ($vi !== '') {
                    $seg = trim($seg . ' ' . $vi);
                }
                $year = $this->year($record);
                if ($year !== null) {
                    $seg .= ' (' . $this->esc($year) . ')';
                }
                $pages = $record->pageRange();
                if ($pages !== null) {
                    $seg .= ': ' . $this->esc($pages);
                }
                $parts[] = $this->terminate($seg);
                break;

            case CitationKind::Chapter:
                $seg = $record->bookTitle !== null ? $this->str($locale, 'in') . ' ' . $this->italic($record->bookTitle) : '';
                $eds = $this->nameList($record->editors, $locale, 'chicago', false);
                if ($eds !== '') {
                    $seg .= ', ' . $this->str($locale, 'eds') . ' ' . $eds;
                }
                $pages = $record->pageRange();
                if ($pages !== null) {
                    $seg .= ', ' . $this->esc($pages);
                }
                $parts[] = $this->terminate($seg);
                $parts[] = $this->terminate($this->publisherYear($record));
                break;

            case CitationKind::Newspaper:
            case CitationKind::Magazine:
            case CitationKind::PeriodicalIssue:
            case CitationKind::Post:
                $seg = $this->italic($record->container);
                if ($record->issue !== null && $kind !== CitationKind::Newspaper) {
                    $seg .= ', ' . $this->str($locale, 'no') . ' ' . $this->esc($record->issue);
                }
                $date = $this->fullDate($record, $locale, 'chicago');
                if ($date !== '') {
                    $seg = $seg !== '' ? $seg . ', ' . $date : $this->ucfirst($date);
                }
                $parts[] = $this->terminate($seg);
                break;

            case CitationKind::Thesis:
                $seg = $this->esc($record->genre) ?: $this->str($locale, 'phd');
                $inst = $this->esc($record->publisher);
                if ($inst !== '') {
                    $seg .= ', ' . $inst;
                }
                $year = $this->year($record);
                if ($year !== null) {
                    $seg .= ', ' . $this->esc($year);
                }
                $parts[] = $this->terminate($seg);
                break;

            case CitationKind::Communication:
                $seg = $this->str($locale, 'presentation');
                $seg = $this->ucfirst($seg);
                $seg .= $this->eventSegment($record, $locale, 'chicago');
                $parts[] = $this->terminate($seg);
                break;

            default: // book, report, av, photo, document, item
                $py = $this->publisherYear($record);
                if ($py !== '') {
                    $parts[] = $this->terminate($py);
                }
                break;
        }

        $parts[] = $this->details($record, $locale);
        $parts[] = $this->linkSegment($record);
        return $this->join($parts);
    }

    // ─── APA (7th edition, reference list entry) ─────────────────────────────

    private function apa(CitationRecord $record, string $locale): string
    {
        $kind = $record->kind;
        $parts = [];

        // Date in parentheses; periodicals carry the full date.
        $date = in_array($kind, self::PERIODICAL_KINDS, true)
            ? $this->fullDate($record, $locale, 'apa')
            : ($this->year($record) !== null ? $this->esc((string) $this->year($record)) : '');
        $dateSeg = '(' . ($date !== '' ? $date : ($locale === 'fr' ? 's. d.' : 'n.d.')) . ').';

        // "Creator. (Date). Title." — with no creator the title takes the slot
        // and the date follows it ("Title. (Date)."), per APA. Chapter → its own
        // author; other kinds → authors, or the editors for an edited volume.
        $creator = $kind === CitationKind::Chapter
            ? $this->nameList($record->authors, $locale, 'apa')
            : $this->creators($record, $locale, 'apa');
        $title = $this->titleSegment($record, $locale, 'apa');
        if ($creator !== '') {
            $parts[] = $this->terminate($creator);
            $parts[] = $dateSeg;
            $parts[] = $title;
        } else {
            $parts[] = $title;
            $parts[] = $dateSeg;
        }

        switch ($kind) {
            case CitationKind::Article:
            case CitationKind::Review:
                $seg = $this->italic($record->container);
                $vol = $this->esc($record->volume);
                if ($vol !== '') {
                    $seg .= ', ' . $this->italic($record->volume);
                }
                if ($record->issue !== null) {
                    $seg .= ($vol === '' ? ', ' : '') . '(' . $this->esc($record->issue) . ')';
                }
                $pages = $record->pageRange();
                if ($pages !== null) {
                    $seg .= ', ' . $this->esc($pages);
                }
                $parts[] = $this->terminate($seg);
                break;

            case CitationKind::Chapter:
                // In {editors} (Eds.), *Book Title* (pp. x–y). Publisher.
                $seg = $record->bookTitle !== null ? $this->str($locale, 'in') . ' ' : '';
                $eds = $this->nameList($record->editors, $locale, 'apa', false);
                if ($eds !== '') {
                    $seg .= $eds . ' ' . $this->editorRole(count($record->editors), 'apa', $locale) . ', ';
                }
                $seg .= $this->italic($record->bookTitle);
                $pages = $record->pageRange();
                if ($pages !== null) {
                    $seg .= ' (' . $this->str($locale, 'pp') . ' ' . $this->esc($pages) . ')';
                }
                $parts[] = $this->terminate($seg);
                if ($this->esc($record->publisher) !== '') {
                    $parts[] = $this->terminate($this->esc($record->publisher));
                }
                break;

            case CitationKind::Newspaper:
            case CitationKind::Magazine:
            case CitationKind::PeriodicalIssue:
            case CitationKind::Post:
                $seg = $this->italic($record->container);
                if ($record->volume !== null) {
                    $seg .= ', ' . $this->italic($record->volume);
                }
                if ($record->issue !== null) {
                    $seg .= '(' . $this->esc($record->issue) . ')';
                }
                if ($record->pageRange() !== null) {
                    $seg .= ', ' . $this->esc($record->pageRange());
                }
                $parts[] = $this->terminate($seg);
                break;

            case CitationKind::Thesis:
                // Degree and institution are attached to the title in APA.
                break;

            default: // book, report, av, photo, document, communication, item
                if ($this->esc($record->publisher) !== '') {
                    $parts[] = $this->terminate($this->esc($record->publisher));
                }
                break;
        }

        if ($kind === CitationKind::Communication) {
            $parts[] = $this->terminate(trim($this->eventSegment($record, $locale, 'apa'), ', '));
        }
        if (in_array($kind, [CitationKind::Document, CitationKind::Photo], true)) {
            $parts[] = $this->terminate(implode(', ', array_filter([$this->esc($record->archive), $this->esc($record->accession)])));
        }
        $parts[] = $this->linkSegment($record, false);
        return $this->join($parts);
    }

    // ─── MLA (9th edition, works-cited entry) ────────────────────────────────

    private function mla(CitationRecord $record, string $locale): string
    {
        $kind = $record->kind;
        $parts = [];

        $creator = $kind === CitationKind::Chapter
            ? $this->nameList($record->authors, $locale, 'mla')
            : $this->creators($record, $locale, 'mla');
        if ($creator !== '') {
            $parts[] = $this->terminate($creator);
        }

        $parts[] = $this->titleSegment($record, $locale, 'mla');

        switch ($kind) {
            case CitationKind::Article:
            case CitationKind::Review:
                $seg = $this->italic($record->container);
                if ($record->volume !== null) {
                    $seg .= ', ' . $this->str($locale, 'vol') . ' ' . $this->esc($record->volume);
                }
                if ($record->issue !== null) {
                    $seg .= ', ' . $this->str($locale, 'no') . ' ' . $this->esc($record->issue);
                }
                $year = $this->year($record);
                if ($year !== null) {
                    $seg .= ', ' . $this->esc($year);
                }
                $pages = $record->pageRange();
                if ($pages !== null) {
                    $seg .= ', ' . $this->str($locale, 'pp') . ' ' . $this->esc($pages);
                }
                $parts[] = $this->terminate($seg);
                break;

            case CitationKind::Chapter:
                $seg = $this->italic($record->bookTitle);
                $eds = $this->nameList($record->editors, $locale, 'mla', false);
                if ($eds !== '') {
                    $seg .= ', ' . $this->str($locale, 'eds') . ' ' . $eds;
                }
                $py = $this->publisherYear($record); // publisher, year
                if ($py !== '') {
                    $seg .= ', ' . $py;
                }
                $pages = $record->pageRange();
                if ($pages !== null) {
                    $seg .= ', ' . $this->str($locale, 'pp') . ' ' . $this->esc($pages);
                }
                $parts[] = $this->terminate($seg);
                break;

            case CitationKind::Newspaper:
            case CitationKind::Magazine:
            case CitationKind::PeriodicalIssue:
            case CitationKind::Post:
                $seg = $this->italic($record->container);
                $date = $this->fullDate($record, $locale, 'mla');
                if ($date !== '') {
                    $seg = $seg !== '' ? $seg . ', ' . $date : $this->ucfirst($date);
                }
                if ($record->issue !== null) {
                    $seg .= ', ' . $this->str($locale, 'no') . ' ' . $this->esc($record->issue);
                }
                if ($record->pageRange() !== null) {
                    $seg .= ', ' . $this->str($locale, 'pp') . ' ' . $this->esc($record->pageRange());
                }
                $parts[] = $this->terminate($seg);
                break;

            default: // book, thesis, report, av, photo, document, communication, item
                $py = $this->publisherYear($record);
                if ($py !== '') {
                    $parts[] = $this->terminate($py);
                }
                break;
        }

        if ($kind === CitationKind::Communication) {
            $parts[] = $this->terminate(trim($this->eventSegment($record, $locale, 'mla'), ', '));
        }
        $parts[] = $this->details($record, $locale);
        $parts[] = $this->linkSegment($record);
        return $this->join($parts);
    }

    // ─── Title ───────────────────────────────────────────────────────────────

    private function titleSegment(CitationRecord $record, string $locale, string $style): string
    {
        $title = $record->title ?: $this->str($locale, 'untitled');
        $kind = $record->kind;

        if ($style === 'apa') {
            // APA: only standalone works are italic; parts stay plain. A thesis
            // is not a "part", so it italicises — correct for APA.
            $segment = $kind->isPartOfWork() && $kind !== CitationKind::Communication ? $this->esc($title) : $this->italic($title);
            $medium = $record->medium ?? match ($kind) {
                CitationKind::Av => $this->str($locale, 'video'),
                CitationKind::Audio => $this->str($locale, 'audio'),
                CitationKind::Photo => $this->str($locale, 'photograph'),
                CitationKind::Communication => $this->str($locale, 'presentation'),
                default => null,
            };
            if ($kind === CitationKind::Thesis) {
                $segment .= ' [' . $this->esc($record->genre ?? $this->str($locale, 'phd'))
                    . ($record->publisher !== null ? ', ' . $this->esc($record->publisher) : '') . ']';
            } elseif ($medium !== null) {
                $segment .= ' [' . $this->esc($medium) . ']';
            }
            if ($kind === CitationKind::Report && $record->number !== null) {
                $segment .= ' (' . $this->str($locale, 'no') . ' ' . $this->esc($record->number) . ')';
            }
            if ($kind === CitationKind::Review && $record->reviewedTitle !== null) {
                $segment .= ' [' . ($locale === 'fr' ? 'Compte rendu de ' : 'Review of ')
                    . $this->italic($record->reviewedTitle) . ']';
            }
            if ($kind === CitationKind::Book && $record->edition !== null) {
                $segment .= ' (' . $this->edition($record->edition, $locale) . ')';
            }
            return $this->terminate($segment);
        }

        // Chicago / MLA: parts AND an unpublished thesis go in quotation marks
        // (period inside); other standalone works are italic.
        $quoted = $kind->isPartOfWork() || ($kind === CitationKind::Thesis && $style === 'chicago');
        if ($quoted) {
            return '“' . $this->terminate($this->esc($title)) . '”';
        }
        return $this->terminate($this->italic($title))
            . ($kind === CitationKind::Book && $record->edition !== null ? ' ' . $this->terminate($this->edition($record->edition, $locale)) : '');
    }

    private function edition(string $edition, string $locale): string
    {
        if (!ctype_digit($edition)) {
            return $this->esc($edition);
        }
        $number = (int) $edition;
        if ($locale === 'fr') {
            return $number . ($number === 1 ? 're' : 'e') . ' éd.';
        }
        $suffix = $number % 100 >= 11 && $number % 100 <= 13 ? 'th' : match ($number % 10) {
            1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th',
        };
        return $number . $suffix . ' ed.';
    }

    // ─── Creators (authors / editors) ────────────────────────────────────────

    /**
     * The creator slot: the authors, or — for an edited work with no authors
     * (an edited volume) — the editors followed by an "ed(s)." role label.
     */
    private function creators(CitationRecord $record, string $locale, string $style): string
    {
        $authors = $this->nameList($record->authors, $locale, $style);
        if ($authors !== '') {
            return $authors;
        }
        $editors = $record->editors;
        $names = $this->nameList($editors, $locale, $style);
        if ($names === '') {
            return '';
        }
        // Chicago/MLA: "Names, eds." · APA: "Names (Eds.)".
        return $names . ($style === 'apa' ? ' ' : ', ') . $this->editorRole(count($editors), $style, $locale);
    }

    /**
     * Format a list of people. The first name is inverted (Family, Given /
     * Family, I.) unless $invertFirst is false (e.g. a chapter's "edited by …");
     * subsequent names are natural order for Chicago/MLA. APA inverts to initials
     * for the reference-list slot ($invertFirst true) and puts the initials first
     * for a non-inverted list ("In J.-L. Triaud & D. Robinson (Eds.)").
     *
     * @param Creator[] $people
     */
    private function nameList(array $people, string $locale, string $style, bool $invertFirst = true): string
    {
        if (!$people) {
            return '';
        }
        $n = count($people);
        if ($style === 'chicago' && $n > 6) {
            return $this->name($people[0], $style, $invertFirst) . ', '
                . $this->name($people[1], $style, false) . ', '
                . $this->name($people[2], $style, false) . ', et al.';
        }
        if ($style === 'apa' && $n > 20) {
            $names = array_map(fn (Creator $p) => $this->name($p, 'apa', $invertFirst), array_slice($people, 0, 19));
            return implode(', ', $names) . ', … ' . $this->name($people[$n - 1], 'apa', $invertFirst);
        }
        $first = $this->name($people[0], $style, $invertFirst);
        if ($n === 1) {
            return $first;
        }

        if ($style === 'mla' && $n >= 3) {
            return $first . ', ' . $this->str($locale, 'et_al');
        }

        // Subsequent names: natural order for Chicago/MLA; APA follows the list's
        // inversion (inverted for creator lists, initials-first for "edited by").
        $restInverted = $style === 'apa' ? $invertFirst : false;
        $rest = [];
        for ($i = 1; $i < $n; $i++) {
            $rest[] = $this->name($people[$i], $style, $restInverted);
        }

        $sep = $style === 'apa' ? '&' : $this->str($locale, 'and');
        if ($n === 2) {
            // A comma precedes the conjunction only when the first name is
            // inverted ("Triaud, Jean-Louis, and …"); a natural-order pair
            // ("edited by A and B") takes none.
            $glue = $invertFirst ? ', ' . $sep . ' ' : ' ' . $sep . ' ';
            return $first . $glue . $rest[0];
        }
        // 3+ (Chicago/APA list all): "A, B, and/& C"
        $last = array_pop($rest);
        return $first . ', ' . implode(', ', $rest) . ', ' . $sep . ' ' . $last;
    }

    /** Role label after editor names in the creator slot ("eds." / "(Eds.)" / "editors"). */
    private function editorRole(int $count, string $style, string $locale): string
    {
        if ($locale === 'fr') {
            // French uses "dir." (sous la direction de), invariant in number.
            return $style === 'apa' ? '(dir.)' : 'dir.';
        }
        $plural = $count > 1;
        return match ($style) {
            'apa' => $plural ? '(Eds.)' : '(Ed.)',
            'mla' => $plural ? 'editors' : 'editor',
            default => $plural ? 'eds.' : 'ed.', // chicago
        };
    }

    private function name(Creator $person, string $style, bool $inverted): string
    {
        if ($person->isSingleField()) {
            return $this->esc($person->literal);
        }
        $family = (string) $person->family;
        $given = (string) ($person->given ?? '');

        if ($style === 'apa') {
            $initials = $this->initials($given);
            if ($initials === '') {
                return $this->esc($family);
            }
            // "Family, Initials" for the reference-list creator slot; initials
            // first ("J.-L. Triaud") for a non-inverted list (chapter editors).
            return $inverted
                ? $this->esc($family) . ', ' . $this->esc($initials)
                : $this->esc($initials) . ' ' . $this->esc($family);
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

    private function volumeIssue(CitationRecord $record, string $locale): string
    {
        $out = '';
        if ($record->volume !== null) {
            $out .= $this->esc($record->volume);
        }
        if ($record->issue !== null) {
            $out .= ($out !== '' ? ', ' : '') . $this->str($locale, 'no') . ' ' . $this->esc($record->issue);
        }
        return $out;
    }

    /** "Publisher, Year" (Chicago/MLA book-like). */
    private function publisherYear(CitationRecord $record): string
    {
        $seg = $this->esc($record->publisher ?? $record->container);
        $year = $this->year($record);
        if ($year !== null) {
            $seg = $seg !== '' ? $seg . ', ' . $this->esc($year) : $this->esc($year);
        }
        return $seg;
    }

    private function linkSegment(CitationRecord $record, bool $period = true): string
    {
        $href = $record->link();
        if (MetadataValue::url($href) === null) {
            return '';
        }
        $hrefEsc = $this->esc($href);
        return '<a href="' . $hrefEsc . '">' . $hrefEsc . '</a>' . ($period ? '.' : '');
    }

    private function eventSegment(CitationRecord $record, string $locale, string $style): string
    {
        $facts = array_filter([$this->esc($record->eventTitle), $this->esc($record->eventPlace),
            $style !== 'apa' ? $this->fullDate($record, $locale, $style) : '']);
        return $facts ? ', ' . implode(', ', $facts) : '';
    }

    private function details(CitationRecord $record, string $locale): string
    {
        $medium = $record->medium ?? match ($record->kind) {
            CitationKind::Av => $this->str($locale, 'video'),
            CitationKind::Audio => $this->str($locale, 'audio'),
            CitationKind::Photo => $this->str($locale, 'photograph'),
            default => null,
        };
        $parts = [];
        if ($medium !== null) {
            $parts[] = '[' . $this->esc($medium) . '].';
        }
        if ($record->number !== null) {
            $parts[] = $this->terminate($this->str($locale, 'no') . ' ' . $this->esc($record->number));
        }
        if ($record->reviewedTitle !== null) {
            $parts[] = $this->terminate(($locale === 'fr' ? 'Compte rendu de ' : 'Review of ') . $this->italic($record->reviewedTitle));
        }
        if (in_array($record->kind, [CitationKind::Document, CitationKind::Photo], true)) {
            $parts[] = $this->terminate(implode(', ', array_filter([$this->esc($record->archive), $this->esc($record->accession)])));
        }
        return $this->join($parts);
    }

    // ─── Dates ───────────────────────────────────────────────────────────────

    private function year(CitationRecord $record): ?string
    {
        return $record->issued->yearOrLiteral();
    }

    /**
     * Full date in the style's order:
     *   Chicago  → "December 7, 2018" / "décembre 2018"
     *   APA      → "2018, December 7"
     *   MLA      → "7 December 2018"
     * Falls back to the year (or the raw literal) when month/day are absent.
     */
    private function fullDate(CitationRecord $record, string $locale, string $style): string
    {
        if ($record->issued->end !== null) {
            return $this->esc($record->issued->literal ?? $record->issued->iso());
        }
        $y = $record->issued->year;
        $m = $record->issued->month;
        $d = $record->issued->day;
        if ($y === null) {
            return $record->issued->literal !== null ? $this->esc($record->issued->literal) : '';
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
