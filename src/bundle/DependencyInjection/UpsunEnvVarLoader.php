<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Bundle\Cloud\DependencyInjection;

use Ibexa\Bundle\Core\Session\Handler\NativeSessionHandler;
use Symfony\Component\DependencyInjection\EnvVarLoaderInterface;
use function is_string;

final class UpsunEnvVarLoader implements EnvVarLoaderInterface
{
    private const string MYSQL_DEFAULT_DATABASE_CHARSET = 'utf8mb4';

    private const string PGSQL_DEFAULT_DATABASE_CHARSET = 'utf8';

    private const string DEFAULT_DATABASE_COLLATION = 'utf8mb4_unicode_520_ci';

    private const string DEFAULT_DFS_RELATIONSHIP_KEY = 'dfs_database';

    public function loadEnvVars(): array
    {
        if (!isset($_SERVER['PLATFORM_RELATIONSHIPS']) || !isset($_SERVER['PLATFORM_ROUTES'])) {
            return [];
        }

        $relationshipsEncoded = $_SERVER['PLATFORM_RELATIONSHIPS'];
        $routesEncoded = $_SERVER['PLATFORM_ROUTES'];

        $relationships = $this->decodePayload($relationshipsEncoded);
        $routes = $this->decodePayload($routesEncoded);

        if ($relationships === null || $routes === null) {
            return [];
        }

        $groupedRelationships = $this->groupRelationshipsByScheme($relationships);

        return array_filter(
            array_merge(
                $this->buildDatabaseEnvVars($groupedRelationships),
                $this->buildDfsEnvVars($groupedRelationships),
                $this->buildCacheEnvVars($groupedRelationships),
                $this->buildSessionEnvVars($groupedRelationships),
                $this->buildSearchEnvVars($groupedRelationships),
                $this->buildVarnishEnvVars($routes),
            ),
            static fn (string|int|null $value): bool => $value !== null && $value !== ''
        );
    }

    /**
     * @param array<string, array<string, mixed>> $relationships
     *
     * @return array<string, string>
     */
    private function buildDatabaseEnvVars(array $relationships): array
    {
        $envVars = [];

        foreach ($relationships as $scheme => $relationshipsByKey) {
            $isPGSQL = $scheme === 'pgsql';
            $isMySQL = $scheme === 'mysql';
            if (!$isPGSQL && !$isMySQL) {
                continue;
            }

            $normalizedScheme = $isPGSQL ? 'postgres' : $scheme;

            foreach ($relationshipsByKey as $key => $endpoints) {
                $key = strtoupper($key);

                foreach ($endpoints as $i => $endpoint) {
                    $prefix = $i === 0 ? "{$key}_" : "{$key}_{$i}_";
                    $prefix = str_replace('-', '_', $prefix);

                    $username = $endpoint['username'] ?? '';
                    $password = $endpoint['password'] ?? '';
                    $host = $endpoint['host'] ?? '';
                    $port = $endpoint['port'] ?? 0;
                    $path = $endpoint['path'] ?? 'main';

                    $url = sprintf('%s://', $normalizedScheme);
                    if ($username !== '') {
                        $url .= $username;
                        if ($password !== '') {
                            $url .= ':' . $password;
                        }
                        $url .= '@';
                    }
                    $url .= sprintf('%s:%s/%s?sslmode=disable', $host, $port, $path);

                    $charset = $isMySQL ? self::MYSQL_DEFAULT_DATABASE_CHARSET : self::PGSQL_DEFAULT_DATABASE_CHARSET;
                    $url .= '&charset=' . $charset;

                    $type = $endpoint['type'] ?? null;
                    if ($type !== null && str_contains((string) $type, ':')) {
                        [, $version] = explode(':', (string) $type, 2);

                        if ($isMySQL) {
                            $minor = $version === '10.2' ? 7 : 0;
                            $version = "{$version}.{$minor}-MariaDB";
                        }

                        $url .= '&serverVersion=' . $version;
                    }

                    $envVars["{$prefix}URL"] = $url;
                    $envVars["{$prefix}USER"] = $username;
                    $envVars["{$prefix}USERNAME"] = $username;
                    $envVars["{$prefix}PASSWORD"] = $password;
                    $envVars["{$prefix}HOST"] = $host;
                    $envVars["{$prefix}PORT"] = (string) $port;
                    $envVars["{$prefix}NAME"] = $path;
                    $envVars["{$prefix}DATABASE"] = $path;
                    $envVars["{$prefix}DRIVER"] = $normalizedScheme;
                    $envVars["{$prefix}SERVER"] = sprintf('%s://%s:%s', $normalizedScheme, $host, $port);

                    if ($key === strtoupper(self::DEFAULT_DFS_RELATIONSHIP_KEY)) {

                    }
                }
            }
        }

        return $envVars;
    }

    /**
     * @param array<string, array<array<string, mixed>>> $relationships
     *
     * @return array<string, string>
     */
    private function buildDfsEnvVars(array $relationships): array
    {
        $dfsPath = $_SERVER['PLATFORMSH_DFS_NFS_PATH'] ?? null;
        if ($dfsPath === null) {
            return [];
        }

        $envVars = [
            $this->envKey('dfs_nfs_path') => $dfsPath,
            $this->envKey('dfs_database_charset') => $_SERVER['DATABASE_CHARSET']
                ?? self::MYSQL_DEFAULT_DATABASE_CHARSET,
            $this->envKey('dfs_database_collation') => $_SERVER['DATABASE_COLLATION']
                ?? self::DEFAULT_DATABASE_COLLATION,
        ];

        if (isset($relationships['dfs_database'])) {
            foreach ($relationships['dfs_database'] as $endpoint) {
                if (!isset($endpoint['query']['is_master'])) {
                    continue;
                }

                $pdoDriver = $this->normalizePdoDriver((string) ($endpoint['scheme'] ?? ''));
                $envVars[$this->envKey('dfs_database_driver')] = $pdoDriver;

                // If the driver is PGSQL, charset has to be set to utf8
                if ($pdoDriver === 'pdo_pgsql') {
                    $envVars[$this->envKey('dfs_database_charset')] = self::PGSQL_DEFAULT_DATABASE_CHARSET;
                }

                $envVars[$this->envKey('dfs_database_url')] = sprintf(
                    '%s://%s:%s@%s:%d/%s',
                    $endpoint['scheme'],
                    $endpoint['username'],
                    $endpoint['password'],
                    $endpoint['host'],
                    $endpoint['port'],
                    ltrim((string) $endpoint['path'], '/')
                );

                break;
            }
        } else {
            $driver = $this->guessRepositoryDriver();
            if ($driver !== null) {
                $envVars[$this->envKey('dfs_database_driver')] = $driver;
            }
        }

        return $envVars;
    }

    /**
     * @param array<string, array<array<string, mixed>>> $relationships
     *
     * @return array<string, string>
     */
    private function buildCacheEnvVars(array $relationships): array
    {
        $envVars = [];
        $cachePoolSet = false;

        foreach ($relationships as $scheme => $relationshipsByKey) {
            if ($scheme === 'redis') {
                foreach ($relationshipsByKey as $key => $endpoints) {
                    $key = strtoupper($key);

                    foreach ($endpoints as $i => $endpoint) {
                        $prefix = $i === 0 ? "{$key}_" : "{$key}_{$i}_";
                        $prefix = str_replace('-', '_', $prefix);

                        $host = $endpoint['host'] ?? '';
                        $port = $endpoint['port'] ?? 0;

                        $envVars["{$prefix}URL"] = sprintf('redis://%s:%s', $host, $port);
                        $envVars["{$prefix}HOST"] = $host;
                        $envVars["{$prefix}PORT"] = (string) $port;
                        $envVars["{$prefix}SCHEME"] = 'redis';

                        // Set cache_pool and cache_dsn for the first redis endpoint found
                        if (!$cachePoolSet) {
                            $envVars[$this->envKey('cache_pool')] = 'cache.redis';
                            $envVars[$this->envKey('cache_dsn')] = sprintf(
                                '%s:%d?retry_interval=3',
                                $host,
                                $port,
                            );
                            $cachePoolSet = true;
                        }
                    }
                }
            }

            if ($scheme === 'memcached') {
                foreach ($relationshipsByKey as $key => $endpoints) {
                    $key = strtoupper($key);

                    foreach ($endpoints as $i => $endpoint) {
                        $prefix = $i === 0 ? "{$key}_" : "{$key}_{$i}_";
                        $prefix = str_replace('-', '_', $prefix);

                        $host = $endpoint['host'] ?? '';
                        $port = $endpoint['port'] ?? 0;

                        $envVars["{$prefix}HOST"] = $host;
                        $envVars["{$prefix}PORT"] = (string) $port;

                        // Set cache_pool and cache_dsn for the first memcached endpoint found (only if redis wasn't found)
                        if (!$cachePoolSet) {
                            @trigger_error('Usage of Memcached is deprecated, redis is recommended', E_USER_DEPRECATED);

                            $envVars[$this->envKey('cache_pool')] = 'cache.memcached';
                            $envVars[$this->envKey('cache_dsn')] = sprintf('%s:%d', $host, $port);
                            $cachePoolSet = true;
                        }
                    }
                }
            }
        }

        return $envVars;
    }

    /**
     * @param array<string, array<array<string, mixed>>> $relationships
     *
     * @return array<string, string>
     */
    private function buildSessionEnvVars(array $relationships): array
    {
        $endpoints = $relationships['redissession'] ?? $relationships['rediscache'] ?? null;
        if ($endpoints === null) {
            return [];
        }

        foreach ($endpoints as $endpoint) {
            if (($endpoint['scheme'] ?? null) !== 'redis') {
                continue;
            }

            return [
                $this->envKey('session_handler_id') => NativeSessionHandler::class,
                $this->envKey('session_save_path') => sprintf(
                    '%s:%d',
                    $endpoint['host'],
                    $endpoint['port'],
                ),
            ];
        }

        return [];
    }

    /**
     * @param array<string, array<array<string, mixed>>> $relationships
     *
     * @return array<string, string>
     */
    private function buildSearchEnvVars(array $relationships): array
    {
        $envVars = [];

        if (isset($relationships['solr'])) {
            foreach ($relationships['solr'] as $key => $endpoints) {
                $key = strtoupper($key);

                foreach ($endpoints as $i => $endpoint) {
                    if (($endpoint['scheme'] ?? null) !== 'solr') {
                        continue;
                    }

                    $prefix = $i === 0 ? "{$key}_" : "{$key}_{$i}_";
                    $prefix = str_replace('-', '_', $prefix);

                    $host = $endpoint['host'] ?? '';
                    $port = $endpoint['port'] ?? 0;
                    $path = $endpoint['path'] ?? '';

                    $envVars[$this->envKey('search_engine')] = 'solr';
                    $envVars[$this->envKey('solr_dsn')] = sprintf(
                        'http://%s:%d/%s',
                        $host,
                        $port,
                        'solr'
                    );
                    $envVars[$this->envKey('solr_core')] = substr($path, 5);

                    $envVars["{$prefix}HOST"] = $host;
                    $envVars["{$prefix}PORT"] = (string) $port;
                    $envVars["{$prefix}NAME"] = $path;
                    $envVars["{$prefix}DATABASE"] = $path;
                }
            }
        }

        if (isset($relationships['elasticsearch'])) {
            foreach ($relationships['elasticsearch'] as $key => $endpoints) {
                $key = strtoupper($key);

                foreach ($endpoints as $i => $endpoint) {
                    $prefix = $i === 0 ? "{$key}_" : "{$key}_{$i}_";
                    $prefix = str_replace('-', '_', $prefix);

                    $host = $endpoint['host'] ?? '';
                    $port = $endpoint['port'] ?? 0;
                    $scheme = $endpoint['scheme'] ?? 'http';
                    $path = $endpoint['path'] ?? null;

                    $dsn = sprintf('%s:%d', $host, $port);

                    if (($endpoint['username'] ?? null) !== null && ($endpoint['password'] ?? null) !== null) {
                        $dsn = $endpoint['username'] . ':' . $endpoint['password'] . '@' . $dsn;
                    }

                    if ($path !== null) {
                        $dsn .= '/' . ltrim((string) $path, '/');
                    }

                    $url = $scheme . '://' . $host . ':' . $port;
                    if ($path !== null && $path !== '') {
                        $url .= $path;
                    }

                    $dsn = $scheme . '://' . $dsn;

                    $envVars[$this->envKey('search_engine')] = 'elasticsearch';
                    $envVars[$this->envKey('elasticsearch_dsn')] = $dsn;

                    $envVars["{$prefix}URL"] = $url;
                    $envVars["{$prefix}HOST"] = $host;
                    $envVars["{$prefix}PORT"] = (string) $port;
                    $envVars["{$prefix}SCHEME"] = $scheme;
                }
            }
        }

        return $envVars;
    }

    /**
     * @param array<string, array<string, mixed>> $routes
     *
     * @return array<string, string>
     */
    private function buildVarnishEnvVars(array $routes): array
    {
        $envVars = [];
        $varnishRoute = null;

        foreach ($routes as $host => $info) {
            if ($varnishRoute === null && $this->isVarnishRoute($info)) {
                $varnishRoute = $host;
            }

            if ($this->isVarnishRoute($info) && ($info['primary'] ?? false) === true) {
                $varnishRoute = $host;
                break;
            }
        }

        $skipHttpCachePurge = (bool) ($_SERVER['SKIP_HTTPCACHE_PURGE'] ?? false);

        if ($varnishRoute !== null && $skipHttpCachePurge === false) {
            $purgeServer = rtrim($varnishRoute, '/');
            $username = $_SERVER['HTTPCACHE_USERNAME'] ?? null;
            $password = $_SERVER['HTTPCACHE_PASSWORD'] ?? null;

            if ($username !== null && $password !== null) {
                $domain = parse_url($purgeServer, PHP_URL_HOST);
                if (is_string($domain) && $domain !== '') {
                    $credentials = rawurlencode($username) . ':' . rawurlencode($password);
                    $purgeServer = str_replace($domain, $credentials . '@' . $domain, $purgeServer);
                }
            }

            $envVars[$this->envKey('httpcache_purge_type')] = 'varnish';
            $envVars[$this->envKey('httpcache_purge_server')] = $purgeServer;
        }

        $envVars[$this->envKey('httpcache_varnish_invalidate_token')] = $_SERVER['HTTPCACHE_VARNISH_INVALIDATE_TOKEN']
            ?? $_SERVER['PLATFORM_PROJECT_ENTROPY']
            ?? '';

        return $envVars;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodePayload(string $payload): ?array
    {
        $decoded = base64_decode($payload, true);

        return $decoded === false ? null : json_decode($decoded, true, JSON_THROW_ON_ERROR);
    }

    private function groupRelationshipsByScheme(array $relationships): array
    {
        $groupedRelationships = [];
        foreach ($relationships as $key => $endpoints) {
            foreach ($endpoints as $endpoint) {
                if (!isset($endpoint['scheme'])) {
                    continue;
                }

                $groupedRelationships[$endpoint['scheme']][$key][] = $endpoint;
            }
        }

        return $groupedRelationships;
    }

    private function normalizePdoDriver(string $scheme): string
    {
        if ($scheme === '') {
            return '';
        }

        return str_starts_with($scheme, 'pdo_') ? $scheme : 'pdo_' . $scheme;
    }

    private function guessRepositoryDriver(): ?string
    {
        $explicit = $this->getFirstNonEmptyEnv('DATABASE_DRIVER');
        if ($explicit !== null) {
            return $explicit;
        }

        $databaseUrl = $this->getFirstNonEmptyEnv('DATABASE_URL');
        if ($databaseUrl === null) {
            return null;
        }

        $scheme = parse_url($databaseUrl, PHP_URL_SCHEME);
        if (!is_string($scheme) || $scheme === '') {
            return null;
        }

        return $this->normalizePdoDriver($scheme);
    }

    private function envKey(string $parameterName): string
    {
        return strtoupper(str_replace(['.', '-'], '_', $parameterName));
    }

    /**
     * @param array<string, mixed> $route
     */
    private function isVarnishRoute(array $route): bool
    {
        return ($route['type'] ?? null) === 'upstream' && ($route['upstream'] ?? null) === 'varnish';
    }

    private function getFirstNonEmptyEnv(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? null;
        $value = $value === '' ? null : $value;

        return is_string($value) ? $value : null;
    }
}
