<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;

class ResetPassword extends \Illuminate\Auth\Notifications\ResetPassword implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public $tries = 5;

    public function __construct($token)
    {
        parent::__construct($token);
        $this->onConnection('database')->onQueue('mail');
    }

    public function backoff(): array
    {
        return [10, 60, 180, 300];
    }

    public function toMail($notifiable)
    {
        return parent::toMail($notifiable)->subject('Đặt lại mật khẩu Airline Smart');
    }
}
