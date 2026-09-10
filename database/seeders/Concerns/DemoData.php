<?php

namespace Database\Seeders\Concerns;

/**
 * Marks a seeder as demo data: invented people, jobs and logins for an empty
 * developer database, and nothing a real company should ever hold.
 *
 * The guard sits on the seeder rather than only on the aggregate that calls it,
 * because `db:seed --class=` reaches a seeder directly and would walk straight
 * past a check further up.
 *
 * It refuses rather than warns. Several of these seeders upsert — one creates
 * accounts whose password is the word `password` and hands one of them the
 * Super Admin role — so a mistyped command on a production shell should do
 * nothing at all.
 */
trait DemoData
{
    protected function demoDataIsAllowed(): bool
    {
        if (app()->environment(['local', 'testing'])) {
            return true;
        }

        $this->command?->warn(sprintf(
            '%s skipped: demo data is seeded only in local and testing (this is %s).',
            class_basename(static::class),
            app()->environment(),
        ));

        return false;
    }
}
