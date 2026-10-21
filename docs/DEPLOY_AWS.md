# Produção na AWS

> Estado em 2026-10-21: servidor **independente** do PC local (sem túnel Cloudflare). O workflow
> `.github/workflows/deploy-aws.yml` descrito mais abaixo era do servidor antigo (13.53.43.240, já não existe) e
> **não está activo**.

## Arquitectura (eu-north-1, Estocolmo)

| Peça | Recurso |
| --- | --- |
| Servidor | EC2 `meuerp-app` (t3.large, Ubuntu 24.04, disco gp3 40 GB encriptado), IP fixo **13.62.141.36** |
| Base de dados | RDS PostgreSQL 16 `meuerp-db` (db.t4g.medium, 50 GB gp3 até 200 GB, encriptada, cópias 7 dias, protecção contra eliminação) |
| Rede | SG `erp-aws-app` (80/443 abertos), SG `erp-aws-db` (5432 só a partir do servidor) |
| Acesso ao servidor | SSM Session Manager (sem SSH): `aws ssm start-session --target <id-da-instância>` |
| Segredos | palavra-passe mestra da RDS em SSM Parameter Store `/meuerp/rds/master_password`; `.env` da raiz só no servidor |
| Transferências | bucket S3 privado `meuerp-transferencia-962367498111` |

No servidor o projecto está em `/opt/erp` e corre com:

```
docker compose -f docker-compose.yml -f docker-compose.aws.yml up -d
```

`docker-compose.aws.yml` liga a aplicação à RDS (`DB_HOST_RDS`), desliga o contentor postgres e põe o Caddy nas portas
80/443 (`deploy/caddy/Caddyfile.aws`).

## Domínio (meuerp.ao)

DNS na zona Route 53 `meuerp.ao` (Z05803741C631O6LW4A1T), nameservers ns-900.awsdns-48.net, ns-278.awsdns-34.com,
ns-1302.awsdns-34.org, ns-1927.awsdns-48.co.uk. Registos: `meuerp.ao`, `www` e `*` → 13.62.141.36; e-mail StackMail
(MX, SPF, DMARC, DKIM `s1`, CNAME mail/smtp/imap/pop/webmail/autodiscover/ftp) copiado da Cloudflare em 2026-10-21.

HTTPS a pedido no Caddy (`deploy/caddy/Caddyfile.aws`): no primeiro acesso a um nome o Caddy pergunta ao Laravel
(`/api/sistema/dominio-permitido`) se é do sistema e obtém o certificado Let's Encrypt. Clientes novos não precisam de
configuração. `DOMINIO_BASE=meuerp.ao` no `.env` da raiz (Caddy) e no `backend/.env` (Laravel).

## Instalar ou actualizar

`deploy/aws/instalar.sh` (corre como root no servidor, via SSM): descarrega o pacote do bucket, cria o `.env` na primeira
vez, opcionalmente restaura a base (`--restaurar`, só numa base vazia), constrói, instala dependências, aplica migrações
(`--database=pgsql_owner`) e a RLS, faz a cache e reinicia. Depois de alterar código PHP: `restart app worker scheduler`
(opcache sem revalidação).

---

## Workflow antigo (servidor 13.53.43.240, inactivo)


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

## Desempenho: cache do Laravel

O deploy corre `php artisan optimize` e `view:cache` (cache de configuração, rotas, eventos e views). Sem isso cada
pedido recarrega todos os ficheiros e a página passa de ~0,2 s para ~3 s (medido no Docker local do Windows).

- **Depois de alterar um `.env` ou configuração**: `docker compose exec app php artisan optimize:clear && docker compose exec app php artisan optimize`
  e `docker compose restart app worker scheduler`.
- **Antes de correr testes** (PHPUnit) no mesmo contentor: `php artisan optimize:clear`, senão os testes usam a configuração em cache.
- No Docker local, depois de editar código ou rotas: `docker compose exec app php artisan optimize:clear`.
