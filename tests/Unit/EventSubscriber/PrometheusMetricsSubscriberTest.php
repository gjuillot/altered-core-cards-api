<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\PrometheusMetricsSubscriber;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Counter;
use Prometheus\Histogram;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class PrometheusMetricsSubscriberTest extends TestCase
{
    private CollectorRegistry&MockObject $registry;
    private PrometheusMetricsSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->registry   = $this->createMock(CollectorRegistry::class);
        $this->subscriber = new PrometheusMetricsSubscriber($this->registry);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSubscribedEvents(): void
    {
        $events = PrometheusMetricsSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
        $this->assertArrayHasKey(KernelEvents::TERMINATE, $events);
    }

    public function testMetricsRouteIsIgnored(): void
    {
        $this->registry->expects($this->never())->method('getOrRegisterCounter');
        $this->registry->expects($this->never())->method('getOrRegisterHistogram');

        $request = Request::create('/metrics/prometheus');
        $request->attributes->set('_route', 'prometheus_bundle_prometheus');

        $kernel = $this->createStub(HttpKernelInterface::class);

        $this->simulateRequest($request, $kernel);
    }

    public function testNormalRequestIsTracked(): void
    {
        $counter   = $this->createMock(Counter::class);
        $histogram = $this->createMock(Histogram::class);

        $counter->expects($this->once())->method('inc');
        $histogram->expects($this->once())->method('observe');

        $this->registry->expects($this->once())
            ->method('getOrRegisterCounter')
            ->willReturn($counter);

        $this->registry->expects($this->once())
            ->method('getOrRegisterHistogram')
            ->willReturn($histogram);

        $request = Request::create('/api/cards');
        $request->attributes->set('_route', 'api_cards_collection');

        $kernel = $this->createStub(HttpKernelInterface::class);

        $this->simulateRequest($request, $kernel);
    }

    public function testUnknownRouteIsTracked(): void
    {
        $counter   = $this->createMock(Counter::class);
        $histogram = $this->createMock(Histogram::class);

        $counter->expects($this->once())->method('inc');
        $histogram->expects($this->once())->method('observe');

        $this->registry->expects($this->once())->method('getOrRegisterCounter')->willReturn($counter);
        $this->registry->expects($this->once())->method('getOrRegisterHistogram')->willReturn($histogram);

        $request = Request::create('/.env.local');
        // No _route attribute set → resolves to 'unknown'

        $kernel = $this->createStub(HttpKernelInterface::class);

        $this->simulateRequest($request, $kernel);
    }

    private function simulateRequest(Request $request, HttpKernelInterface $kernel): void
    {
        $requestEvent = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $this->subscriber->onRequest($requestEvent);

        $response       = new Response('', 200);
        $terminateEvent = new TerminateEvent($kernel, $request, $response);
        $this->subscriber->onTerminate($terminateEvent);
    }
}
