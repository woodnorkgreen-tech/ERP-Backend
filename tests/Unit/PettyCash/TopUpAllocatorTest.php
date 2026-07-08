<?php

use PHPUnit\Framework\TestCase;
use App\Modules\Finance\PettyCash\Services\TopUpAllocator;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;

class TopUpAllocatorTest extends TestCase
{
    public function test_single_topup_sufficient()
    {
        $repo = new class {
            public function getTopUpsWithAvailableBalance(): Collection
            {
                return new Collection([
                    (object)[ 'id' => 1, 'remaining_balance' => '200.00', 'date_topped_up' => Carbon::parse('2026-01-01') ]
                ]);
            }
        };

        $allocator = new TopUpAllocator($repo);
        $alloc = $allocator->plan(150.00, 2.50);

        $this->assertIsArray($alloc);
        $this->assertCount(1, $alloc);
        $this->assertEquals('150.00', $alloc[0]['amount']);
        $this->assertEquals('2.50', $alloc[0]['transaction_cost']);
        $this->assertEquals('152.50', bcadd($alloc[0]['amount'], $alloc[0]['transaction_cost'], 2));
    }

    public function test_multiple_topups_split()
    {
        $repo = new class {
            public function getTopUpsWithAvailableBalance(): Collection
            {
                return new Collection([
                    (object)[ 'id' => 1, 'remaining_balance' => '50.00', 'date_topped_up' => Carbon::parse('2026-01-01') ],
                    (object)[ 'id' => 2, 'remaining_balance' => '100.00', 'date_topped_up' => Carbon::parse('2026-02-01') ],
                ]);
            }
        };

        $allocator = new TopUpAllocator($repo);
        $alloc = $allocator->plan(120.00, 3.00);

        $this->assertIsArray($alloc);
        $this->assertGreaterThan(1, count($alloc));

        $total = '0.00';
        foreach ($alloc as $a) {
            $total = bcadd($total, bcadd($a['amount'], $a['transaction_cost'], 2), 2);
        }

        $this->assertEquals('123.00', $total);
    }

    public function test_insufficient_throws()
    {
        $this->expectException(\Exception::class);

        $repo = new class {
            public function getTopUpsWithAvailableBalance(): Collection
            {
                return new Collection([
                    (object)[ 'id' => 1, 'remaining_balance' => '10.00', 'date_topped_up' => Carbon::parse('2026-01-01') ],
                ]);
            }
        };

        $allocator = new TopUpAllocator($repo);
        $allocator->plan(50.00, 0.00);
    }
}
