<?php

namespace Tests\Unit;

use App\Modules\Finance\BankTransferQr;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BankTransferQrTest extends TestCase
{
    public function test_matches_published_vietqr_generate_example_including_checksum(): void
    {
        // https://www.vietqr.io/generate (public interoperability test vector).
        $this->assertSame('00020101021238560010A0000007270126000697041501121133666688880208QRIBFTTA53037045405790005802VN62220818Ung Ho Quy Vac Xin63043ACF',
            (new BankTransferQr)->payload('970415', '113366668888', 79000, 'Ung Ho Quy Vac Xin'));
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_payment_fields_are_rejected(string $bin, string $account, int $amount, string $content): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new BankTransferQr)->payload($bin, $account, $amount, $content);
    }

    public static function invalidFields(): array
    {
        return [
            ['97041', '123456789012', 79000, 'AS ABCD1234'],
            ['970415', '12345', 79000, 'AS ABCD1234'],
            ['970415', '123456789012', 0, 'AS ABCD1234'],
            ['970415', '123456789012', -1, 'AS ABCD1234'],
            ['970415', '123456789012', 10000000000000, 'AS ABCD1234'],
            ['970415', '123456789012', 79000, ''],
            ['970415', '123456789012', 79000, str_repeat('A', 26)],
            ['970415', '123456789012', 79000, "AS ABCD\n1234"],
        ];
    }

    public function test_mock_and_legacy_snapshots_do_not_show_a_live_qr(): void
    {
        $qr = new BankTransferQr;
        $bank = ['bin' => '970415', 'account_number' => '123456789012', 'content' => 'AS ABCD1234'];
        $this->assertNull($qr->svg($bank, 79000, 'mock'));
        $this->assertNull($qr->svg(['account_number' => '123456789012'], 79000, 'live'));
        $this->assertNull($qr->svg($bank, 0, 'live'));
        $this->assertStringContainsString('<svg', $qr->svg($bank, 79000, 'live'));
    }
}
