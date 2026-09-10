<?php
declare(strict_types=1);

namespace IwacSeo\Service;

/** One policy for tracking parameters, pagination and filter variants. */
final class UrlPolicy
{
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

    /** Pin the deployment's public origin independently of request Host headers. */
    public static function publicUrl(string $url): string
    {
        $origin = rtrim((string) getenv('IWAC_SEO_PUBLIC_ORIGIN'), '/');
        if ($origin === '') {
            return $url;
        }
        if (MetadataValue::url($origin) === null || SiteResolver::hostFromUrl($origin) !== $origin) {
            throw new \RuntimeException('IWAC_SEO_PUBLIC_ORIGIN must be an HTTP(S) origin without a path.');
        }
        $parts = parse_url($url);
        return $origin . ($parts['path'] ?? '/')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
