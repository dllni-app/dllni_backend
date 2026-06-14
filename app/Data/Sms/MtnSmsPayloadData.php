<?php

declare(strict_types=1);

namespace App\Data\Sms;

final readonly class MtnSmsPayloadData
{
    /**
     * @param  array<int, string>  $gsm
     */
    public function __construct(
        public array $gsm,
        public string $message,
        public int $lang,
        public ?int $smsMessageId = null,
    ) {}

    public function gsmString(): string
    {
        return implode(';', $this->gsm);
    }
}
