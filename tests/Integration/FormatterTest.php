<?php

namespace Alfred\Productive\Tests\Integration;

use PHPUnit\Framework\TestCase;
use function Alfred\Productive\Functions\Resources\formatter\deals_formatter;
use function Alfred\Productive\Functions\Resources\formatter\services_formatter;

class FormatterTest extends TestCase
{
    public function testStageLessDealIsNotFormattedAsLost(): void
    {
        $item = deals_formatter([
            'id' => 'deal-1',
            'attributes' => ['name' => 'Internal budget'],
            'relationships' => [
                'company' => [
                    'id' => 'company-1',
                    'attributes' => ['company_code' => 'INT', 'name' => 'Internal'],
                ],
                'responsible' => [
                    'id' => 'person-1',
                    'attributes' => ['first_name' => 'Test', 'last_name' => 'Person'],
                ],
                'deal_status' => null,
            ],
        ]);

        self::assertSame('Internal budget', $item['title']);
        self::assertStringNotContainsString('Perdu', $item['subtitle']);
        self::assertStringContainsString('deal-1', $item['match']);
    }

    public function testServiceMatchContainsItsRelatedDealId(): void
    {
        $item = services_formatter([
            'id' => 'service-1',
            'attributes' => [
                'name' => 'Internal service',
                'worked_time' => 60,
                'budgeted_time' => 120,
                'budget_used' => 10000,
                'budget_total' => 20000,
            ],
            'relationships' => [
                'deal' => [
                    'id' => 'deal-1',
                    'attributes' => [
                        'name' => 'Internal budget',
                        'suffix' => null,
                        'closed_at' => null,
                    ],
                    'relationships' => [
                        'company' => [
                            'id' => 'company-1',
                            'attributes' => ['company_code' => 'INT', 'name' => 'Internal'],
                        ],
                    ],
                ],
            ],
        ]);

        self::assertSame('deal-1', $item['variables']['deal_id']);
        self::assertStringContainsString('deal-1', $item['match']);
    }
}
