<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ValueRepresentation;

/**
 * A record's licence or rights statement, resolved once, so that every signal
 * that names it (the JSON-LD `license`, and a Signposting `license` link) says
 * the same thing.
 *
 * IWAC records state their rights in dcterms:rights with the RightsStatements
 * module's `rights_statement` data type: the value's URI is the statement
 * itself ("http://rightsstatements.org/vocab/InC-EDU/1.0/"), its label the
 * name ("In Copyright - Educational Use Permitted"). On 2026-10-29 that was
 * 19,977 values across newspaper articles, publication issues, audiovisual
 * records, documents and photographs, all three of them In Copyright
 * statements; no record carried dcterms:license.
 *
 * A URI value is taken as stored. A literal is a URL when it is one, the
 * canonical Creative Commons URL when it names a well-known Creative Commons
 * licence, else free text. A linked licence item resolves to a URL when it
 * carries one from a licence registry (creativecommons.org,
 * rightsstatements.org, opensource.org) in an identifier-like property, else
 * when its label names a Creative Commons licence; failing both it is named,
 * not linked — `rel=license` must point at the licence itself. Only public
 * values count, as everywhere else in the module's output.
 *
 * Ported from DRE-SEO, which shares the resolution rules.
 */
final class Licence
{
    /** The properties a licence is read from, preferred first. */
    private const TERMS = ['dcterms:license', 'dcterms:rights'];

    /** Where a licence item may keep its URL. */
    private const URL_TERMS = [
        'dcterms:identifier', 'dcterms:source', 'schema:url', 'foaf:page', 'foaf:homepage',
        'dcterms:references', 'dcterms:relation', 'rdfs:seeAlso', 'owl:sameAs', 'schema:sameAs', 'fabio:hasURL',
    ];

    /** Hosts whose URLs are licences. */
    private const REGISTRIES = ['creativecommons.org', 'rightsstatements.org', 'opensource.org'];

    /** The Creative Commons licence elements in their URL order. */
    private const CC_COMBINATIONS = ['by', 'by-sa', 'by-nc', 'by-nc-sa', 'by-nc-nd', 'by-nd'];

    private function __construct(
        /** The licence URL, when it resolves to one. */
        public readonly ?string $url,
        /** The licence's name, for a licence that does not resolve. */
        public readonly ?string $name,
        /** The licence's authority item, when it is linked. */
        public readonly ?AbstractResourceEntityRepresentation $item,
    ) {
    }

    public static function of(AbstractResourceEntityRepresentation $resource): ?self
    {
        foreach (self::TERMS as $term) {
            foreach ($resource->value($term, ['all' => true]) as $value) {
                if (!$value instanceof ValueRepresentation) {
                    continue;
                }
                $licence = self::fromValue($value);
                if ($licence !== null) {
                    return $licence;
                }
            }
        }
        return null;
    }

    /**
     * The canonical creativecommons.org URL of a licence named by its short
     * form ("CC-BY-NC-SA-4.0", "CC BY 3.0", "CC0-1.0") or its full name
     * ("Creative Commons Attribution-NonCommercial-ShareAlike 4.0
     * International"); null for anything else.
     */
    public static function urlForLabel(string $label): ?string
    {
        $key = strtoupper(trim($label));
        $key = str_replace(
            ['CREATIVE COMMONS', 'ATTRIBUTION', 'NONCOMMERCIAL', 'NON-COMMERCIAL', 'SHAREALIKE', 'SHARE-ALIKE', 'NODERIVATIVES', 'NODERIVS', 'NO DERIVATIVES'],
            ['CC', 'BY', 'NC', 'NC', 'SA', 'SA', 'ND', 'ND', 'ND'],
            $key
        );
        $key = preg_replace('/\s+(INTERNATIONAL|UNPORTED|LICEN[CS]E|PUBLIC DOMAIN DEDICATION|UNIVERSAL)\b/', '', $key) ?? $key;
        $key = trim((string) preg_replace('/[\s_]+/', '-', $key), '-');

        if (preg_match('/\ACC-?0(?:-?1\.0)?\z/', $key) || $key === 'CC-ZERO') {
            return 'https://creativecommons.org/publicdomain/zero/1.0/';
        }
        if (preg_match('/\ACC-(BY(?:-(?:NC|SA|ND))*)-?([34]\.0)\z/', $key, $m)) {
            $elements = strtolower($m[1]);
            if (in_array($elements, self::CC_COMBINATIONS, true)) {
                return 'https://creativecommons.org/licenses/' . $elements . '/' . $m[2] . '/';
            }
        }
        return null;
    }

    private static function fromValue(ValueRepresentation $value): ?self
    {
        $text = MetadataValue::text($value);
        if ($text === null) {
            return null; // private, or links a private record, or empty
        }
        $linked = $value->valueResource();
        if ($linked instanceof AbstractResourceEntityRepresentation) {
            $url = self::registryUrl($linked) ?? self::urlForLabel($text);
            if ($url === null) {
                foreach ($linked->value('dcterms:alternative', ['all' => true]) as $alternative) {
                    if ($alternative instanceof ValueRepresentation && ($label = MetadataValue::text($alternative)) !== null) {
                        $url = self::urlForLabel($label);
                        if ($url !== null) {
                            break;
                        }
                    }
                }
            }
            return new self($url, $text, $linked);
        }
        // A rights statement: the URI is the statement, the label its name.
        $uri = MetadataValue::url(trim((string) $value->uri()));
        if ($uri !== null) {
            $label = trim(strip_tags((string) $value->value()));
            return new self($uri, $label !== '' && $label !== $uri ? $label : null, null);
        }
        if (MetadataValue::url($text) !== null) {
            return new self($text, null, null);
        }
        return new self(self::urlForLabel($text), $text, null);
    }

    /** A licence registry URL among the licence item's own identifiers. */
    private static function registryUrl(AbstractResourceEntityRepresentation $item): ?string
    {
        foreach (self::URL_TERMS as $term) {
            foreach ($item->value($term, ['all' => true]) as $value) {
                if (!$value instanceof ValueRepresentation || !$value->isPublic() || $value->valueResource()) {
                    continue;
                }
                $url = MetadataValue::url(trim((string) ($value->uri() ?: strip_tags((string) $value))));
                if ($url === null) {
                    continue;
                }
                $host = strtolower((string) parse_url($url, PHP_URL_HOST));
                foreach (self::REGISTRIES as $registry) {
                    if ($host === $registry || str_ends_with($host, '.' . $registry)) {
                        return $url;
                    }
                }
            }
        }
        return null;
    }
}
