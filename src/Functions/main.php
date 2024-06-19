<?php

namespace Alfred\Productive\Functions\main;

use Brandlabs\Productiveio\Resources\Projects;
use Brandlabs\Productiveio\Resources\Tasks;
use Brandlabs\Productiveio\Resources\Companies;
use Alfred\Productive\Resources\Deals;
use Alfred\Productive\Resources\Services;
use Brandlabs\Productiveio\Resources\People;
use function Alfred\Productive\Functions\utils\get_root_dir;
use function Alfred\Productive\Functions\cli\cli_service_id;
use function Alfred\Productive\Functions\cli\cli_task_id;
use function Alfred\Productive\Functions\actions\start_timer;
use function Alfred\Productive\Functions\utils\logger;
use function Alfred\Productive\Functions\cache\should_update_cache;
use function Alfred\Productive\Functions\actions\cmd;
use function Alfred\Productive\Functions\env\get_person_id;
use function Alfred\Productive\Functions\utils\time_ago;
use function Alfred\Productive\Functions\utils\to_array_parameter;

/**
 * Main entrypoint.
 * @param string[] $args
 */
function main(array $args):void
{
    $command = $args[1] ?? 'tasks';

    if ($command === 'start-timer') {
        $service_id = cli_service_id();
        $task_id = cli_task_id();

        if (!is_null($service_id)) {
            start_timer(
                service_id: $service_id,
                task_id: is_string($task_id) ? $task_id : null,
            );
        }

        return;
    }

    $resources_map = [
        'companies' => [Companies::class, [
            'filter[status]'    => '1',
            'fields[companies]' => to_array_parameter('name', 'company_code'),
        ]],
        'deals'     => [Deals::class, [
            'filter[sales_status_id]' => '1,2,3',
            'include'                 => to_array_parameter('company', 'responsible', 'deal_status'),
            'fields[deals]'           => to_array_parameter(
                'name',
                'sales_status_id',
                'company',
                'responsible',
                'deal_status',
            ),
            'fields[companies]'       => to_array_parameter('company_code', 'name'),
            'fields[people]'          => to_array_parameter('first_name', 'last_name'),
            'fields[deal_statuses]'   => to_array_parameter('name'),
        ]],
        'people'    => [People::class, [
            'filter[status]'    => '1',
            'include'           => to_array_parameter('company'),
            'fields[people]'    => to_array_parameter(
                'first_name',
                'last_name',
                'title',
                'email',
                'company',
            ),
            'fields[companies]' => to_array_parameter('company_code', 'name'),
        ]],
        'projects'  => [Projects::class, [
            'include'           => to_array_parameter('company'),
            'fields[projects]'  => to_array_parameter(
                'name',
                'company',
            ),
            'fields[companies]' => to_array_parameter('company_code', 'name'),
        ]],
        'services'  => [Services::class, [
            'filter[time_tracking_enabled]'  => 'true',
            'filter[trackable_by_person_id]' => 'true',
            'filter[person_id]'              => get_person_id(),
            'filter[after]'                  => time_ago('-4 months'),
            'include'                        => to_array_parameter('deal', 'deal.company'),
            'fields[services]'               => to_array_parameter(
                'name',
                'worked_time',
                'budgeted_time',
                'budget_used',
                'budget_total',
                'deal',
            ),
            'fields[deals]'                  => to_array_parameter('name', 'suffix', 'closed_at', 'company'),
            'fields[companies]'              => to_array_parameter('company_code', 'name'),
        ]],
        'tasks'     => [Tasks::class, [
            'sort'           => '-updated_at',
            'filter[status]' => '1',
            'include'        => to_array_parameter(
                'project',
                'project.company',
                'assignee',
                'workflow_status'
            ),
            'fields[tasks]'  => to_array_parameter(
                'task_number',
                'title',
                'worked_time',
                'initial_estimate',
                'project',
                'assignee',
                'workflow_status',
            ),
            'fields[projects]'  => to_array_parameter(
                'project_number',
                'name',
                'company',
            ),
            'fields[companies]'  => to_array_parameter(
                'company_code',
                'name',
            ),
            'fields[people]'  => to_array_parameter(
                'first_name',
                'last_name',
            ),
            'fields[statuses]'  => to_array_parameter(
                'name',
                'last_name',
            ),
        ]],
    ];

    logger('main', $command, should_update_cache() ? '--update-cache' : '');

    cmd(
        $command,
        $resources_map[$command][0],
        $resources_map[$command][1]
    );
}
