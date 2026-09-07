<?php

namespace Tests\Unit\Finance;

use App\Modules\Finance\Support\ChartAccountMap;
use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

/**
 * The catalogue names its accounts by reference code; a company keeps its books
 * under whatever codes it already uses. These pin the translation between the
 * two, because getting it wrong is silent: an account that does not resolve
 * switches its expense code off rather than raising anything.
 */
class ChartAccountMapTest extends TestCase
{
    private function withMap(array $map): void
    {
        $container = new Container;
        $container->instance('config', new Repository(['finance_accounts' => ['map' => $map]]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_an_unmapped_code_resolves_to_itself(): void
    {
        // An empty map means the two charts agree, which is the case in
        // development and in every test that does not say otherwise.
        $this->withMap([]);

        $this->assertSame('1211', ChartAccountMap::local('1211'));
        $this->assertSame('1211', ChartAccountMap::localFromGl('1211 Project WIP – Direct Materials'));
    }

    public function test_a_mapped_code_resolves_to_this_chart(): void
    {
        $this->withMap(['1211' => 'COS-003']);

        $this->assertSame('COS-003', ChartAccountMap::localFromGl('1211 Project WIP – Direct Materials'));
    }

    public function test_mapping_one_code_leaves_the_others_alone(): void
    {
        // Adding a mapping must be additive. If it moved codes it does not
        // name, a partial map would silently redirect accounts that already
        // posted correctly.
        $this->withMap(['1211' => 'COS-003']);

        $this->assertSame('1212', ChartAccountMap::local('1212'));
    }

    public function test_an_indirect_account_stays_unresolved(): void
    {
        // "Relevant 1400 PPE account" names a class for a human to choose
        // within. Resolving it would hand the posting engine a header account.
        $this->withMap([]);

        $this->assertNull(ChartAccountMap::localFromGl('Receiving cash/bank account'));
        $this->assertNull(ChartAccountMap::localFromGl('Equity / Dividends Payable'));
        $this->assertNull(ChartAccountMap::localFromGl(null));
    }

    public function test_a_four_digit_code_inside_prose_is_still_found(): void
    {
        // The catalogue writes "Relevant 1400 PPE account" and "1030 Petty Cash
        // Float or bank" alike, so the reference is read out of the text rather
        // than expected to start it.
        $this->withMap(['1400' => 'PE-001']);

        $this->assertSame('PE-001', ChartAccountMap::localFromGl('Relevant 1400 PPE account'));
    }

    public function test_a_blank_mapping_falls_back_rather_than_erasing_the_account(): void
    {
        // A half-filled config template must not resolve to the empty string,
        // which would match no account and switch the code off.
        $this->withMap(['1211' => '']);

        $this->assertSame('1211', ChartAccountMap::local('1211'));
    }
}
