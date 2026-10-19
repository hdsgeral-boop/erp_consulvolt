# Deploy automático para a AWS

Cada push no branch `main` atualiza o servidor. O workflow é `.github/workflows/deploy-aws.yml`.

O que acontece a cada push:
1. Compila o frontend (`npm run build`).
2. Envia o código para `~/erp` no servidor por rsync, sem apagar ficheiros existentes. Preserva `backend/storage` e `backend/bootstrap/cache`, além de `.env`, `.segredos`, `vendor`, `node_modules` e backups. Ficheiros de código removidos do repositório requerem limpeza manual no servidor.
3. Roda `docker compose up -d --build`, `composer install`, `php artisan migrate --force` e reinicia os serviços.
4. Verifica se o site responde.

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
