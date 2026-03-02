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

        if (($_SERVER['HTTPCACHE_PURGE_TYPE'] ?? $_ENV['HTTPCACHE_PURGE_TYPE'] ?? null) === 'varnish') {
            $container->setParameter('ibexa.http_cache.purge_type', 'varnish');
        }

        // Cannot be placed as env var due to how LiipImagineBundle processes its config
        if (\extension_loaded('imagick')) {
            $container->setParameter('liip_imagine_driver', 'imagick');
        }

        $projectDir = $container->getParameter('kernel.project_dir');
        assert(is_string($projectDir));

        if (isset($envVars['DFS_NFS_PATH'])) {
            $loader = new YamlFileLoader(
                $container,
                new FileLocator($projectDir . '/config/packages/dfs')
            );
            $loader->load('dfs.yaml');
        }

        if (!isset($envVars['CACHE_POOL'])) {
            return;
        }

        if ($envVars['CACHE_POOL'] === 'cache.redis') {
            $loader = new YamlFileLoader(
                $container,
                new FileLocator($projectDir . '/config/packages/cache_pool')
            );
            $loader->load('cache.redis.yaml');
        }
    }
}
