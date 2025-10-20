<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Cloud;

use Ibexa\Cloud\Command\IbexaSetupCommand;

final class CommandProvider implements \Composer\Plugin\Capability\CommandProvider
{
    public function getCommands(): array
    {
        return [
            new IbexaSetupCommand(),
        ];
    }
}
