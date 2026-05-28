-- MetaIntel PostgreSQL initialization script
-- Runs once when the container first starts

-- Enable PostGIS extension for spatial queries (if available)
CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS pg_trgm;   -- for LIKE query acceleration
CREATE EXTENSION IF NOT EXISTS unaccent;  -- for accent-insensitive search
CREATE EXTENSION IF NOT EXISTS "uuid-ossp"; -- for UUID generation

-- Create a GIN index helper function for JSONB searches
-- (Migrations will create specific indexes, this is for raw SQL queries)

-- Set timezone
SET timezone = 'UTC';

-- Grant privileges
GRANT ALL PRIVILEGES ON DATABASE metaintel TO metaintel;
