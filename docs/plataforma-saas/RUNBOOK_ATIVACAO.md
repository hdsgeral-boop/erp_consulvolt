# Runbook: ativar e reverter o SaaS

Base: [SAAS_ATIVACAO.md](../arquitetura/SAAS_ATIVACAO.md). Este documento acrescenta a sequência operacional e as lições da ativação de 2026-10-19. Nunca escrever palavras-passe ou chaves neste ficheiro.

## Ficheiros de ambiente (ler primeiro)

- `.env` na **raiz** (`~/erp/.env`): lido pelo `docker compose` (variáveis dos serviços). Alterações aqui exigem recriar os serviços.
- `backend/.env`: lido pela aplicação Laravel dentro do contentor. O PHP-FPM corre como `www-data` (uid/gid 82), por isso o ficheiro **tem de ser legível por esse grupo**:

```
chgrp 82 backend/.env && chmod 640 backend/.env
```

Se o ficheiro não for legível, a aplicação ignora-o em silêncio: a cache cai para `database` e todos os logins dão `ERRO_INTERNO`. O deploy automático não toca em nenhum `.env`, por isso a permissão mantém-se; confirmar sempre depois de editar ou recriar o ficheiro.

## Ativar

1. **Backup** antes de tudo (ver "Backups e restauro") e confirmar que o ficheiro existe e tem tamanho razoável.
2. Confirmar que as migrações estão aplicadas e que o `backend/.env` está legível (secção anterior).
3. Definir no `.env` da raiz: `DB_APP_USERNAME=erp_app`, `DB_APP_PASSWORD` (valor forte, só no servidor), `SAAS_ATIVO=true`, `SAAS_LIGACAO_DONO=pgsql_owner`, e `LICENCAS_ATIVAS=true` se as licenças forem para valer.
4. Definir a palavra-passe da role e aplicar a RLS: `docker compose exec app php artisan erp:saas:aplicar-rls` (idempotente).
5. Recriar os serviços: `docker compose up -d app worker scheduler`.
6. Se necessário: `docker compose exec app php artisan config:clear`.
7. **Verificar** (secção seguinte) e `docker compose exec app php artisan erp:saas:aplicar-rls --verificar`.

### Migrações depois da ativação

Sempre com a ligação do dono, porque `erp_app` não tem DDL:

```
docker compose exec -T app php artisan migrate --force --database=pgsql_owner
```

O workflow `deploy-aws.yml` já faz isto.

## Verificação pós-ativação e pós-deploy

`ferramentas/operacao/verificar_saas.sh` recebe tudo por variáveis de ambiente (nunca guardar credenciais no ficheiro):

```
BASE=meuerp.ao PLATAFORMA=gestao-admin \
SLUG_A=empresa1 USER_A=... PASS_A=... \
SLUG_B=empresa2 USER_B=... PASS_B=... \
USER_P=... PASS_P=... \
sh ferramentas/operacao/verificar_saas.sh
```

Verifica: login legítimo em cada host; token da empresa A rejeitado (HTTP 403) na empresa B e na plataforma; `GET /api/sistema/empresas` não vazio em cada empresa; `GET /api/saude`. Sai com código diferente de zero se algo falhar. Introduzir as palavras-passe sem as deixar no histórico da shell (por exemplo `read -r PASS_A` e `export PASS_A`).

## Cache de empresas por utilizador

`ServicoEmpresas` guarda em cache a lista de empresas acessíveis a cada utilizador, com uma versão global (chave `empresas:versao`). `invalidarTodos()` incrementa essa versão e invalida todas as listas de uma vez; é chamado automaticamente quando uma empresa muda (modelo `Empresa`) ou um grupo é alterado. Se uma lista estiver errada (por exemplo vazia por um estado antigo da cache), invalidar à mão:

```
docker compose exec app php artisan tinker --execute='app(App\Services\Sistema\ServicoEmpresas::class)->invalidarTodos();'
```

Em alternativa, `php artisan cache:clear` (limpa tudo, incluindo a versão). Se a cache estiver a cair para `database` por causa do `backend/.env` ilegível, corrigir primeiro a permissão.

## Reverter

1. `SAAS_ATIVO=false` e retirar `DB_APP_USERNAME` e `DB_APP_PASSWORD` do `.env` da raiz.
2. `docker compose up -d app worker scheduler`.
3. A RLS fica inerte para o superutilizador. Se houve dano nos dados, restaurar o backup (abaixo).
4. Confirmar com `verificar_saas.sh`.

## Backups e restauro

- Antes da ativação foi feito um backup, guardado em `~/erp-backups` no servidor.
- `ferramentas/operacao/backup.sh`: faz `pg_dump` em formato custom, verifica o índice do dump e, opcionalmente, arquiva `storage`, Redis e segredos cifrados (precisa de `ERP_COPIAS_CHAVE`, guardada fora do servidor). Gera `<destino>/{diarias,semanais,mensais}/AAAAMMDD-HHMMSS/` com `MANIFESTO.txt` e `SHA256SUMS`, e roda por retenção (7 diárias, 4 semanais, 12 mensais por omissão). Variáveis: `PGHOST`, `PGUSER`, `PGPASSWORD`, `PGDATABASE` (ou `POSTGRES_*`), `ERP_COPIAS_DESTINO`, etc.; ver o cabeçalho do script.
- `ferramentas/operacao/restaurar.sh <pasta_da_copia|ficheiro.dump> <base_destino> [--confirmar=<base>] [--storage-destino=<pasta>] [--jobs=n]`: restaura para uma base **nova** (nunca escreve sobre uma existente), confere `SHA256SUMS` e o índice antes, e apaga a base parcial se falhar. Para repor produção: restaurar para uma base nova, validar, e trocar `DB_DATABASE`. Ver [PRODUCAO.md](../PRODUCAO.md).
- Depois de restaurar, repetir a verificação e invalidar a cache de empresas.
