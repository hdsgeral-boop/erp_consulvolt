#!/bin/sh
# =============================================================================================
# ERP_CONSULVOLT — suite PHPUnit do backend com a lista EXPLÍCITA de ficheiros de teste.
#
# Porque existe: na pasta do Windows montada no Docker Desktop, o DirectoryIterator do PHP só devolve
# parte das entradas de backend/tests/Feature (48 de 90 na medição de 2026-10-06), ao passo que scandir(),
# glob() e o `ls` da shell vêem todas. O PHPUnit descobre os testes por iteração de pastas, por isso
# `php artisan test` (sem argumentos ou com --filter) corre localmente só ~44 testes e dá uma falsa
# sensação de verde. Passar os ficheiros um a um contorna a descoberta. Não afecta o CI nem a produção
# (sistema de ficheiros Linux nativo). Ver ADR-069.
#
# Uso (a partir de erp_laravel/, no anfitrião):
#   sh ferramentas/testes/correr_backend.sh                      # suite completa
#   sh ferramentas/testes/correr_backend.sh --filter=Owasp       # argumentos extra vão para o PHPUnit
#   sh ferramentas/testes/correr_backend.sh --stop-on-failure
# Dentro do contentor `app` (cwd /var/www/html) também funciona: corre o PHPUnit directamente.
# =============================================================================================
set -eu

if [ -x vendor/bin/phpunit ] && [ -d tests/Feature ]; then
    # Já estamos dentro do contentor (ou numa instalação PHP com o backend como pasta actual).
    # O glob é expandido pela shell (readdir, como o `ls`), não pelo DirectoryIterator do PHP.
    exec php vendor/bin/phpunit tests/Feature/*.php tests/Unit/*.php "$@"
fi

# No anfitrião: delega no contentor `app` (o mesmo comando, com os argumentos passados de forma segura).
cd "$(dirname "$0")/../.."
# shellcheck disable=SC2016 # as aspas simples são intencionais: o glob e o "$@" são expandidos pela shell do contentor
exec docker compose exec -T app sh -c 'php vendor/bin/phpunit tests/Feature/*.php tests/Unit/*.php "$@"' sh "$@"
