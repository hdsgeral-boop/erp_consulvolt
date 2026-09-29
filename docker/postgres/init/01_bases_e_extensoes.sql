-- Executado uma única vez, na criação do volume do PostgreSQL.
-- Base principal: criada pelo entrypoint oficial (POSTGRES_DB). Aqui: extensões e base de testes.

CREATE EXTENSION IF NOT EXISTS unaccent;
CREATE EXTENSION IF NOT EXISTS pg_trgm;   -- pesquisa textual rápida (nomes, NIF, descrições)

CREATE DATABASE erp_consulvolt_testes OWNER CURRENT_USER;
\connect erp_consulvolt_testes
CREATE EXTENSION IF NOT EXISTS unaccent;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
