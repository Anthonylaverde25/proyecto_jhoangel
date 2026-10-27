<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Contexts\CompanyContext;
use App\Core\Interfaces\ICompanyContext;
use App\Core\Interfaces\ICompanyRepository;
use App\Core\Interfaces\ITenantDomains;
use App\Infrastructure\Persistence\EloquentCompanyRepository;
use App\Infrastructure\Tenancy\StanclTenantDomains;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * All core application bindings.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        ICompanyRepository::class => EloquentCompanyRepository::class,
        ITenantDomains::class => StanclTenantDomains::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ICompanyContext::class, CompanyContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // SEC-04: throttle credential-guessing on the login endpoint. The key combines the
        // submitted email and the client IP, so one attacker cannot lock out a victim's account
        // from a single IP while still capping brute-force attempts per account.
        RateLimiter::for('login', function (Request $request): Limit {
            $email = Str::lower((string) $request->input('email'));

            return Limit::perMinute(5)->by($email . '|' . $request->ip());
        });
    }
}
