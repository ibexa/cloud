<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Tests\Integration\Cloud;

use Ibexa\Contracts\Test\Core\IbexaTestKernel;

final class TestKernel extends IbexaTestKernel
{
    public function getSchemaFiles(): iterable
    {
        yield from parent::getSchemaFiles();

        yield from [
            $this->locateResource('@IbexaCoreBundle/Resources/config/storage/legacy/schema.yaml'),
        ];
    }

    public function getFixtures(): iterable
    {
        yield from parent::getFixtures();
    }

    public function registerBundles(): iterable
    {
        yield from parent::registerBundles();
    }

    protected static function getExposedServicesByClass(): iterable
    {
        yield from parent::getExposedServicesByClass();
    }
}
