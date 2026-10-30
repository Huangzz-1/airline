<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;

class VerifyEmail extends \Illuminate\Auth\Notifications\VerifyEmail implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public $tries = 5;

    public function __construct(public string $code)
    {
        $this->onConnection('database')->onQueue('mail');
    }

    public function toMail($notifiable)
    {
        return parent::toMail($notifiable)->subject('Xác minh tài khoản Airline Smart')->line('Mã xác minh của bạn: '.$this->code)->line('Mã có hiệu lực trong 10 phút. Không chia sẻ mã này cho người khác.');
    }

    public function backoff(): array
    {
        return [10, 60, 180, 300];
    }
}
