<?php
declare(strict_types=1);

namespace IwacSeo\Service;

/** One policy for tracking parameters, pagination and filter variants. */
final class UrlPolicy
{
    private const ORIGIN_ENV = 'IWAC_SEO_PUBLIC_ORIGIN';

    /** @var array<string,?string> validated origin per raw environment value */
    private static array $origins = [];

    public static function canonical(string $url): string
    {
        $url = explode('#', $url, 2)[0];
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query)) {
            return $url;
        }
        parse_str($query, $params);
        foreach (array_keys($params) as $key) {
            if (str_starts_with(strtolower($key), 'utm_') || in_array(strtolower($key), ['gclid', 'fbclid', 'msclkid'], true)) {
                unset($params[$key]);
            }
        }
        if (isset($params['page']) && is_scalar($params['page']) && (string) $params['page'] === '1') {
            unset($params['page']);
        }
        ksort($params);
        return Text::withoutQuery($url) . ($params ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '');
    }

    public static function isFiltered(string $url): bool
    {
        parse_str((string) parse_url(self::canonical($url), PHP_URL_QUERY), $params);
        if (isset($params['page']) && is_scalar($params['page']) && ctype_digit((string) $params['page'])) {
            unset($params['page']);
        }
        return $params !== [];
    }

    /**
     * Whether $url names this deployment: the host the request arrived on, or
     * the pinned public origin's host. Behind a proxy that rewrites Host the
     * two differ, and the page's own canonical carries the public one.
     */
    public static function isOwnHost(string $url, ?string $requestHost): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        foreach ([$requestHost, parse_url((string) self::publicOrigin(), PHP_URL_HOST)] as $own) {
            if (is_string($own) && strcasecmp($host, $own) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * The pinned public origin ("https://islam.zmo.de"), or null when none is
     * set or the one set is not an HTTP(S) origin without a path.
     *
     * Validated once per value. A malformed value is logged and ignored rather
     * than thrown: this runs inside every page render, where an exception would
     * take the whole public site down over an SEO setting. The dashboard and
     * the cron script report it instead ({@see originError()}).
     */
    public static function publicOrigin(): ?string
    {
        $raw = (string) getenv(self::ORIGIN_ENV);
        if (!array_key_exists($raw, self::$origins)) {
            $origin = rtrim(trim($raw), '/');
            $valid = $origin !== '' && MetadataValue::url($origin) !== null
                && SiteResolver::hostFromUrl($origin) === $origin;
            if ($origin !== '' && !$valid) {
                error_log('IwacSeo: ignoring ' . self::ORIGIN_ENV . ', which must be an HTTP(S) origin without a path.');
            }
            self::$origins[$raw] = $valid ? $origin : null;
        }
        return self::$origins[$raw];
    }

    /** Why the configured public origin is being ignored, or null when it is usable or unset. */
    public static function originError(): ?string
    {
        return trim((string) getenv(self::ORIGIN_ENV)) !== '' && self::publicOrigin() === null
            ? self::ORIGIN_ENV . ' must be an HTTP(S) origin without a path, such as https://islam.zmo.de.'
            : null;
    }

    /** Pin the deployment's public origin independently of request Host headers. */
    public static function publicUrl(string $url): string
    {
        $origin = self::publicOrigin();
        if ($origin === null) {
            return $url;
        }
        $parts = parse_url($url);
        return $origin . ($parts['path'] ?? '/')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
