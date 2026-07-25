<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use Omeka\Api\Representation\AbstractResourceRepresentation;

/**
 * Canonical public URLs for a resource, guarded.
 *
 * `siteUrl()` throws when the resource is not assigned to the site (or the
 * route cannot be assembled), and every caller in this module wants "the URL,
 * or null" rather than an exception escaping into a page render, a sitemap or
 * an API listener. That try/catch used to be copied seven times; it lives here
 * now.
 */
final class ResourceUrl
{
    /** The resource's absolute page URL on $siteSlug, or null if unavailable. */
    public static function forSite(object $resource, ?string $siteSlug): ?string
    {
        if ($siteSlug === null || $siteSlug === '' || !method_exists($resource, 'siteUrl')) {
            return null;
        }
        try {
            $url = $resource->siteUrl($siteSlug, true);
        } catch (\Throwable $e) {
            return null;
        }
        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * The resource class id, or null when the resource has no class. Omeka
     * returns a representation (not an id) and null for unclassed resources,
     * so this two-step dance appeared at eight call sites.
     */
    public static function classId(?AbstractResourceRepresentation $resource): ?int
    {
        if ($resource === null || !method_exists($resource, 'resourceClass')) {
            return null;
        }
        $class = $resource->resourceClass();
        return $class ? $class->id() : null;
    }

    /** The resource class's human label ("bibo:Article" → "Article"), or null. */
    public static function classLabel(?AbstractResourceRepresentation $resource): ?string
    {
        if ($resource === null || !method_exists($resource, 'resourceClass')) {
            return null;
        }
        $class = $resource->resourceClass();
        return $class ? (string) $class->label() : null;
    }
}
