<?php
declare(strict_types=1);

/**
 * IwacSeo — Omeka S module.
 *
 * Search-engine optimisation for the Islam West Africa Collection (IWAC,
 * islam.zmo.de):
 *
 *   • Injects per-page <title>, meta description, Open Graph + Twitter cards,
 *     canonical links and schema.org JSON-LD into the <head> — derived
 *     automatically from each resource's metadata + resource class, set by
 *     hand for static site pages, with site-wide defaults filling the gaps.
 *   • Serves /sitemap.xml (index + per-type children) and /robots.txt.
 *   • Injects the Google Search Console verification tag from a pasted snippet.
 *   • Optionally pings IndexNow when public content changes.
 *
 * Settings-only: it owns a handful of `iwac_seo_*` settings and one site setting
 * (iwac_seo_pages); install/uninstall just create and drop those. No third-party
 * Composer dependencies, so there is no bundled vendor/ — Omeka autoloads the
 * IwacSeo\ namespace from src/.
 */

namespace IwacSeo;

use IwacSeo\Job\PingSearchEngines;
use IwacSeo\Service\HeadMetadata;
use IwacSeo\Service\PageSeoStore;
use IwacSeo\Service\SiteResolver;
use Laminas\EventManager\EventInterface;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Module\AbstractModule;
use Omeka\Permissions\Acl;

class Module extends AbstractModule
{
    /** Global settings owned by the module (created on install, dropped on uninstall). */
    private const SETTINGS = [
        'iwac_seo_gsc_verification',
        'iwac_seo_bing_verification',
        'iwac_seo_default_description',
        'iwac_seo_default_share_image',
        'iwac_seo_twitter_site',
        'iwac_seo_noindex_site',
        'iwac_seo_noindex_browse',
        'iwac_seo_jsonld_enabled',
        'iwac_seo_citation_meta',
        'iwac_seo_unapi',
        'iwac_seo_sitemap_enabled',
        'iwac_seo_sitemap_ttl',
        'iwac_seo_ping_enabled',
        'iwac_seo_indexnow_key',
        'iwac_seo_ping_pending',
        'iwac_seo_ping_last',
    ];

    /** Internal bookkeeping settings — never surfaced in the config form. */
    private const INTERNAL_SETTINGS = [
        'iwac_seo_ping_pending',
        'iwac_seo_ping_last',
    ];

    /** Sensible defaults applied on install. */
    private const DEFAULTS = [
        'iwac_seo_sitemap_enabled' => '1',
        'iwac_seo_jsonld_enabled'  => '1',
        'iwac_seo_citation_meta'   => '1',
        'iwac_seo_unapi'           => '1',
        'iwac_seo_noindex_browse'  => '1',
        'iwac_seo_sitemap_ttl'     => '86400',
    ];

    /** How often (seconds) a ping job may be dispatched. */
    private const PING_INTERVAL = 900;

    /**
     * Pending-URL queue cap. Public because {@see PingSearchEngines} treats a
     * full queue as a bulk sync and skips the ping — the two must agree.
     */
    public const PING_QUEUE_CAP = 200;

    public function getConfig(): array
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function install(ServiceLocatorInterface $services): void
    {
        $settings = $services->get('Omeka\Settings');
        foreach (self::DEFAULTS as $key => $value) {
            if ($settings->get($key) === null) {
                $settings->set($key, $value);
            }
        }
    }

    public function uninstall(ServiceLocatorInterface $services): void
    {
        $settings = $services->get('Omeka\Settings');
        foreach (self::SETTINGS as $key) {
            $settings->delete($key);
        }
        // Drop the per-site static-page overrides too.
        try {
            $siteSettings = $services->get('Omeka\Settings\Site');
            $sites = $services->get('Omeka\ApiManager')->search('sites')->getContent();
            foreach ($sites as $site) {
                $siteSettings->setTargetId($site->id());
                $siteSettings->delete('iwac_seo_pages');
            }
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    /**
     * ACL: open the public sitemap/robots/IndexNow endpoints to everyone
     * (including anonymous visitors); restrict the admin SEO screens to editors
     * and above (the /admin route already enforces authentication).
     */
    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        /** @var Acl $acl */
        $acl = $event->getApplication()->getServiceManager()->get('Omeka\Acl');
        $acl->allow(null, [
            Controller\SitemapController::class,
            Controller\UnapiController::class,
            Controller\CitationController::class,
        ]);
        $acl->allow(
            [Acl::ROLE_EDITOR, Acl::ROLE_SITE_ADMIN, Acl::ROLE_GLOBAL_ADMIN],
            [Controller\Admin\SeoController::class]
        );
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        // Resource show pages — derive meta + JSON-LD from the resource.
        foreach ([
            'Omeka\Controller\Site\Item',
            'Omeka\Controller\Site\ItemSet',
            'Omeka\Controller\Site\Media',
        ] as $controller) {
            $sharedEventManager->attach($controller, 'view.show.after', [$this, 'handleResourceShow']);
            $sharedEventManager->attach($controller, 'view.browse.after', [$this, 'handleBrowse']);
        }

        // Static site pages — apply the editor's per-page overrides. The home
        // page is rendered by the Index controller, so listen there too (the
        // handler no-ops when no $page is in scope).
        $sharedEventManager->attach('Omeka\Controller\Site\Page', 'view.show.after', [$this, 'handlePageShow']);
        $sharedEventManager->attach('Omeka\Controller\Site\Index', 'view.show.after', [$this, 'handlePageShow']);

        // Every public page — site-wide constants + verification + gap-fill.
        $sharedEventManager->attach('*', 'view.layout', [$this, 'handleLayout']);

        // Auto-ping on public content changes (no-op unless ping is enabled).
        foreach (['Omeka\Api\Adapter\ItemAdapter', 'Omeka\Api\Adapter\SitePageAdapter'] as $adapter) {
            $sharedEventManager->attach($adapter, 'api.create.post', [$this, 'handleContentChange']);
            $sharedEventManager->attach($adapter, 'api.update.post', [$this, 'handleContentChange']);
        }
    }

    // ─── View listeners ─────────────────────────────────────────────────────

    public function handleResourceShow(EventInterface $event): void
    {
        $view = $this->view($event);
        if (!$view) {
            return;
        }
        $site = $this->currentSite($view);
        $resource = $view->item ?? $view->itemSet ?? $view->media ?? null;
        if (!$site || !$resource instanceof AbstractResourceEntityRepresentation) {
            return;
        }
        // applyResource sets the <head> signals and returns optional body markup
        // (the unAPI <abbr class="unapi-id"> element). Omeka's view.show.after
        // trigger discards listener return values, so echo it here — this runs
        // inside the show template's output buffer, placing it in the page body.
        $bodyMarkup = $this->headMetadata()->applyResource($view, $resource, $site);
        if ($bodyMarkup !== null && $bodyMarkup !== '') {
            echo $bodyMarkup;
        }
    }

    public function handleBrowse(EventInterface $event): void
    {
        $view = $this->view($event);
        if (!$view) {
            return;
        }
        $site = $this->currentSite($view);
        if ($site) {
            $this->headMetadata()->applyBrowse($view, $site);
        }
    }

    public function handlePageShow(EventInterface $event): void
    {
        $view = $this->view($event);
        if (!$view) {
            return;
        }
        $site = $this->currentSite($view);
        $page = $view->page ?? null;
        if (!$site || !$page instanceof SitePageRepresentation) {
            return;
        }

        $store = $this->getServiceLocator()->get(PageSeoStore::class);
        $store->setSite($site->id());
        $overrides = $store->get($page->id());

        $homepage = $site->homepage();
        $isHomepage = $homepage && $homepage->id() === $page->id();

        $this->headMetadata()->applyPage($view, $page, $site, $overrides, (bool) $isHomepage);
    }

    public function handleLayout(EventInterface $event): void
    {
        $view = $this->view($event);
        if (!$view) {
            return;
        }
        // Public site pages only — skip the admin layout.
        $site = $this->currentSite($view);
        if (!$site) {
            return;
        }
        $this->headMetadata()->applyGlobals($view, $site);
    }

    // ─── API listener: queue IndexNow pings ─────────────────────────────────

    public function handleContentChange(EventInterface $event): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        if ((string) $settings->get('iwac_seo_ping_enabled', '0') !== '1') {
            return;
        }
        if (trim((string) $settings->get('iwac_seo_indexnow_key', '')) === '') {
            return;
        }

        $response = $event->getParam('response');
        $resource = $response ? $response->getContent() : null;
        if (!$resource || (method_exists($resource, 'isPublic') && !$resource->isPublic())) {
            return;
        }

        $url = $this->resourcePublicUrl($resource);
        if ($url === null) {
            return;
        }

        // Enqueue (deduped, capped so a bulk sync cannot grow it without bound).
        $pending = $settings->get('iwac_seo_ping_pending', []);
        if (!is_array($pending)) {
            $pending = [];
        }
        if (count($pending) < self::PING_QUEUE_CAP && !in_array($url, $pending, true)) {
            $pending[] = $url;
            $settings->set('iwac_seo_ping_pending', $pending);
        }

        // Dispatch at most once per interval; the job batches the whole queue.
        // The throttle is stamped only after a successful dispatch so a failed
        // dispatch does not burn the window.
        $last = (int) $settings->get('iwac_seo_ping_last', 0);
        $now = time();
        if ($now - $last >= self::PING_INTERVAL) {
            try {
                $services->get('Omeka\Job\Dispatcher')->dispatch(PingSearchEngines::class);
                $settings->set('iwac_seo_ping_last', $now);
            } catch (\Throwable $e) {
                // never let SEO bookkeeping break a save
            }
        }
    }

    // ─── Module configuration form ──────────────────────────────────────────

    public function getConfigForm(PhpRenderer $renderer)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $form = $services->get('FormElementManager')->get(Form\ConfigForm::class);

        $data = [];
        foreach (self::SETTINGS as $key) {
            if (in_array($key, self::INTERNAL_SETTINGS, true)) {
                continue;
            }
            $data[$key] = $settings->get($key, self::DEFAULTS[$key] ?? '');
        }
        $form->setData($data);

        return $renderer->formCollection($form, false);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $form = $services->get('FormElementManager')->get(Form\ConfigForm::class);

        $form->setData($controller->params()->fromPost());
        if (!$form->isValid()) {
            $controller->messenger()->addErrors($form->getMessages());
            return false;
        }

        $data = $form->getData();
        foreach (self::SETTINGS as $key) {
            if (in_array($key, self::INTERNAL_SETTINGS, true)) {
                continue;
            }
            if (array_key_exists($key, $data)) {
                $settings->set($key, (string) $data[$key]);
            }
        }
        return true;
    }

    // ─── Internals ──────────────────────────────────────────────────────────

    private function headMetadata(): HeadMetadata
    {
        return $this->getServiceLocator()->get(HeadMetadata::class);
    }

    private function view(EventInterface $event): ?PhpRenderer
    {
        $view = $event->getParam('view');
        if (!$view instanceof PhpRenderer) {
            $target = $event->getTarget();
            $view = $target instanceof PhpRenderer ? $target : null;
        }
        return $view;
    }

    private function currentSite(PhpRenderer $view): ?SiteRepresentation
    {
        $helpers = $view->getHelperPluginManager();
        if (!$helpers->has('currentSite')) {
            return null;
        }
        try {
            $site = $view->currentSite();
            return $site instanceof SiteRepresentation ? $site : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resourcePublicUrl(object $resource): ?string
    {
        $slug = $this->getServiceLocator()->get(SiteResolver::class)->defaultSlug();
        if ($slug === null) {
            return null;
        }
        try {
            if (method_exists($resource, 'siteUrl')) {
                return $resource->siteUrl($slug, true);
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return null;
    }
}
