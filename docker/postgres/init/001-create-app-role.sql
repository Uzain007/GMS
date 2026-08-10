-- Development bootstrap only. Production supplies a separately managed,
-- non-superuser application role so FORCE ROW LEVEL SECURITY is effective.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'ironcore_app') THEN
        CREATE ROLE ironcore_app LOGIN PASSWORD 'change-me-app'
            NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT;
    END IF;
END
$$;

GRANT CONNECT ON DATABASE ironcore TO ironcore_app;
GRANT USAGE, CREATE ON SCHEMA public TO ironcore_app;
