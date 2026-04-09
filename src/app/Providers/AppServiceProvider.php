<?php

namespace App\Providers;

use App\Contracts\PasswordResetMailer;
use App\Support\LaravelPasswordResetMailer;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind a mailer abstraction so we can switch to dedicated SES integration later.
        $this->app->bind(PasswordResetMailer::class, LaravelPasswordResetMailer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
