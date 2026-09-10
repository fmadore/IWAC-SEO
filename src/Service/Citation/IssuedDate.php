<?php
declare(strict_types=1);

namespace IwacSeo\Service\Citation;

/**
 * A publication date, as precise as the archive records it.
 *
 * IWAC dates are NumericDataTypes timestamps stored as YYYY, YYYY-MM or
 * YYYY-MM-DD, and the precision is meaningful: a newspaper article cited in
 * Chicago wants "7 December 2018", a book wants "2018". Month and day are
 * therefore nullable rather than defaulted, and a value that parses as no date
 * at all is kept verbatim in $literal so a citation can still show something.
 */
final class IssuedDate
{
    public function __construct(
        public readonly ?int $year = null,
        public readonly ?int $month = null,
        public readonly ?int $day = null,
        public readonly ?string $literal = null,
        public readonly ?self $end = null,
    ) {
    }

    public static function unknown(): self
    {
        return new self();
    }

    /**
     * Parse a calendar-valid partial date or interval. Other values remain literal.
     */
    public static function parse(string $raw): self
    {
        $raw = trim($raw);
        if ($raw === '') {
            return self::unknown();
        }
        if (str_contains($raw, '/')) {
            $parts = explode('/', $raw);
            if (count($parts) === 2) {
                $start = self::parse($parts[0]);
                $end = self::parse($parts[1]);
                if ($start->hasYear() && $end->hasYear() && strcmp($start->iso() ?? '', $end->iso() ?? '') <= 0) {
                    return new self($start->year, $start->month, $start->day, $raw, $end);
                }
            }
            return new self(literal: $raw);
        }
        if (preg_match('/^(\d{4})(?:-(\d{1,2})(?:-(\d{1,2}))?)?$/D', $raw, $m)) {
            if (!checkdate((int) ($m[2] ?? 1), (int) ($m[3] ?? 1), (int) $m[1])) {
                return new self(literal: $raw);
            }
            return new self(
                (int) $m[1],
                ($m[2] ?? '') !== '' ? (int) $m[2] : null,
                ($m[3] ?? '') !== '' ? (int) $m[3] : null,
                $raw,
            );
        }
        return new self(null, null, null, $raw);
    }

    /** The year as a string, falling back to the raw literal, else null. */
    public function yearOrLiteral(): ?string
    {
        if ($this->end !== null) {
            return $this->literal;
        }
        return $this->year !== null ? (string) $this->year : $this->literal;
    }

    /** ISO date preserving the precision and optional range. */
    public function iso(): ?string
    {
        if (!$this->hasYear()) {
            return null;
        }
        $value = sprintf('%04d', $this->year);
        if ($this->month !== null) {
            $value .= sprintf('-%02d', $this->month);
            if ($this->day !== null) {
                $value .= sprintf('-%02d', $this->day);
            }
        }
        return $value . ($this->end !== null ? '/' . $this->end->iso() : '');
    }

    /** @return array<string,mixed> CSL date object, including unparsed literals. */
    public function csl(): array
    {
        if (!$this->hasYear()) {
            return $this->literal !== null ? ['literal' => $this->literal] : [];
        }
        $parts = [array_values(array_filter([$this->year, $this->month, $this->day], static fn ($v) => $v !== null))];
        if ($this->end !== null) {
            $parts[] = array_values(array_filter([$this->end->year, $this->end->month, $this->end->day], static fn ($v) => $v !== null));
        }
        return ['date-parts' => $parts];
    }

    public function hasYear(): bool
    {
        return $this->year !== null;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'year'    => $this->year,
            'month'   => $this->month,
            'day'     => $this->day,
            'literal' => $this->literal,
        ] + ($this->end !== null ? ['end' => $this->end->toArray()] : []);
    }

    /** @param array<string,mixed> $data the inverse of {@see toArray()} */
    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['year']) ? (int) $data['year'] : null,
            isset($data['month']) ? (int) $data['month'] : null,
            isset($data['day']) ? (int) $data['day'] : null,
            isset($data['literal']) ? (string) $data['literal'] : null,
            isset($data['end']) && is_array($data['end']) ? self::fromArray($data['end']) : null,
        );
    }
}
