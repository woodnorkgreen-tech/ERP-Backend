<?php

namespace App\Modules\Finance\Support;

/**
 * Turns the expense catalogue's departmental and stage wording into the
 * dimension rows the cost ledger actually stores.
 *
 * `expense_codes` carries both spellings of each dimension on purpose — the
 * catalogue's own words in `default_cost_centre` / `project_activity`, and a
 * foreign key beside each. The words came from the source spreadsheet and are
 * what a reviewer reads; the keys are what reporting groups on. Only the words
 * were ever written. Every cost line in the system therefore carried a null
 * cost centre while eighteen cost centres and twenty-seven activities sat in
 * their tables unreferenced, and the `cost_centre_id` filter on the cost
 * account and the cost centre shown on the verification screen were dead.
 *
 * They could not be joined automatically, which is why this class exists rather
 * than a LIKE:
 *
 * - The catalogue names PAIRS of departments — "Production / Stores", "Stores /
 *   Production", "Finance / Admin". The dimension is a single owning centre.
 *   The rule applied here is **the first department named owns the cost**, which
 *   is how the catalogue's own examples read: material bought for a job is
 *   Production's cost that Stores handles, and stock bought to hold is Stores'
 *   cost that Production will draw on. That is why the two orderings are not
 *   duplicates and are not collapsed into one.
 * - Two entries name no department at all. "Asset-owning department" is a
 *   genuine variable — it depends which department the asset lands in — and
 *   resolves to null rather than to a guess.
 * - The stage wording is close to the activity names but not equal to them
 *   ("Fabrication / Production" against FABRICATION, "Site Works" against
 *   INSTALLATION), so it is mapped explicitly instead of matched on text.
 *
 * The seeder and the backfill migration both resolve through here, so a code
 * seeded today and a cost line repaired from history cannot disagree.
 */
class CatalogueDimensionMap
{
    /**
     * Catalogue department wording → cost centre code.
     *
     * A value of null means the catalogue genuinely does not name one. Absent
     * keys are a catalogue row this map has not been taught yet, which the
     * readiness check reports rather than silently defaulting.
     */
    private const COST_CENTRES = [
        'Asset-owning department' => null,
        'Facilities' => 'FAC',
        'Finance' => 'FIN',
        'Finance / Admin' => 'FIN',
        'Finance / Facilities' => 'FIN',
        'Finance / Project' => 'FIN',
        'Finance / Sales' => 'FIN',
        'Hire Assets' => 'HIRE',
        'Logistics' => 'LOG',
        'Production / Site' => 'PROD',
        'Production / Stores' => 'PROD',
        'Production / Workshop' => 'PROD',
        'Projects' => 'PROJ',
        'Stores' => 'STORES',
        'Stores / Production' => 'STORES',
    ];

    /** Catalogue stage wording → activity code. */
    private const ACTIVITIES = [
        'Administration' => 'ADMINISTRATION',
        'Asset build / acquisition' => 'ASSET-BUILD',
        'Capital purchase' => 'CAPITAL-PURCH',
        'Capital works' => 'CAPITAL-WORKS',
        'Client billing' => 'CLIENT-BILL',
        'Fabrication / Production' => 'FABRICATION',
        'Financial close' => 'FIN-CLOSE',
        'Financing' => 'FINANCING',
        'Logistics / Delivery' => 'LOGISTICS',
        'Not Applicable' => 'NA',
        'Procurement / Receiving' => 'RECEIVING',
        'Production' => 'PRODUCTION',
        'Production return' => 'PROD-RETURN',
        'Site Works' => 'INSTALLATION',
        'Tax' => 'TAX',
    ];

    /** The cost centre code for a catalogue department phrase, or null. */
    public static function costCentreCode(?string $wording): ?string
    {
        return self::lookup(self::COST_CENTRES, $wording);
    }

    /** The activity code for a catalogue stage phrase, or null. */
    public static function activityCode(?string $wording): ?string
    {
        return self::lookup(self::ACTIVITIES, $wording);
    }

    /**
     * Catalogue phrases this map does not recognise, out of the ones given.
     *
     * The readiness check reports these. A phrase added to the catalogue without
     * a mapping would otherwise resolve to null and look exactly like the two
     * rows that are meant to be null.
     *
     * @param  iterable<?string>  $wordings
     * @return list<string>
     */
    public static function unmappedCostCentres(iterable $wordings): array
    {
        return self::unmapped(self::COST_CENTRES, $wordings);
    }

    /**
     * @param  iterable<?string>  $wordings
     * @return list<string>
     */
    public static function unmappedActivities(iterable $wordings): array
    {
        return self::unmapped(self::ACTIVITIES, $wordings);
    }

    /**
     * Matched on the trimmed phrase, case-insensitively.
     *
     * The catalogue was typed by hand across three files and the same department
     * appears with different capitalisation in different blocks. Matching
     * exactly would leave those rows unmapped for a reason no reader could see.
     */
    private static function lookup(array $map, ?string $wording): ?string
    {
        if (blank($wording)) {
            return null;
        }

        foreach ($map as $phrase => $code) {
            if (strcasecmp(trim($phrase), trim($wording)) === 0) {
                return $code;
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function unmapped(array $map, iterable $wordings): array
    {
        $known = array_map(static fn ($phrase) => mb_strtolower(trim((string) $phrase)), array_keys($map));
        $missing = [];

        foreach ($wordings as $wording) {
            if (blank($wording)) {
                continue;
            }

            if (! in_array(mb_strtolower(trim((string) $wording)), $known, true)) {
                $missing[trim((string) $wording)] = true;
            }
        }

        return array_keys($missing);
    }
}
