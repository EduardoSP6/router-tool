# RouterTool

#### Author: Eduardo Sales
#### Created At: 14/09/2022

##### This package aims to standardize routing and geocoding routines, using market providers.

##### Supported providers: Google and TomTom.

### Prerequisites:
```
- PHP 7.2 or above;
- Larvel 5.5 or above;
```

### Classes Diagram:
```
Geocode - Class responsible for transforming address into geographic coordinates.

RouterTool - Class for build the routes.

Providers - Classes of providers. 

Services - Consumption classes of provider APIs.
Adapt the methods according to your project.

Hierarchy:

- RouterTool
    - Provider
        - Client
```

### Use cases:

#### Geocoding:
```
For geocoding just call the static method Geocode::exec() passing the parameters:
Full address (avoid abbreviations), neighborhood and city. 
This method sends a request to TomTom and if the neighborhood 
and city is not compatible, send it to Google. 

The return will be an array containing the latitude and longitude keys 
of the address if successful, otherwise it will be null.

$coords = Geocode::exec(
        "Av. Rio Branco, 15000 Centro, Rio de Janeiro - RJ"
        , "Centro",
        "Rio de Janeiro"
    );
```

#### Routing:
##### Attention! Using Google as a provider, in generating the route, the maximum number of points is 23
```
$routerTool = new RouterTool();

// Define the provider. Available: TomTomAPI or GoogleAPI
$routerTool->setProvider(new TomTomAPI());

// Routing calc:
// params: route data array, expected exit date (Carbon instance)
// returns: bool (success = true, fail = false)

$routeSrc = [
    'distance_total' => 0.0,
    'duration_total'=> 0.0,
    'start_point' = [
        'id' => '1',
        'latitude' => 22.3489343,
        'longitude' => 43.998509
    ],
    'steps' => [
        [
            'id' => 112,
            'order' => 1,
            'type' => 'delivery', // values: delivery, collect, deposit
            'latitude' => 22.834783,
            'longitude' => 43.48729,
            'address' => '',
            'number' => null,
            'district' => '',
            'city' => '',
            'uf' => '',
            'date_arrival' => '',
            'time_arrival' => '',
            'time_service' => '',
            'time_course' => '',
            'distance' => 0,
        ]
    ]
]

// Normal mode (keeps the given order sequence of deliveries)
$routerTool->getClient()->performRouting($routeSrc, $exitDate);
           
// Optimized mode (Edit the order sequence of deliveries for better performance, ditance and duration)
$routerTool->getClient()->performRoutingOptimized($routeSrc, $exitDate);
```