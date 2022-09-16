<?php


namespace Eduardosp6\RouterTool\Services;

use Eduardosp6\RouterTool\Contracts\RoutingClient;
use Eduardosp6\RouterTool\Contracts\RoutingProvider;
use Eduardosp6\RouterTool\Exceptions\GoogleApiError;
use Eduardosp6\RouterTool\Exceptions\InvalidRouteArray;
use Eduardosp6\RouterTool\Exceptions\InvalidStepsAmount;
use Eduardosp6\RouterTool\Geocode;
use Carbon\Carbon;
use Illuminate\Support\Arr;

class GoogleClient implements RoutingClient
{
    private RoutingProvider $providerInstance;

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
     * Calculates route times and duration without modifying the sequence of deliveries
     *
     * @param array $srcRoute
     * @param Carbon $dateExit
     * @return array
     * @throws GoogleApiError
     * @throws InvalidRouteArray
     * @throws InvalidStepsAmount
     */
    public function performRouting(array $srcRoute, Carbon $dateExit): array
    {
        RoutingParamsValidator::validate($srcRoute);

        $configTimeCharge = config('router-tool.router-tool.route_options.time_charge');
        $configTimeDischarge = config('router-tool.router-tool.route_options.time_discharge');

        $dateArrivalAndExit = $dateExit->copy();
        $timeDischarge = Carbon::createFromFormat("H:i:s", ($configTimeDischarge != null ? $configTimeDischarge : "00:00:00"));
        $timeCharge = Carbon::createFromFormat("H:i:s", ($configTimeCharge != null ? $configTimeCharge : "00:00:00"));

        $totDuration = Carbon::createFromFormat("H:i:s", "00:00:00"); // duration total of route
        $totDistance = 0; // distance total of route

        $origin = "" . $srcRoute['start_point']['latitude'] . "," . $srcRoute['start_point']['longitude'];

        $steps = $srcRoute['steps'];

        if (count($steps) == 0) {
            return [];
        }

        usort($steps, function ($i1, $i2) {
            return $i1['order'] < $i2['order'];
        });

        $lastStep = Arr::last($steps);

        if ($lastStep['id'] == $srcRoute['start_point']['id']) {
            $destination = $origin;
        } else {
            $destination = "" . $lastStep['latitude'] . "," . $lastStep['longitude'];
        }

        $sentSteps = array();
        $waypoints = "";
        foreach ($steps as $step) {
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

            $waypoints .= (!empty($waypoints) ? "|" : "")
                . $step['latitude'] . "," . $step['longitude'];

            $sentSteps[] = $step;
        }

        // exit of route in seconds since 1970-01-01. It cannot be a date in the past
        if (Carbon::now()->lessThan($dateExit)) {
            $departureTime = $dateExit->copy()->isoFormat('X');
        } else {
            $departureTime = "";
        }

        // Send the request
        $jsonResult = $this->getProviderInstance()->calcDirections(
            false,
            $waypoints,
            'driving',
            $origin,
            $destination,
            'pt-BR',
            'br',
            $departureTime
        );

        if ($jsonResult['status'] !== "OK") {
            throw new GoogleApiError("GOOGLE API ERROR RESPONSE >> STATUS: "
                . $jsonResult['status'] . ' JSON: ' . json_encode($jsonResult));
        }

        if (!isset($jsonResult['routes'])) {
            throw new GoogleApiError("No route found");
        }

        $legs = $jsonResult['routes'][0]['legs'];

        if (!isset($jsonResult['routes'][0]['legs']) || count($jsonResult['routes'][0]['legs']) == 0) {
            throw new GoogleApiError("Empty route returned");
        }

        $idx = 0;
        foreach ($sentSteps as $step) {
            if (!isset($step['id']) || $step['id'] == null) {
                continue;
            }

            if (!isset($legs[$idx])) {
                continue;
            }

            $distance = ($legs[$idx]['distance']['value'] / 1000); // distance in kilometers
            $duration = $legs[$idx]['duration']['value']; // duration in seconds

            $totDistance += $distance;

            // travel time
            $timeCourse = Carbon::createFromFormat("H:i:s", "00:00:00");

            if (!empty($duration)) {
                $dateArrivalAndExit->addSeconds($duration);
                $timeCourse->addSeconds($duration);
                $totDuration->addSeconds($duration);
            }

            // update arrival date and time
            $step['date_arrival'] = $dateArrivalAndExit->toDateString();
            $step['time_arrival'] = $dateArrivalAndExit->toTimeString();

            if (strpos($step['type'], "collect")) {

                $dateArrivalAndExit
                    ->addHours($timeCharge->hour)
                    ->addMinutes($timeCharge->minute)
                    ->addSeconds($timeCharge->second);

                $totDuration
                    ->addHours($timeCharge->hour)
                    ->addMinutes($timeCharge->minute)
                    ->addSeconds($timeCharge->second);

                $step['time_service'] = $timeCharge->toTimeString();

            } else if (strpos($step['type'], "delivery")) {

                $dateArrivalAndExit
                    ->addHours($timeDischarge->hour)
                    ->addMinutes($timeDischarge->minute)
                    ->addSeconds($timeDischarge->second);

                $totDuration
                    ->addHours($timeDischarge->hour)
                    ->addMinutes($timeDischarge->minute)
                    ->addSeconds($timeDischarge->second);

                $step['time_service'] = $timeDischarge->toTimeString();
            }

            $step['date_exit'] = $dateArrivalAndExit->toDateString();
            $step['time_exit'] = $dateArrivalAndExit->toTimeString();
            $step['time_course'] = $timeCourse->toTimeString();
            $step['distance'] = $distance;

            $idx++;
        }

        // save return to deposit data
        if (strpos($lastStep['type'], "deposit")) {

            $lastLeg = end($legs);

            $distance = ($lastLeg['distance']['value'] / 1000); // distance in kilometers
            $duration = $lastLeg['duration']['value']; // duration in seconds

            $totDistance += $distance;

            // travel time
            $timeCourse = Carbon::createFromFormat("H:i:s", "00:00:00");

            if (!empty($duration)) {
                $dateArrivalAndExit->addSeconds($duration);
                $timeCourse->addSeconds($duration);
                $totDuration->addSeconds($duration);
            }

            $lastStep['date_arrival'] = $dateArrivalAndExit->toDateString();
            $lastStep['time_arrival'] = $dateArrivalAndExit->toTimeString();
            $lastStep['distance'] = $distance;
            $lastStep['time_course'] = $timeCourse->toTimeString();
        }

        // update route data
        $srcRoute['steps'] = $steps;
        $srcRoute['distance_total'] = $totDistance;
        $srcRoute['duration_total'] = $totDuration;

        return $srcRoute;
    }

    /**
     * Calculates route times and duration by modifying the sequence of deliveries - Optimized
     * Attention! The costs of calculating the optimized route are higher
     *
     * @param array $srcRoute
     * @param Carbon $dateExit
     * @return array
     * @throws GoogleApiError
     * @throws InvalidRouteArray
     * @throws InvalidStepsAmount
     */
    public function performRoutingOptimized(array $srcRoute, Carbon $dateExit): array
    {
        RoutingParamsValidator::validate($srcRoute);

        $configTimeCharge = config('router-tool.router-tool.route_options.time_charge');
        $configTimeDischarge = config('router-tool.router-tool.route_options.time_discharge');

        $dateArrivalAndExit = $dateExit->copy();
        $timeDischarge = Carbon::createFromFormat("H:i:s", ($configTimeDischarge != null ? $configTimeDischarge : "00:00:00"));
        $timeCharge = Carbon::createFromFormat("H:i:s", ($configTimeCharge != null ? $configTimeCharge : "00:00:00"));

        $totDuration = Carbon::createFromFormat("H:i:s", "00:00:00"); // route duration total
        $totDistance = 0; // route distance total

        $origin = "" . $srcRoute['start_point']['latitude'] . "," . $srcRoute['start_point']['longitude'];

        $steps = $srcRoute['steps'];

        if (count($steps) == 0) {
            return [];
        }

        usort($steps, function ($i1, $i2) {
            return $i1['order'] < $i2['order'];
        });

        $lastStep = Arr::last($steps);

        if ($lastStep['id'] == $srcRoute['start_point']['id']) {
            $destination = $origin;
        } else {
            $destination = "" . $lastStep['latitude'] . "," . $lastStep['longitude'];
        }

        $arraySteps = array();
        $waypoints = "";
        foreach ($steps as $step) {
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

            $waypoints .= (!empty($waypoints) ? "|" : "")
                . $step['latitude'] . "," . $step['longitude'];

            $arraySteps[] = $step;
        }

        // exit of route in seconds since 1970-01-01. It cannot be a date in the past
        if (Carbon::now()->lessThan($dateExit)) {
            $departureTime = $dateExit->copy()->isoFormat('X');
        } else {
            $departureTime = "";
        }

        // Send the request
        $jsonResult = $this->getProviderInstance()->calcDirections(
            true,
            $waypoints,
            'driving',
            $origin,
            $destination,
            'pt-BR',
            'br',
            $departureTime
        );

        if ($jsonResult['status'] != "OK") {
            throw new GoogleApiError("GOOGLE API ERROR RESPONSE >> STATUS: "
                . $jsonResult['status'] . ' JSON: ' . json_encode($jsonResult));
        }

        if (!isset($jsonResult['routes']) || !isset($jsonResult['routes'][0]['waypoint_order'])) {
            throw new GoogleApiError("No route found");
        }

        // sequence array of optimized waypoints
        $waypointOrder = $jsonResult['routes'][0]['waypoint_order'];

        // update steps sequence
        foreach ($waypointOrder as $key => $wo) {
            $step = $arraySteps[$key];
            $step['order'] = ($wo + 2);
        }

        // reorder the steps array
        $steps = $srcRoute['steps'];
        usort($steps, function ($i1, $i2) {
            return $i1['order'] < $i2['order'];
        });

        $legs = $jsonResult['routes'][0]['legs'];

        if (!isset($jsonResult['routes'][0]['legs']) || count($jsonResult['routes'][0]['legs']) == 0) {
            throw new GoogleApiError("Empty route returned");
        }

        $idx = 0;
        foreach ($steps as $step) {
            if (!isset($step['id']) || $step['id'] == null) {
                continue;
            }

            if (!isset($legs[$idx])) {
                continue;
            }

            $distance = ($legs[$idx]['distance']['value'] / 1000); // distance in kilometers
            $duration = $legs[$idx]['duration']['value']; // duration in seconds

            $totDistance += $distance;

            // travel time
            $timeCourse = Carbon::createFromFormat("H:i:s", "00:00:00");

            if (!empty($duration)) {
                $dateArrivalAndExit->addSeconds($duration);
                $timeCourse->addSeconds($duration);
                $totDuration->addSeconds($duration);
            }

            // update arrival date and time
            $step['date_arrival'] = $dateArrivalAndExit->toDateString();
            $step['time_arrival'] = $dateArrivalAndExit->toTimeString();

            if (strpos($step['type'], "collect")) {

                $dateArrivalAndExit
                    ->addHours($timeCharge->hour)
                    ->addMinutes($timeCharge->minute)
                    ->addSeconds($timeCharge->second);

                $totDuration
                    ->addHours($timeCharge->hour)
                    ->addMinutes($timeCharge->minute)
                    ->addSeconds($timeCharge->second);

                $step['time_service'] = $timeCharge->toTimeString();

            } else if (strpos($step['type'], "delivery")) {

                $dateArrivalAndExit
                    ->addHours($timeDischarge->hour)
                    ->addMinutes($timeDischarge->minute)
                    ->addSeconds($timeDischarge->second);

                $totDuration
                    ->addHours($timeDischarge->hour)
                    ->addMinutes($timeDischarge->minute)
                    ->addSeconds($timeDischarge->second);

                $step['time_service'] = $timeDischarge->toTimeString();
            }

            $step['date_exit'] = $dateArrivalAndExit->toDateString();
            $step['time_exit'] = $dateArrivalAndExit->toTimeString();
            $step['time_course'] = $timeCourse->toTimeString();
            $step['distance'] = $distance;

            $idx++;
        }

        // save return to deposit data
        if (strpos($lastStep['type'], "deposit")) {

            $lastLeg = end($legs);

            $distance = ($lastLeg['distance']['value'] / 1000); // distance in kilometers
            $duration = $lastLeg['duration']['value']; // duration in seconds

            $totDistance += $distance;

            // travel time
            $timeCourse = Carbon::createFromFormat("H:i:s", "00:00:00");

            if (!empty($duration)) {
                $dateArrivalAndExit->addSeconds($duration);
                $timeCourse->addSeconds($duration);
                $totDuration->addSeconds($duration);
            }

            $lastStep['date_arrival'] = $dateArrivalAndExit->toDateString();
            $lastStep['time_arrival'] = $dateArrivalAndExit->toTimeString();
            $lastStep['distance'] = $distance;
            $lastStep['time_course'] = $timeCourse->toTimeString();
        }

        // update route data
        $srcRoute['steps'] = $steps;
        $srcRoute['distance_total'] = $totDistance;
        $srcRoute['duration_total'] = $totDuration;

        return $srcRoute;
    }
}
