<?php

namespace App\Providers;

use App\Mail\Transport\SendlibTransport;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });

        Mail::extend('sendlib', function (array $config) {
            return new SendlibTransport($config['api_key'] ?? null);
        });

        // General API traffic — generous, mostly a DoS/abuse backstop.
        // Keyed by user when authenticated so one user's polling/broadcasting
        // auth calls can't exhaust another's, falling back to IP for guests.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // Much stricter — applied directly to login/register/forgot-password/
        // reset-password, which are unauthenticated and the classic
        // credential-stuffing / brute-force / email-bombing targets.
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });
    }
}
