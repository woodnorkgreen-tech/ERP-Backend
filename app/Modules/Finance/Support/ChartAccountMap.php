<?php

namespace App\Modules\Finance\Support;

use App\Modules\Finance\Governance\GovernanceRuntime;

/**
 * Translates the catalogue's reference account codes into the ones this
 * installation actually keeps.
 *
 * The expense catalogue names its posting destinations by four-digit code. That
 * is a reference chart, not a requirement: a company keeping its books under
 * other codes is answered by config/finance_accounts.php rather than by editing
 * the catalogue. Everything that turns a reference code into a real account
 * goes through here, so the seeder and the readiness check cannot disagree
 * about where a code posts.
 *
 * Unmapped codes resolve to themselves. That keeps an empty map meaning "the
 * two charts agree" — true in development and in the test suite — and makes
 * adding a mapping additive rather than a change of behaviour for codes that
 * already resolved.
 */
class ChartAccountMap
{
    /** The local chart code for one reference code. */
    public static function local(string $reference): string
    {
        // Approved Finance configuration first (Report 75): an account mapping the
        // accountant has approved and activated, and the WIP policy in force. This is
        // the one adapter between governed decisions and the map; everything that
        // resolves an account already comes through here.
        $governed = GovernanceRuntime::instance()->account($reference);
        if ($governed !== null) {
            return $governed;
        }

        $mapped = config('finance_accounts.map.'.$reference);

        return filled($mapped) ? (string) $mapped : $reference;
    }

    /**
     * The local chart codes for many reference codes, in the same order.
     *
     * Used where a list of required accounts is checked in one query.
     */
    public static function localMany(array $references): array
    {
        return array_map(static fn (string $reference) => self::local($reference), $references);
    }

    /**
     * The local account a catalogue row DESIGNATES as its debit, or null.
     *
     * The catalogue has exactly two ways of designating an account, and this reads
     * only those. Nothing else in the text is an instruction:
     *
     *  1. it LEADS with the code — "1211 Project WIP – Direct Materials". That is
     *     the row's debit account, translated through the map;
     *  2. it names one class by its header — "Relevant 1400 PPE account" — for a
     *     person to choose within. That stays unresolved unless this installation
     *     maps that very code, which is how Finance says "post the class here".
     *
     * Everything else is null: "Receiving cash/bank account" names no code;
     * "Relevant 5100–5800 Cost of Sales account" names a range, which no single
     * account answers; and "Bank / Cash (credit is 2200 Client Deposits)" only
     * MENTIONS a code, and the credit side at that.
     *
     * It used to take the first four digits found anywhere and accept them when the
     * code was mapped. That was sound against a map holding a handful of deliberate
     * entries. A company profile maps every posting function, so every code a row
     * happened to mention became "explicitly mapped": NE-018 took Client Deposits
     * as its DEBIT, and NE-023 took Cost of Sales – Materials for a transfer whose
     * account is chosen per job (Report 74A). A mention is not a designation.
     */
    public static function localFromGl(?string $gl): ?string
    {
        if (blank($gl)) {
            return null;
        }
        // A code followed by a second one ("5100–5800", "5100 to 5800") is a range.
        $single = '(\d{4})\b(?!\s*(?:[-–—\/]|to\b)\s*\d{4})';

        if (preg_match('/^\s*'.$single.'/u', $gl, $matches)) {
            return self::local($matches[1]);
        }
        if (preg_match('/^\s*Relevant\s+'.$single.'/iu', $gl, $matches) && filled(self::all()[$matches[1]] ?? null)) {
            return self::local($matches[1]);
        }

        return null;
    }

    /** Every reference code this installation redirects. Empty when the charts agree. */
    public static function all(): array
    {
        return (array) config('finance_accounts.map', []);
    }
}
