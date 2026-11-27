<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Bundle\Cloud;

use Ibexa\Bundle\Cloud\DependencyInjection\UpsunEnvVarLoader;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class IbexaCloudBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // runs before the extensions load, so parameters resolved from $_SERVER at compile time see the relationships
        $this->populateEnvVars();
    }

    public function boot(): void
    {
        $this->populateEnvVars();
    }

    private function populateEnvVars(): void
    {
        $envVars = (new UpsunEnvVarLoader())->loadEnvVars();
        foreach ($envVars as $name => $value) {
            $value = (string) $value;

            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}
