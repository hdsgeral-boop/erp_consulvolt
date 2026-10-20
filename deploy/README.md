# deploy

Ficheiros de exemplo para o servidor de produção (nada aqui é lido pela aplicação).

- `caddy/Caddyfile.exemplo`: configuração do Caddy (proxy HTTPS) usada em produção. Copiar para `/etc/caddy/Caddyfile` e recarregar com `systemctl reload caddy`.

Ver também [docs/DEPLOY_AWS.md](../docs/DEPLOY_AWS.md) e [docs/plataforma-saas/RUNBOOK_ATIVACAO.md](../docs/plataforma-saas/RUNBOOK_ATIVACAO.md).
