<?php

namespace Alfred\Productive\Functions\client;

use Brandlabs\Productiveio\ApiClient;
use function Alfred\Productive\Functions\env\get_org_id;
use function Alfred\Productive\Functions\env\get_auth_token;

function get_client(): ApiClient
{
    $client = new ApiClient(
        authToken: get_auth_token(),
        organisationId: (int)get_org_id()
    );

    return $client;
}
