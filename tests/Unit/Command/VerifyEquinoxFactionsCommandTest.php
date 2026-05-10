<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\VerifyEquinoxFactionsCommand;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class VerifyEquinoxFactionsCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/verify_factions_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function rmdirRecursive(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $entry) {
            is_dir($entry) ? $this->rmdirRecursive($entry) : unlink($entry);
        }
        rmdir($dir);
    }

    private function writeJson(string $set, string $faction, string $filename, array $data): void
    {
        $dir = $this->tmpDir . '/' . $set . '/' . $faction;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/' . $filename, json_encode($data));
    }

    private function cardJson(string $reference, string $mainFaction): array
    {
        return [
            'id'          => 'ALTERID_' . $reference,
            'reference'   => $reference,
            'mainFaction' => ['reference' => $mainFaction],
        ];
    }

    private function stubConnection(array $rows): Connection
    {
        $conn = $this->createStub(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn($rows);
        return $conn;
    }

    private function execute(Connection $connection, array $args = []): CommandTester
    {
        $tester = new CommandTester(new VerifyEquinoxFactionsCommand($connection));
        $tester->execute(array_merge(['directory' => $this->tmpDir], $args));
        return $tester;
    }

    // ── tests ────────────────────────────────────────────────────────────────

    public function testMissingDirectoryReturnsFailure(): void
    {
        $tester = new CommandTester(new VerifyEquinoxFactionsCommand($this->createStub(Connection::class)));
        $tester->execute(['directory' => '/nonexistent/path/xyz']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function testEmptyDirectoryReturnsSuccess(): void
    {
        $tester = $this->execute($this->createStub(Connection::class));

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testAllCardsMatchReturnsSuccess(): void
    {
        $this->writeJson('CORE', 'AX', 'ALT_CORE_B_AX_01_C.json', $this->cardJson('ALT_CORE_B_AX_01_C', 'AX'));

        $tester = $this->execute($this->stubConnection([
            ['reference' => 'ALT_CORE_B_AX_01_C', 'faction_code' => 'AX', 'transfuge' => false],
        ]));

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('All cards match', $tester->getDisplay());
    }

    public function testFactionMismatchReturnsFailure(): void
    {
        // DB has AX but Equinox JSON says LY (stale import data)
        $this->writeJson('COREKS', 'LY', 'ALT_COREKS_B_LY_04_U_2769.json', $this->cardJson('ALT_COREKS_B_LY_04_U_2769', 'LY'));

        $tester = $this->execute($this->stubConnection([
            ['reference' => 'ALT_COREKS_B_LY_04_U_2769', 'faction_code' => 'AX', 'transfuge' => false],
        ]));

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('faction DB=AX JSON=LY', $tester->getDisplay());
    }

    public function testTransfugeWronglyTrueReturnsFailure(): void
    {
        // AX reference + AX mainFaction → expected transfuge=false, DB has true
        $this->writeJson('CORE', 'AX', 'ALT_CORE_B_AX_01_C.json', $this->cardJson('ALT_CORE_B_AX_01_C', 'AX'));

        $tester = $this->execute($this->stubConnection([
            ['reference' => 'ALT_CORE_B_AX_01_C', 'faction_code' => 'AX', 'transfuge' => true],
        ]));

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('transfuge DB=true expected=false', $tester->getDisplay());
    }

    public function testTransfugeWronglyFalseReturnsFailure(): void
    {
        // AX reference + MU mainFaction → genuine transfuge, DB has false
        $this->writeJson('CORE', 'AX', 'ALT_CORE_B_AX_06_U_1002.json', $this->cardJson('ALT_CORE_B_AX_06_U_1002', 'MU'));

        $tester = $this->execute($this->stubConnection([
            ['reference' => 'ALT_CORE_B_AX_06_U_1002', 'faction_code' => 'MU', 'transfuge' => false],
        ]));

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('transfuge DB=false expected=true', $tester->getDisplay());
    }

    public function testGenuineTransfugeIsReportedOk(): void
    {
        // Ogun: AX reference, MU mainFaction, DB faction=MU, transfuge=true → all correct
        $this->writeJson('CORE', 'AX', 'ALT_CORE_B_AX_06_U_1002.json', $this->cardJson('ALT_CORE_B_AX_06_U_1002', 'MU'));

        $tester = $this->execute($this->stubConnection([
            ['reference' => 'ALT_CORE_B_AX_06_U_1002', 'faction_code' => 'MU', 'transfuge' => true],
        ]));

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testCardMissingFromDbIsReported(): void
    {
        $this->writeJson('CORE', 'AX', 'ALT_CORE_B_AX_01_C.json', $this->cardJson('ALT_CORE_B_AX_01_C', 'AX'));

        $tester = $this->execute($this->stubConnection([]));

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('missing', $tester->getDisplay());
    }

    public function testBothFactionAndTransfugeMismatchAreReported(): void
    {
        // DB has AX+transfuge=true, but JSON says LY+LY (expected faction=LY, transfuge=false)
        $this->writeJson('COREKS', 'LY', 'ALT_COREKS_B_LY_04_U_2769.json', $this->cardJson('ALT_COREKS_B_LY_04_U_2769', 'LY'));

        $tester = $this->execute($this->stubConnection([
            ['reference' => 'ALT_COREKS_B_LY_04_U_2769', 'faction_code' => 'AX', 'transfuge' => true],
        ]));

        $display = $tester->getDisplay();
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('faction DB=AX JSON=LY', $display);
        $this->assertStringContainsString('transfuge DB=true expected=false', $display);
    }

    public function testShowOkDisplaysMatchingCards(): void
    {
        $this->writeJson('CORE', 'AX', 'ALT_CORE_B_AX_01_C.json', $this->cardJson('ALT_CORE_B_AX_01_C', 'AX'));

        $tester = $this->execute(
            $this->stubConnection([
                ['reference' => 'ALT_CORE_B_AX_01_C', 'faction_code' => 'AX', 'transfuge' => false],
            ]),
            ['--show-ok' => true],
        );

        $display = $tester->getDisplay();
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('ALT_CORE_B_AX_01_C', $display);
        $this->assertStringContainsString('faction=AX', $display);
    }

    public function testSetFilterExcludesOtherSets(): void
    {
        $this->writeJson('CORE', 'AX', 'ALT_CORE_B_AX_01_C.json', $this->cardJson('ALT_CORE_B_AX_01_C', 'AX'));
        $this->writeJson('BISE', 'AX', 'ALT_BISE_B_AX_01_C.json', $this->cardJson('ALT_BISE_B_AX_01_C', 'AX'));

        // Only CORE is queried — BISE card absent from DB result does not cause a "missing" error
        $tester = $this->execute(
            $this->stubConnection([
                ['reference' => 'ALT_CORE_B_AX_01_C', 'faction_code' => 'AX', 'transfuge' => false],
            ]),
            ['--set' => 'CORE'],
        );

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testRarityFilterExcludesOtherRarities(): void
    {
        $this->writeJson('CORE', 'AX', 'ALT_CORE_B_AX_01_C.json', $this->cardJson('ALT_CORE_B_AX_01_C', 'AX'));
        $this->writeJson('CORE', 'AX', 'ALT_CORE_B_AX_01_R1.json', $this->cardJson('ALT_CORE_B_AX_01_R1', 'AX'));

        // Only COMMON processed — R1 absent from DB result does not cause a "missing" error
        $tester = $this->execute(
            $this->stubConnection([
                ['reference' => 'ALT_CORE_B_AX_01_C', 'faction_code' => 'AX', 'transfuge' => false],
            ]),
            ['--rarity' => 'COMMON'],
        );

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }
}
