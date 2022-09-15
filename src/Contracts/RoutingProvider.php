<?php

namespace Eduardosp6\RouterTool\Contracts;

interface RoutingProvider
{
    public function setApiKey(string $k);
    public function getApiKey();
    public function geocode(string $address);
    public function getRestrictions();

    public function calcDistance(string $origins = '',
                                 string $destinations = '',
                                 string $travelMode = '',
                                 string $language = '');

    public function calcDirections(bool $optimized = false,
                                   string $waypoints = '',
                                   string $travelMode = '',
                                   string $origin = '',
                                   string $destination = '',
                                   string $language = '',
                                   string $region = '',
                                   string $departureTime = '',
                                   string $avoid = '');
}