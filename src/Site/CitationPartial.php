<?php
declare(strict_types=1);

namespace IwacSeo\Site;

use Laminas\View\Renderer\PhpRenderer;

/**
 * The "How to cite" panel both citation blocks render. The theme owns the UI:
 * its `common/citation` partial and `js/citation.js`, neither of which this
 * module ships. Under a theme that lacks the partial, rendering it would throw
 * and take the page down, so the block renders nothing instead.
 */
final class CitationPartial
{
    public const TEMPLATE = 'common/citation';

    /** @param array<string,mixed> $citation what the iwacCitation helper returned */
    public static function render(PhpRenderer $view, array $citation): string
    {
        if ($view->resolver(self::TEMPLATE) === false) {
            return '';
        }
        $view->headScript()->appendFile($view->assetUrl('js/citation.js'));
        return $view->partial(self::TEMPLATE, ['citation' => $citation]);
    }
}
