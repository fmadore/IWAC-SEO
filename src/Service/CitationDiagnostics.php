<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use IwacSeo\Service\Citation\CitationRecord;

/** Catalogue gaps for editors; never fills unknown facts in a citation. */
final class CitationDiagnostics
{
    /**
     * Every label missing() can return. They are English msgids, translated
     * where the dashboard shows them; one per line so the string extractor
     * marks each for translation.
     */
    public const LABELS = [
        'title', // @translate
        'date', // @translate
        'container', // @translate
        'book title', // @translate
        'publisher', // @translate
        'degree / thesis type', // @translate
        'institution', // @translate
        'event', // @translate
        'publisher / institution', // @translate
        'archive', // @translate
        'call number', // @translate
        'reviewed title', // @translate
    ];

    /** @return string[] */
    public static function missing(CitationRecord $record): array
    {
        $fields = ['title' => $record->title, 'date' => $record->issued->iso() ?? $record->issued->literal];
        $fields += match ($record->kind) {
            CitationKind::Article, CitationKind::Review, CitationKind::Newspaper,
            CitationKind::Magazine, CitationKind::PeriodicalIssue, CitationKind::Post => ['container' => $record->container],
            CitationKind::Chapter => ['book title' => $record->bookTitle, 'publisher' => $record->publisher],
            CitationKind::Thesis => ['degree / thesis type' => $record->genre, 'institution' => $record->publisher],
            CitationKind::Communication => ['event' => $record->eventTitle],
            CitationKind::Book, CitationKind::Report => ['publisher / institution' => $record->publisher],
            CitationKind::Document, CitationKind::Photo => ['archive' => $record->archive, 'call number' => $record->accession],
            default => [],
        };
        if ($record->kind === CitationKind::Review) {
            $fields['reviewed title'] = $record->reviewedTitle;
        }
        return array_keys(array_filter($fields, static fn ($value) => $value === null || $value === ''));
    }
}
