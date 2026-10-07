<?php
require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Modules\Finance\Support\FinanceAccountFunctions;
use App\Modules\Finance\Support\FinanceChartProfile;
$out = ['database' => DB::connection()->getDatabaseName(), 'environment' => app()->environment(), 'checked_at' => now()->toIso8601String(), 'profile' => config('finance_accounts.profile'), 'wip_mode' => config('finance_accounts.wip_policy'), 'account_functions' => FinanceAccountFunctions::resolution(), 'profile_problems' => FinanceChartProfile::problems(config('finance_accounts.profile'), config('finance_accounts.wip_policy'))];
foreach (['chart_of_accounts','posting_rules','finance_settings','accounting_periods','payment_sources','receiving_channels','expense_codes','document_sequences','vat_treatments','tax_rates','wht_rates','petty_cash_settings','finance_policy_decisions','payroll_settings','wht_categories','employee_salary_histories','department_labour_classifications'] as $table) {
 $out['tables'][$table] = Schema::hasTable($table) ? ['count' => DB::table($table)->count(), 'columns' => Schema::getColumnListing($table)] : ['state' => 'NOT_AVAILABLE'];
}
foreach (['finance_settings','accounting_periods','payment_sources','document_sequences'] as $table) {
 if (!Schema::hasTable($table)) continue;
 $cols = array_values(array_intersect(['key','value','approved_by','approved_at','effective_from','effective_to','code','name','type','is_active','gl_account_id','status','year','starts_on','ends_on','prefix','next_number'], Schema::getColumnListing($table)));
 if ($cols) $out['records'][$table] = DB::table($table)->select($cols)->get();
}
$out['chart_classification_gaps'] = DB::table('chart_of_accounts')->where('is_active',true)->where('is_postable',true)->where(fn($q) => $q->whereNull('account_type')->orWhereNull('normal_balance'))->count();
$out['cash_flow'] = app(App\Modules\Finance\Services\BankCashReportingService::class)->cashFlowReadiness();
$user = App\Models\User::permission('finance.reports.view')->first();
if ($user) {
 $request = Illuminate\Http\Request::create('/api/finance/readiness');
 $request->setUserResolver(fn () => $user);
 $out['readiness_response'] = app(App\Modules\Finance\Controllers\FinanceReadinessController::class)->show($request)->getData(true)['data'];
}
$payrollUser = App\Models\User::permission('finance.payroll.read')->first();
if ($payrollUser) {
 $request = Illuminate\Http\Request::create('/api/finance/payroll/readiness');
 $request->setUserResolver(fn () => $payrollUser);
 $out['payroll_readiness'] = app(App\Modules\Finance\Controllers\PayrollFinanceController::class)->readiness($request)->getData(true)['data'];
}
$out['wng_profile'] = FinanceChartProfile::load('wng');
$out['wng_functions'] = [];
$wngMap = FinanceChartProfile::map('wng', null);
$chart = DB::table('chart_of_accounts')->get()->keyBy('code');
foreach (FinanceAccountFunctions::all() as $key => $function) {
 $local = $wngMap[$function['code']] ?? null;
 $account = $local ? $chart->get($local) : null;
 $out['wng_functions'][$key] = ['reference' => $function['code'], 'mapped_code' => $local, 'exists_now' => $account !== null, 'resolved_now' => $account && $account->is_active && $account->is_postable];
}
$out['wng_proposals'] = [];
foreach ($out['wng_profile']['new_accounts'] ?? [] as $account) {
 $exists = $chart->get($account['code']);
 $duplicate = $chart->first(fn ($row) => $row->code !== $account['code'] && mb_strtolower($row->name) === mb_strtolower($account['name']));
 $out['wng_proposals'][] = ['code' => $account['code'], 'name' => $account['name'], 'state' => $exists ? 'EXISTING_CODE_REVIEW_DEFINITION' : ($duplicate ? 'CONFLICT_DUPLICATE_NAME' : 'MISSING_PROPOSED'), 'duplicate_code' => $duplicate?->code];
}
file_put_contents(__DIR__.'/configuration-snapshot.json', json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo "Read-only snapshot saved for database {$out['database']}\n";
