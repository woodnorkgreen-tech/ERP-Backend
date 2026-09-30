<?php

namespace App\Support\SourceMigration;

use Illuminate\Support\Str;

/**
 * Writes each run's reports to storage/app/source-migration/<run-id>/ as JSON (the
 * record) and Markdown (for review). Reports carry counts, IDs and digests only.
 */
class ReportWriter
{
    public readonly string $directory;

    public function __construct(?string $runId = null, ?string $base = null)
    {
        $runId ??= now()->format('Ymd-His').'-'.Str::lower(Str::random(4));
        $this->directory = rtrim($base ?? (string) config('source_migration.report_path'), '/').'/'.$runId;
    }

    public function write(string $name, array $data): string
    {
        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents("{$this->directory}/{$name}.json", $json."\n");
        file_put_contents("{$this->directory}/{$name}.md", "# {$name}\n\nGenerated ".now()->toIso8601String()."\n\n```json\n{$json}\n```\n");

        return "{$this->directory}/{$name}.json";
    }
}
