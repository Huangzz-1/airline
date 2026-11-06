<?php

namespace App\Modules\Finance;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use InvalidArgumentException;

class BankTransferQr
{
    // NAPAS account transfer (QRIBFTTA). Values are encoded as EMV TLV bytes.
    public function payload(string $bin, string $account, int $amount, string $content): string
    {
        if (! preg_match('/^\d{6}$/D', $bin) || ! preg_match('/^\d{6,19}$/D', $account)
            || $amount < 1 || $amount > 9999999999999 || ! preg_match('/^[A-Za-z0-9 ]{1,25}$/D', $content)) {
            throw new InvalidArgumentException('Invalid VietQR account, amount or transfer content.');
        }
        $receiver = $this->field('00', $bin).$this->field('01', $account);
        $merchant = $this->field('00', 'A000000727').$this->field('01', $receiver).$this->field('02', 'QRIBFTTA');
        $payload = $this->field('00', '01').$this->field('01', '12').$this->field('38', $merchant)
            .$this->field('53', '704').$this->field('54', (string) $amount).$this->field('58', 'VN')
            .$this->field('62', $this->field('08', $content)).'6304';

        // CRC16-CCITT-FALSE, including the CRC tag and its length.
        $crc = 0xFFFF;
        foreach (str_split($payload) as $byte) {
            $crc ^= ord($byte) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = (($crc & 0x8000) ? ($crc << 1) ^ 0x1021 : $crc << 1) & 0xFFFF;
            }
        }

        return $payload.strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    public function svg(array $bank, int $amount, string $mode): ?string
    {
        if ($mode !== 'live' || empty($bank['bin'])) {
            return null;
        }
        try {
            $payload = $this->payload((string) $bank['bin'], (string) ($bank['account_number'] ?? ''), $amount, (string) ($bank['content'] ?? ''));
        } catch (InvalidArgumentException) {
            return null;
        }

        return (new Writer(new ImageRenderer(new RendererStyle(280), new SvgImageBackEnd)))->writeString($payload);
    }

    private function field(string $id, string $value): string
    {
        return $id.str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;
    }
}
