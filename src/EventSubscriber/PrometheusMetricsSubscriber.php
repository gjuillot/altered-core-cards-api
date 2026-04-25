<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Prometheus\CollectorRegistry;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class PrometheusMetricsSubscriber implements EventSubscriberInterface
{
    private float $startTime;

    public function __construct(private readonly CollectorRegistry $registry) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST   => ['onRequest', 256],
            KernelEvents::TERMINATE => ['onTerminate', 0],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->startTime = microtime(true);
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $request  = $event->getRequest();
        $response = $event->getResponse();

        $route      = $request->attributes->getString('_route') ?: 'unknown';
        $method     = $request->getMethod();
        $statusCode = (string) $response->getStatusCode();
        $duration   = microtime(true) - ($this->startTime ?? microtime(true));

        $labels      = ['route', 'method', 'status'];
        $labelValues = [$route, $method, $statusCode];

        $this->registry
            ->getOrRegisterCounter('altered_core', 'http_requests_total', 'Total HTTP requests', $labels)
            ->inc($labelValues);

        $this->registry
            ->getOrRegisterHistogram(
                'altered_core',
                'http_request_duration_seconds',
                'HTTP request duration in seconds',
                $labels,
                [0.01, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0],
            )
            ->observe($duration, $labelValues);
    }
}
