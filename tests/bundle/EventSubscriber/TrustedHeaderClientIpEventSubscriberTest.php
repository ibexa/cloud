<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Tests\Bundle\Cloud\EventSubscriber;

use Ibexa\Bundle\Cloud\EventSubscriber\TrustedHeaderClientIpEventSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * @covers \Ibexa\Bundle\Cloud\EventSubscriber\TrustedHeaderClientIpEventSubscriber
 */
final class TrustedHeaderClientIpEventSubscriberTest extends TestCase
{
    private const string REAL_CLIENT_IP = '98.76.123.234';

    // framework.trusted_headers default
    private const int TRUSTED_HEADER_SET = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO;

    /** @var string[] */
    private array $originalTrustedProxies;

    private int $originalTrustedHeaderSet;

    private mixed $originalRemoteAddr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalTrustedProxies = Request::getTrustedProxies();
        $this->originalTrustedHeaderSet = Request::getTrustedHeaderSet();
        $this->originalRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;

        // Request::setTrustedProxies() resolves 'REMOTE_ADDR' from $_SERVER, not from the request
        $_SERVER['REMOTE_ADDR'] = self::REAL_CLIENT_IP;
        Request::setTrustedProxies([], self::TRUSTED_HEADER_SET);
    }

    protected function tearDown(): void
    {
        $_SERVER['REMOTE_ADDR'] = $this->originalRemoteAddr;

        /** @var int<0, 63> $trustedHeaderSet */
        $trustedHeaderSet = $this->originalTrustedHeaderSet;
        Request::setTrustedProxies($this->originalTrustedProxies, $trustedHeaderSet);

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{bool, array<string, string>, array<string, mixed>}>
     */
    public static function getTrustedHeaderEventSubscriberTestData(): iterable
    {
        yield 'request from random client received outside Ibexa Cloud' => [
            false,
            [],
            [],
        ];

        yield 'request from random client, forging Client-Cdn, received outside Ibexa Cloud' => [
            false,
            ['Client-Cdn' => 'fastly'],
            [],
        ];

        yield 'request from random client received on Ibexa Cloud' => [
            false,
            [],
            ['PLATFORM_RELATIONSHIPS' => true],
        ];

        yield 'request via Fastly received on Ibexa Cloud' => [
            true,
            ['Client-Cdn' => 'fastly'],
            ['PLATFORM_RELATIONSHIPS' => true],
        ];
    }

    /**
     * @dataProvider getTrustedHeaderEventSubscriberTestData
     *
     * @param array<string, string> $headers
     * @param array<string, mixed> $server
     */
    public function testTrustedHeaderEventSubscriberWithTrustedProxy(
        bool $isFromTrustedProxy,
        array $headers,
        array $server
    ): void {
        $request = $this->dispatchRequestEvent($headers, $server);

        self::assertSame($isFromTrustedProxy, $request->isFromTrustedProxy());
        self::assertSame(self::TRUSTED_HEADER_SET, Request::getTrustedHeaderSet());
    }

    public function testTrustedHeaderEventSubscriberWithoutTrustedProxiesConfigured(): void
    {
        // framework.trusted_proxies not configured, so Symfony never sets a trusted header set
        // @phpstan-ignore argument.type
        Request::setTrustedProxies([], -1);

        $request = $this->dispatchRequestEvent(
            [
                'Client-Cdn' => 'fastly',
                'X-Forwarded-Host' => 'forged.example.com',
            ],
            ['PLATFORM_RELATIONSHIPS' => true]
        );

        self::assertFalse($request->isFromTrustedProxy());
        self::assertSame('localhost', $request->getHost());
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $server
     */
    private function dispatchRequestEvent(
        array $headers,
        array $server
    ): Request {
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addSubscriber(new TrustedHeaderClientIpEventSubscriber());

        $request = Request::create('/', Request::METHOD_GET, [], [], [], array_merge(
            $server,
            ['REMOTE_ADDR' => self::REAL_CLIENT_IP],
        ));
        $request->headers->add($headers);

        $event = $eventDispatcher->dispatch(new RequestEvent(
            $this->createMock(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST
        ), KernelEvents::REQUEST);

        return $event->getRequest();
    }
}
