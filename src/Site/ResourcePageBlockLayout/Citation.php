<?php
declare(strict_types=1);

namespace IwacSeo\Site\ResourcePageBlockLayout;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Site\ResourcePageBlockLayout\ResourcePageBlockLayoutInterface;

/**
 * "How to cite" resource page block.
 *
 * Registered by IwacSeo so it appears in the theme's resource-page configuration
 * (Admin → Themes → Configure resource pages), where an admin can place it in a
 * region and order it — instead of the theme hard-coding it into item/show.phtml.
 *
 * The split is unchanged: this module owns the citation data (via the
 * {@see \IwacSeo\View\Helper\Citation iwacCitation} view helper), the theme owns
 * the markup (the `common/citation` partial), styling and JS. Items only, and
 * only citable ones — iwacCitation() returns null for authority records
 * (person / place / organisation / …), so the block renders nothing there.
 */
class Citation implements ResourcePageBlockLayoutInterface
{
    public function getLabel(): string
    {
        return 'How to cite'; // @translate
    }

    public function getCompatibleResourceNames(): array
    {
        // Citations apply to items (the citable works); authority records and
        // media/item-sets are excluded from the block's compatibility.
        return ['items'];
    }

    public function render(PhpRenderer $view, AbstractResourceEntityRepresentation $resource): string
    {
        if (!$resource instanceof ItemRepresentation) {
            return '';
        }
        $helpers = $view->getHelperPluginManager();
        if (!$helpers->has('iwacCitation')) {
            return '';
        }
        $citation = $view->iwacCitation($resource);
        if ($citation === null) {
            return '';
        }

        // The theme owns the UI: enqueue its citation script and render its
        // partial. assetUrl()/partial() resolve against the active theme.
        $view->headScript()->appendFile($view->assetUrl('js/citation.js'));
        return $view->partial('common/citation', ['citation' => $citation]);
    }
}
