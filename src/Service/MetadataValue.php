<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use IwacSeo\Service\Citation\Creator;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ValueRepresentation;

/** Public, plain metadata shared by the citation and discovery pipelines. */
final class MetadataValue
{
    public const DATE_TERMS = ['dcterms:date', 'dcterms:issued', 'dcterms:created'];

    public static function text(ValueRepresentation $value): ?string
    {
        if (!$value->isPublic()) {
            return null;
        }
        $linked = $value->valueResource();
        if ($linked !== null && method_exists($linked, 'isPublic') && !$linked->isPublic()) {
            return null;
        }
        $text = $linked !== null ? $linked->displayTitle() : ($value->value() ?? $value->uri());
        $text = trim(strip_tags((string) $text));
        return $text !== '' ? $text : null;
    }

    /** @param string[] $terms */
    public static function select(AbstractResourceEntityRepresentation $resource, array $terms, ?string $locale = null): ?string
    {
        foreach ($terms as $term) {
            $best = null;
            $bestRank = PHP_INT_MAX;
            foreach ($resource->value($term, ['all' => true]) as $value) {
                if (!$value instanceof ValueRepresentation || ($text = self::text($value)) === null) {
                    continue;
                }
                $language = strtolower(str_replace('_', '-', (string) $value->lang()));
                $wanted = strtolower(str_replace('_', '-', $locale ?? ''));
                $rank = match (true) {
                    $locale === null, $language === $wanted => 0,
                    $language !== '' && explode('-', $language)[0] === explode('-', $wanted)[0] => 1,
                    $language === '' => 2,
                    default => 3,
                };
                if ($rank < $bestRank) {
                    $best = $text;
                    $bestRank = $rank;
                }
            }
            if ($best !== null) {
                return $best;
            }
        }
        return null;
    }

    public static function creator(ValueRepresentation $value, CitationKindMap $kinds): ?Creator
    {
        $label = self::text($value);
        if ($label === null) {
            return null;
        }
        $linked = $value->valueResource();
        if ($kinds->isOrganization($linked)) {
            return Creator::institution($label);
        }
        if ($linked instanceof AbstractResourceEntityRepresentation) {
            $family = self::select($linked, ['foaf:lastName', 'foaf:familyName']);
            $given = self::select($linked, ['foaf:firstName', 'foaf:givenName']);
            if ($family !== null) {
                return Creator::person($family, $given, $label);
            }
        }
        return Creator::parse($label, false);
    }

    public static function url(?string $url): ?string
    {
        return $url !== null && preg_match('~^https?://[^\s<>"{}]+$~iu', $url) && parse_url($url, PHP_URL_HOST)
            ? $url : null;
    }

    public static function language(?string $label): ?string
    {
        return match (mb_strtolower($label ?? '')) {
            'français', 'french' => 'fr', 'anglais', 'english' => 'en',
            'arabe', 'arabic' => 'ar', 'haoussa', 'hausa' => 'ha',
            'allemand', 'german' => 'de', 'portugais', 'portuguese' => 'pt',
            default => $label,
        };
    }

    public static function isAudio(AbstractResourceEntityRepresentation $resource): bool
    {
        $type = self::select($resource, ['dcterms:type', 'dcterms:format']) ?? '';
        if (preg_match('/\b(?:audio|sonore|sound)\b/i', $type)) {
            return true;
        }
        $source = self::select($resource, ['fabio:hasURL']);
        if ($source !== null && preg_match('/(^|\.)soundcloud\.com$/i', (string) parse_url($source, PHP_URL_HOST))) {
            return true;
        }
        if ($resource instanceof \Omeka\Api\Representation\ItemRepresentation) {
            $audio = false;
            foreach ($resource->media() as $media) {
                if (!$media->isPublic()) {
                    continue;
                }
                if (str_starts_with((string) $media->mediaType(), 'video/')) {
                    return false;
                }
                $audio = $audio || str_starts_with((string) $media->mediaType(), 'audio/');
            }
            return $audio;
        }
        return false;
    }
}
