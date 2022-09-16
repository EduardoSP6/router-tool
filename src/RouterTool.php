<?php

namespace Eduardosp6\RouterTool;

use Carbon\Carbon;
use Eduardosp6\RouterTool\Contracts\RoutingClient;
use Eduardosp6\RouterTool\Contracts\RoutingProvider;

class RouterTool
{
    protected RoutingProvider $provider;

    public function setProvider(RoutingProvider $provider)
    {
        $this->provider = $provider;
    }

    public function getProvider(): RoutingProvider
    {
        return $this->provider;
    }

    public function getClient(): RoutingClient
    {
        return $this->provider->client;
    }

    public function distanceBetweenCoords($lat1, $lon1, $lat2, $lon2): float
    {
        $lat1 = deg2rad($lat1);
        $lat2 = deg2rad($lat2);
        $lon1 = deg2rad($lon1);
        $lon2 = deg2rad($lon2);

        $earthRadius = 6371;

        $adjust = 1.16; // adjust of 16% for better proximity to reality

        $dist = ($earthRadius * acos(cos($lat1) * cos($lat2) * cos($lon2 - $lon1) + sin($lat1) * sin($lat2)));
        $dist *= $adjust;

        return $dist;
    }

    public function performRouting(array $sourceRoute, Carbon $dateExit, string $mode = 'normal')
    {
        $providerName = config('provider');

        $this->setProvider((new $providerName));

        if ($mode == "optimized") {
            return $this->getClient()->performRoutingOptimized($sourceRoute, $dateExit);
        }

        return $this->getClient()->performRouting($sourceRoute, $dateExit);
    }
}