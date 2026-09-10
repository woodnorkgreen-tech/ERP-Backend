<?php

namespace App\Console\Commands;

use App\Modules\HR\Models\Employee;
use Illuminate\Console\Command;

/**
 * Maps the attendance device's person ids onto employees, by name.
 *
 * This was a seeder, and it is not one: it carries the person list from a single
 * Hikvision export taken in May 2026 and matches it against whoever is in the
 * employees table now. That is a backfill — run once against a known state,
 * read its output, done — and it sat in database/seeders where `db:seed` could
 * reach it and where nobody would think to look for it.
 *
 * It never overwrites: an employee already carrying a different person id is
 * reported as a conflict and skipped, as is an ambiguous name. Reading the
 * output is the point, so nothing here is silent.
 */
class MapHikvisionIdsToEmployees extends Command
{
    protected $signature = 'hr:map-hikvision-ids';

    protected $description = 'Match the May 2026 Hikvision person ids onto employees by name';

    /**
     * Persons that couldn't be auto-matched (single-word name or ambiguous).
     * Each entry: person_id => [first, last]  (null = skip that token in the WHERE clause).
     * The seeder confirms uniqueness at runtime before writing.
     */
    private array $forceMappings = [
        '33622821' => ['first' => 'Beth',    'last' => null],      // "Beth" — single word on device
        '28126777' => ['first' => 'Francis', 'last' => 'Kimwaki'],  // device shows "Francis Mwangi" but DB surname is Kimwaki
    ];

    /**
     * All unique persons extracted from the May 2026 Hikvision attendance export.
     * Person IDs are strings (device-internal fingerprint IDs).
     */
    private array $persons = [
        ['id' => '1139682',   'name' => 'Harison'],
        ['id' => '20036608',  'name' => 'Ledunda James'],
        ['id' => '22424985',  'name' => 'Reuben Olubayi'],
        ['id' => '22863904',  'name' => 'Timothy Wepukhulu'],
        ['id' => '23174656',  'name' => 'Dorcas Mararo'],
        ['id' => '23222624',  'name' => 'Nyabera Allan'],
        ['id' => '24415002',  'name' => 'Francis Musyoka'],
        ['id' => '24814178',  'name' => 'Rita Veronica'],
        ['id' => '24836508',  'name' => 'Grace Yieke'],
        ['id' => '24921669',  'name' => 'Danson Thuku'],
        ['id' => '25362823',  'name' => 'Kennedy Malemba'],
        ['id' => '25458215',  'name' => 'Benson Gwaka'],
        ['id' => '25661952',  'name' => 'Wilson Mwangi'],
        ['id' => '25687538',  'name' => '25687538'],           // name same as ID — no real name registered
        ['id' => '25728028',  'name' => 'Onsare Vincent'],
        ['id' => '27023806',  'name' => 'Timothy Chesi'],
        ['id' => '27353928',  'name' => 'Mutale Jaspah'],
        ['id' => '28126777',  'name' => 'Francis Mwangi'],
        ['id' => '28277294',  'name' => 'Felix Mutisya'],
        ['id' => '28958139',  'name' => 'Ronald Bulemi'],
        ['id' => '29571379',  'name' => 'Jacob'],
        ['id' => '29750558',  'name' => 'Milka Adhiambo'],
        ['id' => '29863404',  'name' => 'Jemimah Wanjiru'],
        ['id' => '30041923',  'name' => 'George Charagu'],
        ['id' => '30131596',  'name' => 'Damnian Aloo'],
        ['id' => '30227232',  'name' => 'Nancy Bisieri'],
        ['id' => '30276134',  'name' => 'Bonface'],
        ['id' => '30311672',  'name' => 'Wilson Sanya'],
        ['id' => '31338475',  'name' => 'Caroline Mbuu'],
        ['id' => '31824442',  'name' => 'Kevin Mulika'],
        ['id' => '32491661',  'name' => 'Daniel Mwangi'],
        ['id' => '32504645',  'name' => 'Mary Karanja'],
        ['id' => '32683127',  'name' => 'Hr'],                 // device admin card — skip
        ['id' => '33130814',  'name' => 'Boaz Karani'],
        ['id' => '33505042',  'name' => 'Cosmas Asango'],
        ['id' => '33622821',  'name' => 'Beth'],
        ['id' => '34100689',  'name' => 'Geoffrey Kingori'],
        ['id' => '34201469',  'name' => 'Godfrey G'],          // "GodfreyG" on device
        ['id' => '35347550',  'name' => 'Musyoki Titus'],
        ['id' => '35850142',  'name' => 'Antony Mutinda'],
        ['id' => '36313963',  'name' => 'Ombeta Dennis'],
        ['id' => '36706196',  'name' => 'Clinton Mwangi'],
        ['id' => '37023146',  'name' => 'Swaleh'],
        ['id' => '37214476',  'name' => 'Kitui Duncan'],
        ['id' => '37319835',  'name' => 'Winnie Adhiambo'],
        ['id' => '37630495',  'name' => 'Victor'],
        ['id' => '37796725',  'name' => 'Simon Kepha'],
        ['id' => '38179991',  'name' => 'Tabitha Amoit'],
        ['id' => '38252972',  'name' => 'Daniel Kamau'],
        ['id' => '38279515',  'name' => 'Silvester Mwanzia'],
        ['id' => '38607194',  'name' => 'Mwaniki John'],
        ['id' => '38740259',  'name' => 'Yegon'],
        ['id' => '38759303',  'name' => 'Oscar Otieno'],
        ['id' => '38974352',  'name' => 'Moses Ndolo'],
        ['id' => '39026344',  'name' => 'Mundia John'],
        ['id' => '39202697',  'name' => 'Douglas Otieno'],
        ['id' => '39262977',  'name' => 'Caleb Ngeywo'],
        ['id' => '39339449',  'name' => 'Ambesa Blaury'],
        ['id' => '39535086',  'name' => 'Shadrack Mutuku'],
        ['id' => '39790985',  'name' => 'David Kibe'],
        ['id' => '39866735',  'name' => 'Ochola Brighton'],
        ['id' => '39873605',  'name' => 'Daniel Wafula'],      // "Daniel Wafula Wayongo" on device
        ['id' => '39930108',  'name' => 'Simon Gachuki'],
        ['id' => '39971758',  'name' => 'Ann Muthoni'],
        ['id' => '40136497',  'name' => 'Denis Kipngetich'],
        ['id' => '40176354',  'name' => 'John Thairu'],
        ['id' => '40788362',  'name' => 'Aloice Omwa'],
        ['id' => '41080561',  'name' => 'Wabwile John'],
        ['id' => '41210352',  'name' => 'Mercy'],
        ['id' => '41247791',  'name' => 'Diana Makori'],
        ['id' => '41270534',  'name' => 'Kevin Asava'],
        ['id' => '41915094',  'name' => 'Stephen Nyachwaya'],
        ['id' => '42052828',  'name' => 'Griffine'],
        ['id' => '42340226',  'name' => 'Kingori James'],
        ['id' => '42871866',  'name' => 'Maende Kevin'],
        ['id' => '44345060',  'name' => 'Gift Juma'],
        ['id' => '54317427',  'name' => 'Kalabai Nehemiah'],
        ['id' => '87040341',  'name' => 'Derrick Mwendo'],
    ];

    public function handle(): int
    {
        $employees = Employee::select('id', 'first_name', 'last_name', 'hikvision_id')->get();

        $byHikId   = $employees->whereNotNull('hikvision_id')->keyBy('hikvision_id');
        $nameIndex = $this->buildNameIndex($employees);

        $matched       = 0;
        $alreadyMapped = 0;
        $ambiguous     = 0;
        $noMatch       = 0;

        foreach ($this->persons as $p) {
            $personId   = $p['id'];
            $personName = $p['name'];

            // Already mapped to this exact person_id
            if ($byHikId->has($personId)) {
                $alreadyMapped++;
                $this->out("<comment>SKIP already-mapped:</comment> {$personId} {$personName}");
                continue;
            }

            $candidates = $this->matchByName($personName, $nameIndex);

            if (count($candidates) === 1) {
                $emp = $candidates[0];
                // Skip if this employee is already mapped to a different id
                if ($emp->hikvision_id && $emp->hikvision_id !== $personId) {
                    $this->out("<comment>SKIP conflict:</comment> {$personId} {$personName} — employee #{$emp->id} already has id {$emp->hikvision_id}");
                    $noMatch++;
                    continue;
                }
                Employee::where('id', $emp->id)->update(['hikvision_id' => $personId]);
                $matched++;
                $this->out("<info>MATCHED:</info> {$personId}  {$personName}  →  {$emp->first_name} {$emp->last_name} [#{$emp->id}]");
            } elseif (count($candidates) > 1) {
                $ambiguous++;
                $names = implode(', ', array_map(fn($e) => "{$e->first_name} {$e->last_name}", $candidates));
                $this->out("<error>AMBIGUOUS:</error> {$personId} {$personName}  — matches: {$names}");
            } else {
                $noMatch++;
                $this->out("<error>NO MATCH:</error>  {$personId} {$personName}");
            }
        }

        // ── Force mappings (single-word names / confirmed-unique overrides) ──────
        $this->out('');
        $this->out('── Force mappings ──────────────────────────────────────────────────');
        $forceMatched = 0;
        // Re-fetch so $byHikId reflects updates made above
        $byHikIdFresh = Employee::select('id', 'first_name', 'last_name', 'hikvision_id')
            ->whereNotNull('hikvision_id')->get()->keyBy('hikvision_id');

        foreach ($this->forceMappings as $personId => $name) {
            if ($byHikIdFresh->has($personId)) {
                $this->out("<comment>SKIP already-mapped (force):</comment> {$personId}");
                continue;
            }

            $query = Employee::query();
            if ($name['first'] !== null) {
                $query->where('first_name', 'like', $name['first']);
            }
            if ($name['last'] !== null) {
                $query->where('last_name', 'like', $name['last']);
            }
            $hits = $query->get();

            if ($hits->count() === 1) {
                $emp = $hits->first();
                if ($emp->hikvision_id && $emp->hikvision_id !== $personId) {
                    $this->out("<comment>SKIP conflict (force):</comment> {$personId} — employee #{$emp->id} already has id {$emp->hikvision_id}");
                    continue;
                }
                Employee::where('id', $emp->id)->update(['hikvision_id' => $personId]);
                $forceMatched++;
                $this->out("<info>FORCE-MATCHED:</info> {$personId}  →  {$emp->first_name} {$emp->last_name} [#{$emp->id}]");
            } elseif ($hits->count() === 0) {
                $this->out("<error>FORCE NO MATCH:</error> {$personId} — no employee found for given name tokens");
            } else {
                $names = $hits->map(fn($e) => "{$e->first_name} {$e->last_name}")->implode(', ');
                $this->out("<error>FORCE AMBIGUOUS:</error> {$personId} — {$hits->count()} employees match: {$names}");
            }
        }

        $total = count($this->persons);
        $this->out('');
        $this->out("<info>Done.</info>  Total: {$total}  |  Matched: {$matched}  |  Force-matched: {$forceMatched}  |  Already mapped: {$alreadyMapped}  |  Ambiguous: {$ambiguous}  |  No match: {$noMatch}");

        if ($noMatch > 0 || $ambiguous > 0) {
            $this->out('');
            $this->out('Unresolved persons can be linked manually via the employee profile edit form (Hikvision Device ID field).');
        }

        // Zero regardless: an unmatched or ambiguous name is a name for somebody
        // to look at, not a failed run.
        return self::SUCCESS;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildNameIndex($employees): array
    {
        $index = [];
        foreach ($employees as $emp) {
            $fn = $this->norm($emp->first_name);
            $ln = $this->norm($emp->last_name);
            if ($fn === '' || $ln === '') {
                continue;
            }
            $k1 = "{$fn}|{$ln}";
            $k2 = "{$ln}|{$fn}";
            $index[$k1][] = $emp;
            if ($k1 !== $k2) {
                $index[$k2][] = $emp;
            }
        }
        return $index;
    }

    /**
     * Try to match a device person name against the employee name index.
     * Splits on underscores or spaces, tries all consecutive 2-token pairs in both orderings.
     * Returns array of unique matching Employee models.
     */
    private function matchByName(string $personName, array $nameIndex): array
    {
        $tokens = preg_split('/[_\s]+/', trim($personName));
        $tokens = array_values(array_filter(array_map('trim', $tokens)));

        if (count($tokens) < 2) {
            return []; // Single-word names can't be matched reliably
        }

        $seen       = [];
        $candidates = [];

        // Try every consecutive pair of tokens (handles 2-word and 3-word names)
        for ($i = 0; $i < count($tokens) - 1; $i++) {
            $a = $this->norm($tokens[$i]);
            $b = $this->norm($tokens[$i + 1]);

            foreach (["{$a}|{$b}", "{$b}|{$a}"] as $key) {
                foreach ($nameIndex[$key] ?? [] as $emp) {
                    if (!isset($seen[$emp->id])) {
                        $seen[$emp->id] = true;
                        $candidates[]   = $emp;
                    }
                }
            }
        }

        return $candidates;
    }

    private function norm(string $s): string
    {
        return strtolower(trim(str_replace(['-', "'", '.', ' '], '', $s)));
    }

    private function out(string $msg): void
    {
        $this->line($msg);
    }
}
