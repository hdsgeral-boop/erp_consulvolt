# Ambiente de TESTE na AWS — teste.consulvolt.meuerp.ao

Instância EC2 `erp-teste-app` (t3.micro, eu-north-1, Ubuntu 24.04, Docker 29), código em `/opt/erp`, dados em volumes
Docker, cópias em `/srv/erp/copias`, chaves AGT em `/srv/erp/segredos`. O nginx do host termina o HTTPS (Let's Encrypt,
certbot) e encaminha para o contentor `web` (127.0.0.1:8080). O DNS (`teste.consulvolt.meuerp.ao`) está no Route53.

É um ambiente descartável: não partilha nada com a produção (`meuerp.ao`, instância `meuerp-app` + RDS) nem com o
serviço ODK no Lightsail.

## Stack (desde 2026-11-02)

| Componente | Versão | Onde |
|---|---|---|
| PHP | 8.5 (php:8.5-fpm-alpine, phpredis 6.3 compilado do GitHub: o PECL deixou de publicar) | `docker/php/Dockerfile.prod`, `docker/php/Dockerfile` |
| Node (só no build do frontend) | 26 | `docker/php/Dockerfile.prod` |
| nginx (contentor `web`) | nginxinc/nginx-unprivileged 1.31 | idem |
| PostgreSQL | 18 (volume `erp_pgdados18` montado em `/var/lib/postgresql`) | `docker-compose.prod.yml` |
| Redis | 8 | idem |
| Laravel / React | ver `backend/composer.json` e `frontend/package.json` | |

## Como actualizar o servidor de teste

A t3.micro (0,9 GB de RAM) não consegue construir as imagens: constroem-se noutra máquina (com Docker, amd64),
enviam-se para o bucket de transferência do S3 e carregam-se no servidor com `docker load`.

```sh
# 1. Na máquina de desenvolvimento (raiz do repositório)
V=teste-$(date +%Y%m%d-%H%M)
docker build -f docker/php/Dockerfile.prod --target app    --build-arg ERP_VERSAO=$V -t erp_consulvolt/app:$V .
docker build -f docker/php/Dockerfile.prod --target web    -t erp_consulvolt/web:$V .
docker build -f docker/php/Dockerfile.prod --target copias -t erp_consulvolt/copias:$V .
docker save erp_consulvolt/app:$V erp_consulvolt/web:$V erp_consulvolt/copias:$V | gzip -1 > /tmp/imagens-$V.tar.gz
tar -czf /tmp/codigo-$V.tar.gz --exclude=.git --exclude=node_modules --exclude=backend/vendor --exclude=frontend/dist \
    --exclude='.env*' --exclude=copias --exclude='*.dump' .
aws s3 cp /tmp/codigo-$V.tar.gz  s3://meuerp-transferencia-962367498111/teste-deploy/codigo-$V.tar.gz  --profile erp-cli
aws s3 cp /tmp/imagens-$V.tar.gz s3://meuerp-transferencia-962367498111/teste-deploy/imagens-$V.tar.gz --profile erp-cli
URL_C=$(aws s3 presign s3://meuerp-transferencia-962367498111/teste-deploy/codigo-$V.tar.gz  --expires-in 3600 --profile erp-cli)
URL_I=$(aws s3 presign s3://meuerp-transferencia-962367498111/teste-deploy/imagens-$V.tar.gz --expires-in 3600 --profile erp-cli)

# 2. No servidor (por SSM ou SSH), como root — o script descarrega, carrega as imagens, substitui o código em /opt/erp
#    (preserva .env.prod, .segredos, docker-compose.teste.yml) e corre deploy.sh --sem-build com os limites do teste
sudo sh /opt/erp/ferramentas/operacao/actualizar-teste.sh "$V" "$URL_C" "$URL_I"
```

`docker load` aceita o `.tar.gz` directamente. O `docker-compose.teste.yml` (só no servidor) reduz os limites de memória
dos serviços; `prod.sh`, `deploy.sh` e `migrar-postgres18.sh` aplicam-no através de `ERP_COMPOSE_EXTRA="-f docker-compose.teste.yml"`.

## Migração PostgreSQL 16 → 18 (feita uma vez)

```sh
cd /opt/erp && ERP_COMPOSE_EXTRA="-f docker-compose.teste.yml" sudo -E sh ferramentas/operacao/migrar-postgres18.sh --sem-confirmar
```
Faz `pg_dump` do contentor 16, arranca o 18 no volume novo `erp_pgdados18`, restaura, confere o número de tabelas e
linhas e arranca o resto. O volume antigo `erp_consulvolt_prod_erp_pgdados` fica intacto até ser apagado à mão.

## Imagens no Cloudflare R2

Logótipos (empresas e ecrã de entrada) e imagens de produtos vão para um bucket R2 e a base guarda só o URL público
(`ServicoImagensPublicas`, `config/filesystems.php › imagens`). Para activar no teste:

1. Painel Cloudflare › R2 › activar o R2 (pede um método de pagamento, mesmo no escalão gratuito: 10 GB).
2. Criar o bucket `meuerp-teste-imagens` (região automática) e, em *Settings › Public access*, activar o subdomínio
   `r2.dev` (ou ligar um domínio próprio). Copiar o URL público e o *S3 API endpoint* (`https://<account_id>.r2.cloudflarestorage.com`).
3. R2 › *Manage API tokens* › *Create API token* com **Object Read & Write** limitado a esse bucket. Copiar Access Key ID
   e Secret Access Key.
4. Em `/opt/erp/.env.prod`: `ERP_IMAGENS_DISCO=imagens`, `R2_BUCKET`, `R2_ENDPOINT`, `R2_URL_PUBLICA`, `R2_ACCESS_KEY_ID`,
   `R2_SECRET_ACCESS_KEY`; depois `prod.sh up -d --force-recreate app worker scheduler`.
5. Migrar o que já existe: `docker exec erp_consulvolt_prod-app-1 php artisan erp:imagens:migrar --simular` e, se bater
   certo, sem `--simular`.
6. CORS do bucket (só se as impressões/PDF desenharem o logótipo numa canvas): origem `https://teste.consulvolt.meuerp.ao`, método GET.

A CSP do nginx (`docker/nginx/producao/cabecalhos-seguranca.conf`) já permite `img-src https://*.r2.dev`; com um domínio
próprio, acrescentá-lo lá.

## Limpeza feita em 2026-11-02

Build cache do Docker (4,3 GB), imagens e volumes sem uso apagados; ficheiros do legado (`wstb_payroll_backup_*.json`,
`esquema.json`) comprimidos em `/srv/erp/legado` (root, 640); nginx do host com `proxy_http_version 1.1` (sem isto o
contentor não comprimia: 2,1 MB de JS passaram a ~660 KB); `PermitRootLogin no`; pacotes do Ubuntu actualizados.
