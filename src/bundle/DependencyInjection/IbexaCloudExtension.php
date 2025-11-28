<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Bundle\Cloud\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Yaml\Yaml;

final class IbexaCloudExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../Resources/config')
        );

        $loader->load('services.yaml');

        if ($this->shouldLoadTestServices($container)) {
            $loader->load('test/pages.yaml');
            $loader->load('test/components.yaml');
            $loader->load('test/contexts.yaml');
        }
    }

    public function prepend(ContainerBuilder $container): void
    {
        $this->configureUpsunSetup($container);
        $this->prependDefaultConfiguration($container);
        $this->prependJMSTranslation($container);

        if (($_SERVER['HTTPCACHE_PURGE_TYPE'] ?? $_ENV['HTTPCACHE_PURGE_TYPE'] ?? null) === 'varnish') {
            $container->setParameter('ibexa.http_cache.purge_type', 'varnish');
        }

        // Adapt config based on enabled PHP extensions
        // Get imagine to use imagick if enabled, to avoid using php memory for image conversions
        // Cannot be placed as env var due to how LiipImagineBundle processes its config
        if (\extension_loaded('imagick')) {
            $container->setParameter('liip_imagine_driver', 'imagick');
        }
    }

    private function prependDefaultConfiguration(ContainerBuilder $container): void
    {
        $configFile = __DIR__ . '/../Resources/config/prepend.yaml';

        $container->addResource(new FileResource($configFile));

        $configs = Yaml::parseFile($configFile, Yaml::PARSE_CONSTANT) ?? [];
        foreach ($configs as $name => $config) {
            $container->prependExtensionConfig($name, $config);
        }
    }

    private function prependJMSTranslation(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('jms_translation', [
            'configs' => [
                'ibexa_cloud' => [
                    'dirs' => [
                        __DIR__ . '/../../',
                    ],
                    'excluded_dirs' => ['Behat'],
                    'output_dir' => __DIR__ . '/../Resources/translations/',
                    'output_format' => 'xliff',
                ],
            ],
        ]);
    }

    private function shouldLoadTestServices(ContainerBuilder $container): bool
    {
        return $container->hasParameter('ibexa.behat.browser.enabled')
            && true === $container->getParameter('ibexa.behat.browser.enabled');
    }

    private function configureUpsunSetup(ContainerBuilder $container): void
    {
        $envVars = (new UpsunEnvVarLoader())->loadEnvVars();

        if ($envVars === []) {
            return;
        }

        $projectDir = $container->getParameter('kernel.project_dir');

        // Map environment variables to container parameters
        $this->setParametersFromEnvVars($container, $envVars, $projectDir);

        // Load additional YAML configuration files based on enabled services
        $this->loadServiceConfigurations($container, $envVars, $projectDir);
    }

    /**
     * @param array<string, string> $envVars
     */
    private function setParametersFromEnvVars(
        ContainerBuilder $container,
        array $envVars,
        string $projectDir
    ): void {
        $parameterMapping = [
            'DFS_DATABASE_DRIVER' => 'dfs_database_driver',
            'DFS_DATABASE_URL' => 'dfs_database_url',
            'DFS_DATABASE_CHARSET' => 'dfs_database_charset',
            'DFS_DATABASE_COLLATION' => 'dfs_database_collation',
            'CACHE_POOL' => 'cache_pool',
            'CACHE_DSN' => 'cache_dsn',
            'SESSION_HANDLER_ID' => 'ibexa.session.handler_id',
            'SESSION_SAVE_PATH' => 'ibexa.session.save_path',
            'SEARCH_ENGINE' => 'search_engine',
            'SOLR_DSN' => 'solr_dsn',
            'SOLR_CORE' => 'solr_core',
            'ELASTICSEARCH_DSN' => 'elasticsearch_dsn',
            'HTTPCACHE_PURGE_TYPE' => ['purge_type', 'ibexa.http_cache.purge_type'],
            'HTTPCACHE_PURGE_SERVER' => 'purge_server',
            'HTTPCACHE_VARNISH_INVALIDATE_TOKEN' => 'varnish_invalidate_token',
        ];

        foreach ($envVars as $envKey => $value) {
            if (!isset($parameterMapping[$envKey])) {
                continue;
            }

            $parameters = (array) $parameterMapping[$envKey];
            foreach ($parameters as $parameterName) {
                $container->setParameter($parameterName, $value);
            }
        }

        // Handle DFS_NFS_PATH specially - convert from relative to absolute path
        if (isset($envVars['DFS_NFS_PATH'])) {
            $absolutePath = sprintf('%s/%s', $projectDir, $envVars['DFS_NFS_PATH']);
            $container->setParameter('dfs_nfs_path', $absolutePath);
        }
    }

    /**
     * @param array<string, string> $envVars
     */
    private function loadServiceConfigurations(
        ContainerBuilder $container,
        array $envVars,
        string $projectDir
    ): void {
        // Load DFS configuration if DFS is enabled
        if (isset($envVars['DFS_NFS_PATH'])) {
            $loader = new YamlFileLoader(
                $container,
                new FileLocator($projectDir . '/config/packages/dfs')
            );
            $loader->load('dfs.yaml');
        }

        // Load cache configuration based on cache type
        if (isset($envVars['CACHE_POOL'])) {
            $cacheType = $envVars['CACHE_POOL'];
            $configFile = match ($cacheType) {
                'cache.redis' => 'cache.redis.yaml',
                'cache.memcached' => 'cache.memcached.yaml',
                default => null,
            };

            if ($configFile !== null) {
                $loader = new YamlFileLoader(
                    $container,
                    new FileLocator($projectDir . '/config/packages/cache_pool')
                );
                $loader->load($configFile);
            }
        }
    }
}
