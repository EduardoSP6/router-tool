<?php

namespace Eduardosp6\RouterTool\Services;

use Eduardosp6\RouterTool\Exceptions\InvalidRouteArray;
use Eduardosp6\RouterTool\Exceptions\InvalidStepsAmount;

class RoutingParamsValidator
{
    /**
     * @throws InvalidRouteArray
     * @throws InvalidStepsAmount
     */
    public static function validate(array $srcRoute)
    {
        if (!isset($srcRoute["start_point"])) {
            throw new InvalidRouteArray();
        }

        if (empty($srcRoute["start_point"]["id"])
            || empty($srcRoute["start_point"]["latitude"])
            || empty($srcRoute["start_point"]["longitude"])) {
            throw new InvalidRouteArray();
        }

        if (!isset($srcRoute["steps"])
            || !is_array($srcRoute["steps"])
            || count($srcRoute["steps"]) == 0) {
            throw new InvalidStepsAmount();
        }
    }
}