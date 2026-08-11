#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Build only the disposable CI database. Production roles and databases are
 * provider-managed and must never be created from repository automation.
 */
if (getenv('CI') !== 'true' || getenv('IRONCORE_RUNTIME_GATE') !== 'true') {
    fwrite(STDERR, "Refusing to prepare PostgreSQL outside the explicit CI runtime gate.\n");
    exit(64);
}

/** @return non-empty-string */
function requiredEnvironment(string $name): string
{
    $value = getenv($name);

    if (! is_string($value) || $value === '') {
        throw new RuntimeException("Missing required CI environment value: {$name}");
    }

    return $value;
}

/** @return non-empty-string */
function postgresIdentifier(string $value, string $label): string
{
    if (! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $value)) {
        throw new RuntimeException("Invalid PostgreSQL {$label} identifier.");
    }

    return '"'.str_replace('"', '""', $value).'"';
}

function postgresBoolean(mixed $value): bool
{
    return in_array($value, [true, 1, '1', 't', 'true'], true);
}

$host = requiredEnvironment('CI_POSTGRES_HOST');
$port = requiredEnvironment('CI_POSTGRES_PORT');
$adminDatabase = requiredEnvironment('CI_POSTGRES_ADMIN_DATABASE');
$superuser = requiredEnvironment('CI_POSTGRES_SUPERUSER');
$superuserPassword = requiredEnvironment('CI_POSTGRES_SUPERUSER_PASSWORD');
$database = requiredEnvironment('CI_POSTGRES_DATABASE');
$applicationRole = requiredEnvironment('CI_POSTGRES_APP_ROLE');
$applicationPassword = requiredEnvironment('CI_POSTGRES_APP_PASSWORD');

if (! preg_match('/^[A-Za-z0-9_.:-]+$/', $host) || ! ctype_digit($port)) {
    throw new RuntimeException('Invalid CI PostgreSQL connection address.');
}

$databaseIdentifier = postgresIdentifier($database, 'database');
$roleIdentifier = postgresIdentifier($applicationRole, 'role');

$admin = new PDO(
    "pgsql:host={$host};port={$port};dbname={$adminDatabase}",
    $superuser,
    $superuserPassword,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$roleExists = $admin->prepare('select exists(select 1 from pg_roles where rolname = :role)');
$roleExists->execute(['role' => $applicationRole]);

// The application login is deliberately unable to bypass tenant RLS or create
// other roles/databases. It owns only the short-lived test database.
$roleDefinition = sprintf(
    '%s LOGIN PASSWORD %s NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS',
    $roleIdentifier,
    $admin->quote($applicationPassword),
);

if ((int) $roleExists->fetchColumn() === 1) {
    $admin->exec("ALTER ROLE {$roleDefinition}");
} else {
    $admin->exec("CREATE ROLE {$roleDefinition}");
}

$databaseExists = $admin->prepare('select exists(select 1 from pg_database where datname = :database)');
$databaseExists->execute(['database' => $database]);

if ((int) $databaseExists->fetchColumn() === 1) {
    $admin->exec("ALTER DATABASE {$databaseIdentifier} OWNER TO {$roleIdentifier}");
} else {
    $admin->exec(
        "CREATE DATABASE {$databaseIdentifier} OWNER {$roleIdentifier} TEMPLATE template0 ENCODING 'UTF8'",
    );
}

$application = new PDO(
    "pgsql:host={$host};port={$port};dbname={$database}",
    $applicationRole,
    $applicationPassword,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$identity = $application->query(<<<'SQL'
    select current_user as role,
           current_database() as database,
           rolsuper,
           rolcreatedb,
           rolcreaterole,
           rolinherit,
           rolbypassrls,
           rolcanlogin
      from pg_roles
     where rolname = current_user
    SQL)->fetch(PDO::FETCH_ASSOC);

if (! is_array($identity)
    || $identity['role'] !== $applicationRole
    || $identity['database'] !== $database
    || postgresBoolean($identity['rolsuper'])
    || postgresBoolean($identity['rolcreatedb'])
    || postgresBoolean($identity['rolcreaterole'])
    || postgresBoolean($identity['rolinherit'])
    || postgresBoolean($identity['rolbypassrls'])
    || ! postgresBoolean($identity['rolcanlogin'])) {
    throw new RuntimeException('The CI application role does not satisfy the RLS least-privilege contract.');
}

fwrite(STDOUT, "Prepared disposable PostgreSQL database {$database} for non-superuser role {$applicationRole}.\n");
