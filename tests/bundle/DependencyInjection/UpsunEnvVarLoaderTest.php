<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Tests\Bundle\Cloud\DependencyInjection;

use Ibexa\Bundle\Cloud\DependencyInjection\UpsunEnvVarLoader;
use Ibexa\Bundle\Core\Session\Handler\NativeSessionHandler;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ibexa\Bundle\Cloud\DependencyInjection\UpsunEnvVarLoader
 */
final class UpsunEnvVarLoaderTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalServer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalServer = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;

        parent::tearDown();
    }

    /**
     * @param array<string, array<array<string, mixed>>> $relationships
     * @param array<string, array<string, mixed>> $routes
     * @param array<string, string> $expectedEnv
     * @param array<string, mixed> $serverValues
     *
     * @dataProvider providerForTestLoadEnvVars
     */
    public function testLoadEnvVars(
        array $relationships,
        array $routes,
        array $expectedEnv,
        array $serverValues
    ): void {
        $_SERVER = $this->originalServer;

        foreach ($serverValues as $key => $value) {
            $_SERVER[$key] = $value;
        }

        $_SERVER['PLATFORM_RELATIONSHIPS'] = base64_encode(json_encode($relationships, JSON_THROW_ON_ERROR));
        $_SERVER['PLATFORM_ROUTES'] = base64_encode(json_encode($routes, JSON_THROW_ON_ERROR));

        $loader = new UpsunEnvVarLoader();
        $result = $loader->loadEnvVars();

        self::assertSame($expectedEnv, $result);
    }

    /**
     * @return iterable<
     *     string,
     *     array{
     *         array<string, array<int, array<string, mixed>>>,
     *         array<string, array<string, mixed>>,
     *         array<string, string>,
     *         array<string, mixed>
     *     }
     * >
     */
    public function providerForTestLoadEnvVars(): iterable
    {
        $routes = $this->createRoutes();
        $serverValues = ['PLATFORM_PROJECT_ENTROPY' => 'project_entropy'];

        yield 'redis cache with session fallback and elasticsearch' => [
            [
                'replica_db' => [
                    [
                        'host' => 'database.internal',
                        'hostname' => 'mysql_db_random._.eu-4.platformsh.site',
                        'cluster' => 'some_cluster',
                        'service' => 'mysqldb',
                        'rel' => 'user',
                        'scheme' => 'mysql',
                        'username' => 'user',
                        'password' => 'some_password',
                        'port' => 3306,
                        'epoch' => 0,
                        'path' => 'main',
                        'query' => ['is_master' => true],
                        'fragment' => null,
                        'public' => false,
                        'host_mapped' => false,
                        'type' => 'mariadb:10.4',
                        'instance_ips' => ['127.0.0.1'],
                        'ip' => '127.0.0.1',
                    ],
                ],
                'rediscache' => [
                    [
                        'host' => 'rediscache.internal',
                        'hostname' => 'redis.service._.eu-4.platformsh.site',
                        'cluster' => 'some_cluster',
                        'service' => 'rediscache',
                        'rel' => 'redis',
                        'scheme' => 'redis',
                        'username' => null,
                        'password' => null,
                        'port' => 6379,
                        'epoch' => 0,
                        'path' => null,
                        'query' => [],
                        'fragment' => null,
                        'public' => false,
                        'host_mapped' => false,
                        'type' => 'redis:5.0',
                        'instance_ips' => ['127.0.0.1'],
                        'ip' => '127.0.0.1',
                    ],
                ],
                'site_elasticsearch' => [
                    [
                        'username' => null,
                        'password' => null,
                        'scheme' => 'http',
                        'service' => 'elasticsearch',
                        'fragment' => null,
                        'ip' => '123.456.78.90',
                        'hostname' => 'something.elasticsearch.service._.eu-1.platformsh.site',
                        'port' => 9200,
                        'cluster' => 'something-main-7rqtwti',
                        'host' => 'elasticsearch.internal',
                        'rel' => 'elasticsearch',
                        'path' => null,
                        'query' => [],
                        'type' => 'elasticsearch:8.5',
                        'public' => false,
                        'host_mapped' => false,
                    ],
                ],
            ],
            $routes,
            [
                'REPLICA_DB_URL' => 'mysql://user:some_password@database.internal:3306/main?sslmode=disable&charset=utf8mb4&serverVersion=10.4.0-MariaDB',
                'REPLICA_DB_USER' => 'user',
                'REPLICA_DB_USERNAME' => 'user',
                'REPLICA_DB_PASSWORD' => 'some_password',
                'REPLICA_DB_HOST' => 'database.internal',
                'REPLICA_DB_PORT' => '3306',
                'REPLICA_DB_NAME' => 'main',
                'REPLICA_DB_DATABASE' => 'main',
                'REPLICA_DB_DRIVER' => 'mysql',
                'REPLICA_DB_SERVER' => 'mysql://database.internal:3306',
                'REDISCACHE_URL' => 'redis://rediscache.internal:6379',
                'REDISCACHE_HOST' => 'rediscache.internal',
                'REDISCACHE_PORT' => '6379',
                'REDISCACHE_SCHEME' => 'redis',
                'CACHE_POOL' => 'cache.redis',
                'CACHE_DSN' => 'rediscache.internal:6379?retry_interval=3',
                'SESSION_HANDLER_ID' => NativeSessionHandler::class,
                'SESSION_SAVE_PATH' => 'rediscache.internal:6379',
                'SEARCH_ENGINE' => 'elasticsearch',
                'ELASTICSEARCH_DSN' => 'http://elasticsearch.internal:9200',
                'SITE_ELASTICSEARCH_URL' => 'http://elasticsearch.internal:9200',
                'SITE_ELASTICSEARCH_HOST' => 'elasticsearch.internal',
                'SITE_ELASTICSEARCH_PORT' => '9200',
                'SITE_ELASTICSEARCH_SCHEME' => 'http',
                'HTTPCACHE_VARNISH_INVALIDATE_TOKEN' => 'project_entropy',
            ],
            $serverValues,
        ];

        yield 'redis cache with session fallback and solr' => [
            [
                'replica_db' => [
                    [
                        'host' => 'database.internal',
                        'hostname' => 'mysql_db_random._.eu-4.platformsh.site',
                        'cluster' => 'some_cluster',
                        'service' => 'mysqldb',
                        'rel' => 'user',
                        'scheme' => 'mysql',
                        'username' => 'user',
                        'password' => 'some_password',
                        'port' => 3306,
                        'epoch' => 0,
                        'path' => 'main',
                        'query' => ['is_master' => true],
                        'fragment' => null,
                        'public' => false,
                        'host_mapped' => false,
                        'type' => 'mariadb:10.4',
                        'instance_ips' => ['127.0.0.1'],
                        'ip' => '127.0.0.1',
                    ],
                ],
                'rediscache' => [
                    [
                        'host' => 'rediscache.internal',
                        'hostname' => 'redis.service._.eu-4.platformsh.site',
                        'cluster' => 'some_cluster',
                        'service' => 'rediscache',
                        'rel' => 'redis',
                        'scheme' => 'redis',
                        'username' => null,
                        'password' => null,
                        'port' => 6379,
                        'epoch' => 0,
                        'path' => null,
                        'query' => [],
                        'fragment' => null,
                        'public' => false,
                        'host_mapped' => false,
                        'type' => 'redis:5.0',
                        'instance_ips' => ['127.0.0.1'],
                        'ip' => '127.0.0.1',
                    ],
                ],
                'site_solr' => [
                    [
                        'username' => null,
                        'scheme' => 'solr',
                        'service' => 'solr',
                        'fragment' => null,
                        'ip' => '123.456.78.90',
                        'hostname' => 'host.solr.service._.eu-1.platformsh.site',
                        'port' => 8080,
                        'cluster' => 'some-cluster',
                        'host' => 'solr.internal',
                        'rel' => 'solr',
                        'path' => 'solr/collection1',
                        'query' => [],
                        'password' => null,
                        'type' => 'solr:9.9',
                        'public' => false,
                        'host_mapped' => false,
                    ],
                ],
            ],
            $routes,
            [
                'REPLICA_DB_URL' => 'mysql://user:some_password@database.internal:3306/main?sslmode=disable&charset=utf8mb4&serverVersion=10.4.0-MariaDB',
                'REPLICA_DB_USER' => 'user',
                'REPLICA_DB_USERNAME' => 'user',
                'REPLICA_DB_PASSWORD' => 'some_password',
                'REPLICA_DB_HOST' => 'database.internal',
                'REPLICA_DB_PORT' => '3306',
                'REPLICA_DB_NAME' => 'main',
                'REPLICA_DB_DATABASE' => 'main',
                'REPLICA_DB_DRIVER' => 'mysql',
                'REPLICA_DB_SERVER' => 'mysql://database.internal:3306',
                'REDISCACHE_URL' => 'redis://rediscache.internal:6379',
                'REDISCACHE_HOST' => 'rediscache.internal',
                'REDISCACHE_PORT' => '6379',
                'REDISCACHE_SCHEME' => 'redis',
                'CACHE_POOL' => 'cache.redis',
                'CACHE_DSN' => 'rediscache.internal:6379?retry_interval=3',
                'SESSION_HANDLER_ID' => NativeSessionHandler::class,
                'SESSION_SAVE_PATH' => 'rediscache.internal:6379',
                'SEARCH_ENGINE' => 'solr',
                'SOLR_DSN' => 'http://solr.internal:8080/solr',
                'SOLR_CORE' => 'collection1',
                'SITE_SOLR_HOST' => 'solr.internal',
                'SITE_SOLR_PORT' => '8080',
                'SITE_SOLR_NAME' => 'solr/collection1',
                'SITE_SOLR_DATABASE' => 'solr/collection1',
                'HTTPCACHE_VARNISH_INVALIDATE_TOKEN' => 'project_entropy',
            ],
            $serverValues,
        ];

        yield 'dfs' => [
            [
                'dfs_database' => [
                    [
                        'host' => 'dfs_database.internal',
                        'scheme' => 'mysql',
                        'username' => 'dfs',
                        'password' => 'dfs',
                        'port' => 3306,
                        'path' => 'dfs',
                        'query' => ['is_master' => true],
                    ],
                ],
            ],
            $routes,
            [
                'DFS_DATABASE_URL' => 'mysql://dfs:dfs@dfs_database.internal:3306/dfs?sslmode=disable&charset=utf8mb4',
                'DFS_DATABASE_USER' => 'dfs',
                'DFS_DATABASE_USERNAME' => 'dfs',
                'DFS_DATABASE_PASSWORD' => 'dfs',
                'DFS_DATABASE_HOST' => 'dfs_database.internal',
                'DFS_DATABASE_PORT' => '3306',
                'DFS_DATABASE_NAME' => 'dfs',
                'DFS_DATABASE_DATABASE' => 'dfs',
                'DFS_DATABASE_DRIVER' => 'pdo_mysql',
                'DFS_DATABASE_SERVER' => 'mysql://dfs_database.internal:3306',
                'DFS_NFS_PATH' => '/mnt/dfs/nfs',
                'DFS_DATABASE_CHARSET' => 'utf8mb4',
                'DFS_DATABASE_COLLATION' => 'utf8mb4_unicode_520_ci',
                'HTTPCACHE_VARNISH_INVALIDATE_TOKEN' => 'project_entropy',
            ],
            $serverValues + ['PLATFORMSH_DFS_NFS_PATH' => '/mnt/dfs/nfs'],
        ];

        yield 'postgresql without version' => [
            [
                'pg_main' => [
                    [
                        'username' => 'main',
                        'password' => 'main',
                        'host' => 'database.internal',
                        'port' => 5432,
                        'path' => 'main',
                        'scheme' => 'pgsql',
                        'query' => ['is_master' => true],
                    ],
                ],
            ],
            $routes,
            [
                'PG_MAIN_URL' => 'postgres://main:main@database.internal:5432/main?sslmode=disable&charset=utf8',
                'PG_MAIN_USER' => 'main',
                'PG_MAIN_USERNAME' => 'main',
                'PG_MAIN_PASSWORD' => 'main',
                'PG_MAIN_HOST' => 'database.internal',
                'PG_MAIN_PORT' => '5432',
                'PG_MAIN_NAME' => 'main',
                'PG_MAIN_DATABASE' => 'main',
                'PG_MAIN_DRIVER' => 'postgres',
                'PG_MAIN_SERVER' => 'postgres://database.internal:5432',
                'HTTPCACHE_VARNISH_INVALIDATE_TOKEN' => 'project_entropy',
            ],
            $serverValues,
        ];

        yield 'postgresql with version 9.6' => [
            [
                'legacy_pgsql' => [
                    [
                        'username' => 'main',
                        'password' => 'main',
                        'host' => 'database.internal',
                        'port' => 5432,
                        'path' => 'main',
                        'scheme' => 'pgsql',
                        'type' => 'postgresql:9.6',
                        'query' => ['is_master' => true],
                    ],
                ],
            ],
            $routes,
            [
                'LEGACY_PGSQL_URL' => 'postgres://main:main@database.internal:5432/main?sslmode=disable&charset=utf8&serverVersion=9.6',
                'LEGACY_PGSQL_USER' => 'main',
                'LEGACY_PGSQL_USERNAME' => 'main',
                'LEGACY_PGSQL_PASSWORD' => 'main',
                'LEGACY_PGSQL_HOST' => 'database.internal',
                'LEGACY_PGSQL_PORT' => '5432',
                'LEGACY_PGSQL_NAME' => 'main',
                'LEGACY_PGSQL_DATABASE' => 'main',
                'LEGACY_PGSQL_DRIVER' => 'postgres',
                'LEGACY_PGSQL_SERVER' => 'postgres://database.internal:5432',
                'HTTPCACHE_VARNISH_INVALIDATE_TOKEN' => 'project_entropy',
            ],
            $serverValues,
        ];

        yield 'mysql with version 10.0' => [
            [
                'app_db' => [
                    [
                        'username' => 'main',
                        'password' => '6e602888576703030f53c154051bd778',
                        'host' => 'database.internal',
                        'port' => 3306,
                        'path' => 'main',
                        'scheme' => 'mysql',
                        'type' => 'mysql:10.0',
                        'query' => ['is_master' => true],
                    ],
                ],
            ],
            $routes,
            [
                'APP_DB_URL' => 'mysql://main:6e602888576703030f53c154051bd778@database.internal:3306/main?sslmode=disable&charset=utf8mb4&serverVersion=10.0.0-MariaDB',
                'APP_DB_USER' => 'main',
                'APP_DB_USERNAME' => 'main',
                'APP_DB_PASSWORD' => '6e602888576703030f53c154051bd778',
                'APP_DB_HOST' => 'database.internal',
                'APP_DB_PORT' => '3306',
                'APP_DB_NAME' => 'main',
                'APP_DB_DATABASE' => 'main',
                'APP_DB_DRIVER' => 'mysql',
                'APP_DB_SERVER' => 'mysql://database.internal:3306',
                'HTTPCACHE_VARNISH_INVALIDATE_TOKEN' => 'project_entropy',
            ],
            $serverValues,
        ];

        yield 'postgresql with version 10' => [
            [
                'analytics_db' => [
                    [
                        'username' => 'main',
                        'password' => 'main',
                        'host' => 'database.internal',
                        'port' => 5432,
                        'path' => 'main',
                        'scheme' => 'pgsql',
                        'type' => 'postgresql:10',
                        'query' => ['is_master' => true],
                    ],
                ],
            ],
            $routes,
            [
                'ANALYTICS_DB_URL' => 'postgres://main:main@database.internal:5432/main?sslmode=disable&charset=utf8&serverVersion=10',
                'ANALYTICS_DB_USER' => 'main',
                'ANALYTICS_DB_USERNAME' => 'main',
                'ANALYTICS_DB_PASSWORD' => 'main',
                'ANALYTICS_DB_HOST' => 'database.internal',
                'ANALYTICS_DB_PORT' => '5432',
                'ANALYTICS_DB_NAME' => 'main',
                'ANALYTICS_DB_DATABASE' => 'main',
                'ANALYTICS_DB_DRIVER' => 'postgres',
                'ANALYTICS_DB_SERVER' => 'postgres://database.internal:5432',
                'HTTPCACHE_VARNISH_INVALIDATE_TOKEN' => 'project_entropy',
            ],
            $serverValues,
        ];

        yield 'mysql without credentials version 10.1' => [
            [
                'cache_db' => [
                    [
                        'host' => 'database.internal',
                        'port' => 3306,
                        'scheme' => 'mysql',
                        'type' => 'mysql:10.1',
                        'query' => [],
                    ],
                ],
            ],
            $routes,
            [
                'CACHE_DB_URL' => 'mysql://database.internal:3306/main?sslmode=disable&charset=utf8mb4&serverVersion=10.1.0-MariaDB',
                'CACHE_DB_HOST' => 'database.internal',
                'CACHE_DB_PORT' => '3306',
                'CACHE_DB_NAME' => 'main',
                'CACHE_DB_DATABASE' => 'main',
                'CACHE_DB_DRIVER' => 'mysql',
                'CACHE_DB_SERVER' => 'mysql://database.internal:3306',
                'HTTPCACHE_VARNISH_INVALIDATE_TOKEN' => 'project_entropy',
            ],
            $serverValues,
        ];

        yield 'mysql version 10.2 with special minor version' => [
            [
                'cms_database' => [
                    [
                        'host' => 'database.internal',
                        'port' => 3306,
                        'scheme' => 'mysql',
                        'type' => 'mysql:10.2',
                        'query' => [],
                    ],
                ],
            ],
            $routes,
            [
                'CMS_DATABASE_URL' => 'mysql://database.internal:3306/main?sslmode=disable&charset=utf8mb4&serverVersion=10.2.7-MariaDB',
                'CMS_DATABASE_HOST' => 'database.internal',
                'CMS_DATABASE_PORT' => '3306',
                'CMS_DATABASE_NAME' => 'main',
                'CMS_DATABASE_DATABASE' => 'main',
                'CMS_DATABASE_DRIVER' => 'mysql',
                'CMS_DATABASE_SERVER' => 'mysql://database.internal:3306',
                'HTTPCACHE_VARNISH_INVALIDATE_TOKEN' => 'project_entropy',
            ],
            $serverValues,
        ];

        yield 'two databases with indexed naming' => [
            [
                'main_mysql' => [
                    [
                        'username' => 'user',
                        'password' => 'pass',
                        'host' => 'database.internal',
                        'port' => 3306,
                        'scheme' => 'mysql',
                        'type' => 'mysql:10.6',
                        'query' => ['is_master' => true],
                    ],
                    [
                        'username' => 'replica_user',
                        'password' => 'replica_pass',
                        'host' => 'database-replica.internal',
                        'port' => 3306,
                        'scheme' => 'mysql',
                        'type' => 'mysql:10.6',
                        'query' => ['is_master' => false],
                    ],
                ],
            ],
            $routes,
            [
                'MAIN_MYSQL_URL' => 'mysql://user:pass@database.internal:3306/main?sslmode=disable&charset=utf8mb4&serverVersion=10.6.0-MariaDB',
                'MAIN_MYSQL_USER' => 'user',
                'MAIN_MYSQL_USERNAME' => 'user',
                'MAIN_MYSQL_PASSWORD' => 'pass',
                'MAIN_MYSQL_HOST' => 'database.internal',
                'MAIN_MYSQL_PORT' => '3306',
                'MAIN_MYSQL_NAME' => 'main',
                'MAIN_MYSQL_DATABASE' => 'main',
                'MAIN_MYSQL_DRIVER' => 'mysql',
                'MAIN_MYSQL_SERVER' => 'mysql://database.internal:3306',
                'MAIN_MYSQL_1_URL' => 'mysql://replica_user:replica_pass@database-replica.internal:3306/main?sslmode=disable&charset=utf8mb4&serverVersion=10.6.0-MariaDB',
                'MAIN_MYSQL_1_USER' => 'replica_user',
                'MAIN_MYSQL_1_USERNAME' => 'replica_user',
                'MAIN_MYSQL_1_PASSWORD' => 'replica_pass',
                'MAIN_MYSQL_1_HOST' => 'database-replica.internal',
                'MAIN_MYSQL_1_PORT' => '3306',
                'MAIN_MYSQL_1_NAME' => 'main',
                'MAIN_MYSQL_1_DATABASE' => 'main',
                'MAIN_MYSQL_1_DRIVER' => 'mysql',
                'MAIN_MYSQL_1_SERVER' => 'mysql://database-replica.internal:3306',
                'HTTPCACHE_VARNISH_INVALIDATE_TOKEN' => 'project_entropy',
            ],
            $serverValues,
        ];
    }

    /**
     * @return array<
     *     string,
     *     array{
     *         id: null,
     *         original_url: string,
     *         primary: bool,
     *         production_url: string,
     *         type: string,
     *         to?: string,
     *         attributes?: array<string, mixed>,
     *         upstream?: string
     *     }
     * >
     */
    private function createRoutes(): array
    {
        return [
            'http://app.example.com/' => [
                'id' => null,
                'original_url' => 'http://some_app.example.com',
                'primary' => false,
                'production_url' => 'http://some_app.example.com/',
                'to' => 'https://app.example.com/',
                'type' => 'redirect',
            ],
            'http://www.app.example.com/' => [
                'id' => null,
                'original_url' => 'http://www.{default}/',
                'primary' => false,
                'production_url' => 'http://www.some_app.example.com/',
                'to' => 'https://www.app.example.com/',
                'type' => 'redirect',
            ],
            'https://app.example.com/' => [
                'attributes' => [],
                'id' => null,
                'original_url' => 'https://some_app.example.com',
                'primary' => true,
                'production_url' => 'https://some_app.example.com/',
                'type' => 'upstream',
                'upstream' => 'app',
            ],
            'https://www.app.example.com/' => [
                'attributes' => [],
                'id' => null,
                'original_url' => 'https://www.{default}/',
                'primary' => false,
                'production_url' => 'https://www.some_app.example.com/',
                'to' => 'https://app.example.com/',
                'type' => 'redirect',
            ],
        ];
    }
}
