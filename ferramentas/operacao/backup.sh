#!/bin/sh
# =============================================================================================
# ERP_CONSULVOLT — cópia de segurança (PostgreSQL + ficheiros + Redis opcional + segredos cifrados opcional)
#
# Shell POSIX (corre em /bin/sh do Alpine — serviço "copias" do docker-compose.prod.yml — e em bash).
#
# Conteúdo de cada cópia (pasta <destino>/<classe>/<AAAAMMDD-HHMMSS>/):
#   base_<nome>.dump         pg_dump formato custom, comprimido (restauro com restaurar.sh / pg_restore)
#   base_<nome>.lista        índice do dump (pg_restore --list) — prova de que o ficheiro é legível
#   storage.tar.gz           storage/app do Laravel (relatórios de migração, cópias por empresa, anexos)  [opcional]
#   redis.tar.gz             dados do Redis (AOF/RDB: filas pendentes, locks)                               [opcional]
#   segredos.tar.gz.enc      chaves AGT cifradas (AES-256-CBC, PBKDF2 200 000 iterações)                    [opcional]
#   MANIFESTO.txt, SHA256SUMS
#
# Rotação: todas as cópias são "diarias"; a do dia ERP_COPIAS_DIA_SEMANAL (1=2.ª … 7=domingo) é também
# guardada em "semanais" e a do dia 1 do mês em "mensais" (ligações físicas quando possível).
#
# Variáveis (valores por omissão entre parênteses):
#   PGHOST (postgres) PGPORT (5432) PGUSER/PGPASSWORD/PGDATABASE (ou POSTGRES_USER/POSTGRES_PASSWORD/POSTGRES_DB)
#   ERP_COPIAS_DESTINO (/copias)
#   ERP_COPIAS_RETER_DIARIAS (7) ERP_COPIAS_RETER_SEMANAIS (4) ERP_COPIAS_RETER_MENSAIS (12)
#   ERP_COPIAS_DIA_SEMANAL (7)
#   ERP_COPIAS_STORAGE_DIR  pasta a arquivar como storage.tar.gz (vazio = não arquiva)
#   ERP_COPIAS_REDIS_DIR    pasta de dados do Redis (vazio = não arquiva)
#   ERP_COPIAS_SEGREDOS_DIR pasta das chaves AGT; só é arquivada se ERP_COPIAS_CHAVE estiver definida
#   ERP_COPIAS_CHAVE        frase-passe da cifra dos segredos (guardar FORA do servidor, num cofre)
#
# Código de saída: 0 = cópia criada e verificada; ≠0 = falhou (nada fica na pasta final).
# =============================================================================================
set -eu
umask 077

registar() { printf '%s [backup] %s\n' "$(date '+%Y-%m-%dT%H:%M:%S%z')" "$*"; }
falhar() { registar "ERRO: $*"; exit 1; }

export PGHOST="${PGHOST:-postgres}"
export PGPORT="${PGPORT:-5432}"
export PGUSER="${PGUSER:-${POSTGRES_USER:-}}"
export PGPASSWORD="${PGPASSWORD:-${POSTGRES_PASSWORD:-}}"
export PGDATABASE="${PGDATABASE:-${POSTGRES_DB:-}}"
[ -n "$PGUSER" ] || falhar "PGUSER/POSTGRES_USER não definido."
[ -n "$PGDATABASE" ] || falhar "PGDATABASE/POSTGRES_DB não definido."

DESTINO="${ERP_COPIAS_DESTINO:-/copias}"
RETER_D="${ERP_COPIAS_RETER_DIARIAS:-7}"
RETER_S="${ERP_COPIAS_RETER_SEMANAIS:-4}"
RETER_M="${ERP_COPIAS_RETER_MENSAIS:-12}"
DIA_SEMANAL="${ERP_COPIAS_DIA_SEMANAL:-7}"

command -v pg_dump >/dev/null || falhar "pg_dump não encontrado."
command -v pg_restore >/dev/null || falhar "pg_restore não encontrado."

mkdir -p "$DESTINO/diarias" "$DESTINO/semanais" "$DESTINO/mensais"

# Uma cópia de cada vez (mkdir é atómico).
TRINCO="$DESTINO/.trinco"
if ! mkdir "$TRINCO" 2>/dev/null; then
    falhar "outra cópia está em curso ($TRINCO). Se não estiver, apague a pasta e repita."
fi
CARIMBO="$(date '+%Y%m%d-%H%M%S')"
TEMP="$DESTINO/.em-curso-$CARIMBO"
limpar() { rm -rf "$TEMP" "$TRINCO"; }
trap limpar EXIT INT TERM
mkdir -p "$TEMP"

INICIO=$(date +%s)
registar "início: base '$PGDATABASE' em $PGHOST:$PGPORT -> $DESTINO"

# --- 1. PostgreSQL ------------------------------------------------------------------------------
pg_isready -q -t 30 || falhar "PostgreSQL indisponível em $PGHOST:$PGPORT."
DUMP="$TEMP/base_${PGDATABASE}.dump"
pg_dump --format=custom --compress=6 --no-password --file="$DUMP" "$PGDATABASE" \
    || falhar "pg_dump falhou."
pg_restore --list "$DUMP" > "$TEMP/base_${PGDATABASE}.lista" \
    || falhar "pg_restore --list não consegue ler o dump: cópia inválida."
N_TABELAS=$(grep -c ' TABLE DATA ' "$TEMP/base_${PGDATABASE}.lista" || true)
[ "${N_TABELAS:-0}" -gt 0 ] || falhar "o dump não contém dados de tabelas (TABLE DATA = 0)."
registar "PostgreSQL: $(du -h "$DUMP" | cut -f1) · $N_TABELAS tabelas no índice (TABLE DATA) · índice verificado"

# --- 2. Ficheiros da aplicação (storage/app) ----------------------------------------------------
if [ -n "${ERP_COPIAS_STORAGE_DIR:-}" ]; then
    [ -d "$ERP_COPIAS_STORAGE_DIR" ] || falhar "ERP_COPIAS_STORAGE_DIR não existe: $ERP_COPIAS_STORAGE_DIR"
    tar -C "$ERP_COPIAS_STORAGE_DIR" -czf "$TEMP/storage.tar.gz" . || falhar "tar do storage falhou."
    gzip -t "$TEMP/storage.tar.gz" || falhar "storage.tar.gz corrompido."
    registar "storage: $(du -h "$TEMP/storage.tar.gz" | cut -f1)"
fi

# --- 3. Redis (opcional) ------------------------------------------------------------------------
if [ -n "${ERP_COPIAS_REDIS_DIR:-}" ]; then
    [ -d "$ERP_COPIAS_REDIS_DIR" ] || falhar "ERP_COPIAS_REDIS_DIR não existe: $ERP_COPIAS_REDIS_DIR"
    tar -C "$ERP_COPIAS_REDIS_DIR" -czf "$TEMP/redis.tar.gz" . || falhar "tar do Redis falhou."
    gzip -t "$TEMP/redis.tar.gz" || falhar "redis.tar.gz corrompido."
    registar "redis: $(du -h "$TEMP/redis.tar.gz" | cut -f1)"
fi

# --- 4. Segredos AGT cifrados (opcional) --------------------------------------------------------
if [ -n "${ERP_COPIAS_SEGREDOS_DIR:-}" ] && [ -n "${ERP_COPIAS_CHAVE:-}" ]; then
    [ -d "$ERP_COPIAS_SEGREDOS_DIR" ] || falhar "ERP_COPIAS_SEGREDOS_DIR não existe: $ERP_COPIAS_SEGREDOS_DIR"
    command -v openssl >/dev/null || falhar "openssl não encontrado (necessário para cifrar os segredos)."
    tar -C "$ERP_COPIAS_SEGREDOS_DIR" -cz . \
        | openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -pass env:ERP_COPIAS_CHAVE -out "$TEMP/segredos.tar.gz.enc" \
        || falhar "cifra dos segredos falhou."
    openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass env:ERP_COPIAS_CHAVE -in "$TEMP/segredos.tar.gz.enc" \
        | tar -tz >/dev/null || falhar "verificação da decifra dos segredos falhou."
    registar "segredos: cifrados e verificados"
elif [ -n "${ERP_COPIAS_SEGREDOS_DIR:-}" ]; then
    registar "AVISO: ERP_COPIAS_SEGREDOS_DIR definido sem ERP_COPIAS_CHAVE — segredos NÃO incluídos (nunca em claro)."
fi

# --- 5. Manifesto e somas de verificação --------------------------------------------------------
{
    echo "ERP_CONSULVOLT — cópia de segurança"
    echo "data:        $(date '+%Y-%m-%dT%H:%M:%S%z')"
    echo "base:        $PGDATABASE ($PGHOST:$PGPORT)"
    echo "servidor:    $(psql -XAtc 'show server_version' 2>/dev/null || echo '?')"
    echo "pg_dump:     $(pg_dump --version)"
    echo "tabelas:     $N_TABELAS (entradas TABLE DATA)"
    echo "migracoes:   $(psql -XAtc 'select count(*) from migracoes' 2>/dev/null || echo '?')"
    echo "ficheiros:"
    for f in "$TEMP"/*; do
        [ "$(basename "$f")" = MANIFESTO.txt ] && continue
        printf '  %12s bytes  %s\n' "$(wc -c < "$f" | tr -d ' ')" "$(basename "$f")"
    done
} > "$TEMP/MANIFESTO.txt"
(cd "$TEMP" && sha256sum ./* > SHA256SUMS) || falhar "sha256sum falhou."

# --- 6. Publicar e rodar ------------------------------------------------------------------------
FINAL="$DESTINO/diarias/$CARIMBO"
mv "$TEMP" "$FINAL"

copiar_para() {   # $1 = classe (semanais|mensais)
    alvo="$DESTINO/$1/$CARIMBO"
    mkdir -p "$alvo"
    for f in "$FINAL"/*; do
        ln "$f" "$alvo/" 2>/dev/null || cp -p "$f" "$alvo/"
    done
    registar "também guardada em $1/"
}
[ "$(date +%u)" = "$DIA_SEMANAL" ] && copiar_para semanais
[ "$(date +%d)" = "01" ] && copiar_para mensais

rodar() {   # $1 = classe, $2 = quantas manter
    [ "$2" -ge 1 ] || return 0
    find "$DESTINO/$1" -mindepth 1 -maxdepth 1 -type d -name '[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]-[0-9][0-9][0-9][0-9][0-9][0-9]' \
        -exec basename {} \; | sort -r | tail -n +"$(( $2 + 1 ))" \
        | while read -r antiga; do
            rm -rf "${DESTINO:?}/$1/$antiga"
            registar "rotação: removida $1/$antiga"
        done
}
rodar diarias "$RETER_D"
rodar semanais "$RETER_S"
rodar mensais "$RETER_M"

registar "concluída em $(( $(date +%s) - INICIO )) s: $FINAL ($(du -sh "$FINAL" | cut -f1))"
