<?php


return [

    'provider' => Eduardosp6\RouterTool\Providers\TomTomAPI::class,

    'geocoding_mode' => 'both',   // accepted values: tomtom, google, both (consume 2 apis if necessary for better accuracy)

    'TOMTOM_API_KEY' => '',

    'GOOGLE_MAPS_KEY' => '',

    'route_options' => [
        'time_discharge' => null,  // estimated time for execution (delivery)
        'time_charge' => null,     // estimated time for execution (collect)
        'return_base' => false,    // whether the driver must return to the starting point
    ],
];