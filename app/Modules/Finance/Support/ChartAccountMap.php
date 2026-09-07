<?php

namespace App\Modules\Finance\Support;

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
     * Reads the reference code out of the catalogue's GL prose and answers with
     * the local code, or null when the text names an account indirectly
     * ("Relevant 1400 PPE account", "Receiving cash/bank account").
     *
     * Those stay unresolved on purpose: they describe a class of account for a
     * human to choose within, and resolving one to a header would hand the
     * posting engine an account it cannot post to.
     */
    public static function localFromGl(?string $gl): ?string
    {
        if (blank($gl) || ! preg_match('/\b(\d{4})\b/', $gl, $matches)) {
            return null;
        }

        return self::local($matches[1]);
    }

    /** Every reference code this installation redirects. Empty when the charts agree. */
    public static function all(): array
    {
        return (array) config('finance_accounts.map', []);
    }
}
