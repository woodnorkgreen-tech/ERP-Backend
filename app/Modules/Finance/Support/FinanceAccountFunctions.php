<?php

namespace App\Modules\Finance\Support;

use Illuminate\Support\Facades\DB;

/**
 * Every ledger account the redesigned Finance posting code needs, by what it IS.
 *
 * The posting services used to carry these as private four-digit literals, one
 * class at a time, so no single place said which accounts Finance depends on or
 * why — and a company on its own chart (WNG keeps its QuickBooks chart, D3) had to
 * discover the list by meeting failures. Each function here is named by its
 * accounting meaning; its value is the REFERENCE chart code, which
 * {@see ChartAccountMap} then translates to the installation's own code
 * (config/finance_accounts.php `map`). The accounting meaning is authoritative;
 * the reference number is only the key the map is written against.
 *
 * Posting services take their codes from here, so the value each one posts to is
 * unchanged; what changed is that there is now one list to map, check and report.
 */
final class FinanceAccountFunctions
{
    // Cash and bank
    public const BANK_DEFAULT = '1010';
    public const PETTY_CASH_FLOAT = '1030';
    // Receivables and advances
    public const ACCOUNTS_RECEIVABLE = '1100';
    public const STAFF_ADVANCES = '1300';
    public const INPUT_VAT = '1330';
    // Stock and work in progress
    public const INVENTORY = '1200';
    public const WIP_DIRECT_MATERIALS = '1211';
    public const WIP_DIRECT_LABOUR = '1212';
    public const WIP_SUBCONTRACTORS = '1213';
    public const WIP_TRANSPORT_LOGISTICS = '1214';
    public const WIP_EQUIPMENT_SITE = '1215';
    public const WIP_PROJECT_UTILITIES = '1216';
    public const WIP_PROJECT_FACILITATION = '1217';
    public const WIP_VENUE_STATUTORY = '1218';
    public const WIP_REWORK_WARRANTY = '1219';
    // Liabilities
    public const ACCOUNTS_PAYABLE = '2100';
    public const OUTPUT_VAT = '2110';
    public const WHT_PAYABLE = '2120';
    public const PAYE_PAYABLE = '2130';
    public const STATUTORY_PAYABLE = '2140';
    public const ACCRUED_EXPENSES = '2150';
    public const NET_PAYROLL_PAYABLE = '2160';
    public const CLIENT_DEPOSITS = '2200';
    // Equity and revenue
    public const OPENING_BALANCE_EQUITY = '3900';
    public const PROJECT_REVENUE = '4100';
    // Cost of sales (WIP release targets; direct labour is also payroll's)
    public const COS_DIRECT_MATERIALS = '5100';
    public const COS_DIRECT_LABOUR = '5200';
    public const COS_SUBCONTRACTORS = '5300';
    public const COS_TRANSPORT_LOGISTICS = '5400';
    public const COS_EQUIPMENT_SITE = '5500';
    public const COS_PROJECT_UTILITIES = '5600';
    public const COS_PROJECT_FACILITATION = '5700';
    public const COS_VENUE_STATUTORY = '5800';
    public const COS_REWORK_WARRANTY = '5900';
    // Expenses
    public const INVENTORY_ADJUSTMENTS = '6800';
    public const SALARIES_EXPENSE = '7550';
    public const BANK_CHARGES = '7800';
    /**
     * Debit for a cost line that carries NO expense code and matches no posting rule
     * (historical/producer lines only — a coded line never falls through). This was a
     * query for "the first WIP-band or expense account by code", which on the
     * reference chart is always 1211; on any other chart it was an arbitrary account.
     * It is now that same reference account, resolved through the map, or a refusal.
     */
    public const UNCODED_COST_FALLBACK = self::WIP_DIRECT_MATERIALS;

    /**
     * function key => [reference code, meaning, what posts to it, workflows]
     *
     * @return array<string, array{code: string, meaning: string, used_by: string, workflows: list<string>}>
     */
    public static function all(): array
    {
        $f = fn (string $code, string $meaning, string $usedBy, array $workflows) => compact('code', 'meaning') + ['used_by' => $usedBy, 'workflows' => $workflows];

        return [
            'bank_default' => $f(self::BANK_DEFAULT, 'Default operating bank account', 'JournalPostingService (payments with no paying account); PaymentSourceSeeder', ['W2', 'W4', 'W5']),
            'petty_cash_float' => $f(self::PETTY_CASH_FLOAT, 'Petty cash float (cash on hand)', 'JournalPostingService (top-ups, replenishment); PaymentSourceSeeder', ['W3', 'W5']),
            'accounts_receivable' => $f(self::ACCOUNTS_RECEIVABLE, 'Amounts clients owe', 'ReceivablesPostingService', ['W1']),
            'staff_advances' => $f(self::STAFF_ADVANCES, 'Staff advances / imprest (asset)', 'JournalPostingService (requisition advances, surrender)', ['W3', 'W5']),
            'input_vat' => $f(self::INPUT_VAT, 'Input VAT recoverable (asset)', 'JournalPostingService (supplier invoices, costs with VAT)', ['W2', 'W3', 'W5']),
            'inventory' => $f(self::INVENTORY, 'Raw-material inventory (asset)', 'JournalPostingService (GRN accrual, stock issue); StockMovementPostingService', ['W2']),
            'wip_direct_materials' => $f(self::WIP_DIRECT_MATERIALS, 'Project work in progress: direct materials', 'Expense catalogue; WorkInProgressReleaseService; uncoded-cost fallback', ['W2', 'W3', 'W5', 'W6']),
            'wip_direct_labour' => $f(self::WIP_DIRECT_LABOUR, 'Project work in progress: direct labour', 'Expense catalogue (DL-*); W7 labour actuals; WorkInProgressReleaseService', ['W6', 'W7']),
            'wip_subcontractors' => $f(self::WIP_SUBCONTRACTORS, 'Project WIP: subcontractors', 'Expense catalogue; WorkInProgressReleaseService', ['W2', 'W6']),
            'wip_transport_logistics' => $f(self::WIP_TRANSPORT_LOGISTICS, 'Project WIP: transport and logistics', 'Expense catalogue; WorkInProgressReleaseService', ['W3', 'W5', 'W6']),
            'wip_equipment_site' => $f(self::WIP_EQUIPMENT_SITE, 'Project WIP: equipment and site', 'Expense catalogue; WorkInProgressReleaseService', ['W2', 'W6']),
            'wip_project_utilities' => $f(self::WIP_PROJECT_UTILITIES, 'Project WIP: project utilities', 'Expense catalogue; WorkInProgressReleaseService', ['W3', 'W6']),
            'wip_project_facilitation' => $f(self::WIP_PROJECT_FACILITATION, 'Project WIP: project facilitation (meals, accommodation, per diem)', 'Expense catalogue; WorkInProgressReleaseService', ['W3', 'W5', 'W6']),
            'wip_venue_statutory' => $f(self::WIP_VENUE_STATUTORY, 'Project WIP: venue and statutory', 'Expense catalogue; WorkInProgressReleaseService', ['W3', 'W6']),
            'wip_rework_warranty' => $f(self::WIP_REWORK_WARRANTY, 'Project WIP: rework and warranty', 'Expense catalogue; WorkInProgressReleaseService', ['W6']),
            'accounts_payable' => $f(self::ACCOUNTS_PAYABLE, 'Amounts owed to suppliers and payees', 'JournalPostingService; SpendVoucherController (liability allocation)', ['W2', 'W4']),
            'output_vat' => $f(self::OUTPUT_VAT, 'Output VAT payable', 'ReceivablesPostingService', ['W1']),
            'wht_payable' => $f(self::WHT_PAYABLE, 'Withholding tax payable', 'JournalPostingService; FinanceTaxSeeder', ['W2', 'W4']),
            'paye_payable' => $f(self::PAYE_PAYABLE, 'PAYE payable', 'PayrollFinancePostingService', ['Payroll']),
            'statutory_payable' => $f(self::STATUTORY_PAYABLE, 'Statutory deductions payable (NSSF, SHIF, housing levy)', 'PayrollFinancePostingService', ['Payroll']),
            'accrued_expenses' => $f(self::ACCRUED_EXPENSES, 'Goods received not yet invoiced / accrued expenses', 'JournalPostingService (GRN accrual); SpendVoucherController', ['W2', 'W4']),
            'net_payroll_payable' => $f(self::NET_PAYROLL_PAYABLE, 'Net pay owed to staff', 'PayrollFinancePostingService', ['Payroll']),
            'client_deposits' => $f(self::CLIENT_DEPOSITS, 'Client deposits received before revenue is earned', 'ReceivablesPostingService', ['W1']),
            'opening_balance_equity' => $f(self::OPENING_BALANCE_EQUITY, 'Opening balance equity (opening stock counts)', 'StockMovementPostingService', ['Stores']),
            'project_revenue' => $f(self::PROJECT_REVENUE, 'Project revenue', 'ReceivablesPostingService', ['W1']),
            'cos_direct_materials' => $f(self::COS_DIRECT_MATERIALS, 'Cost of sales: direct materials (WIP release)', 'WorkInProgressReleaseService', ['W6']),
            'cos_direct_labour' => $f(self::COS_DIRECT_LABOUR, 'Cost of sales: direct labour (WIP release; payroll direct labour)', 'WorkInProgressReleaseService; PayrollFinancePostingService', ['W6', 'Payroll']),
            'cos_subcontractors' => $f(self::COS_SUBCONTRACTORS, 'Cost of sales: subcontractors (WIP release)', 'WorkInProgressReleaseService', ['W6']),
            'cos_transport_logistics' => $f(self::COS_TRANSPORT_LOGISTICS, 'Cost of sales: transport and logistics (WIP release)', 'WorkInProgressReleaseService', ['W6']),
            'cos_equipment_site' => $f(self::COS_EQUIPMENT_SITE, 'Cost of sales: equipment and site (WIP release)', 'WorkInProgressReleaseService', ['W6']),
            'cos_project_utilities' => $f(self::COS_PROJECT_UTILITIES, 'Cost of sales: project utilities (WIP release)', 'WorkInProgressReleaseService', ['W6']),
            'cos_project_facilitation' => $f(self::COS_PROJECT_FACILITATION, 'Cost of sales: project facilitation (WIP release)', 'WorkInProgressReleaseService', ['W6']),
            'cos_venue_statutory' => $f(self::COS_VENUE_STATUTORY, 'Cost of sales: venue and statutory (WIP release)', 'WorkInProgressReleaseService', ['W6']),
            'cos_rework_warranty' => $f(self::COS_REWORK_WARRANTY, 'Cost of sales: rework and warranty (WIP release)', 'WorkInProgressReleaseService', ['W6']),
            'inventory_adjustments' => $f(self::INVENTORY_ADJUSTMENTS, 'Inventory adjustments and shrinkage', 'StockMovementPostingService (stock counts)', ['Stores']),
            'salaries_expense' => $f(self::SALARIES_EXPENSE, 'Salaries and wages: office and admin staff (overhead)', 'PayrollFinancePostingService', ['Payroll']),
            'bank_charges' => $f(self::BANK_CHARGES, 'Bank and mobile-money transaction charges', 'JournalPostingService (payment fees)', ['W3', 'W4', 'W5']),
        ];
    }

    /**
     * How each function resolves against the connected chart right now: the local code
     * the map gives it, and whether that account exists, is postable and active.
     *
     * @return array<string, array{code: string, local_code: string, meaning: string, workflows: list<string>, resolved: bool, account: ?string}>
     */
    public static function resolution(?string $connection = null): array
    {
        $functions = self::all();
        $locals = array_map(fn ($f) => ChartAccountMap::local($f['code']), $functions);
        $accounts = DB::connection($connection)->table('chart_of_accounts')
            ->whereIn('code', array_values($locals))->where('is_postable', true)->where('is_active', true)
            ->pluck('name', 'code');

        $out = [];
        foreach ($functions as $key => $f) {
            $local = $locals[$key];
            $out[$key] = [
                'code' => $f['code'],
                'local_code' => $local,
                'meaning' => $f['meaning'],
                'workflows' => $f['workflows'],
                'resolved' => $accounts->has($local),
                'account' => $accounts->get($local),
            ];
        }

        return $out;
    }
}
