<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

use Ibexa\Contracts\Test\Core\Bootstrapper\Bootstrapper;
use Ibexa\Contracts\Test\Core\Bootstrapper\PurgeIndexAfterFixturesHook;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

chdir(dirname(__DIR__, 2));

// The kernel class comes from the KERNEL_CLASS environment variable set in
// phpunit.integration.xml.
(new Bootstrapper())->bootstrap(null, [
    // The old bootstrap purged the index once the fixtures were in; this hook is the same step at
    // the same point (priority 800, after FixtureHook at 900) and is off unless asked for.
    PurgeIndexAfterFixturesHook::class => [
        PurgeIndexAfterFixturesHook::OPTION_PURGE_INDEX => true,
    ],
]);
