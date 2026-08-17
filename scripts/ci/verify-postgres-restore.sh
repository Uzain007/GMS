#!/usr/bin/env bash

set -Eeuo pipefail

# This drill is deliberately restricted to GitHub's disposable PostgreSQL
# service. It must never be repointed at a provider or production database.
if [[ "${CI:-}" != "true" || "${IRONCORE_RUNTIME_GATE:-}" != "true" || "${IRONCORE_RESTORE_DRILL_GATE:-}" != "true" ]]; then
  echo "Refusing to run the PostgreSQL restore drill outside the explicit CI gate." >&2
  exit 64
fi

required_environment=(
  POSTGRES_CONTAINER
  CI_POSTGRES_ADMIN_DATABASE
  CI_POSTGRES_SUPERUSER
  CI_POSTGRES_SUPERUSER_PASSWORD
  CI_POSTGRES_DATABASE
  CI_POSTGRES_APP_ROLE
  CI_POSTGRES_APP_PASSWORD
)

for name in "${required_environment[@]}"; do
  if [[ -z "${!name:-}" ]]; then
    echo "Missing required restore-drill environment value: ${name}" >&2
    exit 64
  fi
done

readonly source_database="${CI_POSTGRES_DATABASE}"
readonly restore_database="ironcore_restore_test"
readonly admin_database="${CI_POSTGRES_ADMIN_DATABASE}"
readonly superuser="${CI_POSTGRES_SUPERUSER}"
readonly application_role="${CI_POSTGRES_APP_ROLE}"
readonly dump_path="/tmp/ironcore-restore-drill.dump"
readonly fixture_user="33333333-3333-4333-8333-333333333333"
readonly fixture_gym_a="11111111-1111-4111-8111-111111111111"
readonly fixture_gym_b="22222222-2222-4222-8222-222222222222"
readonly fixture_member_a="44444444-4444-4444-8444-444444444444"
readonly fixture_member_b="55555555-5555-4555-8555-555555555555"
readonly absent_gym="66666666-6666-4666-8666-666666666666"

if [[ "${source_database}" != "ironcore_test" || "${admin_database}" != "postgres" || "${application_role}" != "ironcore_app" ]]; then
  echo "Restore drill accepts only the reviewed disposable IronCore CI identities." >&2
  exit 64
fi

for identifier in "${source_database}" "${restore_database}" "${admin_database}" "${superuser}" "${application_role}"; do
  if [[ ! "${identifier}" =~ ^[a-z_][a-z0-9_]{0,62}$ ]]; then
    echo "Restore drill received an invalid PostgreSQL identifier." >&2
    exit 64
  fi
done

if [[ ! "${POSTGRES_CONTAINER}" =~ ^[a-f0-9]{12,64}$ ]]; then
  echo "Restore drill requires the reviewed PostgreSQL service-container ID." >&2
  exit 64
fi

if ! command -v docker >/dev/null 2>&1; then
  echo "Restore drill requires Docker access to the PostgreSQL 17 service container." >&2
  exit 69
fi

admin_psql() {
  local database="$1"
  shift
  docker exec -i \
    -e PGPASSWORD="${CI_POSTGRES_SUPERUSER_PASSWORD}" \
    "${POSTGRES_CONTAINER}" \
    psql --host=127.0.0.1 --username="${superuser}" --dbname="${database}" \
      --set=ON_ERROR_STOP=1 "$@"
}

application_psql() {
  local database="$1"
  shift
  docker exec -i \
    -e PGPASSWORD="${CI_POSTGRES_APP_PASSWORD}" \
    "${POSTGRES_CONTAINER}" \
    psql --host=127.0.0.1 --username="${application_role}" --dbname="${database}" \
      --set=ON_ERROR_STOP=1 "$@"
}

cleanup() {
  local status=$?
  trap - EXIT
  set +e

  admin_psql "${admin_database}" --command="DROP DATABASE IF EXISTS \"${restore_database}\" WITH (FORCE);" >/dev/null
  admin_psql "${source_database}" >/dev/null <<SQL
DELETE FROM members WHERE id IN ('${fixture_member_a}', '${fixture_member_b}');
DELETE FROM gym_user WHERE gym_id IN ('${fixture_gym_a}', '${fixture_gym_b}') AND user_id = '${fixture_user}';
DELETE FROM users WHERE id = '${fixture_user}';
DELETE FROM gyms WHERE id IN ('${fixture_gym_a}', '${fixture_gym_b}');
SQL
  docker exec "${POSTGRES_CONTAINER}" rm -f "${dump_path}" >/dev/null

  exit "${status}"
}

trap cleanup EXIT

# Add two known tenants through the same non-superuser role used by Laravel.
# The explicit settings prove the fixture itself respects the active policies.
application_psql "${source_database}" <<SQL
BEGIN;

INSERT INTO users (id, name, email, password, created_at, updated_at)
VALUES ('${fixture_user}', 'Restore Drill User', 'restore-drill@example.test', 'synthetic-not-a-login-secret', now(), now());

INSERT INTO gyms (id, name, slug, country_code, created_at, updated_at)
VALUES
  ('${fixture_gym_a}', 'Restore Drill Gym A', 'restore-drill-gym-a', 'GB', now(), now()),
  ('${fixture_gym_b}', 'Restore Drill Gym B', 'restore-drill-gym-b', 'GB', now(), now());

SELECT set_config('ironcore.current_user_id', '${fixture_user}', false);
SELECT set_config('ironcore.current_gym_id', '${fixture_gym_a}', false);
INSERT INTO gym_user (gym_id, user_id, role, status, joined_at, created_at, updated_at)
VALUES ('${fixture_gym_a}', '${fixture_user}', 'gym_owner', 'active', now(), now(), now());
INSERT INTO members (id, gym_id, member_number, first_name, last_name, status, created_at, updated_at)
VALUES ('${fixture_member_a}', '${fixture_gym_a}', 'RESTORE-A', 'Synthetic', 'Member A', 'active', now(), now());

SELECT set_config('ironcore.current_gym_id', '${fixture_gym_b}', false);
INSERT INTO gym_user (gym_id, user_id, role, status, joined_at, created_at, updated_at)
VALUES ('${fixture_gym_b}', '${fixture_user}', 'gym_owner', 'active', now(), now(), now());
INSERT INTO members (id, gym_id, member_number, first_name, last_name, status, created_at, updated_at)
VALUES ('${fixture_member_b}', '${fixture_gym_b}', 'RESTORE-B', 'Synthetic', 'Member B', 'active', now(), now());

COMMIT;
SQL

# PostgreSQL's administrative role is used only to capture all synthetic rows;
# pg_dump otherwise refuses to bypass forced RLS. No production connection or
# credential is present in this workflow.
docker exec \
  -e PGPASSWORD="${CI_POSTGRES_SUPERUSER_PASSWORD}" \
  "${POSTGRES_CONTAINER}" \
  pg_dump --host=127.0.0.1 --username="${superuser}" --dbname="${source_database}" \
    --format=custom --no-owner --no-acl --file="${dump_path}"

docker exec "${POSTGRES_CONTAINER}" test -s "${dump_path}"
docker exec "${POSTGRES_CONTAINER}" pg_restore --list "${dump_path}" >/dev/null

admin_psql "${admin_database}" --command="DROP DATABASE IF EXISTS \"${restore_database}\" WITH (FORCE);"
admin_psql "${admin_database}" --command="CREATE DATABASE \"${restore_database}\" OWNER \"${application_role}\" TEMPLATE template0 ENCODING 'UTF8';"

# Restoring without ownership or ACL commands makes the least-privileged
# application role own the recreated schema, matching the disposable source.
docker exec \
  -e PGPASSWORD="${CI_POSTGRES_APP_PASSWORD}" \
  "${POSTGRES_CONTAINER}" \
  pg_restore --host=127.0.0.1 --username="${application_role}" --dbname="${restore_database}" \
    --exit-on-error --single-transaction --no-owner --no-acl "${dump_path}"

# Verify identity, schema protection and row visibility after the real restore.
application_psql "${restore_database}" <<SQL
DO \$identity\$
DECLARE
  role_record record;
BEGIN
  SELECT rolsuper, rolcreatedb, rolcreaterole, rolinherit, rolbypassrls, rolcanlogin
    INTO role_record
    FROM pg_roles
   WHERE rolname = current_user;

  IF current_user <> '${application_role}'
     OR current_database() <> '${restore_database}'
     OR role_record.rolsuper
     OR role_record.rolcreatedb
     OR role_record.rolcreaterole
     OR role_record.rolinherit
     OR role_record.rolbypassrls
     OR NOT role_record.rolcanlogin THEN
    RAISE EXCEPTION 'Restored database is not running under the least-privileged application identity';
  END IF;
END
\$identity\$;

DO \$rls\$
DECLARE
  tenant_table_count integer;
  unprotected_tables text;
BEGIN
  SELECT count(*),
         string_agg(c.relname, ', ' ORDER BY c.relname)
           FILTER (WHERE NOT c.relrowsecurity OR NOT c.relforcerowsecurity)
    INTO tenant_table_count, unprotected_tables
    FROM pg_class c
    JOIN pg_namespace n ON n.oid = c.relnamespace
    JOIN pg_attribute a ON a.attrelid = c.oid
   WHERE n.nspname = 'public'
     AND c.relkind = 'r'
     AND a.attname = 'gym_id'
     AND NOT a.attisdropped;

  IF tenant_table_count = 0 THEN
    RAISE EXCEPTION 'Restored schema contains no tenant tables';
  END IF;
  IF unprotected_tables IS NOT NULL THEN
    RAISE EXCEPTION 'Restored tenant tables lost RLS/FORCE RLS: %', unprotected_tables;
  END IF;
END
\$rls\$;

SELECT set_config('ironcore.current_user_id', '', false);
SELECT set_config('ironcore.current_gym_id', '', false);
DO \$closed\$
BEGIN
  IF (SELECT count(*) FROM members) <> 0 OR (SELECT count(*) FROM gym_user) <> 0 THEN
    RAISE EXCEPTION 'Restored tenant records did not fail closed without context';
  END IF;
END
\$closed\$;

SELECT set_config('ironcore.current_gym_id', '${fixture_gym_a}', false);
DO \$tenant_a\$
BEGIN
  IF (SELECT count(*) FROM members WHERE member_number = 'RESTORE-A') <> 1
     OR (SELECT count(*) FROM members) <> 1
     OR (SELECT count(*) FROM gym_user) <> 1 THEN
    RAISE EXCEPTION 'Restored tenant A is incomplete or can see another tenant';
  END IF;
END
\$tenant_a\$;

SELECT set_config('ironcore.current_gym_id', '${fixture_gym_b}', false);
DO \$tenant_b\$
BEGIN
  IF (SELECT count(*) FROM members WHERE member_number = 'RESTORE-B') <> 1
     OR (SELECT count(*) FROM members) <> 1
     OR (SELECT count(*) FROM gym_user) <> 1 THEN
    RAISE EXCEPTION 'Restored tenant B is incomplete or can see another tenant';
  END IF;
END
\$tenant_b\$;

SELECT set_config('ironcore.current_gym_id', '${absent_gym}', false);
DO \$cross_tenant\$
BEGIN
  IF (SELECT count(*) FROM members) <> 0 OR (SELECT count(*) FROM gym_user) <> 0 THEN
    RAISE EXCEPTION 'Restored records are visible to an unrelated tenant';
  END IF;
END
\$cross_tenant\$;
SQL

echo "Synthetic PostgreSQL backup and restore drill passed with forced tenant RLS intact."
