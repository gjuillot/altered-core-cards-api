<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\DataFixtures\AppFixtures;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Loader;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CardFilterTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->setServerParameter('HTTP_ACCEPT', 'application/json');

        $em = static::getContainer()->get('doctrine')->getManager();
        $loader = new Loader();
        $loader->addFixture(new AppFixtures());
        $executor = new ORMExecutor($em, new ORMPurger($em));
        $executor->execute($loader->getFixtures());
    }

    // --- helpers ---

    private function getCollection(array $params): array
    {
        $this->client->request('GET', '/api/cards', $params);
        return json_decode($this->client->getResponse()->getContent(), true);
    }

    private function assertTotalItems(int $expected, array $params): void
    {
        $data = $this->getCollection($params);
        $this->assertSame($expected, $data['totalItems'], sprintf(
            'Expected %d result(s) for filter %s, got %d',
            $expected,
            http_build_query($params),
            $data['totalItems'],
        ));
    }

    // --- faction.code (CardGroupAliasFilter) ---

    public function testFilterByFactionCodeReturnsMatch(): void
    {
        $this->assertTotalItems(1, ['faction.code' => 'AX']);
    }

    public function testFilterByFactionCodeUnknownReturnsEmpty(): void
    {
        $this->assertTotalItems(0, ['faction.code' => 'MU']);
    }

    // --- set.reference (SearchFilter) ---

    public function testFilterBySetReferenceReturnsMatch(): void
    {
        $this->assertTotalItems(1, ['set.reference' => 'COREKS']);
    }

    public function testFilterBySetReferenceUnknownReturnsEmpty(): void
    {
        $this->assertTotalItems(0, ['set.reference' => 'UNKNOWN']);
    }

    // --- rarity (ReferenceFilter) ---

    public function testFilterByRarityReturnsMatch(): void
    {
        $this->assertTotalItems(1, ['rarity' => 'COMMON']);
    }

    public function testFilterByRarityUnknownReturnsEmpty(): void
    {
        $this->assertTotalItems(0, ['rarity' => 'RARE']);
    }

    // --- cardType (CardGroupAliasFilter REF_MAP) ---

    public function testFilterByCardTypeReturnsMatch(): void
    {
        $this->assertTotalItems(1, ['cardType' => 'HERO']);
    }

    public function testFilterByCardTypeUnknownReturnsEmpty(): void
    {
        $this->assertTotalItems(0, ['cardType' => 'SPELL']);
    }

    // --- kickstarter / promo (SearchFilter boolean) ---

    public function testFilterByKickstarterTrueReturnsMatch(): void
    {
        $this->assertTotalItems(1, ['kickstarter' => '1']);
    }

    public function testFilterByPromoTrueReturnsEmpty(): void
    {
        $this->assertTotalItems(0, ['promo' => '1']);
    }

    // --- mainCost range (CardGroupAliasFilter) ---

    public function testFilterByMainCostGteMatchesExactValue(): void
    {
        $this->assertTotalItems(1, ['mainCost' => ['gte' => '3']]);
    }

    public function testFilterByMainCostGteAboveFixtureReturnsEmpty(): void
    {
        $this->assertTotalItems(0, ['mainCost' => ['gte' => '4']]);
    }

    public function testFilterByMainCostLteMatchesExactValue(): void
    {
        $this->assertTotalItems(1, ['mainCost' => ['lte' => '3']]);
    }

    public function testFilterByMainCostLteBelowFixtureReturnsEmpty(): void
    {
        $this->assertTotalItems(0, ['mainCost' => ['lte' => '2']]);
    }

    // --- reference exact (SearchFilter) ---

    public function testFilterByReferenceExactReturnsMatch(): void
    {
        $this->assertTotalItems(1, ['reference' => AppFixtures::CARD_REFERENCE]);
    }

    public function testFilterByReferenceUnknownReturnsEmpty(): void
    {
        $this->assertTotalItems(0, ['reference' => 'UNKNOWN_REF']);
    }
}
