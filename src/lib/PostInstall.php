<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Cloud;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Ibexa\Cloud\CommandProvider as SetupToolCommandProvider;

final class PostInstall implements PluginInterface, Capable
{
    public function activate(Composer $composer, IOInterface $io): void
    {
        $io->write('[Ibexa Cloud tool] Activate', true, IOInterface::DEBUG);
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        $io->write('[Ibexa Cloud tool] Deactivate', true, IOInterface::DEBUG);
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        $io->write('[Ibexa Cloud tool] Uninstall', true, IOInterface::DEBUG);
    }

    public function getCapabilities(): array
    {
        return [
            CommandProvider::class => SetupToolCommandProvider::class,
        ];
    }
}
