<?php
declare(strict_types=1);

namespace IwacSeo\Controller\Admin;

use IwacSeo\Form\PageSeoForm;
use IwacSeo\Service\PageSeoStore;
use IwacSeo\Service\SitemapGenerator;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Settings\Settings;

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
        private readonly Settings $settings,
    ) {
    }

    public function dashboardAction(): ViewModel
    {
        $site = $this->resolveSite();
        $hostUrl = $site ? $this->hostUrl($site) : '';

        $view = new ViewModel([
            'site'           => $site,
            'gscConfigured'  => trim((string) $this->settings->get('iwac_seo_gsc_verification', '')) !== '',
            'jsonLdEnabled'  => $this->boolSetting('iwac_seo_jsonld_enabled', true),
            'citationEnabled' => $this->boolSetting('iwac_seo_citation_meta', true),
            'sitemapEnabled' => $this->boolSetting('iwac_seo_sitemap_enabled', true),
            'noindexSite'    => $this->boolSetting('iwac_seo_noindex_site'),
            'pingEnabled'    => $this->boolSetting('iwac_seo_ping_enabled'),
            'indexNowKey'    => trim((string) $this->settings->get('iwac_seo_indexnow_key', '')),
            'sitemapUrl'     => $hostUrl ? $hostUrl . '/sitemap.xml' : '',
            'robotsUrl'      => $hostUrl ? $hostUrl . '/robots.txt' : '',
            'counts'         => $site ? $this->generator->counts($site->id()) : ['items' => 0, 'itemSets' => 0, 'pages' => 0],
            'confirmForm'    => $this->getForm(\Omeka\Form\ConfirmForm::class)
                ->setAttribute('action', $this->url()->fromRoute('admin/iwac-seo/regenerate')),
        ]);
        return $view->setTemplate('iwac-seo/admin/seo/dashboard');
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
        $defaultSiteId = (int) $this->settings->get('default_site');
        if ($defaultSiteId) {
            try {
                return $this->api->read('sites', $defaultSiteId)->getContent();
            } catch (\Throwable $e) {
                // fall through
            }
        }
        try {
            $sites = $this->api->search('sites', ['limit' => 1])->getContent();
            return $sites[0] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function hostUrl(SiteRepresentation $site): string
    {
        $siteUrl = $this->url()->fromRoute('site', ['site-slug' => $site->slug()], ['force_canonical' => true]);
        $parts = parse_url($siteUrl);
        $host = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        if (!empty($parts['port'])) {
            $host .= ':' . $parts['port'];
        }
        return $host;
    }

    private function boolSetting(string $key, bool $default = false): bool
    {
        $value = $this->settings->get($key, $default ? '1' : '0');
        return $value === '1' || $value === 1 || $value === true;
    }
}
