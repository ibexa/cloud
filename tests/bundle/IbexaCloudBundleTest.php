<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Tests\Bundle\Cloud;

use Ibexa\Bundle\Cloud\IbexaCloudBundle;
use Ibexa\Bundle\Core\Session\Handler\NativeSessionHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @covers \Ibexa\Bundle\Cloud\IbexaCloudBundle
 */
final class IbexaCloudBundleTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalServer;

    /** @var array<string, mixed> */
    private array $originalEnv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalServer = $_SERVER;
        $this->originalEnv = $_ENV;

        $_SERVER['PLATFORM_RELATIONSHIPS'] = $this->encode(
            [
                'rediscache' => [
                    [
                        'scheme' => 'redis',
                        'host' => 'rediscache.internal',
                        'port' => 6379,
                    ],
                ],
            ]
        );
        $_SERVER['PLATFORM_ROUTES'] = $this->encode([]);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        $_ENV = $this->originalEnv;

        parent::tearDown();
    }

    public function testBuildPopulatesSessionEnvVars(): void
    {
        $bundle = new IbexaCloudBundle();
        $bundle->build(self::createStub(ContainerBuilder::class));

        self::assertSame(NativeSessionHandler::class, $_SERVER['SESSION_HANDLER_ID']);
        self::assertSame('rediscache.internal:6379', $_SERVER['SESSION_SAVE_PATH']);
    }

    public function testBuildOverridesSessionEnvVarsComingFromDotEnv(): void
    {
        $_SERVER['SESSION_HANDLER_ID'] = 'session.handler.native_file';
        $_SERVER['SESSION_SAVE_PATH'] = '/app/var/sessions/prod';

        $bundle = new IbexaCloudBundle();
        $bundle->build(self::createStub(ContainerBuilder::class));

        self::assertSame(NativeSessionHandler::class, $_SERVER['SESSION_HANDLER_ID']);
        self::assertSame('rediscache.internal:6379', $_SERVER['SESSION_SAVE_PATH']);
    }

    public function testBuildLeavesEnvVarsAloneOutsideUpsun(): void
    {
        unset($_SERVER['PLATFORM_RELATIONSHIPS'], $_SERVER['PLATFORM_ROUTES']);
        $_SERVER['SESSION_HANDLER_ID'] = 'session.handler.native_file';

        $bundle = new IbexaCloudBundle();
        $bundle->build(self::createStub(ContainerBuilder::class));

        self::assertSame('session.handler.native_file', $_SERVER['SESSION_HANDLER_ID']);
        self::assertArrayNotHasKey('SESSION_SAVE_PATH', $_SERVER);
    }

    public function testBootPopulatesSessionEnvVars(): void
    {
        $bundle = new IbexaCloudBundle();
        $bundle->boot();

        self::assertSame(NativeSessionHandler::class, $_SERVER['SESSION_HANDLER_ID']);
        self::assertSame('rediscache.internal:6379', $_SERVER['SESSION_SAVE_PATH']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        return base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
