<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use Laminas\View\Renderer\PhpRenderer;

/**
 * Writes `<head>` signals into Omeka's request-global placeholder helpers, and
 * remembers which ones have been written.
 *
 * The module emits head metadata in two passes — resource/page specifics during
 * the action render, site-wide constants and gap-fills during the layout render
 * — so "has this signal already been set?" has to be answered across the two.
 * That answer is this object's entire reason to exist: it is the module's one
 * piece of request-scoped mutable state, and keeping it in a single small class
 * with a documented lifetime is what lets {@see HeadMetadata} be pure policy
 * about *what* to write.
 *
 * Lifetime: one instance per request (registered shared). Anything longer —
 * a queue worker reusing the container across jobs — would leak one page's
 * applied-set into the next, which is why nothing here is static.
 */
final class HeadWriter
{
    /** @var array<string,bool> Signals already emitted this request. */
    private array $applied = [];

    /** The description as written, so the layout pass can mirror it to og/twitter. */
    private ?string $description = null;

    /** The canonical as written, so the layout pass can mirror it to og:url. */
    private ?string $canonical = null;

    /** The indexing directive as written, kept apart from the preview hint merged into it. */
    private ?string $robots = null;

    private bool $largeImagePreview = false;

    /** @var array<int,array{rel:string,href:string,type?:string}> The Signposting links, for the Link header. */
    private array $signposts = [];

    public function description(PhpRenderer $view, string $description): void
    {
        $view->headMeta()->setName('description', $description);
        // Twitter reads og:description, but set the explicit one too for clarity.
        $view->headMeta()->appendName('twitter:description', $description);
        $this->description = $description;
        $this->mark('description');
    }

    /** The description written this request, if any. */
    public function writtenDescription(): ?string
    {
        return $this->description;
    }

    public function canonical(PhpRenderer $view, ?string $url): void
    {
        if ($url === null || $url === '') {
            return;
        }
        $view->headLink(['rel' => 'canonical', 'href' => $url]);
        $this->canonical = $url;
        $this->mark('canonical');
    }

    /** The canonical written this request, if any. */
    public function writtenCanonical(): ?string
    {
        return $this->canonical;
    }

    /**
     * The share-card image, with what a card needs beside the URL: alt text
     * for screen readers (Open Graph and X both carry one), and the pixel size
     * when it is known, so a card can be laid out before the image arrives.
     *
     * @param array{0:int,1:int}|null $size width and height; omitted when unknown
     */
    public function image(PhpRenderer $view, string $url, ?string $alt = null, ?array $size = null): void
    {
        $alt = $alt !== null && trim($alt) !== '' ? $alt : null;
        // og:image's structured properties follow it, in one write.
        $this->openGraph($view, [
            'og:image'        => $url,
            'og:image:width'  => $size !== null ? (string) $size[0] : null,
            'og:image:height' => $size !== null ? (string) $size[1] : null,
            'og:image:alt'    => $alt,
        ]);
        $view->headMeta()->appendName('twitter:image', $url);
        if ($alt !== null) {
            $view->headMeta()->appendName('twitter:image:alt', $alt);
        }
        $this->mark('image');
    }

    /**
     * The page's indexing directive ("noindex, follow", "index, follow" …).
     *
     * @param bool $force overwrite a directive already set this request —
     *   the site-wide staging noindex outranks any per-page directive.
     */
    public function robots(PhpRenderer $view, string $value, bool $force = false): void
    {
        if (!$force && $this->has('robots')) {
            return;
        }
        $this->robots = $value;
        $this->mark('robots');
        $this->writeRobots($view);
    }

    /**
     * Let search engines show the page's images at full width (Google's
     * default in Search and Discover is a small thumbnail) — for a scan-heavy
     * archive, the picture is the result.
     *
     * A hint, not a directive, so it merges with the page's indexing directive
     * instead of competing with it: it is written into the same robots meta
     * beside whatever robots() set, before or after this call, and dropped
     * while that directive is noindex — a page kept out of the index has no
     * preview to size. It does not count as a robots value having been set,
     * so a later noindex still applies.
     */
    public function largeImagePreview(PhpRenderer $view): void
    {
        $this->largeImagePreview = true;
        $this->writeRobots($view);
    }

    /** The robots meta for the directive and hint recorded so far; replaces any earlier one. */
    private function writeRobots(PhpRenderer $view): void
    {
        $content = array_filter([
            $this->robots,
            $this->largeImagePreview && PageIndexability::allows($this->robots) ? 'max-image-preview:large' : null,
        ], static fn (?string $part): bool => $part !== null && $part !== '');
        if ($content !== []) {
            $view->headMeta()->setName('robots', implode(', ', $content));
        }
    }

    /** @param array<string,string|null> $tags og property => content */
    public function openGraph(PhpRenderer $view, array $tags): void
    {
        $headMeta = $view->headMeta();
        foreach ($tags as $property => $content) {
            if ($content === null || $content === '') {
                continue;
            }
            $this->writeProperty($headMeta, $property, $content, true);
            // Twitter falls back to og:* for most fields; mirror title only.
            if ($property === 'og:title') {
                $headMeta->appendName('twitter:title', $content);
            }
            $this->mark($property);
        }
    }

    /** Append a repeated Open Graph property such as og:locale:alternate. */
    public function appendOpenGraph(PhpRenderer $view, string $property, string $content): void
    {
        if ($content === '') {
            return;
        }
        $this->writeProperty($view->headMeta(), $property, $content, false);
        $this->mark($property);
    }

    /** @param array<mixed> $data A JSON-LD document. */
    public function jsonLd(PhpRenderer $view, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_PRETTY_PRINT);
        if ($json !== false) {
            // application/ld+json must not be wrapped in HeadScript's JS
            // CDATA/comment guard. Make that exception local to this entry:
            // setAutoEscape(false) changes the shared helper and would also
            // disable escaping for scripts appended later by the theme.
            $view->headScript()->appendScript($json, 'application/ld+json', ['noescape' => true]);
        }
    }

    /**
     * Emit reciprocal hreflang alternate `<link>`s (plus x-default). A self link
     * is included so each language version references the whole set, as Google
     * requires. Skipped when there are fewer than two versions, keeping
     * single-language pages clean.
     *
     * @param array<int,array{lang:string,href:string,slug:string}> $alternates
     */
    public function alternates(PhpRenderer $view, array $alternates, ?string $xDefaultSlug): void
    {
        if (count($alternates) < 2) {
            return;
        }
        $xDefaultHref = null;
        foreach ($alternates as $alternate) {
            $view->headLink([
                'rel'      => 'alternate',
                'hreflang' => $alternate['lang'],
                'href'     => $alternate['href'],
            ]);
            if ($xDefaultSlug !== null && $alternate['slug'] === $xDefaultSlug) {
                $xDefaultHref = $alternate['href'];
            }
        }
        if ($xDefaultHref !== null) {
            $view->headLink(['rel' => 'alternate', 'hreflang' => 'x-default', 'href' => $xDefaultHref]);
        }
    }

    /**
     * The page's FAIR Signposting links, as `<link>` elements, kept for the
     * Module to repeat as an HTTP `Link` header once the page is rendered.
     * A page signposts one record: the first to be written, as resource
     * values win over later ones.
     *
     * @param array<int,array{rel:string,href:string,type?:string}> $links
     */
    public function signposts(PhpRenderer $view, array $links): void
    {
        if ($this->signposts !== []) {
            return;
        }
        foreach ($links as $link) {
            $view->headLink($link);
        }
        $this->signposts = $links;
    }

    /**
     * The Signposting links written this request; empty on a page that has
     * none (a static page, a listing).
     *
     * @return array<int,array{rel:string,href:string,type?:string}>
     */
    public function writtenSignposts(): array
    {
        return $this->signposts;
    }

    /** Replace the title stack with a single segment (an editor's override). */
    public function title(PhpRenderer $view, string $title): void
    {
        $view->headTitle()->getContainer()->exchangeArray([]);
        $view->headTitle()->append($title);
        $this->mark('title');
    }

    /** The leading segment of the rendered `<title>`, if the theme set one. */
    public function leadingTitle(PhpRenderer $view): ?string
    {
        $parts = $view->headTitle()->getContainer()->getArrayCopy();
        return isset($parts[0]) && $parts[0] !== '' ? (string) $parts[0] : null;
    }

    public function has(string $signal): bool
    {
        return !empty($this->applied[$signal]);
    }

    public function mark(string $signal): void
    {
        $this->applied[$signal] = true;
    }

    /**
     * Laminas View 2.x rejects property="…" meta entries under Omeka's HTML5
     * doctype even though Open Graph requires that attribute. Preserve the
     * public helper path where it works, then fall back to the helper's public
     * container so Omeka can render standards-compliant Open Graph markup.
     */
    private function writeProperty(object $headMeta, string $property, string $content, bool $replace): void
    {
        try {
            if ($replace) {
                $headMeta->setProperty($property, $content);
            } else {
                $headMeta->appendProperty($property, $content);
            }
            return;
        } catch (\Laminas\View\Exception\InvalidArgumentException $e) {
            // Expected with Laminas View 2.x + Omeka's HTML5 doctype.
        }

        $container = $headMeta->getContainer();
        if ($replace) {
            foreach ($container->getArrayCopy() as $index => $item) {
                if (
                    is_object($item)
                    && ($item->type ?? null) === 'property'
                    && ($item->property ?? null) === $property
                ) {
                    $container->offsetUnset($index);
                }
            }
        }
        $container->append((object) [
            'type' => 'property',
            'property' => $property,
            'content' => $content,
            'modifiers' => [],
        ]);
    }
}
