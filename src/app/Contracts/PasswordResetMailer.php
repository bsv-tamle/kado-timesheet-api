<?php

namespace App\Contracts;

use App\Models\User;

interface PasswordResetMailer
{
    public function sendResetLink(User $user, string $token): void;
}

