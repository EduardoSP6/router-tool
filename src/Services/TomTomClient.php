<?php


namespace Eduardosp6\RouterTool\Services;


use Eduardosp6\RouterTool\Contracts\RoutingClient;
use Eduardosp6\RouterTool\Contracts\RoutingProvider;
use Eduardosp6\RouterTool\Exceptions\TomTomApiError;
use Eduardosp6\RouterTool\Geocode;
use Carbon\Carbon;
use Illuminate\Support\Arr;

class TomTomClient implements RoutingClient
{
    private $providerInstance;

    public function __construct(RoutingProvider $provider)
    {
        $this->providerInstance = $provider;
    }

    public function setProviderInstance(RoutingProvider $pi)
    {
        $this->providerInstance = $pi;
    }

    public function getProviderInstance(): RoutingProvider
    {
        return $this->providerInstance;
    }

    public function performGeocoding(string $address)
    {
        return $this->getProviderInstance()->geocode($address);
    }

    /**
     * Calculates distance and duration between points. Maximum 100 (sources + destinations)
     * origins e destinations = string de lat e long separated by comma and pipe.
     * Ex: -22.382,-43.3823|-22.4583,-43.34987
     */
    public function calcDistance(
        string $origins = '',
        string $destinations = '',
        string $travelMode = 'car',
        string $language = 'pt-BR'
    ): ?array
    {
        return $this->getProviderInstance()->calcDistance($origins, $destinations, $travelMode, $language);
    }

    /**
     * Calculates route times and duration without modifying the sequence of deliveries.
     * Maximum number of waypoints: 150
     *
     * @throws TomTomApiError
     */
    public function performRouting(array $srcRoute, Carbon $exit_prog): array
    {
        $configTimeCharge = config('route_options.time_charge');
        $configTimeDischarge = config('route_options.time_discharge');
        $configReturnBase = config('route_options.return_base');

        $time_service = Carbon::createFromFormat("H:i:s", ($configTimeDischarge != null ? $configTimeDischarge : "00:00:00"));
        $time_charge = Carbon::createFromFormat("H:i:s", ($configTimeCharge != null ? $configTimeCharge : "00:00:00"));

        $arrayWaypoints = array();

        // origin
        $startWp = [
            "latitude" => $srcRoute['start_point']['latitude'],
            "longitude" => $srcRoute['start_point']['longitude']
        ];

        $arrayWaypoints[] = $startWp;

        $createdSteps = $srcRoute['steps'];

        if (count($createdSteps) == 0) {
            return [];
        }

        usort($createdSteps, function ($i1, $i2) {
            return $i1['order'] < $i2['order'];
        });

        $arraySteps = array();
        foreach ($createdSteps as $step) {
            if (!isset($step['id']) || $step['id'] == null) {
                continue;
            }

            // Get the latitude and longitude from address
            if (empty($step['latitude']) || empty($step['longitude'])) {

                $address = trim($step['address']) . ", " . $step['number']
                    . trim($step['district']) . " " . trim($step['city']) . " " . $step['uf'];

                $result = Geocode::exec($address, trim($step['district']), trim($step['city']));
                if ($result != null) {
                    $step['latitude'] = $result['latitude'];
                    $step['longitude'] = $result['longitude'];
                }
            }

            $arrayWaypoints[] = [
                "latitude" => $step['latitude'],
                "longitude" => $step['longitude']
            ];

            $arraySteps[] = $step;
        }

        // Destination
        $arrayWaypoints[] = $startWp;

        // Waypoints
        $waypoints = "";
        foreach ($arrayWaypoints as $waypoint) {
            $waypoints .= (
            $waypoints == ""
                ? $waypoint['latitude'] . "," . $waypoint['longitude']
                : ":" . $waypoint['latitude'] . "," . $waypoint['longitude']
            );
        }

        $departureTime = $exit_prog->copy()->format('Y-m-d\TH:i:s.u\Z');

        // Send the request
        $jsonResult = $this->getProviderInstance()->calcDirections(false, $waypoints,
            'car', '', '', 'pt-BR', '', $departureTime);

        if (!isset($jsonResult["routes"])) {
            throw new TomTomApiError("No route found");
        }

        $totDuration = Carbon::createFromFormat("H:i:s", "00:00:00");
        $totDistance = 0;
        $routeNode = $jsonResult['routes'][0]; // first route returned
        $legs = $routeNode["legs"];
        $summary = $routeNode['summary'];
        $legsLastIndex = (count($legs) > 0 ? (count($legs) - 1) : 0);

        $distance = (double)$summary['lengthInMeters']; // distance in meters
        $distance = ($distance / 1000); // convert in kilometers

        // Accumulate total distance and travel time
        $totDuration = $totDuration->addSeconds($summary['travelTimeInSeconds']);
        $totDistance += $distance;

        // subtract time and distance if there is no return step
        if (!$configReturnBase) {
            $distanceLast = (double)$legs[$legsLastIndex]["summary"]["lengthInMeters"];
            $distanceLast = ($distanceLast / 1000);
            $totDistance -= $distanceLast;
            $lastDuration = $legs[$legsLastIndex]["summary"]["travelTimeInSeconds"];
            $totDuration = $totDuration->subSeconds($lastDuration);
        }

        $counterSteps = 0;
        foreach ($arraySteps as $step) {
            $exit_prog->addSeconds($legs[$counterSteps]["summary"]["travelTimeInSeconds"]);

            $distance = (double)$legs[$counterSteps]["summary"]["lengthInMeters"];
            $duration = $legs[$counterSteps]["summary"]["travelTimeInSeconds"];

            $time_course = Carbon::createFromFormat("H:i:s", "00:00:00");
            if (!empty($duration)) {
                $time_course->addSeconds($duration);
            }

            $step->date_arrival = $exit_prog->toDateString();
            $step->time_arrival = $exit_prog->toTimeString();

            if (strpos($step['type'], "collect")) {
                $exit_prog
                    ->addHours($time_charge->hour)
                    ->addMinutes($time_charge->minute)
                    ->addSeconds($time_charge->second);

                $totDuration
                    ->addHours($time_charge->hour)
                    ->addMinutes($time_charge->minute)
                    ->addSeconds($time_charge->second);

                $step['time_service'] = $time_charge->toTimeString();
            } else {
                $exit_prog
                    ->addHours($time_service->hour)
                    ->addMinutes($time_service->minute)
                    ->addSeconds($time_service->second);

                $totDuration
                    ->addHours($time_service->hour)
                    ->addMinutes($time_service->minute)
                    ->addSeconds($time_service->second);

                $step['time_service'] = $time_service->toTimeString();
            }

            $step['date_exit'] = $exit_prog->toDateString();
            $step['time_exit'] = $exit_prog->toTimeString();
            $step['distance'] = ($distance / 1000);
            $step['time_course'] = $time_course->toTimeString();

            $counterSteps++;
        }

        // updates the expected arrival if there is a return step
        if ($configReturnBase) {

            $lastStep = Arr::last($createdSteps);
            if (strpos(strtolower(trim($lastStep['type'])), "deposit")) {

                $distanceLast = (double)$legs[$legsLastIndex]["summary"]["lengthInMeters"]; // distance in meters
                $lastDuration = $legs[$legsLastIndex]["summary"]["travelTimeInSeconds"]; // duration in seconds

                $exit_prog->addSeconds($lastDuration);

                $lastStep['date_arrival'] = $exit_prog->toDateString();
                $lastStep['time_arrival'] = $exit_prog->toTimeString();
                $lastStep['distance'] = ($distanceLast / 1000);
            }
        }

        // update route data
        $srcRoute['steps'] = $createdSteps;
        $srcRoute['distance_total'] = $totDistance;
        $srcRoute['duration_total'] = $totDuration;

        return $srcRoute;
    }

    /**
     * Calculates route times and duration by modifying the sequence of deliveries - Optimized.
     * Max number of waypoints: 150
     *
     * @throws TomTomApiError
     */
    public function performRoutingOptimized(array $srcRoute, Carbon $exit_prog): array
    {
        $configTimeCharge = config('route_options.time_charge');
        $configTimeDischarge = config('route_options.time_discharge');
        $configReturnBase = config('route_options.return_base');

        $time_service = Carbon::createFromFormat("H:i:s", ($configTimeDischarge != null ? $configTimeDischarge : "00:00:00"));
        $time_charge = Carbon::createFromFormat("H:i:s", ($configTimeCharge != null ? $configTimeCharge : "00:00:00"));

        $arrayWaypoints = array();

        // origin
        $startWp = [
            "latitude" => $srcRoute['start_point']['latitude'],
            "longitude" => $srcRoute['start_point']['longitude']
        ];

        $arrayWaypoints[] = $startWp;

        $createdSteps = $srcRoute['steps'];

        if (count($createdSteps) == 0) {
            return [];
        }

        usort($createdSteps, function ($i1, $i2) {
            return $i1['order'] < $i2['order'];
        });

        $arraySteps = array();
        foreach ($createdSteps as $step) {
            if (!isset($step['id']) || $step['id'] == null) {
                continue;
            }

            // Get the latitude and longitude from address
            if (empty($step['latitude']) || empty($step['longitude'])) {

                $address = trim($step['address']) . ", " . $step['number']
                    . trim($step['district']) . " " . trim($step['city']) . " " . $step['uf'];

                $result = Geocode::exec($address, trim($step['district']), trim($step['city']));
                if ($result != null) {
                    $step['latitude'] = $result['latitude'];
                    $step['longitude'] = $result['longitude'];
                }
            }

            $arrayWaypoints[] = [
                "latitude" => $step['latitude'],
                "longitude" => $step['longitude']
            ];

            $arraySteps[] = $step;
        }

        // destination
        $arrayWaypoints[] = $startWp;

        // waypoints
        $waypoints = "";
        foreach ($arrayWaypoints as $waypoint) {
            $waypoints .= (
            $waypoints == ""
                ? $waypoint['latitude'] . "," . $waypoint['longitude']
                : ":" . $waypoint['latitude'] . "," . $waypoint['longitude']
            );
        }

        // Send the request
        $jsonResult = $this->getProviderInstance()->calcDirections(true, $waypoints);

        if (!isset($jsonResult["routes"]) || !isset($jsonResult["optimizedWaypoints"])) {
            throw new TomTomApiError("No route found");
        }

        $totDuration = Carbon::createFromFormat("H:i:s", "00:00:00");
        $totDistance = 0;
        $routeNode = $jsonResult['routes'][0]; // first route returned
        $legs = $routeNode["legs"];
        $summary = $routeNode['summary'];
        $optimizedWaypoints = $jsonResult['optimizedWaypoints']; // order of steps (does not contain return step)
        $legsLastIndex = (count($legs) > 0 ? (count($legs) - 1) : 0);

        // atualiza a sequencia das steps
        foreach ($optimizedWaypoints as $ow) {
            $step = $arraySteps[$ow["optimizedIndex"]];
            $step['order'] = ($ow["providedIndex"] + 2);
        }

        $distance = (double)$summary['lengthInMeters']; // distance in meters
        $distance = ($distance / 1000); // convert in kilometers

        // Accumulate total distance and travel time
        $totDuration = $totDuration->addSeconds($summary['travelTimeInSeconds']);
        $totDistance += $distance;

        // subtract time and distance if there is no return step
        if (!$configReturnBase) {
            $distanceLast = (double)$legs[$legsLastIndex]["summary"]["lengthInMeters"];
            $distanceLast = ($distanceLast / 1000);
            $totDistance -= $distanceLast;
            $totDuration = $totDuration->subSeconds($legs[$legsLastIndex]["summary"]["travelTimeInSeconds"]);
        }

        // reorder the steps
        $steps = $srcRoute['steps'];

        usort($steps, function ($i1, $i2) {
            return $i1['order'] < $i2['order'];
        });

        $counterSteps = 0;
        foreach ($steps as $step) {
            if ($counterSteps == 0) {
                $counterSteps++;
                continue;
            }

            $idx = ($counterSteps - 1);

            $exit_prog->addSeconds($legs[$idx]["summary"]["travelTimeInSeconds"]);

            $distance = (double)$legs[$idx]["summary"]["lengthInMeters"];

            $duration = $legs[$idx]["summary"]["travelTimeInSeconds"];

            $time_course = Carbon::createFromFormat("H:i:s", "00:00:00");
            if (!empty($duration)) {
                $time_course->addSeconds($duration);
            }

            $step['date_arrival'] = $exit_prog->toDateString();
            $step['time_arrival'] = $exit_prog->toTimeString();

            if (strpos($step['type'], "collect")) {
                $exit_prog
                    ->addHours($time_charge->hour)
                    ->addMinutes($time_charge->minute)
                    ->addSeconds($time_charge->second);

                $totDuration
                    ->addHours($time_charge->hour)
                    ->addMinutes($time_charge->minute)
                    ->addSeconds($time_charge->second);

                $step['time_service'] = $time_charge->toTimeString();
            } else {
                $exit_prog
                    ->addHours($time_service->hour)
                    ->addMinutes($time_service->minute)
                    ->addSeconds($time_service->second);

                $totDuration
                    ->addHours($time_service->hour)
                    ->addMinutes($time_service->minute)
                    ->addSeconds($time_service->second);

                $step['time_service'] = $time_service->toTimeString();
            }

            $step['date_exit'] = $exit_prog->toDateString();
            $step['time_exit'] = $exit_prog->toTimeString();
            $step['distance'] = ($distance / 1000);
            $step['time_course'] = $time_course->toTimeString();

            $counterSteps++;
        }

        // atualiza a chegada prevista se houver step de retorno
        if ($configReturnBase) {

            $lastStep = Arr::last($steps);
            if (strpos(strtolower(trim($lastStep['type'])), "deposit")) {

                $distanceLast = (double)$legs[$legsLastIndex]["summary"]["lengthInMeters"]; // distancia em metros
                $lastDuration = $legs[$legsLastIndex]["summary"]["travelTimeInSeconds"]; // duracao em segundos

                $exit_prog->addSeconds($lastDuration);

                $lastStep['date_arrival'] = $exit_prog->toDateString();
                $lastStep['time_arrival'] = $exit_prog->toTimeString();
                $lastStep['distance'] = ($distanceLast / 1000);
            }
        }

        // update route data
        $srcRoute['steps'] = $steps;
        $srcRoute['distance_total'] = $totDistance;
        $srcRoute['duration_total'] = $totDuration;

        return $srcRoute;
    }
}
