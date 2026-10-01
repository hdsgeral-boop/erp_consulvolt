-- PRODUÇÃO: executado uma única vez, na criação do volume do PostgreSQL (docker-compose.prod.yml).
-- Ao contrário de docker/postgres/init (desenvolvimento), NÃO cria a base de testes.
-- A base principal é criada pelo entrypoint oficial (POSTGRES_DB); aqui só as extensões usadas pelo ERP.

CREATE EXTENSION IF NOT EXISTS unaccent;
CREATE EXTENSION IF NOT EXISTS pg_trgm;   -- pesquisa textual rápida (nomes, NIF, descrições)
