<?php
declare(strict_types=1);

namespace IwacSeo\Controller\Admin;

use IwacSeo\Form\PageSeoForm;
use IwacSeo\Service\Hreflang;
use IwacSeo\Service\PageSeoStore;
use IwacSeo\Service\SettingsGate;
use IwacSeo\Service\SitemapGenerator;
use IwacSeo\Service\SiteResolver;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\SiteRepresentation;

/**
 * Admin → SEO. A status dashboard (what is configured, sitemap URLs + counts,
 * regenerate button) and a table for the manual per-static-page SEO overrides.
 */
class SeoController extends AbstractActionController
{
    public function __construct(
        private readonly SitemapGenerator $generator,
        private readonly PageSeoStore $pageSeoStore,
        private readonly ApiManager $api,
        private readonly SettingsGate $settings,
        private readonly SiteResolver $siteResolver,
        private readonly Hreflang $hreflang,
    ) {
    }

    public function dashboardAction(): ViewModel
    {
        $site = $this->resolveSite();
        $hostUrl = $site ? $this->hostUrl($site) : '';
        $indexNowKey = $this->settings->text('iwac_seo_indexnow_key');

        $view = new ViewModel([
            'site'           => $site,
            'gscConfigured'  => $this->settings->text('iwac_seo_gsc_verification') !== '',
            'jsonLdEnabled'  => $this->settings->isOn('iwac_seo_jsonld_enabled', true),
            'citationEnabled' => $this->settings->isOn('iwac_seo_citation_meta', true),
            'sitemapEnabled' => $this->settings->isOn('iwac_seo_sitemap_enabled', true),
            'noindexSite'    => $this->settings->isOn('iwac_seo_noindex_site'),
            'pingEnabled'    => $this->settings->isOn('iwac_seo_ping_enabled'),
            'indexNowKey'    => $indexNowKey,
            // The /{key}.txt route only matches a hex key; a non-hex key can
            // never be served, so IndexNow verification would fail silently.
            'indexNowKeyValid' => $indexNowKey === ''
                || (bool) preg_match('/^[A-Fa-f0-9]{8,128}$/', $indexNowKey),
            'sitemapUrl'     => $hostUrl ? $hostUrl . '/sitemap.xml' : '',
            'robotsUrl'      => $hostUrl ? $hostUrl . '/robots.txt' : '',
            'counts'         => $site ? $this->generator->counts($site->id()) : ['items' => 0, 'itemSets' => 0, 'pages' => 0],
            'hreflangEnabled' => $this->hreflang->isEnabled(),
            'hreflangCoverage' => $this->hreflangCoverage(),
            'confirmForm'    => $this->getForm(\Omeka\Form\ConfirmForm::class)
                ->setAttribute('action', $this->url()->fromRoute('admin/iwac-seo/regenerate')),
        ]);
        return $view->setTemplate('iwac-seo/admin/seo/dashboard');
    }

    /**
     * The two ways a public page can be wrong about its translations, so drift
     * surfaces as soon as a page is added, renamed or unpublished.
     *
     * `unpaired` — no `page_pairs` row, so no alternate is emitted. Correct for
     * a page that genuinely has no translation; a gap once one exists.
     *
     * `broken` — a row exists, but the counterpart it names is not a public
     * page, so the alternate points at a 404. Worse for search engines than
     * emitting none, and invisible to a per-site check: the stale row is what
     * marks the page it breaks as covered.
     *
     * Both sites' pages are therefore loaded before either is judged — whether
     * a counterpart resolves is a question about the *other* site.
     *
     * @return array{
     *     unpaired:array<int,array{site:SiteRepresentation,lang:string,pages:\Omeka\Api\Representation\SitePageRepresentation[]}>,
     *     broken:array<int,array{site:SiteRepresentation,page:\Omeka\Api\Representation\SitePageRepresentation,targetSite:string,targetSlug:string}>
     * }
     */
    private function hreflangCoverage(): array
    {
        if (!$this->hreflang->isEnabled()) {
            return ['unpaired' => [], 'broken' => []];
        }

        /** @var array<string,array{site:SiteRepresentation,lang:string,pages:array<string,\Omeka\Api\Representation\SitePageRepresentation>}> */
        $loaded = [];
        foreach ($this->hreflang->sites() as $slug => $lang) {
            $slug = (string) $slug;
            try {
                $sites = $this->api->search('sites', ['slug' => $slug])->getContent();
                $site = $sites[0] ?? null;
                if (!$site instanceof SiteRepresentation) {
                    continue;
                }
                $pages = $this->api->search('site_pages', ['site_id' => $site->id()])->getContent();
            } catch (\Throwable $e) {
                continue;
            }
            $public = [];
            foreach ($pages as $page) {
                if ($page->isPublic()) {
                    $public[(string) $page->slug()] = $page;
                }
            }
            $loaded[$slug] = ['site' => $site, 'lang' => (string) $lang, 'pages' => $public];
        }

        $unpaired = [];
        $broken = [];
        foreach ($loaded as $slug => $info) {
            $missing = [];
            foreach ($info['pages'] as $pageSlug => $page) {
                // Both cast: array keys narrow a numeric slug to int, and
                // strict_types would make that a TypeError, not a coercion.
                $partners = $this->hreflang->partnersFor((string) $slug, (string) $pageSlug);
                if ($partners === []) {
                    $missing[] = $page;
                    continue;
                }
                foreach ($partners as $targetSite => $targetSlug) {
                    // A site whose pages could not be loaded cannot be judged;
                    // reporting its counterparts as broken would be a guess.
                    if (!isset($loaded[$targetSite])) {
                        continue;
                    }
                    if (!isset($loaded[$targetSite]['pages'][$targetSlug])) {
                        $broken[] = [
                            'site'       => $info['site'],
                            'page'       => $page,
                            'targetSite' => (string) $targetSite,
                            'targetSlug' => $targetSlug,
                        ];
                    }
                }
            }
            if ($missing) {
                $unpaired[] = ['site' => $info['site'], 'lang' => $info['lang'], 'pages' => $missing];
            }
        }
        return ['unpaired' => $unpaired, 'broken' => $broken];
    }

    public function regenerateAction()
    {
        if ($this->getRequest()->isPost()) {
            $form = $this->getForm(\Omeka\Form\ConfirmForm::class);
            $form->setData($this->params()->fromPost());
            if ($form->isValid()) {
                $this->generator->clearCache();
                $this->messenger()->addSuccess('Sitemap cache cleared — it will be rebuilt on the next request.'); // @translate
            } else {
                $this->messenger()->addError('Invalid form submission.'); // @translate
            }
        }
        return $this->redirect()->toRoute('admin/iwac-seo');
    }

    public function pagesAction()
    {
        // IWAC is bilingual: the same collection is two Omeka sites
        // (afrique_ouest/fr, westafrica/en), each with its own static pages and
        // its own per-site overrides. Let the editor pick which site to edit —
        // POST (the save) carries the site, then ?site_id=, then the default.
        $sites = $this->api->search('sites')->getContent();
        if (!$sites) {
            $this->messenger()->addError('No site found.'); // @translate
            return $this->redirect()->toRoute('admin/iwac-seo');
        }
        $requestedId = (int) ($this->params()->fromPost('site_id')
            ?: $this->params()->fromQuery('site_id', 0));
        $site = $this->siteById($sites, $requestedId) ?? $this->resolveSite() ?? $sites[0];

        $this->pageSeoStore->setSite($site->id());
        $form = $this->getForm(PageSeoForm::class);

        if ($this->getRequest()->isPost()) {
            $post = $this->params()->fromPost();
            $form->setData($post);
            if ($form->isValid()) {
                $map = [];
                foreach ((array) ($post['pages'] ?? []) as $pageId => $fields) {
                    $overrides = array_filter([
                        'title'       => trim((string) ($fields['title'] ?? '')),
                        'description' => trim((string) ($fields['description'] ?? '')),
                        'image'       => (int) ($fields['image'] ?? 0) ?: null,
                        'robots'      => ($fields['robots'] ?? '') !== '' ? (string) $fields['robots'] : null,
                    ], static fn ($v) => $v !== null && $v !== '');
                    if ($overrides !== []) {
                        $map[(int) $pageId] = $overrides;
                    }
                }
                $this->pageSeoStore->replaceAll($map);
                $this->messenger()->addSuccess('Static-page SEO saved.'); // @translate
                return $this->redirect()->toRoute('admin/iwac-seo/pages', [], ['query' => ['site_id' => $site->id()]]);
            }
            $this->messenger()->addError('Invalid form submission.'); // @translate
        }

        $pages = $this->api->search('site_pages', ['site_id' => $site->id()])->getContent();

        $view = new ViewModel([
            'site'      => $site,
            'sites'     => $sites,
            'pages'     => $pages,
            'overrides' => $this->pageSeoStore->all(),
            'form'      => $form,
        ]);
        return $view->setTemplate('iwac-seo/admin/seo/pages');
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /**
     * The site with the given id from an already-loaded list, or null.
     *
     * @param SiteRepresentation[] $sites
     */
    private function siteById(array $sites, int $id): ?SiteRepresentation
    {
        if (!$id) {
            return null;
        }
        foreach ($sites as $site) {
            if ($site->id() === $id) {
                return $site;
            }
        }
        return null;
    }

    private function resolveSite(): ?SiteRepresentation
    {
        return $this->siteResolver->defaultSite();
    }

    private function hostUrl(SiteRepresentation $site): string
    {
        $siteUrl = $this->url()->fromRoute('site', ['site-slug' => $site->slug()], ['force_canonical' => true]);
        return SiteResolver::hostFromUrl($siteUrl);
    }
}
