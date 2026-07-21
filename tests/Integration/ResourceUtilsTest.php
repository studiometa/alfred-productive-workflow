<?php

namespace Alfred\Productive\Tests\Integration;

use PHPUnit\Framework\TestCase;
use function Alfred\Productive\Functions\Resources\utils\merge_relationships;

class ResourceUtilsTest extends TestCase
{
    public function testRowsWithoutRelationshipsArePreserved(): void
    {
        $rows = merge_relationships([
            ['type' => 'companies', 'id' => 'company-1', 'attributes' => ['name' => 'Company']],
        ], []);

        self::assertSame('company-1', $rows[0]['id']);
    }

    public function testRelationshipsAndNestedCompaniesAreResolvedFromTheIndex(): void
    {
        $rows = merge_relationships([
            [
                'type' => 'services',
                'id' => 'service-1',
                'relationships' => [
                    'deal' => ['data' => ['type' => 'deals', 'id' => 'deal-1']],
                ],
            ],
        ], [
            [
                'type' => 'deals',
                'id' => 'deal-1',
                'relationships' => [
                    'company' => ['data' => ['type' => 'companies', 'id' => 'company-1']],
                ],
            ],
            [
                'type' => 'companies',
                'id' => 'company-1',
                'attributes' => ['name' => 'Company'],
            ],
        ]);

        self::assertSame('deal-1', $rows[0]['relationships']['deal']['id']);
        self::assertSame(
            'Company',
            $rows[0]['relationships']['deal']['relationships']['company']['attributes']['name']
        );
    }
}
