-- Runs once, on the first start of an empty data volume (postgis/postgis image).
-- The image already enables PostGIS in POSTGRES_DB; add the other extensions and the test database.
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS pgcrypto;

CREATE DATABASE coalshield_test OWNER coalshield;
\connect coalshield_test
CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS pgcrypto;
