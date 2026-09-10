# Seeding in production

Read on 9–10 September 2026. 36 seeder files: 34 seeders and two aggregators
(`DatabaseSeeder`, `FinanceReferenceSeeder`).

The deploy workflow already refused to seed, and said why:

```
# No db:seed. ChartOfAccountSeeder deletes accounts outside its own
# list, so seeding is a deliberate act against a known chart, never
# a step in shipping code.
```

That was the right call for `db:seed` as a whole, but it leaves a gap. Some of
these seeders **are** the definition of production reference data — statutory
PAYE bands, the expense catalogue, accounting periods — and nothing in the
pipeline asserts them. Others plant sixteen invented employees and seven
accounts whose password is `password`. Today both sit behind the same command,
so the safe move is to run neither, and the reference data drifts.

## The rule

Three kinds of thing are living in `database/seeders` and the module `Seeders`
directories, and they want opposite treatment:

- **Reference data** — the lists the application cannot work without, whose
  authority is the file, not the database. Should be re-asserted on every
  deploy. Must be idempotent and keyed on a natural code.
- **Demo data** — invented employees, clients, tasks and logins, for a
  developer's empty database. Must never reach production. None of them
  currently check.
- **One-off backfills** wearing a seeder's clothes — run once against a known
  state, then finished. These belong in `app/Console/Commands`, where
  `MigrateLegacyFinanceGate` and friends already live.

## The inventory

As found, before the changes under **What was done** below — so the "wired
into" column reads as it did on the day, not as it reads now.

### Reference data — safe to re-run on production

| Seeder | Holds | Keyed on | Wired into |
| --- | --- | --- | --- |
| `RoleAndPermissionSeeder` | every role and permission, from `RolePermissions::matrix()` | name; additive by design | `DatabaseSeeder` |
| `FinanceDimensionSeeder` | cost centres, activities, cost causes, payee types | code | `FinanceReferenceSeeder` |
| `FinanceTaxSeeder` | VAT treatments, WHT categories | code | `FinanceReferenceSeeder` |
| `PaymentSourceSeeder` | the paying-account master | code | `FinanceReferenceSeeder` |
| `FinanceSettingsSeeder` | finance settings | key + `effective_from` floor | `FinanceReferenceSeeder` |
| `AccountingPeriodSeeder` | monthly periods, 2024 → next year | year+month | `FinanceReferenceSeeder` |
| `ExpenseCodeSeeder` | the expense catalogue | code | `FinanceReferenceSeeder` |
| `PettyCashRequisitionTypeSeeder` | requisition types over the catalogue | code, `insertOrIgnore` | `FinanceReferenceSeeder` |
| `MaterialCategorySeeder` | material taxonomy | name | `DatabaseSeeder` |
| `WorkstationSeeder` | workstations | code | `DatabaseSeeder` |
| `DesignTypeSeeder` | graphic + structural design types | stream+name | `DatabaseSeeder` |
| `TeamCategoriesSeeder` | workshop / setup / setdown | `category_key` | `DatabaseSeeder` |
| `TeamTypesSeeder` | team types | `type_key` | `DatabaseSeeder` |
| `TeamCategoryTypesSeeder` | category↔type links | category + type id | `DatabaseSeeder` |
| `AssetCategorySeeder` | the WNG Asset Register picklist | name | **nothing** |
| `PayrollSeeder` | NSSF / SHIF / housing levy / personal relief / PAYE bands | name | **nothing** |

`FinanceSettingsSeeder` keys on the setting plus a fixed `effective_from`
floor, so a value Finance has since re-dated is left alone.

`AccountingPeriodSeeder` is worth a note in its favour: it upserts only
`starts_on`/`ends_on`, so a period Finance has locked stays locked. It does
rewrite `created_at` on every run, which is noise, not damage.

Two of these are reference data that no command reaches. `PayrollSeeder` is the
one that matters: it carries Kenya's statutory rates and tax bands, and a
payroll run on a database where it never ran computes against whatever is
there.

### Reference data whose implementation is not production-safe

| Seeder | Problem |
| --- | --- |
| `ChartOfAccountSeeder` | upserts its own 88-account numeric chart, then **deletes** every account outside that list which four named tables do not reference. WNG's live chart is 123 mostly-mnemonic accounts imported from QuickBooks — nearly all of them are outside the list. |
| `ElementTypeSeeder` | opens with `DB::table('element_types')->truncate()`, then `create()`s twelve rows. |
| `ElementTemplatesSeeder` | plain `create()` on a column that is UNIQUE, so the second run threw a duplicate-key error. Its materials are keyed by nothing, so any run that got that far added another copy of every line. |
| `TaskTemplateSeeder` | plain `create()` on a column that is only indexed, so every run added a fresh copy of all six templates. It also wrote `created_by => 1` with the comment "Assuming admin user exists". |
| `UniversalTaskSeeder` | picked `issue_type` at random from five values the migrated schema does not accept. |
| `TeamCategoryTypesSeeder` | named 27 pairings by surrogate id — `['category_id' => 1, 'team_type_id' => 4, …]` — so it was right only where the auto-increments happened to land on 1..3 and 1..9. |

`ChartOfAccountSeeder`'s docblock claims it "deactivates any account not in this
list rather than deleting it… so a code that once carried postings stays
resolvable". The code deactivates only when `isReferenced()` says so, and that
method checks four columns: `payment_sources.gl_account_id`,
`posting_rules.debit/credit_account_id`, `expense_codes.default_debit_account_id`
and `chart_of_accounts.parent_id`. Four more columns point at the chart and are
not checked:

| Column | FK delete rule | Consequence |
| --- | --- | --- |
| `journal_lines.account_id` | `RESTRICT` | the seeder aborts — postings are protected by the database, not by the seeder |
| `project_invoice_lines.revenue_account_id` | `SET NULL` | an issued invoice line silently loses its revenue account |
| `vat_treatments.gl_account_id` | `SET NULL` | a VAT treatment silently loses its GL account |
| `wht_categories.gl_account_id` | `SET NULL` | same for WHT |

So the ledger itself is safe, by `RESTRICT` rather than by intent — but the
three `SET NULL` columns are silent damage, and the docblock's promise is not
what the code does. Note also that the dev database carries these foreign keys;
production is a separately grown database and may not. Verify before trusting
`RESTRICT` to catch anything.

`TeamCategoryTypesSeeder` is the one that would have gone unnoticed longest.
The other three fail loudly; this one fails loudly only when the foreign key
happens to catch it. Where the ids resolve to *something*, it succeeds against
whatever rows they now point at, and a category quietly acquires another team
type's crew sizes. It is fixed to resolve through `category_key` and `type_key`,
which is how both parent seeders already identify their own rows.

`UniversalTaskSeeder`'s failure is worth its own note, because the seeder is
the messenger rather than the culprit. `task_issues.issue_type` is declared by
its migration with sixteen values (`bug`, `feature_request`, `improvement`, …,
`blocker`, `other`), and the dev database's column holds five entirely
different ones (`blocker`, `technical`, `resource`, `dependency`, `general`) —
evidently altered by hand at some point. Only `blocker` is in both. The seeder
wrote the dev vocabulary, so on a freshly migrated database four of its five
picks were truncated, and because it picked at random it failed intermittently.
**Which vocabulary is right is an open question for whoever owns the module**,
and worth checking against production before either list is trusted.

### Demo data — must never reach production

| Seeder | Plants | Wired into |
| --- | --- | --- |
| `EmployeeSeeder` | 16 invented employees with salaries, KRA PINs, NSSF/NHIF ids and bank accounts, plus a user each, password `password` | `DatabaseSeeder` |
| `SuperAdminUserSeeder` | `superadmin@company.com` / `password`, Super Admin role | `DatabaseSeeder` |
| `AdminUserSeeder` | `admin@company.com` / `password` | `DatabaseSeeder` |
| `HRUserSeeder` | `hr@company.com` / `password` | `DatabaseSeeder` |
| `ClientServiceUserSeeder` | `clientservice@company.com` / `password` | `DatabaseSeeder` |
| `DesignerUserSeeder` | `designer@company.com` / `password` | `DatabaseSeeder` |
| `ProjectsUserSeeder` | `pm@company.com`, `po@company.com` / `password` | `DatabaseSeeder` |
| `ClientSeeder` | invented clients (John Smith, Tech Solutions Ltd…) | `DatabaseSeeder` |
| `UniversalTaskSeeder` | invented tasks, comments, issues, dependencies, assigned to real users | `DatabaseSeeder` |
| `SampleTaskSeeder` | invented tasks, and users with password `password` if none exist | nothing |

Every one of these is reached by `php artisan db:seed`. None of them checked the
environment — `grep` for `app()->environment` across all 36 files returned
nothing.

Laravel's own `db:seed` does confirm before running when `APP_ENV=production`,
which is a real second rail. It is not enough on its own: `--force` walks past
it, and `--force` is exactly what a deploy script types.

Two collision notes, both established by reading the dev database:

- `EmployeeSeeder` matches on `employee_id` with values `EMP001`–`EMP016`. Real
  WNG employee ids are four-digit (`EMP0004`, `EMP0006`, …), so it adds rows
  rather than overwriting real people. That is luck, not design: it upserts,
  and a three-digit id would have replaced a real employee's name, salary, KRA
  PIN and bank account.
- The dev database holds 75 employees, 16 of them invented, and 23 accounts on
  `@company.com` — so demo and real data are already mixed there. Whether
  production is in the same state is the first thing to check.

`DepartmentSeeder` sits between the two categories. The department list is real
reference data, but it upserts the whole row including `'budget' => 0.00` and
`'location' => ''`, so a re-run resets every department budget and location
that HR or Finance has set.

### One-off backfills, misfiled

| File | What it is |
| --- | --- |
| `HikvisionIdSeeder` | maps 60-odd real device person-ids from the May 2026 attendance export onto employees. Production-relevant, run once, finished. |

### Dead and contradictory

`HRActionTypeSeeder` exists twice — `app/Modules/HR/Database/Seeders/` (8 types)
and `app/Modules/HR/Database/Seeds/` (5, differently named). Both autoload; nothing calls
either; their contents disagree. The disagreement has already leaked into the
UI, where `CareerHistoryTab.vue:233` reads

```js
if (t === 'SALARY_UPDATE' || t === 'SALARY_INCREMENT')
```

— one code from each file. `hr_action_types` holds a single row in dev
(`PROFILE_UPDATE`, which is in neither file), so neither seeder has ever run.

## What was done

**The entry point is split.** `db:seed` used to mean one thing — "a working
developer database" — so the only safe thing to do on production was to seed
nothing.

| Seeder | Means |
| --- | --- |
| `ReferenceDataSeeder` | the sixteen lists above. Safe on production, and meant to be run there. |
| `DemoDataSeeder` | invented people, jobs and logins. Refuses to run outside `local` and `testing`. |
| `DatabaseSeeder` | calls the first, then the second. Unchanged in meaning for developers. |

```
php artisan db:seed --class=ReferenceDataSeeder --force
```

**The demo guard sits on each seeder, not only on the aggregate**, via the
`Database\Seeders\Concerns\DemoData` trait. `--class=` addresses a seeder
directly and would walk straight past a check further up. It refuses rather
than warns: several of these upsert, and one of them hands Super Admin to an
account whose password is the word `password`.

**Whose chart of accounts this is became a question the config answers.**
`config/finance_accounts.php` gains `seed_reference_chart` — true outside
production, false in it, overridable by `FINANCE_SEED_REFERENCE_CHART` for a
genuinely fresh production install. `ChartOfAccountSeeder` asks before doing
anything, so `FinanceReferenceSeeder`'s promise that every child is safe to
re-run on every deploy is now true of all nine.

**The chart seeder's purge pass is gone.** It was written to clear a 120-row
export that had never received a posting; that export is long gone, and what
remained was a seeder that assumed it owned a chart it had never seen. It now
touches only the accounts it lists.

**The two orphans are wired in.** `PayrollSeeder` (the statutory rates) and
`AssetCategorySeeder` are in `ReferenceDataSeeder`.

**The five broken implementations are fixed.** `DepartmentSeeder` asserts
only the description, so a re-run no longer clears departmental budgets and
locations. `ElementTypeSeeder` upserts on its unique name instead of opening
with `truncate()`. `ElementTemplatesSeeder` and `TaskTemplateSeeder` upsert,
and the latter resolves a real owner rather than assuming user 1 exists.
`TeamCategoryTypesSeeder` resolves its pairings by key rather than by id.

**`HRActionTypeSeeder` is one file.** The `Database/Seeds/` copy is deleted and
the eight-type `Database/Seeders/` version — the list the career-history screen
was actually built against — is wired into `ReferenceDataSeeder`. The frontend's
`if (t === 'SALARY_UPDATE' || t === 'SALARY_INCREMENT')` is down to one code.

**`HikvisionIdSeeder` is now `php artisan hr:map-hikvision-ids`.** It is a
backfill, and it sat where `db:seed` could reach it.

Along the way: 33 `$this->command->info(...)` calls across twelve seeders are
now null-safe. `$this->command` is null whenever a seeder runs outside the
console, which is how every one of these behaves under test.

`tests/Feature/Seeding/SeedingGuardTest.php` covers the two properties that
have to hold: demo data refuses outside local and testing (as the aggregate and
as each seeder alone), and the reference chart is neither planted on nor purged
from a chart the ERP did not write.

## What remains a decision, not a task

- **The deploy workflow still does not seed.** The comment now names
  `ReferenceDataSeeder` and says why it is not automatic yet: `PayrollSeeder`
  and `AssetCategorySeeder` have never run on production, so the first run
  inserts rather than upserts and wants somebody watching. Run it by hand once,
  check the result, then add it to the pipeline.
- **`task_issues.issue_type`**: the migration and the live column declare
  different vocabularies. Settle which is right.
- **The chart of accounts**: unchanged by any of this. The sequence in
  `docs/chart-of-accounts-mapping.md` still stands — add the missing control
  accounts, decide the WIP question, fill in the map, re-run the catalogue
  seeder.

## Before anything else: check what production already holds

`db:seed` may have been run there at some point. These are read-only:

```sql
-- demo logins, all with the password `password`
SELECT id, name, email, is_active FROM users
 WHERE email LIKE '%@company.com' OR email LIKE '%@example.com';

-- invented employees (the three-digit ids)
SELECT employee_id, first_name, last_name, salary FROM employees
 WHERE employee_id REGEXP '^EMP[0-9]{3}$';

-- invented clients
SELECT id, full_name, company_name FROM clients
 WHERE email IN ('john.smith@company.com','sarah.johnson@email.com','michael.brown@corp.com');
```

Any rows from the first query are the urgent finding: `superadmin@company.com`
with a known password and the Super Admin role. Deactivate or delete those
before anything else in this document.

Invented employees are not only untidy — they carry salaries, so they land in
payroll totals, headcount and departmental cost. Removing them is a data
decision for HR and Finance, not a cleanup script.
