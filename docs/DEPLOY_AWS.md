# Deploy automático para a AWS

Cada push no branch `main` atualiza o servidor. O workflow é `.github/workflows/deploy-aws.yml`.

O que acontece a cada push:
1. Compila o frontend (`npm run build`).
2. Envia o código para `~/erp` no servidor por rsync, sem apagar ficheiros existentes. Preserva `backend/storage` e `backend/bootstrap/cache`, além de `.env`, `.segredos`, `vendor`, `node_modules` e backups. Ficheiros de código removidos do repositório requerem limpeza manual no servidor.
3. Roda `docker compose up -d --build`, `composer install`, `php artisan migrate --force --database=pgsql_owner` e reinicia os serviços.
4. Verifica se o site responde.

## Estado atual (2026-10-19)

- O SaaS está ativado em produção: a aplicação liga à base como `erp_app`, que não tem DDL. Por isso as migrações correm pela ligação `pgsql_owner` (`--database=pgsql_owner`), como o workflow já faz. Migrações manuais no servidor também têm de usar essa opção.
- O workflow **não toca em nenhum `.env`** (nem `.env` da raiz, nem `backend/.env`): estão excluídos do rsync. Alterações de configuração fazem-se à mão no servidor e exigem `docker compose up -d` para os serviços as lerem.
- O `backend/.env` tem de ser legível pelo utilizador do PHP-FPM no contentor (`www-data`, uid/gid 82): `chgrp 82 backend/.env && chmod 640 backend/.env`. Senão a aplicação ignora o ficheiro e todos os logins falham com `ERRO_INTERNO`.
- Depois de cada deploy relevante, correr `ferramentas/operacao/verificar_saas.sh`.
- Ativação, reversão e lições: [plataforma-saas/RUNBOOK_ATIVACAO.md](plataforma-saas/RUNBOOK_ATIVACAO.md). Proxy HTTPS: [deploy/caddy/Caddyfile.exemplo](../deploy/caddy/Caddyfile.exemplo).

## Configuração (uma vez)

**1. Chave SSH para o deploy** (no seu computador):

```
ssh-keygen -t ed25519 -f erp-deploy -N ""
```

Adicione o conteúdo de `erp-deploy.pub` ao final de `~/.ssh/authorized_keys` no servidor. Pode ser pelo EC2 Instance Connect no console.

**2. Segredos do repositório** (GitHub > Settings > Secrets and variables > Actions > Secrets):

| Nome | Valor |
| --- | --- |
| `SERVER_HOST` | `13.53.43.240` |
| `SERVER_USER` | `ubuntu` |
| `SERVER_SSH_KEY` | conteúdo completo do arquivo `erp-deploy` (chave privada) |

**3. Ativar o deploy** (GitHub > Settings > Secrets and variables > Actions > Variables):

| Nome | Valor |
| --- | --- |
| `DEPLOY_ENABLED` | `true` |

Enquanto `DEPLOY_ENABLED` não for `true`, o workflow não roda.

## Atenção: porta 22

O security group `erp-web` libera a porta 22 só para o seu IP. Os runners do GitHub usam IPs que mudam, então o deploy só funciona se a porta 22 estiver aberta para a internet, com autenticação só por chave. Isso é uma decisão de segurança: confirme antes de liberar.

## Testar

Depois de configurar, vá em Actions > Deploy AWS > Run workflow. O log mostra cada etapa.
