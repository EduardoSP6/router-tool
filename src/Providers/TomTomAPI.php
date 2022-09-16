<?php


namespace Eduardosp6\RouterTool\Providers;


use Eduardosp6\RouterTool\Services\TomTomClient;
use Eduardosp6\RouterTool\Contracts\RoutingProvider;

class TomTomAPI implements RoutingProvider
{
    public $apiKey;
    public TomTomClient $client;

    /**
     * TomTomAPI constructor.
     */
    public function __construct()
    {
        $this->apiKey = config('router-tool.TOMTOM_API_KEY');
        $this->client = (new TomTomClient($this));
    }

    public function setApiKey(string $k)
    {
        $this->apiKey = $k;
    }

    public function getApiKey()
    {
        return $this->apiKey;
    }

    public function setClient($c)
    {
        $this->client = $c;
    }

    public function getClient(): TomTomClient
    {
        return $this->client;
    }

    /**
     * Get the geographic coordinates of the given address.
     * Attention! For better accuracy do not abbreviate the address.
     *
     * @param string $address
     * @return array|null
     * @throws GuzzleException
     */
    public function geocode(string $address): ?array
    {
        $url = 'https://api.tomtom.com/search/2/geocode/'
            . urlencode(trim($address))
            . '.json?key=' . $this->getApiKey();

        $client = new Client();
        $response = $client->request('GET', $url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept-Encoding' => 'application/gzip'
            ]
        ]);

        if ($response->getStatusCode() === 200) {
            $content = $response->getBody();
            return json_decode($content->getContents(), true);
        }

        return null;
    }

    /**
     * Calculates distance between points. Maximum number of points: 100 (origins + destinations)
     * origins e destinations = string de lat e long separated by comma and pipe.
     * Ex: -22.382, -43.3823|-22.4583, -43.34987
     *
     * @param string $origins
     * @param string $destinations
     * @param string $travelMode
     * @param string $language
     * @return array|null
     * @throws GuzzleException
     */
    public function calcDistance(
        string $origins = '',
        string $destinations = '',
        string $travelMode = 'car',
        string $language = 'pt-BR'
    ): ?array
    {
        // https://developer.tomtom.com/routing-api/routing-api-documentation-matrix-routing/synchronous-matrix

        $url = 'https://api.tomtom.com/routing/1/matrix/sync'
            . '/json?key=' . $this->getApiKey()
            . '&routeType=fastest'
            . '&travelMode='. $travelMode;

        $body = [];

        $coordOrigins = explode("|", $origins);

        if (count($coordOrigins) == 0 && !empty($origins)) {
            $coordOrigins[] = explode(",", $origins);
        }

        if (count($coordOrigins) > 0) {
            foreach ($coordOrigins as $coordOrigin)
            {
                $coords = explode(",", $coordOrigin);

                $body['origins'][] = [
                    "point" => [
                        "latitude" => $coords[0],
                        "longitude" => $coords[1]
                    ]
                ];
            }
        }

        $coordDests = explode("|", $destinations);

        if (count($coordDests) == 0 && !empty($destinations)) {
            $coordDests[] = explode(",", $destinations);
        }

        if (count($coordDests) > 0) {
            foreach ($coordDests as $coordDest)
            {
                $coords = explode(",", $coordDest);

                $body['destinations'][] = [
                    "point" => [
                        "latitude" => $coords[0],
                        "longitude" => $coords[1]
                    ]
                ];
            }
        }

        $client = new Client();
        $response = $client->request('POST', $url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept-Encoding' => 'application/gzip'
            ],
            'body' => json_encode($body)
        ]);

        if ($response->getStatusCode() === 200) {
            $content = $response->getBody();
            return json_decode($content->getContents(), true);
        }

        return null;
    }

    /**
     * Routing calc
     *
     * @param bool $optimized
     * @param string $waypoints
     * @param string $travelMode
     * @param string $origin
     * @param string $destination
     * @param string $language
     * @param string $region
     * @param string $departureTime
     * @param string $avoid
     * @return array|null
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function calcDirections(
        bool $optimized = false,
        string $waypoints = '',
        string $travelMode = 'car',
        string $origin = '',
        string $destination = '',
        string $language = 'pt-BR',
        string $region = 'br',
        string $departureTime = 'now', // date/hour format: Y-m-d\TH:i:s.u\Z
        string $avoid = '' // restrictions
    ): ?array
    {
        // https://developer.tomtom.com/routing-api/routing-api-documentation-routing/common-routing-parameters

        if ($optimized) {
            $url = 'https://api.tomtom.com/routing/1/calculateRoute/' . $waypoints
                . '/json?key=' . $this->getApiKey()
                . '&computeBestOrder=true'
                . '&routeType=shortest'
                . '&traffic=true' // considers traffic during routing
                . '&language=' . $language
                . '&departAt=' . $departureTime
                . '&travelMode=' . $travelMode;
        } else {
            $url = 'https://api.tomtom.com/routing/1/calculateRoute/' . $waypoints
                . '/json?key=' . $this->getApiKey()
                . '&traffic=true' // considers traffic during routing
                . '&language=' . $language
                . '&departAt=' . $departureTime
                . '&travelMode=' . $travelMode;
        }

        // Add the restrictions
        if (!empty($avoid)) {
            if (! strpos($avoid, ",")) {
                if (in_array($avoid, $this->getRestrictions())) {
                    $url .= "&avoid=" . $avoid;
                }
            } else {
                $arrayAvoid = explode(",", $avoid);
                if (is_array($arrayAvoid) && count($arrayAvoid) > 0) {
                    foreach ($arrayAvoid as $iAvoid) {
                        if (in_array($iAvoid, $this->getRestrictions())) {
                            $url .= "&avoid=" .$iAvoid;
                        }
                    }
                }
            }
        }

        $client = new \GuzzleHttp\Client();
        $response = $client->request('GET', $url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept-Encoding' => 'application/gzip'
            ]
        ]);

        if ($response->getStatusCode() === 200) {
            $content = $response->getBody();
            return json_decode($content->getContents(), true);
        }

        return null;
    }

    /** Array of restrictions supported by API **/
    public function getRestrictions(): array
    {
        return array(
            'tollRoads',
            'motorways',
            'ferries',
            'unpavedRoads',
            'carpools',
            'alreadyUsedRoads',
            'borderCrossings',
        );
    }
}
