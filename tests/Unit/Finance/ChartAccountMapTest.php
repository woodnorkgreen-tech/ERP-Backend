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
        $this->assertNull(ChartAccountMap::localFromGl('Relevant 1400 PPE account'));
        $this->assertNull(ChartAccountMap::localFromGl('Equity / Dividends Payable'));
        $this->assertNull(ChartAccountMap::localFromGl(null));
    }

    public function test_an_indirect_account_can_be_resolved_only_by_an_explicit_mapping(): void
    {
        // An indirect account names a class for Finance to choose within. It is
        // resolved only when this installation explicitly maps that class to a
        // concrete postable account.
        $this->withMap(['1400' => 'PE-001']);

        $this->assertSame('PE-001', ChartAccountMap::localFromGl('Relevant 1400 PPE account'));
    }

    /**
     * Report 74A. A company profile maps every posting function, so "the code is
     * mapped" stopped meaning "Finance pointed this class somewhere". Under WNG's
     * profile NE-018 took Client Deposits as its debit and NE-023 took Cost of
     * Sales – Materials, from codes their text only mentions.
     */
    public function test_a_code_merely_mentioned_in_the_text_never_resolves_however_fully_the_chart_is_mapped(): void
    {
        $this->withMap(['2200' => 'CD-001', '5100' => 'COS-008', '5800' => 'COS-023', '1010' => 'EQB-001', '1211' => 'WIP-002']);

        // NE-018: the debit is the bank or cash that received the money; 2200 is the CREDIT.
        $this->assertNull(ChartAccountMap::localFromGl('Bank / Cash (credit is 2200 Client Deposits)'));
        // NE-023: a range of accounts, one of which is chosen per job.
        $this->assertNull(ChartAccountMap::localFromGl('Relevant 5100–5800 Cost of Sales account'));
        $this->assertNull(ChartAccountMap::localFromGl('Relevant 5100-5800 Cost of Sales account'));
        $this->assertNull(ChartAccountMap::localFromGl('Relevant 5100 to 5800 Cost of Sales account'));
        $this->assertNull(ChartAccountMap::localFromGl('5100–5800 Cost of Sales'), 'a leading range designates no single account either');
        // A mapped code anywhere but in the two designating positions.
        $this->assertNull(ChartAccountMap::localFromGl('Paid from 1010 or petty cash'));
        $this->assertNull(ChartAccountMap::localFromGl('Usually the same as 1211'));
        $this->assertNull(ChartAccountMap::localFromGl('See note 2200'));
    }

    public function test_the_two_ways_the_catalogue_designates_an_account_still_resolve(): void
    {
        $this->withMap(['1211' => 'WIP-002', '7150' => 'OPE-030', '1400' => 'PPE-001']);

        $this->assertSame('WIP-002', ChartAccountMap::localFromGl('1211 Project WIP – Direct Materials'));
        $this->assertSame('OPE-030', ChartAccountMap::localFromGl('7150 Office Supplies & Stationery'));
        $this->assertSame('7800', ChartAccountMap::localFromGl('7800 Bank & Mobile-money Charges'), 'unmapped: resolves to itself');
        $this->assertSame('PPE-001', ChartAccountMap::localFromGl('Relevant 1400 PPE account'), 'a class Finance mapped on purpose');
        $this->assertNull(ChartAccountMap::localFromGl('Relevant 1500 Hire Asset account'), 'a class nobody mapped waits for a person');
    }

    public function test_a_blank_mapping_falls_back_rather_than_erasing_the_account(): void
    {
        // A half-filled config template must not resolve to the empty string,
        // which would match no account and switch the code off.
        $this->withMap(['1211' => '']);

        $this->assertSame('1211', ChartAccountMap::local('1211'));
    }
}
