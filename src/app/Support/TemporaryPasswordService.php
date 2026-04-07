<?php

namespace App\Support;

class TemporaryPasswordService
{
    public function generate(int $length = 14): string
    {
        $defaultPassword = trim((string) config('auth.default_temp_password', ''));
        if ($defaultPassword !== '') {
            return $defaultPassword;
        }

        $uppercase = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lowercase = 'abcdefghjkmnpqrstuvwxyz';
        $digits = '23456789';
        $symbols = '@$!%*?&';

        $seed = [
            $this->pick($uppercase),
            $this->pick($lowercase),
            $this->pick($digits),
            $this->pick($symbols),
        ];

        $pool = $uppercase.$lowercase.$digits.$symbols;
        while (count($seed) < max($length, 8)) {
            $seed[] = $this->pick($pool);
        }

        shuffle($seed);

        return implode('', $seed);
    }

    private function pick(string $characters): string
    {
        return $characters[random_int(0, strlen($characters) - 1)];
    }
}
