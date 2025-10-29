<?php

declare(strict_types=1);

namespace Ibexa\Bundle\Cloud\DependencyInjection;

use Ibexa\Bundle\Core\Session\Handler\NativeSessionHandler;
use JsonException;
use Symfony\Component\DependencyInjection\EnvVarLoaderInterface;

final class UpsunEnvVarLoader implements EnvVarLoaderInterface
{
    private const string MYSQL_DEFAULT_DATABASE_CHARSET = 'utf8mb4';

    private const string PGSQL_DEFAULT_DATABASE_CHARSET = 'utf8';

    private const string DEFAULT_DATABASE_COLLATION = 'utf8mb4_unicode_520_ci';

    public function loadEnvVars(): array
    {
        $relationshipsEncoded = $_SERVER['PLATFORM_RELATIONSHIPS'] ?? null;
        $routesEncoded = $_SERVER['PLATFORM_ROUTES'] ?? null;

        if ($relationshipsEncoded === null || $routesEncoded === null) {
            return [];
        }

        $relationships = $this->decodePayload($relationshipsEncoded);

        $routes = $this->decodePayload($routesEncoded);

        if ($relationships === null || $routes === null) {
            return [];
        }

        return array_filter(
            array_merge(
                $this->buildDfsEnvVars($relationships),
                $this->buildCacheEnvVars($relationships),
                $this->buildSessionEnvVars($relationships),
                $this->buildSearchEnvVars($relationships),
                $this->buildVarnishEnvVars($routes),
            ),
            static fn (string|int|null $value): bool => $value !== null && $value !== ''
        );
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
                if (empty($endpoint['query']['is_master'])) {
                    continue;
                }

                $pdoDriver = $this->normalizePdoDriver((string) ($endpoint['scheme'] ?? ''));
                $envVars[$this->envKey('dfs_database_driver')] = $pdoDriver;

                // If driver is PGSQL, charset has to be set to utf8
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
        if (isset($relationships['rediscache'])) {
            foreach ($relationships['rediscache'] as $endpoint) {
                if (($endpoint['scheme'] ?? null) !== 'redis') {
                    continue;
                }

                return [
                    $this->envKey('cache_pool') => 'cache.redis',
                    $this->envKey('cache_dsn') => sprintf(
                        '%s:%d?retry_interval=3',
                        $endpoint['host'],
                        $endpoint['port'],
                    ),
                ];
            }
        }

        if (isset($relationships['cache'])) {
            foreach ($relationships['cache'] as $endpoint) {
                if (($endpoint['scheme'] ?? null) !== 'memcached') {
                    continue;
                }

                @trigger_error('Usage of Memcached is deprecated, redis is recommended', \E_USER_DEPRECATED);

                return [
                    $this->envKey('cache_pool') => 'cache.memcached',
                    $this->envKey('cache_dsn') => sprintf('%s:%d', $endpoint['host'], $endpoint['port']),
                ];
            }
        }

        return [];
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
            foreach ($relationships['solr'] as $endpoint) {
                if (($endpoint['scheme'] ?? null) !== 'solr') {
                    continue;
                }

                $envVars[$this->envKey('search_engine')] = 'solr';
                $envVars[$this->envKey('solr_dsn')] = sprintf(
                    'http://%s:%d/%s',
                    $endpoint['host'],
                    $endpoint['port'],
                    'solr'
                );
                $envVars[$this->envKey('solr_core')] = substr((string) $endpoint['path'], 5);
            }
        }

        if (isset($relationships['elasticsearch'])) {
            foreach ($relationships['elasticsearch'] as $endpoint) {
                $dsn = sprintf('%s:%d', $endpoint['host'], $endpoint['port']);

                if (($endpoint['username'] ?? null) !== null && ($endpoint['password'] ?? null) !== null) {
                    $dsn = $endpoint['username'] . ':' . $endpoint['password'] . '@' . $dsn;
                }

                if (($endpoint['path'] ?? null) !== null) {
                    $dsn .= '/' . ltrim((string) $endpoint['path'], '/');
                }

                $dsn = $endpoint['scheme'] . '://' . $dsn;

                $envVars[$this->envKey('search_engine')] = 'elasticsearch';
                $envVars[$this->envKey('elasticsearch_dsn')] = $dsn;
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

        if ($varnishRoute !== null && !($_SERVER['SKIP_HTTPCACHE_PURGE'] ?? false)) {
            $purgeServer = rtrim($varnishRoute, '/');
            $username = $_SERVER['HTTPCACHE_USERNAME'] ?? null;
            $password = $_SERVER['HTTPCACHE_PASSWORD'] ?? null;

            if ($username !== null && $password !== null) {
                $domain = parse_url($purgeServer, PHP_URL_HOST);
                if (\is_string($domain) && $domain !== '') {
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
        if ($decoded === false) {
            return null;
        }

        try {
            /** @var array<string, mixed> $data */
            return json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
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
        if (!\is_string($scheme) || $scheme === '') {
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

        return \is_string($value) ? $value : null;
    }
}
