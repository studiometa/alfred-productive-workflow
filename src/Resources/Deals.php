<?php

namespace Alfred\Productive\Resources;

use Brandlabs\Productiveio\ApiClient;
use Brandlabs\Productiveio\BaseResource;
use Brandlabs\Productiveio\Resources\Contracts\Create;
use Brandlabs\Productiveio\Resources\Contracts\Delete;
use Brandlabs\Productiveio\Resources\Contracts\Fetch;
use Brandlabs\Productiveio\Resources\Contracts\Update;
use Brandlabs\Productiveio\Resources\Traits\CreateResource;
use Brandlabs\Productiveio\Resources\Traits\DeleteResource;
use Brandlabs\Productiveio\Resources\Traits\GetResource;
use Brandlabs\Productiveio\Resources\Traits\ListResource;
use Brandlabs\Productiveio\Resources\Traits\UpdateResource;

class Deals extends BaseResource implements Create, Delete, Fetch, Update
{
    use CreateResource, DeleteResource, GetResource, ListResource, UpdateResource;

    const RESOURCE_PATH = '/deals';

    public function __construct(ApiClient $apiClient)
    {
        parent::__construct($apiClient, self::RESOURCE_PATH);
    }
}
