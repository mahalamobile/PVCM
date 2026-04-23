<?php

namespace App\Providers;

use App\Models\Campaign;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        RateLimiter::for('client-campaign-create', function (Request $request) {
            $clientId = $request->integer('client_id');
            $max = max(1, (int) config('campaign.rate_limits.create_campaign_per_minute', 60));

            return Limit::perMinute($max)
                ->by($clientId > 0 ? "client:{$clientId}" : "ip:{$request->ip()}")
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Rate limit exceeded for campaign creation.',
                ], 429, $headers));
        });

        RateLimiter::for('client-ingestion', function (Request $request) {
            $campaignRouteValue = $request->route('campaign');
            $campaignId = is_numeric($campaignRouteValue)
                ? (int) $campaignRouteValue
                : ($campaignRouteValue instanceof Campaign ? (int) $campaignRouteValue->id : 0);

            $clientId = 0;

            if ($campaignRouteValue instanceof Campaign) {
                $clientId = (int) $campaignRouteValue->client_id;
            } elseif ($campaignId > 0) {
                $clientId = (int) Campaign::query()
                    ->whereKey($campaignId)
                    ->value('client_id');
            }

            $max = max(1, (int) config('campaign.rate_limits.ingest_data_per_minute', 120));

            return Limit::perMinute($max)
                ->by($clientId > 0 ? "client:{$clientId}" : "ip:{$request->ip()}")
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Rate limit exceeded for campaign data ingestion.',
                ], 429, $headers));
        });
    }
}
