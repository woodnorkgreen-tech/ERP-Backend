<?php

namespace App\Support\SourceMigration;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The guards between this tooling and a live database.
 *
 * The source is the Stage 1 staging COPY on its own connection. It is never the
 * application's connection, never a database named as the live source, never the
 * same database as the target, and it is put into a read-only session before
 * anything reads it, so no code path in Stage 2 can write to it.
 */
class ConnectionGuard
{
    public function __construct(
        private readonly string $sourceConnection,
        private readonly string $targetConnection,
    ) {}

    public static function make(?string $targetConnection = null): self
    {
        return new self(
            (string) config('source_migration.source_connection', 'source_staging'),
            $targetConnection ?? (string) config('database.default'),
        );
    }

    public function sourceConnection(): string
    {
        return $this->sourceConnection;
    }

    public function targetConnection(): string
    {
        return $this->targetConnection;
    }

    /**
     * Everything that can be decided from configuration, before connecting.
     *
     * @return list<string>
     */
    public function configurationProblems(): array
    {
        $problems = [];
        $source = config("database.connections.{$this->sourceConnection}");
        $target = config("database.connections.{$this->targetConnection}");

        if ($this->sourceConnection === $this->targetConnection) {
            $problems[] = "Source connection '{$this->sourceConnection}' is the target connection.";
        }
        if (! is_array($source)) {
            return [...$problems, "Source connection '{$this->sourceConnection}' is not defined in config/database.php."];
        }
        foreach (['host', 'database', 'username'] as $key) {
            if (blank($source[$key] ?? null)) {
                $problems[] = "Source connection '{$this->sourceConnection}' has no {$key} configured (set SOURCE_STAGING_DB_".strtoupper($key).').';
            }
        }

        $sourceDb = (string) ($source['database'] ?? '');
        if (in_array($sourceDb, (array) config('source_migration.live_source_databases', []), true)) {
            $problems[] = "Source database '{$sourceDb}' is the LIVE source ERP. Stage 2 reads only the staging copy.";
        }
        if (is_array($target) && $sourceDb !== ''
            && $sourceDb === (string) ($target['database'] ?? '')
            && (string) ($source['host'] ?? '') === (string) ($target['host'] ?? '')
            && (string) ($source['port'] ?? '3306') === (string) ($target['port'] ?? '3306')) {
            $problems[] = "Source and target are configured as the same database ('{$sourceDb}' on the same host).";
        }

        return $problems;
    }

    /**
     * Connect, and prove from the servers themselves that source and target differ.
     *
     * @return array{source: array<string, string>, target: array<string, string>}
     */
    public function assertDistinctDatabases(): array
    {
        $problems = $this->configurationProblems();
        if ($problems !== []) {
            throw MigrationRefused::because($problems);
        }

        try {
            $source = $this->identity($this->sourceConnection);
        } catch (Throwable $e) {
            throw MigrationRefused::because(["Cannot connect to source '{$this->sourceConnection}': ".$e->getMessage()]);
        }
        $target = $this->identity($this->targetConnection);

        if ($source === $target) {
            throw MigrationRefused::because(["Source and target resolve to the same database ({$source['database']} on {$source['server']}:{$source['port']})."]);
        }
        if (in_array($source['database'], (array) config('source_migration.live_source_databases', []), true)) {
            throw MigrationRefused::because(["Source resolves to the LIVE source database '{$source['database']}'."]);
        }

        return ['source' => $source, 'target' => $target];
    }

    /**
     * TIMESTAMP values travel as strings in each session's time zone. Two different
     * session zones would shift every timestamp by the difference, silently.
     */
    public function assertSameSessionTimezone(): void
    {
        $source = DB::connection($this->sourceConnection)->selectOne('SELECT @@session.time_zone AS tz')->tz;
        $target = DB::connection($this->targetConnection)->selectOne('SELECT @@session.time_zone AS tz')->tz;

        if ($source !== $target) {
            throw MigrationRefused::because(["Session time zones differ (source {$source}, target {$target}); TIMESTAMP values would shift. Set the same DB_TIMEZONE for both connections."]);
        }
    }

    /**
     * Put the source session into read-only mode. Any write through this
     * connection afterwards fails at the server, whatever code attempts it.
     */
    public function makeSourceReadOnly(): void
    {
        DB::connection($this->sourceConnection)->statement('SET SESSION TRANSACTION READ ONLY');
    }

    public function isLiveTarget(): bool
    {
        return in_array($this->identity($this->targetConnection)['database'], (array) config('source_migration.live_target_databases', []), true);
    }

    /**
     * The execute guard: explicit confirmation naming the target database, and
     * --cutover as well when the target is the live one.
     */
    public function assertExecutionConfirmed(?string $confirm, bool $cutover): void
    {
        $target = $this->identity($this->targetConnection)['database'];

        if ($confirm !== $target) {
            throw MigrationRefused::because(["Execution requires --confirm={$target} (the target database name, typed exactly)."]);
        }
        if ($this->isLiveTarget() && ! $cutover) {
            throw MigrationRefused::because(["'{$target}' is the LIVE target. Loading it is a cutover step and requires --cutover (D8: deferred until the rehearsal passes)."]);
        }
    }

    /** @return array{server: string, port: string, database: string} */
    public function identity(string $connection): array
    {
        $row = DB::connection($connection)->selectOne('SELECT @@hostname AS server, @@port AS port, DATABASE() AS db');

        return ['server' => (string) $row->server, 'port' => (string) $row->port, 'database' => (string) $row->db];
    }
}
