<?php

namespace App\Providers;

use App\Fiscal\Agt\Contracts\AgtGateway;
use App\Fiscal\Agt\Contracts\JwsSigner;
use App\Fiscal\Agt\Contracts\SigningKeyResolver;
use App\Fiscal\Agt\Signing\FilesystemSigningKeyResolver;
use App\Fiscal\Agt\Signing\OpenSslJwsSigner;
use App\Fiscal\Agt\V1_2\RestAgtGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AgtServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(AgtGateway::class, RestAgtGateway::class);
        $this->app->bind(JwsSigner::class, OpenSslJwsSigner::class);
        $this->app->bind(SigningKeyResolver::class, FilesystemSigningKeyResolver::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        RateLimiter::for('agt', function (mixed $job): Limit {
            $key = is_object($job) && method_exists($job, 'rateLimitKey')
                ? (string) $job->rateLimitKey()
                : 'agt-global';

            return Limit::perMinute(
                (int) config('agt.transport.rate_limit_per_minute', 30),
            )->by($key);
        });
    }
}
