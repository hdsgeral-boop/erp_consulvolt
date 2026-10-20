#!/bin/sh
# Verificação pós-deploy / pós-ativação do SaaS. Não contém credenciais: tudo por variáveis de ambiente.
#
#   BASE        domínio base (ex.: meuerp.ao)
#   SLUG_A, USER_A, PASS_A      empresa A (host SLUG_A.BASE)
#   SLUG_B, USER_B, PASS_B      empresa B (host SLUG_B.BASE)
#   PLATAFORMA, USER_P, PASS_P  plataforma (ex.: gestao-admin; host PLATAFORMA.BASE)
#   ESQUEMA     opcional, https por omissão (http para testes locais)
#
# Sai com código 1 se alguma verificação falhar.
set -eu

for v in BASE SLUG_A USER_A PASS_A SLUG_B USER_B PASS_B PLATAFORMA USER_P PASS_P; do
    eval "val=\${$v:-}"
    [ -n "$val" ] || { echo "Variável em falta: $v" >&2; exit 2; }
done
command -v curl >/dev/null || { echo "curl não encontrado" >&2; exit 2; }

ESQUEMA="${ESQUEMA:-https}"
FALHAS=0
CORPO=$(mktemp)
trap 'rm -f "$CORPO"' EXIT

ok() { printf 'OK     %s\n' "$1"; }
falha() { printf 'FALHA  %s\n' "$1"; FALHAS=$((FALHAS + 1)); }

# Escapa \ e " para JSON.
json() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'; }

# estado_http <metodo> <url> [token] [corpo_json]: imprime o código HTTP; resposta em $CORPO
estado_http() {
    _m="$1"; _u="$2"; _t="${3:-}"; _b="${4:-}"
    set -- -sS -o "$CORPO" -w '%{http_code}' --max-time 30 -X "$_m" -H 'Accept: application/json'
    [ -z "$_t" ] || set -- "$@" -H "Authorization: Bearer $_t"
    [ -z "$_b" ] || set -- "$@" -H 'Content-Type: application/json' --data "$_b"
    curl "$@" "$_u" 2>/dev/null || echo 000
}

# entrar <host> <user> <pass>: imprime o token (vazio se falhar)
entrar() {
    _corpo=$(printf '{"nome_utilizador":"%s","palavra_passe":"%s","dispositivo":"verificacao"}' "$(json "$2")" "$(json "$3")")
    _c=$(estado_http POST "$ESQUEMA://$1/api/autenticacao/entrar" "" "$_corpo")
    [ "$_c" = 200 ] || return 0
    sed -n 's/.*"token"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$CORPO" | head -n 1
}

HOST_A="$SLUG_A.$BASE"; HOST_B="$SLUG_B.$BASE"; HOST_P="$PLATAFORMA.$BASE"

TOKEN_A=$(entrar "$HOST_A" "$USER_A" "$PASS_A")
if [ -n "$TOKEN_A" ]; then ok "login em $HOST_A"; else falha "login em $HOST_A"; fi
TOKEN_B=$(entrar "$HOST_B" "$USER_B" "$PASS_B")
if [ -n "$TOKEN_B" ]; then ok "login em $HOST_B"; else falha "login em $HOST_B"; fi
TOKEN_P=$(entrar "$HOST_P" "$USER_P" "$PASS_P")
if [ -n "$TOKEN_P" ]; then ok "login em $HOST_P"; else falha "login em $HOST_P"; fi

# Pedido cruzado: o token da empresa A noutro host tem de dar 403.
if [ -n "$TOKEN_A" ]; then
    for h in "$HOST_B" "$HOST_P"; do
        c=$(estado_http GET "$ESQUEMA://$h/api/sistema/empresas" "$TOKEN_A")
        if [ "$c" = 403 ]; then ok "token de $HOST_A recusado em $h (403)"; else falha "token de $HOST_A em $h devolveu HTTP $c (esperado 403)"; fi
    done
else
    falha "pedido cruzado não executado (sem token de $HOST_A)"
fi

# Lista de empresas não vazia.
lista_empresas() {   # $1 host  $2 token
    if [ -z "$2" ]; then falha "empresas em $1 não verificado (sem token)"; return 0; fi
    c=$(estado_http GET "$ESQUEMA://$1/api/sistema/empresas" "$2")
    if [ "$c" != 200 ]; then falha "GET /api/sistema/empresas em $1 devolveu HTTP $c"; return 0; fi
    if tr -d ' \n\r\t' < "$CORPO" | grep -q '"dados":\[\]'; then
        falha "lista de empresas VAZIA em $1"
    elif grep -q '"id"' "$CORPO"; then
        ok "lista de empresas não vazia em $1"
    else
        falha "resposta de empresas em $1 sem itens reconhecíveis"
    fi
}
lista_empresas "$HOST_A" "$TOKEN_A"
lista_empresas "$HOST_B" "$TOKEN_B"

# Saúde.
for h in "$HOST_A" "$HOST_B" "$HOST_P"; do
    c=$(estado_http GET "$ESQUEMA://$h/api/saude")
    if [ "$c" = 200 ]; then ok "saúde em $h"; else falha "saúde em $h devolveu HTTP $c"; fi
done

if [ "$FALHAS" -gt 0 ]; then
    printf '\n%s verificação(ões) falharam.\n' "$FALHAS"
    exit 1
fi
printf '\nTodas as verificações passaram.\n'
