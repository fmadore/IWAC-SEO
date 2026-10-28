<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Settings\SiteSettings;

/**
 * Which public sites still carry Omeka's own JSON-LD embed.
 *
 * Omeka S core echoes each resource's whole API representation into the body
 * of every public show and browse page unless the site's "Disable JSON-LD
 * embed" box (`disable_jsonld_embed`) is ticked. On IWAC that block is 2–4 MB
 * on an authority record, whose `@reverse` lists every item that links to it:
 * Google truncates it and reports unparsable structured data, and it describes
 * the record a second time, under its API URL, beside the schema.org block
 * this module emits (CHANGELOG 1.0.1, "Not a code change"). Ticking the box is
 * the whole fix, and nothing in Omeka reminds the admin to — hence the warning
 * on the configuration form, which this decides.
 *
 * Every public site is checked, not only the default one: the setting is per
 * site, and the French and English sites each hold their own.
 */
final class OmekaJsonLdEmbed
{
    public function __construct(
        private readonly SettingsGate $settings,
        private readonly SiteResolver $sites,
        private readonly SiteSettings $siteSettings,
    ) {
    }

    /**
     * The public sites still embedding, while this module's own JSON-LD is on
     * — with it off, Omeka's embed is not competing with anything here. Empty
     * when the lookup fails: a reminder must never break the form it sits on.
     *
     * @return SiteRepresentation[]
     */
    public function sitesStillEmbedding(): array
    {
        if (!$this->settings->isOn('iwac_seo_jsonld_enabled', true)) {
            return [];
        }
        try {
            $embedding = [];
            foreach ($this->sites->publicSites() as $site) {
                // The truthiness test Omeka itself applies to the setting
                // (application/Module.php, view.show.after), read for each
                // site without disturbing the service's current target.
                if (!$this->siteSettings->get('disable_jsonld_embed', null, $site->id())) {
                    $embedding[] = $site;
                }
            }
            return $embedding;
        } catch (\Throwable $e) {
            return [];
        }
    }
}
