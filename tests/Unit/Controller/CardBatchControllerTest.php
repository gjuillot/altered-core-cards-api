<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\CardBatchController;
use App\Repository\CardRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\SerializerInterface;

final class CardBatchControllerTest extends TestCase
{
    private CardBatchController $controller;

    protected function setUp(): void
    {
        $this->controller = new CardBatchController(
            $this->createMock(CardRepository::class),
            $this->createMock(SerializerInterface::class),
        );
    }

    public function testEmptyBodyReturnsBadRequest(): void
    {
        $request  = Request::create('/api/cards/batch', 'POST', content: '{}');
        $response = ($this->controller)($request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('references', (string) $response->getContent());
    }

    public function testEmptyReferencesArrayReturnsBadRequest(): void
    {
        $request  = Request::create('/api/cards/batch', 'POST', content: '{"references":[]}');
        $response = ($this->controller)($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testMoreThan200ReferencesReturnsBadRequest(): void
    {
        $references = array_fill(0, 201, 'ALT_CORE_B_AX_1_C');
        $request    = Request::create(
            '/api/cards/batch',
            'POST',
            content: json_encode(['references' => $references]),
        );

        $response = ($this->controller)($request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('200', (string) $response->getContent());
    }
}
