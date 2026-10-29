<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ValueRepresentation;

/**
 * BCP 47 language tags for a record's dcterms:language.
 *
 * schema.org `inLanguage`, CSL-JSON `language`, `citation_language` and
 * Zotero's language field all want a BCP 47 tag, which uses the two-letter
 * ISO 639-1 code where one exists ("fr") and the three-letter code otherwise
 * ("mos" for Mooré). IWAC links each record to one of twelve language
 * authority items (class 244, under the category item 8301 "Langue"), titled
 * in French with the ISO 639-1 code in dcterms:alternative — except where no
 * such code exists or none was recorded: Dendi holds its ISO 639-3 code
 * ("ddn"), Mooré and Kabiyè only an unaccented name ("Moore", "Kabiye"), and
 * Spanish nothing. The code is read first; failing it, the record's name is
 * looked up among the names those twelve go by in French and English.
 * Anything else yields no tag: a language name is not one.
 *
 * Before this, the six languages a hard-coded label map knew became codes and
 * every other language reached `inLanguage` as its French name ("Mooré"),
 * which no consumer of a language tag can read.
 *
 * The ISO 639-2 table and toBcp47() are DRE-SEO's.
 */
final class LanguageCode
{
    /** @var array<string,string> ISO 639-2/3 code => ISO 639-1 code (Library of Congress, ISO-639-2_utf-8.txt) */
    private const TWO_LETTER = [
        'aar' => 'aa', 'abk' => 'ab', 'afr' => 'af', 'aka' => 'ak', 'alb' => 'sq', 'amh' => 'am', 'ara' => 'ar', 'arg' => 'an',
        'arm' => 'hy', 'asm' => 'as', 'ava' => 'av', 'ave' => 'ae', 'aym' => 'ay', 'aze' => 'az', 'bak' => 'ba', 'bam' => 'bm',
        'baq' => 'eu', 'bel' => 'be', 'ben' => 'bn', 'bis' => 'bi', 'bod' => 'bo', 'bos' => 'bs', 'bre' => 'br', 'bul' => 'bg',
        'bur' => 'my', 'cat' => 'ca', 'ces' => 'cs', 'cha' => 'ch', 'che' => 'ce', 'chi' => 'zh', 'chu' => 'cu', 'chv' => 'cv',
        'cor' => 'kw', 'cos' => 'co', 'cre' => 'cr', 'cym' => 'cy', 'cze' => 'cs', 'dan' => 'da', 'deu' => 'de', 'div' => 'dv',
        'dut' => 'nl', 'dzo' => 'dz', 'ell' => 'el', 'eng' => 'en', 'epo' => 'eo', 'est' => 'et', 'eus' => 'eu', 'ewe' => 'ee',
        'fao' => 'fo', 'fas' => 'fa', 'fij' => 'fj', 'fin' => 'fi', 'fra' => 'fr', 'fre' => 'fr', 'fry' => 'fy', 'ful' => 'ff',
        'geo' => 'ka', 'ger' => 'de', 'gla' => 'gd', 'gle' => 'ga', 'glg' => 'gl', 'glv' => 'gv', 'gre' => 'el', 'grn' => 'gn',
        'guj' => 'gu', 'hat' => 'ht', 'hau' => 'ha', 'heb' => 'he', 'her' => 'hz', 'hin' => 'hi', 'hmo' => 'ho', 'hrv' => 'hr',
        'hun' => 'hu', 'hye' => 'hy', 'ibo' => 'ig', 'ice' => 'is', 'ido' => 'io', 'iii' => 'ii', 'iku' => 'iu', 'ile' => 'ie',
        'ina' => 'ia', 'ind' => 'id', 'ipk' => 'ik', 'isl' => 'is', 'ita' => 'it', 'jav' => 'jv', 'jpn' => 'ja', 'kal' => 'kl',
        'kan' => 'kn', 'kas' => 'ks', 'kat' => 'ka', 'kau' => 'kr', 'kaz' => 'kk', 'khm' => 'km', 'kik' => 'ki', 'kin' => 'rw',
        'kir' => 'ky', 'kom' => 'kv', 'kon' => 'kg', 'kor' => 'ko', 'kua' => 'kj', 'kur' => 'ku', 'lao' => 'lo', 'lat' => 'la',
        'lav' => 'lv', 'lim' => 'li', 'lin' => 'ln', 'lit' => 'lt', 'ltz' => 'lb', 'lub' => 'lu', 'lug' => 'lg', 'mac' => 'mk',
        'mah' => 'mh', 'mal' => 'ml', 'mao' => 'mi', 'mar' => 'mr', 'may' => 'ms', 'mkd' => 'mk', 'mlg' => 'mg', 'mlt' => 'mt',
        'mon' => 'mn', 'mri' => 'mi', 'msa' => 'ms', 'mya' => 'my', 'nau' => 'na', 'nav' => 'nv', 'nbl' => 'nr', 'nde' => 'nd',
        'ndo' => 'ng', 'nep' => 'ne', 'nld' => 'nl', 'nno' => 'nn', 'nob' => 'nb', 'nor' => 'no', 'nya' => 'ny', 'oci' => 'oc',
        'oji' => 'oj', 'ori' => 'or', 'orm' => 'om', 'oss' => 'os', 'pan' => 'pa', 'per' => 'fa', 'pli' => 'pi', 'pol' => 'pl',
        'por' => 'pt', 'pus' => 'ps', 'que' => 'qu', 'roh' => 'rm', 'ron' => 'ro', 'rum' => 'ro', 'run' => 'rn', 'rus' => 'ru',
        'sag' => 'sg', 'san' => 'sa', 'sin' => 'si', 'slk' => 'sk', 'slo' => 'sk', 'slv' => 'sl', 'sme' => 'se', 'smo' => 'sm',
        'sna' => 'sn', 'snd' => 'sd', 'som' => 'so', 'sot' => 'st', 'spa' => 'es', 'sqi' => 'sq', 'srd' => 'sc', 'srp' => 'sr',
        'ssw' => 'ss', 'sun' => 'su', 'swa' => 'sw', 'swe' => 'sv', 'tah' => 'ty', 'tam' => 'ta', 'tat' => 'tt', 'tel' => 'te',
        'tgk' => 'tg', 'tgl' => 'tl', 'tha' => 'th', 'tib' => 'bo', 'tir' => 'ti', 'ton' => 'to', 'tsn' => 'tn', 'tso' => 'ts',
        'tuk' => 'tk', 'tur' => 'tr', 'twi' => 'tw', 'uig' => 'ug', 'ukr' => 'uk', 'urd' => 'ur', 'uzb' => 'uz', 'ven' => 've',
        'vie' => 'vi', 'vol' => 'vo', 'wel' => 'cy', 'wln' => 'wa', 'wol' => 'wo', 'xho' => 'xh', 'yid' => 'yi', 'yor' => 'yo',
        'zha' => 'za', 'zho' => 'zh', 'zul' => 'zu',
    ];

    /**
     * The names IWAC's language records go by, in French and English, with
     * and without their diacritics, lower-cased.
     *
     * @var array<string,string> name => BCP 47 tag
     */
    private const NAMES = [
        'allemand' => 'de', 'german' => 'de',
        'anglais' => 'en', 'english' => 'en',
        'arabe' => 'ar', 'arabic' => 'ar',
        'dendi' => 'ddn',
        'espagnol' => 'es', 'spanish' => 'es',
        'ewé' => 'ee', 'éwé' => 'ee', 'ewe' => 'ee',
        'français' => 'fr', 'francais' => 'fr', 'french' => 'fr',
        'haoussa' => 'ha', 'hausa' => 'ha',
        'italien' => 'it', 'italian' => 'it',
        'kabyè' => 'kbp', 'kabiyè' => 'kbp', 'kabye' => 'kbp', 'kabiye' => 'kbp',
        'mooré' => 'mos', 'moore' => 'mos',
        'portugais' => 'pt', 'portuguese' => 'pt',
        'slovène' => 'sl', 'slovene' => 'sl', 'slovenian' => 'sl',
    ];

    /**
     * The record's languages as BCP 47 tags, in document order and without
     * repeats, from its public dcterms:language values. A language that
     * resolves to no tag is left out.
     *
     * @return string[]
     */
    public static function of(AbstractResourceEntityRepresentation $resource): array
    {
        $tags = [];
        foreach ($resource->value('dcterms:language', ['all' => true]) as $value) {
            if (!$value instanceof ValueRepresentation || ($label = MetadataValue::text($value)) === null) {
                continue;
            }
            $tag = self::forValue($value, $label);
            if ($tag !== null) {
                $tags[$tag] = $tag;
            }
        }
        return array_values($tags);
    }

    /** A BCP 47 tag from a stored code ("deu" → "de", "ddn" → "ddn", "en-GB" → "en-GB"); null for anything else. */
    public static function toBcp47(?string $code): ?string
    {
        $code = trim((string) $code);
        if (!preg_match('/\A([A-Za-z]{2,3})((?:-[A-Za-z0-9]{2,8})*)\z/', $code, $m)) {
            return null;
        }
        $language = strtolower($m[1]);
        $language = self::TWO_LETTER[$language] ?? $language;
        $subtags = array_values(array_filter(explode('-', $m[2]), static fn (string $s): bool => $s !== ''));
        foreach ($subtags as $i => $subtag) {
            // Region subtags are upper case, scripts title case, the rest lower.
            $subtags[$i] = match (true) {
                strlen($subtag) === 2 && ctype_alpha($subtag) => strtoupper($subtag),
                strlen($subtag) === 4 && ctype_alpha($subtag) => ucfirst(strtolower($subtag)),
                default => strtolower($subtag),
            };
        }
        return implode('-', [$language, ...$subtags]);
    }

    /** The tag for one of the archive's language names ("Mooré" → "mos"); null for any other text. */
    public static function fromName(?string $name): ?string
    {
        return self::NAMES[mb_strtolower(trim((string) $name))] ?? null;
    }

    private static function forValue(ValueRepresentation $value, string $label): ?string
    {
        $linked = $value->valueResource();
        if ($linked instanceof AbstractResourceEntityRepresentation) {
            foreach ($linked->value('dcterms:alternative', ['all' => true]) as $alternative) {
                if (!$alternative instanceof ValueRepresentation) {
                    continue;
                }
                $tag = self::toBcp47(MetadataValue::text($alternative));
                if ($tag !== null) {
                    return $tag;
                }
            }
            return self::fromName($label);
        }
        // A literal: one of the archive's language names, else a literal that
        // is itself a code ("fr", "en-GB").
        return self::fromName($label) ?? self::toBcp47($label);
    }
}
