<?php

namespace Alfred\Productive\Functions\Resources\utils;

use Exception;
use ReflectionClass;
use Brandlabs\Productiveio\BaseResource;

function merge_relationships(array $data, array $included): array
{
    $included_index = [];
    foreach ($included as $item) {
        if (isset($item['type'], $item['id'])) {
            $included_index[$item['type']][$item['id']] = $item;
        }
    }

    $merged = [];
    foreach ($data as $row) {
        $new_row = $row;
        $new_row['included'] = $included;

        if (!isset($row['relationships'])) {
            $merged[] = $new_row;
            continue;
        }

        foreach ($row['relationships'] as $key => $data) {
            $type = $data['data']['type'] ?? null;
            $id = $data['data']['id'] ?? null;

            if (is_null($type) || is_null($id)) {
                continue;
            }

            $resolved_relationship = $included_index[$type][$id] ?? null;

            // Resolve companies relationships for projects and deals
            if (in_array($type, ['projects','deals']) && isset($resolved_relationship['relationships']['company'])) {
                $company_id = $resolved_relationship['relationships']['company']['data']['id'] ?? null;
                $company = $included_index['companies'][$company_id] ?? null;

                if ($company) {
                    $resolved_relationship['relationships']['company'] = $company;
                }
            }

            if (!is_null($resolved_relationship)) {
                $new_row['relationships'][$key] = $resolved_relationship;
            }
        }

        $merged[] = $new_row;
    }

    return $merged;
}

function validate_resource_class(string $resource_class): void
{
    if (!class_exists($resource_class)) {
        throw new Exception("The '{$resource_class}' class does not exist.");
    }

    if (!(new ReflectionClass($resource_class))->isSubclassOf(BaseResource::class)) {
        throw new Exception("The '{$resource_class}' class is not a subclass of '".BaseResource::class."'.");
    }
}


function get_resource_name(string $resource_class)
{
    return basename(str_replace('\\', '/', $resource_class));
}
