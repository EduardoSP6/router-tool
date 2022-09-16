<?php


namespace Eduardosp6\RouterTool\Providers;

use Eduardosp6\RouterTool\Contracts\RoutingProvider;
use Eduardosp6\RouterTool\Services\GoogleClient;

class GoogleAPI implements RoutingProvider
{
    public $apiKey;
    public GoogleClient $client;

    public function __construct()
    {
        $this->apiKey = config('router-tool.GOOGLE_MAPS_KEY');
        $this->client = (new GoogleClient($this));
    }

    public function setApiKey(string $k)
    {
        $this->apiKey = $k;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function setClient($c)
    {
        $this->client = $c;
    }

    public function getClient(): GoogleClient
    {
        return $this->client;
    }

    /**
     * Get coordinates of the address
     *
     * @param string $address
     * @return array|null
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function geocode(string $address): ?array
    {
        if (empty($address))
            return null;

        $address = urlencode(trim($address));
        $url = 'https://maps.google.com/maps/api/geocode/json?address=' . $address
            . '&sensor=false'
            . "&key=" . $this->getApiKey();

        $client = new \GuzzleHttp\Client();
        $response = $client->request('POST', $url);

        if ($response->getStatusCode() === 200) {
            $content = $response->getBody();
            $jsonRes = json_decode($content->getContents(), true);

            if ($jsonRes['status'] != "OK") {
                return null;
            }

            return $jsonRes;
        }

        return null;
    }

    /**
     * Get the distance and duration between 2 points using Google Distance Matrix API.
     * Attention: The distance is returned in meters and duration in seconds.
     */
    public function calcDistance(
        string $origins = '',
        string $destinations = '',
        string $travelMode = 'driving',
        string $language = 'pt-BR'
    ): ?array
    {
        $origins = urlencode($origins);
        $destinations = urlencode($destinations);

        $url = "https://maps.googleapis.com/maps/api/distancematrix/json?"
            . "origins=" . $origins
            . "&destinations=" . $destinations
            . "&mode=" . $travelMode
            . "&language=" . $language
            . "&sensor=false"
            . "&key=" . $this->getApiKey();

        $result = [];

        $client = new \GuzzleHttp\Client();
        $response = $client->request('POST', $url);

        if ($response->getStatusCode() === 200) {

            $content = $response->getBody();
            $jsonRes = json_decode($content->getContents(), true);

            if (isset($jsonRes['rows']) && count($jsonRes['rows']) > 0) {

                if (count($jsonRes['rows'][0]['elements']) > 0) {

                    $elements = $jsonRes['rows'][0]['elements'][0];

                    if (array_key_exists('distance', $elements)
                        && array_key_exists('duration', $elements)) {

                        $result['distance'] = $elements['distance']['value'];
                        $result['duration'] = $elements['duration']['value'];

                        return $result;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Routing calc through Directions API. Max waypoints: 23
     *
     * @param bool $optimized
     * @param string $waypoints
     * @param string $travelMode
     * @param string $origin
     * @param string $destination
     * @param string $language
     * @param string $region
     * @param string $departureTime
     * @return array|null
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function calcDirections(
        bool $optimized = false,
        string $waypoints = '',
        string $travelMode = 'driving',
        string $origin = '',
        string $destination = '',
        string $language = 'pt-BR',
        string $region = 'br',
        string $departureTime = '', // Seconds since 1970-01-01. It could not be past date. Ex: Carbon::now()->isoFormat('X')
        string $avoid = '' // restrictions separated by comma
    ): ?array
    {
        // https://developers.google.com/maps/documentation/directions/get-directions

        $url = 'https://maps.googleapis.com/maps/api/directions/json?'
            . 'origin=' . urlencode($origin)
            . '&destination=' . urlencode($destination)
            . '&waypoints=' . ($optimized ? 'optimize:true|' : '') . urlencode($waypoints)
            . '&region=' . $region
            . '&alternatives=false'
            . '&language=' . $language
            . '&mode=' . $travelMode
            . '&key=' . $this->getApiKey();

        // date and hour of exit
        if (!empty($departureTime)) {
            $url .= '&departure_time=' . $departureTime;
        }

        // Adding restrictions
        if (!empty($avoid)) {
            if (! strpos($avoid, ",")) {
                if (in_array($avoid, $this->getRestrictions())) {
                    $url .= "&avoid=" . $avoid;
                }
            } else {
                $arrayAvoid = explode(",", $avoid);
                if (is_array($arrayAvoid) && count($arrayAvoid) > 0) {
                    $strAvoids = "";
                    foreach ($arrayAvoid as $iAvoid) {
                        if (in_array($avoid, $this->getRestrictions())) {
                            $strAvoids .= (!empty($strAvoids) ? '|' : '') . $iAvoid;
                        }
                    }

                    if (!empty($strAvoids)) {
                        $url .= "&avoid=" . $strAvoids;
                    }
                }
            }
        }

        $client = new \GuzzleHttp\Client();
        $response = $client->request('POST', $url);

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
            'tolls',
            'highways',
            'ferries',
        );
    }
}
