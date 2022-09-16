<?php

namespace Eduardosp6\RouterTool;

use Eduardosp6\RouterTool\Providers\GoogleAPI;
use Eduardosp6\RouterTool\Providers\TomTomAPI;

class Geocode
{
    private static RouterTool $router;

    /**
     * Returns array containing latitude and longitude of the given address.
     * Do not abbreviate the address for better accuracy.
     * The address is searched by TomTom and if the city and city are not the same,
     * a request is sent to Google.
     *
     * @param string $fullAddress
     * @param string $district
     * @param string $city
     * @return array|null
     */
    public static function exec(string $fullAddress, string $district, string $city): ?array
    {
        if (empty($fullAddress) || empty($district) || empty($city)) {
            return null;
        }

        $mode = config('router-tool.geocoding_mode');

        self::$router = new RouterTool();

        if ($mode == 'tomtom') {
            return self::tomtomGeocoding($fullAddress, $district, $city);

        } else if ($mode == 'google') {
            return self::googleGeocoding($fullAddress);

        } else if($mode == 'both') {
            $result = self::tomtomGeocoding($fullAddress, $district, $city);

            if ($result != null) {
                return $result;
            }

            return self::googleGeocoding($fullAddress);
        }

        return null;
    }

    /**
     * TomTom geocoding
     *
     * @param string $fullAddress
     * @param string $district
     * @param string $city
     * @return array|null
     */
    private static function tomtomGeocoding(string $fullAddress, string $district, string $city): ?array
    {
        self::$router->setProvider(new TomTomAPI());

        $resultTomTom = self::$router->getClient()->performGeocoding($fullAddress);

        if ($resultTomTom != null
            && isset($resultTomTom['results'])
            && count($resultTomTom['results']) > 0) {

            //get the first position of the results array that contains the most assertive result
            $addressEl = $resultTomTom['results'][0]['address'];
            $positionEl = $resultTomTom['results'][0]['position'];

            // returns if there is no district
            if (!isset($addressEl['municipalitySubdivision'])) {
                return null;
            }

            // returns if district and city equals to given address
            if ($district == $addressEl['municipalitySubdivision']
                && $city == $addressEl['municipality']) {

                return [
                    'latitude' => $positionEl['lat'],
                    'longitude' => $positionEl['lon'],
                ];
            }
        }

        return null;
    }

    /**
     * Google geocoding
     *
     * @param string $fullAddress
     * @return array|null
     */
    private static function googleGeocoding(string $fullAddress): ?array
    {
        self::$router->setProvider(new GoogleAPI());

        $resultGoogle = self::$router->getClient()->performGeocoding($fullAddress);

        if ($resultGoogle != null
            && isset($resultGoogle['results'])
            && count($resultGoogle['results']) > 0) {

            $positionEl = $resultGoogle['results'][0]['geometry']['location'];

            return [
                'latitude' => $positionEl['lat'],
                'longitude' => $positionEl['lng'],
            ];
        }

        return null;
    }
}