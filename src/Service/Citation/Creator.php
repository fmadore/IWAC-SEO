<?php
declare(strict_types=1);

namespace IwacSeo\Service\Citation;

/**
 * One author or editor of a citable work.
 *
 * IWAC person records display as "First Last", so a name is split on the last
 * whitespace-delimited token; an already-inverted "Family, Given" literal is
 * detected by its comma. Institutions — creators linked to an Organisation
 * authority record — are the exception that drives the shape of this class:
 * they must survive as a single field, never split or inverted, or "Association
 * Islamique d'Al Mawadda Burkina Faso" is filed under the surname "Faso".
 *
 * The literal is always kept, so every consumer can fall back to the name
 * exactly as the archive records it.
 *
 * $structured says where the split came from: true when the family and given
 * names are the authority record's own fields (foaf:lastName, foaf:firstName),
 * false when they are this class's reading of a label. The split is the same
 * either way for the formatted citations and the exports, but a signal that
 * hands the split to another program as fact — the citation meta tags'
 * "Family, Given" — sends it only when the record made it.
 */
final class Creator
{
    private function __construct(
        public readonly ?string $family,
        public readonly ?string $given,
        public readonly string $literal,
        public readonly bool $isInstitution,
        public readonly bool $structured = false,
    ) {
    }

    /** A creator whose name is one indivisible field. */
    public static function institution(string $literal): self
    {
        return new self(null, null, $literal, true);
    }

    /**
     * @param bool $structured whether $family and $given are the authority
     *   record's own name fields, rather than read off the label
     */
    public static function person(?string $family, ?string $given, string $literal, bool $structured = false): self
    {
        return new self($family, ($given ?? '') !== '' ? $given : null, $literal, false, $structured);
    }

    /**
     * Parse a display name. $isInstitution short-circuits the split — the
     * caller knows, from the linked authority record's class, what this is.
     */
    public static function parse(string $label, bool $isInstitution): self
    {
        $label = trim(preg_replace('/\s+/', ' ', $label) ?? $label);
        if ($isInstitution) {
            return self::institution($label);
        }
        if (str_contains($label, ',')) {
            $bits = array_map('trim', explode(',', $label, 2));
            return self::person($bits[0] !== '' ? $bits[0] : $label, $bits[1] ?? null, $label);
        }
        $parts = explode(' ', $label);
        if (count($parts) === 1) {
            return self::person($label, null, $label);
        }
        $family = array_pop($parts);
        return self::person($family, implode(' ', $parts), $label);
    }

    /**
     * Whether the name must be emitted as one field: an institution, or a
     * literal that could not be split.
     */
    public function isSingleField(): bool
    {
        return $this->isInstitution || $this->family === null;
    }

    /** @return array{family:?string,given:?string,literal:string,isInstitution:bool,structured:bool} */
    public function toArray(): array
    {
        return [
            'family'        => $this->family,
            'given'         => $this->given,
            'literal'       => $this->literal,
            'isInstitution' => $this->isInstitution,
            'structured'    => $this->structured,
        ];
    }

    /**
     * The inverse of {@see toArray()}. An array written before `structured`
     * existed reads as a split of the label, which is the safe assumption.
     *
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $literal = (string) ($data['literal'] ?? '');
        if (!empty($data['isInstitution'])) {
            return self::institution($literal);
        }
        $family = $data['family'] ?? null;
        return self::person(
            $family !== null ? (string) $family : null,
            isset($data['given']) ? (string) $data['given'] : null,
            $literal,
            !empty($data['structured'])
        );
    }
}
