<?php
declare(strict_types=1);

namespace IwacSeo\Site\BlockLayout;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Site\BlockLayout\AbstractBlockLayout;

/**
 * "How to cite this page" page block — the static-page counterpart of the
 * item pages' resource page block ({@see \IwacSeo\Site\ResourcePageBlockLayout\Citation}).
 *
 * Placing it is the opt-in: a page carrying it is citable, and only such a
 * page's downloads are served (/cite/page/{id}/{format}). The block holds no
 * data of its own. Authors, title and date are page metadata, set once in
 * Admin → SEO → Static pages beside the page's meta title, with defaults from
 * {@see \IwacSeo\Service\PageCitationData}; one block placement on each
 * language version cites that version, in its language, at its URL.
 *
 * The split is the item block's: this module owns the citation (through the
 * `iwacCitation` view helper, which accepts a page), the theme owns the markup
 * (`common/citation`), styling and JS.
 */
class PageCitation extends AbstractBlockLayout
{
    public function getLabel(): string
    {
        return 'How to cite this page'; // @translate
    }

    public function form(
        PhpRenderer $view,
        SiteRepresentation $site,
        ?SitePageRepresentation $page = null,
        ?SitePageBlockRepresentation $block = null
    ): string {
        $link = $view->hyperlink(
            $view->translate('SEO → Static pages'),
            $view->url('admin/iwac-seo/pages', [], ['query' => ['site_id' => $site->id()]])
        );
        $note = 'Shows this page’s citation in Chicago, APA and MLA, with BibTeX, RIS and CSL-JSON downloads. The author, title and date default to the configured author, the page title and the day the page was created; change them in:'; // @translate
        return '<p>' . $view->escapeHtml($view->translate($note)) . ' ' . $link . '.</p>';
    }

    public function render(PhpRenderer $view, SitePageBlockRepresentation $block): string
    {
        $helpers = $view->getHelperPluginManager();
        if (!$helpers->has('iwacCitation')) {
            return '';
        }
        $citation = $view->iwacCitation($block->page());
        if ($citation === null) {
            return '';
        }

        // The theme owns the UI: enqueue its citation script and render its
        // partial, exactly as the item page's block does.
        $view->headScript()->appendFile($view->assetUrl('js/citation.js'));
        return $view->partial('common/citation', ['citation' => $citation]);
    }
}
