-- Runs once on first container init (empty data dir).
-- Main DB `fleet` is created by POSTGRES_DB; add the test DB + extensions.

CREATE DATABASE fleet_test;

\connect fleet
CREATE EXTENSION IF NOT EXISTS timescaledb;

\connect fleet_test
CREATE EXTENSION IF NOT EXISTS timescaledb;
