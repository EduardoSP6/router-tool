<?php

namespace Eduardosp6\RouterTool\Contracts;

interface RoutingClient
{
    public function __construct(RoutingProvider $provider);
    public function setProviderInstance(RoutingProvider $pi);
    public function getProviderInstance();
}