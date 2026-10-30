<?php

namespace App\Models;

use App\Modules\Customer\EmailVerification;
use App\Notifications\ResetPassword;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    public function sendEmailVerificationNotification(): void
    {
        app(EmailVerification::class)->issue($this);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPassword($token));
    }

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $attributes = ['role' => 'passenger'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'disabled_at' => 'datetime',
            'must_change_password' => 'boolean',
        ];
    }
}
