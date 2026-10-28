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

use IwacSeo\Service\HeadMetadata;
use IwacSeo\Service\PageSeoStore;
use IwacSeo\Service\PingQueue;
use IwacSeo\Service\ResourceUrl;
use IwacSeo\Service\SettingsGate;
use IwacSeo\Service\SitemapGenerator;
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
use Omeka\Api\Request as ApiRequest;
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

    /**
     * What an API request's pre-events noted for its post-event, which can no
     * longer read it: whether the record an update changes was public before,
     * and the URL of a record a delete removes. Keyed by the request object, so
     * an entry lives exactly as long as its request (batch operations finalize
     * each sub-request separately).
     *
     * @var \WeakMap<ApiRequest,bool>
     */
    private \WeakMap $wasPublic;

    /** @var \WeakMap<ApiRequest,string> */
    private \WeakMap $deletedUrls;

    public function __construct()
    {
        $this->wasPublic = new \WeakMap();
        $this->deletedUrls = new \WeakMap();
    }

    public function getConfig(): array
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function install(ServiceLocatorInterface $services): void
    {
        $this->loadLifecycleDependencies();
        $this->applyDefaults($services->get('Omeka\Settings'));
        (new Service\PingRepository($services->get('Omeka\Connection')))->install();
    }

    /**
     * Re-apply any default that is still unset.
     *
     * Omeka calls this on a version bump. Without it a default introduced after
     * a site's first install (iwac_seo_sitemap_ttl and iwac_seo_noindex_browse
     * both arrived after 0.1) would never reach that site: install() runs once,
     * and nothing else writes a default. Idempotent, and it never overwrites a
     * value an administrator has set.
     */
    public function upgrade(
        $oldVersion,
        $newVersion,
        ServiceLocatorInterface $services
    ): void {
        $this->loadLifecycleDependencies();
        $this->applyDefaults($services->get('Omeka\Settings'));
        $repository = new Service\PingRepository($services->get('Omeka\Connection'));
        $repository->install();
        $settings = $services->get('Omeka\Settings');
        foreach ((array) $settings->get('iwac_seo_ping_pending', []) as $url) {
            $repository->push((string) $url);
        }
        $settings->delete('iwac_seo_ping_pending');
    }

    private function loadLifecycleDependencies(): void
    {
        // Omeka invokes install/upgrade while this module is inactive: its src/
        // namespace and services have not been registered for the request yet.
        // A PSR-4 loader scoped to IwacSeo\ resolves whatever the lifecycle
        // touches, so a new interface or helper cannot fall off a hand list
        // (1.1.0 shipped missing PingRepository, 1.2.0 nearly PingOutboxInterface).
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;
        $src = __DIR__ . '/src/';
        spl_autoload_register(static function (string $class) use ($src): void {
            if (!str_starts_with($class, __NAMESPACE__ . '\\')) {
                return;
            }
            $relative = substr($class, strlen(__NAMESPACE__) + 1);
            $file = $src . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }, true, true);
    }

    public function uninstall(ServiceLocatorInterface $services): void
    {
        $services->get('Omeka\Connection')->executeStatement('DROP TABLE IF EXISTS iwac_seo_ping');
        $settings = $services->get('Omeka\Settings');
        foreach (self::SETTINGS as $key) {
            $settings->delete($key);
        }
        // Remove the cached sitemap files (files/iwac-seo-cache).
        try {
            $services->get(SitemapGenerator::class)->destroyCache();
        } catch (\Throwable $e) {
            // best-effort
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
        $resourceControllers = [
            'Omeka\Controller\Site\Item',
            'Omeka\Controller\Site\ItemSet',
            'Omeka\Controller\Site\Media',
        ];
        foreach ($resourceControllers as $controller) {
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

        // Sitemap invalidation + auto-ping on content changes. Deletes are
        // included: the URL leaves the sitemap, and IndexNow is also the
        // fastest way to tell engines a URL vanished (they recrawl → 404).
        // Item sets have their own child sitemap, so their edits count too.
        // The pre-events note what the post-events can no longer read: the
        // visibility a record had before an update, and the URL of a record
        // being deleted.
        $contentAdapters = [
            'Omeka\Api\Adapter\ItemAdapter',
            'Omeka\Api\Adapter\ItemSetAdapter',
            'Omeka\Api\Adapter\SitePageAdapter',
        ];
        foreach ($contentAdapters as $adapter) {
            $sharedEventManager->attach($adapter, 'api.hydrate.pre', [$this, 'noteVisibility']);
            $sharedEventManager->attach($adapter, 'api.delete.pre', [$this, 'noteDeletion']);
            $sharedEventManager->attach($adapter, 'api.create.post', [$this, 'handleContentChange']);
            $sharedEventManager->attach($adapter, 'api.update.post', [$this, 'handleContentChange']);
            $sharedEventManager->attach($adapter, 'api.delete.post', [$this, 'handleContentChange']);
        }

        // Changes that alter a sitemap without being a sitemap URL of their
        // own: a media's visibility (the items' image entries), a site's
        // navigation or homepage (page order and priority). They clear the
        // cache and queue no IndexNow ping.
        foreach (['Omeka\Api\Adapter\MediaAdapter', 'Omeka\Api\Adapter\SiteAdapter'] as $adapter) {
            $sharedEventManager->attach($adapter, 'api.create.post', [$this, 'handleSitemapChange']);
            $sharedEventManager->attach($adapter, 'api.update.post', [$this, 'handleSitemapChange']);
            $sharedEventManager->attach($adapter, 'api.delete.post', [$this, 'handleSitemapChange']);
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

    /**
     * Note whether a record was public before an update changes it, so an
     * edit that makes it private can be pinged too. api.hydrate.pre is the
     * last point at which the entity still holds its stored visibility.
     */
    public function noteVisibility(EventInterface $event): void
    {
        $request = $event->getParam('request');
        $entity = $event->getParam('entity');
        if (
            !$request instanceof ApiRequest
            || $request->getOperation() !== ApiRequest::UPDATE
            || !is_object($entity) || !method_exists($entity, 'isPublic')
        ) {
            return;
        }
        $this->wasPublic[$request] = (bool) $entity->isPublic();
    }

    /**
     * Note the URL of a record about to be deleted. By api.delete.post
     * Doctrine has cleared the entity's id, so the URL can no longer be built.
     * The ping itself waits for the post-event: a delete that fails pings
     * nothing.
     */
    public function noteDeletion(EventInterface $event): void
    {
        $request = $event->getParam('request');
        $adapter = $event->getTarget();
        if (
            !$request instanceof ApiRequest
            || !is_object($adapter) || !method_exists($adapter, 'findEntity')
            || !$this->getServiceLocator()->get(PingQueue::class)->isEnabled()
        ) {
            return;
        }
        try {
            $entity = $adapter->findEntity($request->getId(), $request);
        } catch (\Throwable $e) {
            return;
        }
        $url = is_object($entity) ? $this->publicUrl($adapter, $entity) : null;
        if ($url !== null) {
            $this->deletedUrls[$request] = $url;
        }
    }

    public function handleContentChange(EventInterface $event): void
    {
        $services = $this->getServiceLocator();

        $response = $event->getParam('response');
        $entity = $response ? $response->getContent() : null;
        if (!is_object($entity)) {
            return;
        }
        // Read what the request's pre-events noted, whether or not a ping follows.
        $request = $event->getParam('request');
        $wasPublic = false;
        $deletedUrl = null;
        if ($request instanceof ApiRequest) {
            $wasPublic = $this->wasPublic[$request] ?? false;
            $deletedUrl = $this->deletedUrls[$request] ?? null;
            unset($this->wasPublic[$request], $this->deletedUrls[$request]);
        }

        $this->invalidateSitemap();

        $queue = $services->get(PingQueue::class);
        if (!$queue->isEnabled()) {
            return;
        }
        // Creates and updates announce a public URL, and so does an update
        // that has just made a record private, so engines recrawl and drop it.
        // A delete is pinged regardless of visibility, at the URL noted before
        // the deletion.
        if ((string) $event->getName() === 'api.delete.post') {
            $url = $deletedUrl;
        } else {
            $isPublic = !method_exists($entity, 'isPublic') || $entity->isPublic();
            $url = $isPublic || $wasPublic ? $this->publicUrl($event->getTarget(), $entity) : null;
        }
        if ($url === null) {
            return;
        }

        try {
            $queue->push($url);
            $queue->dispatchIfDue();
        } catch (\Throwable $error) {
            $services->get('Omeka\Logger')->err('IwacSeo: failed to queue an IndexNow URL.', [
                'url' => $url,
                'exception' => $error,
            ]);
        }
    }

    public function handleSitemapChange(EventInterface $event): void
    {
        $this->invalidateSitemap();
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

        return $this->omekaJsonLdWarning($renderer) . $renderer->formCollection($form, false);
    }

    /**
     * A warning above the form naming each public site whose pages still
     * carry Omeka's own JSON-LD embed — the 2–4 MB block that broke Search
     * Console's parsing in 1.0.1, which only a per-site checkbox removes.
     */
    private function omekaJsonLdWarning(PhpRenderer $renderer): string
    {
        $sites = $this->getServiceLocator()->get(Service\OmekaJsonLdEmbed::class)->sitesStillEmbedding();
        if ($sites === []) {
            return '';
        }
        $names = implode(', ', array_map(
            static fn (SiteRepresentation $site): string => '<em>' . $renderer->escapeHtml($site->title()) . '</em>',
            $sites
        ));
        // Escaped before the titles go in, so each part is escaped once.
        $message = sprintf(
            $renderer->escapeHtml($renderer->translate('Omeka still embeds its own JSON-LD in the record and browse pages of %s: the whole API representation of each resource, 2–4 MB on an authority record, which search engines truncate and report as unparsable structured data beside this module’s schema.org block. On each of these sites, tick “Disable JSON-LD embed” under Sites → the site → Settings → General.')), // @translate
            $names
        );
        return '<ul class="messages"><li class="warning">' . $message . '</li></ul>';
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

    /**
     * Invalidate the sitemap cache so a change shows up on the next crawl
     * instead of after the TTL. Any create/update can affect the URL set or a
     * <lastmod> (including a public→private edit), so there is no public check.
     * The clear is debounced per request, so a bulk import pays for it once
     * rather than once per saved item.
     */
    private function invalidateSitemap(): void
    {
        $services = $this->getServiceLocator();
        if (!$services->get(SettingsGate::class)->isOn('iwac_seo_sitemap_enabled', true)) {
            return;
        }
        try {
            $services->get(SitemapGenerator::class)->clearCache();
        } catch (\Throwable $e) {
            // never let SEO bookkeeping break a save
        }
    }

    /** Set each default that has no stored value yet. */
    private function applyDefaults(\Omeka\Settings\Settings $settings): void
    {
        foreach (self::DEFAULTS as $key => $value) {
            if ($settings->get($key) === null) {
                $settings->set($key, $value);
            }
        }
    }

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

    /**
     * The URL a record is pinged at. Items and item sets are pinged on the
     * default site; a page exists only on its own site, so it is pinged there,
     * and not at all when that site is private.
     */
    private function publicUrl(mixed $adapter, object $entity): ?string
    {
        $resource = $this->representation($adapter, $entity);
        if ($resource instanceof SitePageRepresentation) {
            $site = $resource->site();
            return $site->isPublic() ? ResourceUrl::forSite($resource, $site->slug()) : null;
        }
        if ($resource === null) {
            return null;
        }
        return ResourceUrl::forSite(
            $resource,
            $this->getServiceLocator()->get(SiteResolver::class)->defaultSlug()
        );
    }

    /**
     * The API representation of an entity. The api.* events carry the
     * Doctrine entity, which has no siteUrl() — the API manager turns the
     * response into a representation only after the post-events have run —
     * so it is built from the adapter.
     */
    private function representation(mixed $adapter, object $entity): ?object
    {
        if ($entity instanceof AbstractResourceEntityRepresentation || $entity instanceof SitePageRepresentation) {
            return $entity;
        }
        if (!is_object($adapter) || !method_exists($adapter, 'getRepresentation')) {
            return null;
        }
        try {
            return $adapter->getRepresentation($entity);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
