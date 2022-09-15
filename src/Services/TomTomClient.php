<?php


namespace Eduardosp6\RouterTool\Services;


use Eduardosp6\RouterTool\Contracts\RoutingClient;
use Eduardosp6\RouterTool\Contracts\RoutingProvider;
use Eduardosp6\RouterTool\Geocode;
use Carbon\Carbon;

class TomTomClient implements RoutingClient
{
    private $providerInstance;

    /**
     * TomTomClient constructor.
     */
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
     * Calcula distancia e duracao entre pontos. Maximo 100 (origens + destinos)
     * origins e destinations = string de lat e long separados por virgula e pipe.
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
     * Calcula os tempos e duracao da rota sem modificar a sequencia das entregas
     * Numero maximo de waypoints: 150
     **/
    public function performRouting(RouteDelivery $routeDelivery, Carbon $exit_prog): bool
    {
        // Setup
        $setup = Setup::first();

        $time_service = Carbon::createFromFormat("H:i:s", ($setup->time_discharge != null ? $setup->time_discharge : "00:00:00"));
        $time_charge  = Carbon::createFromFormat("H:i:s", ($setup->time_charge != null ? $setup->time_charge : "00:00:00"));

        $arrayWaypoints = array();

        $route = $routeDelivery->route;

        $cd = $route->dist_center;

        // origin
        $waypointCD = [
            "latitude"  => $cd->latitude,
            "longitude" => $cd->longitude
        ];

        $arrayWaypoints[] = $waypointCD;

        // STEPS
        $createdSteps = $routeDelivery->route->steps()->orderBy('sequence')->get();

        if($createdSteps->count() == 0) {
            return false;
        }

        $arraySteps = array();

        foreach ($createdSteps as $step)
        {
            if ($step->place_id == null) {
                continue;
            }

            $place = $step->place;

            // Obtem coordenadas do endereço se estiver vazio
            if (empty($place->latitude) || empty($place->longitude)) {

                $address = trim($place->address) .", $place->number "
                    . trim($place->district) ." ". trim($place->city) ." ". $place->uf;

                $result = Geocode::exec($address, trim($place->district), trim($place->city));
                if ($result != null) {
                    $place->latitude = $result['latitude'];
                    $place->longitude = $result['longitude'];
                    $place->save();
                }
            }

            $arrayWaypoints[] = [
                "latitude"  => $place->latitude,
                "longitude" => $place->longitude
            ];

            $arraySteps[] = $step;
        }

        // Destination
        $arrayWaypoints[] = $waypointCD;

        // Waypoints
        $waypoints = "";
        foreach ($arrayWaypoints as $waypoint) {
            $waypoints .= (
                $waypoints == ""
                ? $waypoint['latitude'] .",". $waypoint['longitude']
                : ":".$waypoint['latitude'] .",". $waypoint['longitude']
            );
        }

        // data e hora de saida da rota
        $departureTime = $exit_prog->copy()->format('Y-m-d\TH:i:s.u\Z');

        // ENVIA REQUISIÇÃO DE ROTA PARA A TOMTOM
        $jsonResult = $this->getProviderInstance()->calcDirections(false, $waypoints,
            'car', '', '', 'pt-BR', '', $departureTime);

        if (!isset($jsonResult["routes"])) {
            return false;
        }

        $totDuration   = Carbon::createFromFormat("H:i:s", "00:00:00");
        $totDistance   = 0;
        $routeNode     = $jsonResult['routes'][0]; // primeira posicao do array de rotas
        $legs          = $routeNode["legs"];
        $summary       = $routeNode['summary'];
        $legsLastIndex = (count($legs) > 0 ? (count($legs) - 1) : 0);

        $distance      = (double) $summary['lengthInMeters']; // distancia em metros
        $distance      = ($distance / 1000); // converte em KM

        // Acumula totais de distancia e tempo de percurso
        $totDuration   = $totDuration->addSeconds($summary['travelTimeInSeconds']);
        $totDistance   += $distance;

        // subtrai tempo e distancia se nao houver step de retorno
        if ($setup->return_base != Setup::RETURN_BASE_TRUE) {

            $distanceLast = (double) $legs[$legsLastIndex]["summary"]["lengthInMeters"]; // distancia em metros
            $distanceLast = ($distanceLast / 1000); // converte em KM
            $totDistance -= $distanceLast;

            $lastDuration = $legs[$legsLastIndex]["summary"]["travelTimeInSeconds"];
            $totDuration  = $totDuration->subSeconds($lastDuration);
        }

        $counterSteps = 0;
        foreach ($arraySteps as $step)
        {
            $exit_prog->addSeconds($legs[$counterSteps]["summary"]["travelTimeInSeconds"]);

            $distance = (double)$legs[$counterSteps]["summary"]["lengthInMeters"];
            $duration = $legs[$counterSteps]["summary"]["travelTimeInSeconds"];

            $time_course = Carbon::createFromFormat("H:i:s", "00:00:00");
            if (!empty($duration)) {
                $time_course->addSeconds($duration);
            }

            $step->date_arrival = $exit_prog->toDateString();
            $step->time_arrival = $exit_prog->toTimeString();

            if (strpos($step->type, "retirada")) {
                $exit_prog
                    ->addHours($time_charge->hour)
                    ->addMinutes($time_charge->minute)
                    ->addSeconds($time_charge->second);

                $totDuration
                    ->addHours($time_charge->hour)
                    ->addMinutes($time_charge->minute)
                    ->addSeconds($time_charge->second);

                $step->time_service = $time_charge->toTimeString();
            } else {
                $exit_prog
                    ->addHours($time_service->hour)
                    ->addMinutes($time_service->minute)
                    ->addSeconds($time_service->second);

                $totDuration
                    ->addHours($time_service->hour)
                    ->addMinutes($time_service->minute)
                    ->addSeconds($time_service->second);

                $step->time_service = $time_service->toTimeString();
            }

            $step->date_exit = $exit_prog->toDateString();
            $step->time_exit = $exit_prog->toTimeString();
            $step->distance  = ($distance / 1000);
            $step->time_course = $time_course->toTimeString();
            $step->save();

            $counterSteps++;
        }

        // atualiza a chegada prevista se houver step de retorno
        if ($setup->return_base == Setup::RETURN_BASE_TRUE) {

            $lastStep = $createdSteps->last();
            if ($lastStep instanceof Step && strpos(strtolower(trim($lastStep->type)), "chegada")) {

                $distanceLast = (double) $legs[$legsLastIndex]["summary"]["lengthInMeters"]; // distancia em metros
                $lastDuration = $legs[$legsLastIndex]["summary"]["travelTimeInSeconds"]; // duracao em segundos

                $exit_prog->addSeconds($lastDuration);

                $lastStep->date_arrival = $exit_prog->toDateString();
                $lastStep->time_arrival = $exit_prog->toTimeString();
                $lastStep->distance     = ($distanceLast / 1000);
                $lastStep->save();
            }
        }

        // atualiza totalizadores da rota
        $route->distance_total = $totDistance;
        $route->duration_total = $totDuration;
        $route->save();

        return true;
    }

    /**
     * Calcula os tempos e duracao da rota modificando a sequencia das entregas - Otimizado
     * Numero maximo de waypoints: 150
     **/
    public function performRoutingOptimized($routeDelivery, $exit_prog): bool
    {
        // Setup
        $setup = Setup::first();

        $time_service = Carbon::createFromFormat("H:i:s", ($setup->time_discharge != null ? $setup->time_discharge : "00:00:00"));
        $time_charge  = Carbon::createFromFormat("H:i:s", ($setup->time_charge != null ? $setup->time_charge : "00:00:00"));

        $arrayWaypoints = array();

        // Rota
        $route = $routeDelivery->route;
        if($route == null) {
            return false;
        }

        // CD
        $cd = $route->dist_center;
        if($cd == null) {
            return false;
        }

        // origin
        $waypointCD = [
            "latitude" => $cd->latitude,
            "longitude" => $cd->longitude
        ];

        $arrayWaypoints[] = $waypointCD;

        // Entregas
        $deliveries = $routeDelivery->deliveries()->get();

        if($deliveries->count() == 0) {
            return false;
        }

        $arraySteps = array();
        foreach ($deliveries as $delivery)
        {
            $place = $delivery->place;

            // Obtem coordenadas do endereço se estiver vazio
            if (empty($place->latitude) || empty($place->longitude)) {

                $address = trim($place->address) .", $place->number "
                    . trim($place->district) ." ". trim($place->city) ." ". $place->uf;

                $result = Geocode::exec($address, trim($place->district), trim($place->city));
                if ($result != null) {
                    $place->latitude = $result['latitude'];
                    $place->longitude = $result['longitude'];
                    $place->save();
                }
            }

            $arrayWaypoints[] = [
                "latitude"  => $place->latitude,
                "longitude" => $place->longitude
            ];

            $arraySteps[] = $delivery->relatedStep();
        }

        // destination
        $arrayWaypoints[] = $waypointCD;

        // waypoints
        $waypoints = "";
        foreach ($arrayWaypoints as $waypoint) {
            $waypoints .= (
                $waypoints == ""
                    ? $waypoint['latitude'].",". $waypoint['longitude']
                    : ":".$waypoint['latitude'].",". $waypoint['longitude']
            );
        }

        // Envia requisição para TOMTOM
        $jsonResult = $this->getProviderInstance()->calcDirections(true, $waypoints);

        if (!isset($jsonResult["routes"]) || !isset($jsonResult["optimizedWaypoints"])) {
            return false;
        }

        $totDuration   = Carbon::createFromFormat("H:i:s", "00:00:00");
        $totDistance   = 0;
        $routeNode     = $jsonResult['routes'][0]; // primeira posicao do array de rotas
        $legs          = $routeNode["legs"];
        $summary       = $routeNode['summary'];
        $optimizedWaypoints = $jsonResult['optimizedWaypoints']; // ordem das steps (Não contem a de retorno ao CD)
        $legsLastIndex = (count($legs) > 0 ? (count($legs) - 1) : 0);

        // atualiza a sequencia das steps
        foreach ($optimizedWaypoints as $ow) {
            $step = $arraySteps[$ow["optimizedIndex"]];
            $step->sequence = ($ow["providedIndex"] + 2);
            $step->save();
        }

        $distance = (double) $summary['lengthInMeters']; // distancia em metros
        $distance = ($distance / 1000); // converte em KM

        // Acumula totais de distancia e tempo de percurso
        $totDuration = $totDuration->addSeconds($summary['travelTimeInSeconds']);
        $totDistance += $distance;

        // subtrai tempo e distancia se nao houver step de retorno
        if ($setup->return_base != Setup::RETURN_BASE_TRUE) {

            $distanceLast = (double) $legs[$legsLastIndex]["summary"]["lengthInMeters"]; // distancia em metros
            $distanceLast = ($distanceLast / 1000); // converte em KM

            $totDistance -= $distanceLast;

            $totDuration = $totDuration->subSeconds($legs[$legsLastIndex]["summary"]["travelTimeInSeconds"]);
        }

        $steps = $routeDelivery->route->steps()->orderBy('sequence')->get();

        $counterSteps = 0;
        foreach ($steps as $step)
        {
            if($counterSteps == 0) {
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

            $step->date_arrival = $exit_prog->toDateString();
            $step->time_arrival = $exit_prog->toTimeString();

            if (strpos($step->type, "retirada")) {
                $exit_prog
                    ->addHours($time_charge->hour)
                    ->addMinutes($time_charge->minute)
                    ->addSeconds($time_charge->second);

                $totDuration
                    ->addHours($time_charge->hour)
                    ->addMinutes($time_charge->minute)
                    ->addSeconds($time_charge->second);

                $step->time_service = $time_charge->toTimeString();
            } else {
                $exit_prog
                    ->addHours($time_service->hour)
                    ->addMinutes($time_service->minute)
                    ->addSeconds($time_service->second);

                $totDuration
                    ->addHours($time_service->hour)
                    ->addMinutes($time_service->minute)
                    ->addSeconds($time_service->second);

                $step->time_service = $time_service->toTimeString();
            }

            $step->date_exit = $exit_prog->toDateString();
            $step->time_exit = $exit_prog->toTimeString();
            $step->distance  = ($distance / 1000);
            $step->time_course = $time_course->toTimeString();
            $step->save();

            $counterSteps++;
        }

        // atualiza a chegada prevista se houver step de retorno
        if ($setup->return_base == Setup::RETURN_BASE_TRUE) {

            $lastStep = $steps->last();
            if ($lastStep instanceof Step && strpos(strtolower(trim($lastStep->type)), "chegada")) {

                $distanceLast = (double) $legs[$legsLastIndex]["summary"]["lengthInMeters"]; // distancia em metros
                $lastDuration = $legs[$legsLastIndex]["summary"]["travelTimeInSeconds"]; // duracao em segundos

                $exit_prog->addSeconds($lastDuration);

                $lastStep->date_arrival = $exit_prog->toDateString();
                $lastStep->time_arrival = $exit_prog->toTimeString();
                $lastStep->distance     = ($distanceLast / 1000);
                $lastStep->save();
            }
        }

        // atualiza totalizadores da rota
        $route->distance_total = $totDistance;
        $route->duration_total = $totDuration;
        $route->save();

        return true;
    }
}
