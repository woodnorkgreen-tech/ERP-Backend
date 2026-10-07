<?php

// Validation only: reuse the schema built by the standard RefreshDatabase run.
// Each test still starts/rolls back its normal data transaction. Do not bootstrap
// an extra Laravel application here: that would interfere with PHPUnit handlers.
require dirname(__DIR__, 4).'/vendor/autoload.php';
\Dotenv\Dotenv::createImmutable(dirname(__DIR__, 4))->safeLoad();
$database = $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE');
$environment = $_ENV['APP_ENV'] ?? getenv('APP_ENV');
if ($environment !== 'testing' || $database !== 'db_test') {
    throw new \RuntimeException('This validation bootstrap only permits testing against db_test.');
}
$pdo = new \PDO(
    'mysql:host='.($_ENV['DB_HOST'] ?? 'db').';port='.($_ENV['DB_PORT'] ?? '3306').';dbname=db_test',
    $_ENV['DB_USERNAME'] ?? 'db', $_ENV['DB_PASSWORD'] ?? 'db',
    [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
);
$query = $pdo->prepare('SELECT COUNT(*) FROM migrations WHERE migration = ?');
$query->execute(['2026_10_06_000003_add_requisition_verification']);
if ((int) $query->fetchColumn() !== 1) {
    throw new \RuntimeException('Run the standard RefreshDatabase suite before reusing its schema.');
}
unset($query, $pdo);
\Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = true;
