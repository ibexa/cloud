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
        $relationships = [
            'database' => [
                $this->createDatabase(),
            ],
            'rediscache' => [
                $this->createRedisCache(),
            ],
        ];
        $routes = $this->createRoutes();

        $expected = [
            'CACHE_POOL' => 'cache.redis',
            'CACHE_DSN' => 'rediscache.internal:6379?retry_interval=3',
            'SESSION_HANDLER_ID' => NativeSessionHandler::class,
            'SESSION_SAVE_PATH' => 'rediscache.internal:6379',
            'SEARCH_ENGINE' => 'elasticsearch',
            'ELASTICSEARCH_DSN' => 'http://elasticsearch.internal:9200',
            'HTTPCACHE_VARNISH_INVALIDATE_TOKEN' => 'project_entropy',
        ];

        $serverValues = [
            'PLATFORM_PROJECT_ENTROPY' => 'project_entropy',
        ];

        yield 'redis cache with session fallback and elasticsearch' => [
            $relationships + ['elasticsearch' => [
                $this->createElasticSearch(),
            ]],
            $routes,
            $expected,
            $serverValues,
        ];

        $expected = [
            'CACHE_POOL' => 'cache.redis',
            'CACHE_DSN' => 'rediscache.internal:6379?retry_interval=3',
            'SESSION_HANDLER_ID' => NativeSessionHandler::class,
            'SESSION_SAVE_PATH' => 'rediscache.internal:6379',
            'SEARCH_ENGINE' => 'solr',
            'SOLR_DSN' => 'http://solr.internal:8080/solr',
            'SOLR_CORE' => 'collection1',
            'HTTPCACHE_VARNISH_INVALIDATE_TOKEN' => 'project_entropy',
        ];

        yield 'redis cache with session fallback and solr' => [
            $relationships + ['solr' => [
                $this->createSolr(),
            ]],
            $routes,
            $expected,
            $serverValues,
        ];

        $expected = [
            'DFS_NFS_PATH' => '/mnt/dfs/nfs',
            'DFS_DATABASE_CHARSET' => 'utf8mb4',
            'DFS_DATABASE_COLLATION' => 'utf8mb4_unicode_520_ci',
            'DFS_DATABASE_DRIVER' => 'pdo_mysql',
            'DFS_DATABASE_URL' => 'mysql://dfs:dfs@localhost:3306/dfs',
            'HTTPCACHE_VARNISH_INVALIDATE_TOKEN' => 'project_entropy',
        ];

        yield 'dfs' => [
            ['dfs_database' => [$this->createDfs()]],
            $routes,
            $expected,
            $serverValues + ['PLATFORMSH_DFS_NFS_PATH' => '/mnt/dfs/nfs'],
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

    /**
     * @return array{
     *     host: string,
     *     hostname: string,
     *     cluster: string,
     *     service: string,
     *     rel: string,
     *     scheme: string,
     *     username: string,
     *     password: string,
     *     port: int,
     *     epoch: int,
     *     path: string,
     *     query: array<string, bool>,
     *     fragment: null,
     *     public: bool,
     *     host_mapped: bool,
     *     type: string,
     *     instance_ips: array<int, string>,
     *     ip: string
     * }
     */
    private function createDatabase(): array
    {
        return [
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
        ];
    }

    /**
     * @return array{
     *     host: string,
     *     hostname: string,
     *     cluster: string,
     *     service: string,
     *     rel: string,
     *     scheme: string,
     *     username: null,
     *     password: null,
     *     port: int,
     *     epoch: int,
     *     path: null,
     *     query: array<int, mixed>,
     *     fragment: null,
     *     public: bool,
     *     host_mapped: bool,
     *     type: string,
     *     instance_ips: array<int, string>,
     *     ip: string
     * }
     */
    private function createRedisCache(): array
    {
        return [
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
        ];
    }

    /**
     * @return array{
     *     username: null,
     *     scheme: string,
     *     service: string,
     *     fragment: null,
     *     ip: string,
     *     hostname: string,
     *     port: int,
     *     cluster: string,
     *     host: string,
     *     rel: string,
     *     path: null,
     *     query: array<int, mixed>,
     *     password: string,
     *     type: string,
     *     public: bool,
     *     host_mapped: bool
     * }
     */
    private function createElasticSearch(): array
    {
        return [
            'username' => null,
            'scheme' => 'http',
            'service' => 'elasticsearch',
            'fragment' => null,
            'ip' => '123.456.78.90',
            'hostname' => 'azertyuiopqsdfghjklm.elasticsearch.service._.eu-1.platformsh.site',
            'port' => 9200,
            'cluster' => 'azertyuiopqsdf-main-7rqtwti',
            'host' => 'elasticsearch.internal',
            'rel' => 'elasticsearch',
            'path' => null,
            'query' => [],
            'password' => 'ChangeMe',
            'type' => 'elasticsearch:8.5',
            'public' => false,
            'host_mapped' => false,
        ];
    }

    /**
     * @return array{
     *     username: null,
     *     scheme: string,
     *     service: string,
     *     fragment: null,
     *     ip: string,
     *     hostname: string,
     *     port: int,
     *     cluster: string,
     *     host: string,
     *     rel: string,
     *     path: string,
     *     query: array<int, mixed>,
     *     password: null,
     *     type: string,
     *     public: bool,
     *     host_mapped: bool
     * }
     */
    private function createSolr(): array
    {
        return [
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
        ];
    }

    /**
     * @return array{
     *     host: string,
     *     scheme: string,
     *     username: string,
     *     password: string,
     *     port: int,
     *     path: string,
     *     query: array{is_master: bool}
     * }
     */
    private function createDfs(): array
    {
        $parts = parse_url('mysql://dfs:dfs@localhost:3306/dfs');

        return [
            'host' => $parts['host'],
            'scheme' => $parts['scheme'],
            'username' => $parts['user'],
            'password' => $parts['pass'],
            'port' => $parts['port'],
            'path' => ltrim($parts['path'], '/'),
            'query' => [
                'is_master' => true,
            ],
        ];
    }
}
