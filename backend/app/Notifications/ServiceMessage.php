<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ServiceMessage extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public $tries = 5;

    public function __construct(public string $title, public string $body)
    {
        $this->onConnection('database')->onQueue('mail');
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function backoff(): array
    {
        return [10, 60, 180, 300];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->title)->greeting('Xin chào '.$notifiable->name)->line($this->body)->action('Xem thông báo', url('/workspace/notifications'));
    }
}
