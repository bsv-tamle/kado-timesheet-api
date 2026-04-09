<?php

namespace App\Support;

use App\Contracts\PasswordResetMailer;
use App\Mail\PasswordResetLinkMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class LaravelPasswordResetMailer implements PasswordResetMailer
{
    public function sendResetLink(User $user, string $token): void
    {
        $baseUrl = (string) config('auth.reset_password_url', 'http://localhost:5173/reset-password');
        $separator = str_contains($baseUrl, '?') ? '&' : '?';
        $resetUrl = $baseUrl.$separator.http_build_query([
            'email' => $user->email,
            'token' => $token,
        ]);

        Mail::to($user->email)->send(new PasswordResetLinkMail($user, $resetUrl));
    }
}

