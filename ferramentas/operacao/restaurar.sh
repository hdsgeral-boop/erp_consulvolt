#!/bin/sh
# =============================================================================================
# ERP_CONSULVOLT — restauro de uma cópia de segurança para uma base de dados NOVA
#
# Uso:
#   restaurar.sh <pasta_da_copia | ficheiro.dump> <base_destino> [opções]
#
# Opções:
#   --confirmar=<base_destino>   confirmação não interactiva (tem de repetir exactamente o nome da base)
#   --storage-destino=<pasta>    extrai também storage.tar.gz da cópia para esta pasta (tem de estar vazia)
#   --jobs=<n>                   processos paralelos do pg_restore (2)
#
# Regras de segurança:
#   - a base de destino NÃO pode existir: o restauro nunca escreve sobre uma base existente
#     (para repor produção: restaurar para uma base nova e trocar DB_DATABASE — ver docs/PRODUCAO.md);
#   - verifica SHA256SUMS e o índice do dump ANTES de criar a base;
#   - em caso de erro a base parcialmente criada é apagada.
#
# Ligação: PGHOST (postgres) PGPORT (5432) PGUSER/PGPASSWORD (ou POSTGRES_USER/POSTGRES_PASSWORD).
# O utilizador precisa de CREATEDB (o POSTGRES_USER da imagem oficial é superutilizador).
# =============================================================================================
set -eu

registar() { printf '%s [restauro] %s\n' "$(date '+%Y-%m-%dT%H:%M:%S%z')" "$*"; }
falhar() { registar "ERRO: $*"; exit 1; }
uso() { sed -n '2,25p' "$0" | sed 's/^# \{0,1\}//'; exit 2; }

[ $# -ge 2 ] || uso
ORIGEM="$1"; BASE="$2"; shift 2
CONFIRMAR=""; STORAGE_DESTINO=""; JOBS=2
for arg in "$@"; do
    case "$arg" in
        --confirmar=*) CONFIRMAR="${arg#--confirmar=}" ;;
        --storage-destino=*) STORAGE_DESTINO="${arg#--storage-destino=}" ;;
        --jobs=*) JOBS="${arg#--jobs=}" ;;
        *) uso ;;
    esac
done

export PGHOST="${PGHOST:-postgres}"
export PGPORT="${PGPORT:-5432}"
export PGUSER="${PGUSER:-${POSTGRES_USER:-}}"
export PGPASSWORD="${PGPASSWORD:-${POSTGRES_PASSWORD:-}}"
[ -n "$PGUSER" ] || falhar "PGUSER/POSTGRES_USER não definido."

echo "$BASE" | grep -Eq '^[a-z_][a-z0-9_]{0,62}$' || falhar "nome de base inválido: '$BASE' (minúsculas, dígitos e _)."

# --- Localizar e verificar a cópia --------------------------------------------------------------
if [ -d "$ORIGEM" ]; then
    PASTA="$ORIGEM"
    DUMP=""
    for f in "$PASTA"/base_*.dump; do
        if [ -f "$f" ]; then DUMP="$f"; break; fi
    done
    [ -n "$DUMP" ] || falhar "a pasta $PASTA não contém base_*.dump."
    if [ -f "$PASTA/SHA256SUMS" ]; then
        (cd "$PASTA" && sha256sum -c SHA256SUMS >/dev/null) || falhar "SHA256SUMS não confere: cópia corrompida."
        registar "somas SHA-256 conferidas"
    else
        registar "AVISO: sem SHA256SUMS — integridade não verificada por soma."
    fi
else
    DUMP="$ORIGEM"; PASTA=$(dirname "$ORIGEM")
    [ -f "$DUMP" ] || falhar "ficheiro não encontrado: $DUMP"
fi
LISTA=$(mktemp)
trap 'rm -f "$LISTA"' EXIT
pg_restore --list "$DUMP" > "$LISTA" || falhar "pg_restore não consegue ler $DUMP."
N_DADOS=$(grep -c ' TABLE DATA ' "$LISTA" || true)
[ "${N_DADOS:-0}" -gt 0 ] || falhar "o dump não tem dados de tabelas."
registar "dump legível: $N_DADOS tabelas no índice ($DUMP)"

pg_isready -q -t 30 || falhar "PostgreSQL indisponível em $PGHOST:$PGPORT."
EXISTE=$(psql -XAt -d postgres -c "select 1 from pg_database where datname = '$BASE'")
[ -z "$EXISTE" ] || falhar "a base '$BASE' já existe. O restauro só cria bases novas (apague-a ou escolha outro nome)."

if [ -n "$STORAGE_DESTINO" ]; then
    [ -f "$PASTA/storage.tar.gz" ] || falhar "a cópia não tem storage.tar.gz."
    mkdir -p "$STORAGE_DESTINO"
    [ -z "$(ls -A "$STORAGE_DESTINO")" ] || falhar "a pasta $STORAGE_DESTINO não está vazia."
fi

# --- Confirmação --------------------------------------------------------------------------------
if [ -z "$CONFIRMAR" ]; then
    if [ -t 0 ]; then
        printf 'Vai ser CRIADA a base "%s" em %s:%s a partir de %s.\nEscreva o nome da base para confirmar: ' "$BASE" "$PGHOST" "$PGPORT" "$DUMP"
        read -r CONFIRMAR
    else
        falhar "sem terminal interactivo: use --confirmar=$BASE"
    fi
fi
[ "$CONFIRMAR" = "$BASE" ] || falhar "confirmação não confere — nada foi feito."

# --- Restauro -----------------------------------------------------------------------------------
INICIO=$(date +%s)
CRIADA=0
desfazer() {
    rm -f "$LISTA"
    if [ "$CRIADA" = "1" ]; then
        registar "a apagar a base parcial '$BASE'…"
        dropdb --if-exists "$BASE" || true
    fi
}
trap desfazer EXIT INT TERM

createdb --template=template0 --encoding=UTF8 "$BASE" || falhar "createdb falhou."
CRIADA=1
registar "base '$BASE' criada; a restaurar com $JOBS processo(s)…"
pg_restore --dbname="$BASE" --no-owner --exit-on-error --jobs="$JOBS" "$DUMP" || falhar "pg_restore falhou."
psql -X -q -d "$BASE" -c 'ANALYZE' || falhar "ANALYZE falhou."

# --- Verificação --------------------------------------------------------------------------------
N_TABELAS=$(psql -XAt -d "$BASE" -c "select count(*) from pg_tables where schemaname = 'public'")
N_MIGRACOES=$(psql -XAt -d "$BASE" -c "select count(*) from migracoes" 2>/dev/null || echo '?')
N_LANC=$(psql -XAt -d "$BASE" -c "select count(*) from lancamentos_contabeis" 2>/dev/null || echo '?')
N_EMPRESAS=$(psql -XAt -d "$BASE" -c "select count(*) from empresas" 2>/dev/null || echo '?')
[ "$N_TABELAS" -gt 0 ] || falhar "a base restaurada não tem tabelas."
CRIADA=0   # sucesso: já não se apaga

if [ -n "$STORAGE_DESTINO" ]; then
    tar -C "$STORAGE_DESTINO" -xzf "$PASTA/storage.tar.gz" || falhar "extracção do storage falhou."
    registar "storage extraído para $STORAGE_DESTINO"
fi

registar "concluído em $(( $(date +%s) - INICIO )) s: base '$BASE' — $N_TABELAS tabelas, $N_MIGRACOES migrações, $N_EMPRESAS empresas, $N_LANC linhas de lançamentos."
registar "Próximo passo (se for para pôr em serviço): ver docs/PRODUCAO.md › Restauro."
