<?php

namespace Eduardosp6\RouterTool;

class RouterTool
{
    public $provider;

    public function __construct($provider = null)
    {
        $this->provider = $provider;
    }

    public function setProvider($provider)
    {
        $this->provider = $provider;
    }

    public function getProvider()
    {
        return $this->provider;
    }

    public function getClient()
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

        $dist = ($earthRadius * acos( cos($lat1) * cos($lat2) * cos($lon2 - $lon1) + sin($lat1) * sin($lat2) ));
        $dist *= $adjust;

        return $dist;
    }
}