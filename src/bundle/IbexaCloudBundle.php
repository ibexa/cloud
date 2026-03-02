<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Bundle\Cloud;

use Ibexa\Bundle\Cloud\DependencyInjection\UpsunEnvVarLoader;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class IbexaCloudBundle extends Bundle
{
    public function boot(): void
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
