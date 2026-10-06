# Produção — runbook do ERP Consulvolt

Guia de operação da nova arquitectura em produção: instalação, implantação de versões, rollback, cópias de segurança, monitorização e o **plano da migração definitiva** do legado (`payroll_system_web`, IndexedDB) para o PostgreSQL.

| Peça | Ficheiro |
| :--- | :--- |
| Imagens de produção (multi-stage: `app`, `web`, `copias`) | `docker/php/Dockerfile.prod` |
| Configuração PHP/FPM de produção | `docker/php/producao/{php.ini,www.conf,entrypoint.sh}` |
| nginx de produção (SPA + `/api`, cabeçalhos, gzip, cache) | `docker/nginx/producao/*.conf` |
| Orquestração | `docker-compose.prod.yml` |
| Variáveis (modelo documentado) | `.env.prod.example` → `.env.prod` (fora do Git) |
| Extensões do PostgreSQL de produção | `docker/postgres/producao/01_extensoes.sql` |
| Operação | `ferramentas/operacao/{prod.sh,deploy.sh,backup.sh,restaurar.sh,copias-agendadas.sh}` |
| Integração contínua | `.github/workflows/ci.yml` |

> Convenção: todos os comandos são executados na raiz do repositório, no servidor. `prod.sh` é um atalho para `docker compose -f docker-compose.prod.yml --env-file .env.prod`. Num clone novo em Linux, se os scripts não forem executáveis, use `sh ferramentas/operacao/<script>.sh`.

---

## 1. Arquitectura em produção

```
Internet ──HTTPS──► reverse proxy do host (Caddy / nginx / Traefik; certificado TLS)
                      │  http://127.0.0.1:8080   (X-Forwarded-For / X-Forwarded-Proto)
                      ▼
                   web  (nginx sem root, 8080) ── SPA React (frontend/dist dentro da imagem)
                      │  FastCGI app:9000 (/api, /up)
                      ▼
                   app  (PHP 8.3-FPM sem root, código imutável, OPcache sem revalidação)
                   worker (queue:work redis — alta, agt, default, pdfs, baixa)
                   scheduler (schedule:work — ciclo AGT 2/2 min, partições da auditoria, contratos)
                      │
          ┌───────────┴───────────┐
       postgres 16             redis 7 (palavra-passe; cache, locks, filas, sessões)
       (volume erp_pgdados)     (volume erp_redisdados)
                   copias (pg_dump agendado + storage + segredos cifrados → ERP_PASTA_COPIAS)
```

Nenhuma porta da base de dados ou do Redis é publicada. Só o nginx do ERP fica publicado, e apenas em `127.0.0.1` (o reverse proxy é a única porta de entrada).

Princípios de segurança das imagens:
- código, `vendor` (sem dependências de desenvolvimento) e frontend construído **dentro** das imagens; nada montado a partir do disco, excepto as chaves AGT (só leitura) e os volumes de dados;
- PHP-FPM corre como `www-data` (uid 82) e o nginx como utilizador sem privilégios; sistema de ficheiros **só de leitura** nos serviços PHP e web (caches em `tmpfs`), `cap_drop: ALL`, `no-new-privileges`;
- nenhum segredo nas imagens: `APP_KEY`, palavras-passe e chaves chegam em tempo de execução; `config:cache` corre no arranque de cada contentor, e as caches de rotas e eventos já vêm do build;
- o `.dockerignore` impede que `.env*`, `.segredos/`, cópias, dumps, `node_modules`, `vendor` e o backup legado entrem no contexto de build.

## 2. Requisitos do servidor

| Recurso | Mínimo | Recomendado |
| :--- | :--- | :--- |
| Sistema | Linux x86-64 (Ubuntu 24.04 LTS / Debian 12) | idem, com actualizações automáticas de segurança |
| CPU | 4 vCPU | 8 vCPU |
| RAM | 8 GB | 16 GB (os limites do compose somam cerca de 7,5 GB) |
| Disco | 60 GB SSD | 100 GB SSD para o sistema e os volumes, mais um **disco separado** (ou montagem remota) para as cópias |
| Software | Docker Engine 26+ com Compose v2.24+ | idem; `git`; reverse proxy com TLS (Caddy recomendado) |
| Rede | Saída HTTPS para a AGT (`sigt.agt.minfin.gov.ao`), para o BAI (`www.bancobai.ao` — «Câmbios do BAI», lido pelo servidor) e para o registo de imagens | firewall: só 22 (SSH, restrito) e 80/443 abertos |
| Relógio | NTP activo (assinaturas JWS da AGT, sessões) | idem |

Os fusos horários estão fixados: a aplicação corre em `Africa/Luanda` e o PostgreSQL guarda em UTC (`TIMESTAMPTZ`, ADR-011).

## 3. Primeira instalação

```bash
# 1. Utilizador e pastas fora do repositório
sudo mkdir -p /srv/erp/{segredos/agt/contribuintes,copias,legado}
sudo git clone git@github.com:hdsgeral-boop/erp_consulvolt.git /srv/erp/app && cd /srv/erp/app

# 2. Variáveis de produção (ver secção 4)
cp .env.prod.example .env.prod && chmod 600 .env.prod
#    - gerar POSTGRES_PASSWORD, REDIS_PASSWORD (openssl rand -base64 36 | tr -d '/+=' | cut -c1-40)
#    - gerar APP_KEY:
docker run --rm php:8.3-cli-alpine php -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'
#    - APP_URL=https://<domínio>, AGT_PASTA_SEGREDOS=/srv/erp/segredos/agt, ERP_PASTA_COPIAS=/srv/erp/copias

# 3. Chaves da AGT (ver secção 11) — legíveis só pelo uid 82 (www-data das imagens)
sudo chown -R 82:82 /srv/erp/segredos/agt && sudo chmod -R u=rX,go= /srv/erp/segredos/agt

# 4. Construir e arrancar
export ERP_VERSAO=$(git describe --always --tags)
sh ferramentas/operacao/prod.sh build
sh ferramentas/operacao/prod.sh up -d --wait postgres redis
sh ferramentas/operacao/prod.sh run --rm --no-deps app php artisan migrate --force
sh ferramentas/operacao/prod.sh up -d --wait

# 5. Verificar
curl -s http://127.0.0.1:8080/api/saude | jq .dados
sh ferramentas/operacao/prod.sh exec app php artisan about --only=environment,cache,drivers
```

A base fica vazia até à migração definitiva (secção 13). As partições da auditoria são criadas pelas migrações e, depois, todos os anos pelo agendador (secção 10).

## 4. Variáveis de ambiente

Todas estão documentadas em `.env.prod.example`. O ficheiro é lido de duas formas: `--env-file` (substitui as `${...}` do compose: portas, imagens, credenciais da base, Redis e cópias) e `env_file` (ambiente do Laravel nos serviços `app`, `worker` e `scheduler`). As ligações internas (`DB_HOST=postgres`, `REDIS_HOST=redis`, credenciais) são impostas pelo próprio compose.

| Grupo | Variáveis essenciais | Notas |
| :--- | :--- | :--- |
| Aplicação | `APP_KEY`, `APP_URL`, `APP_ENV=production`, `APP_DEBUG=false` | Sem `APP_KEY` o contentor não arranca. A `APP_KEY` nunca muda; para rodá-la, use `APP_PREVIOUS_KEYS` |
| Base de dados | `POSTGRES_DB`, `POSTGRES_USER`, `POSTGRES_PASSWORD` | O ERP usa as mesmas credenciais |
| Redis | `REDIS_PASSWORD` | Obrigatória |
| Filas | `QUEUE_CONNECTION=redis`, `REDIS_QUEUE_RETRY_AFTER=660` | **Tem de ser maior do que o `--timeout=600` do worker.** Com o valor por omissão (90 s), um trabalho longo seria repetido em paralelo |
| Logs | `LOG_CHANNEL=stderr`, `LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter`, `LOG_LEVEL=info` | JSON no `docker logs` |
| Sessão | `SESSION_DRIVER=redis`, `SESSION_SECURE_COOKIE=true`, `ERP_SESSAO_*` | A API usa tokens Bearer (ADR-007) e `SANCTUM_STATEFUL_DOMAINS` fica vazio |
| Manutenção | `APP_MAINTENANCE_DRIVER=cache`, `APP_MAINTENANCE_STORE=redis` | `artisan down` partilhado pelos contentores |
| AGT | `AGT_PASTA_SEGREDOS`, `AGT_DRIVER`, `AGT_AMBIENTE`, `AGT_*` | Ver secção 11 |
| E-mail | `MAIL_*` | `log` até haver envio real (o CRM só regista e-mails, ADR-054) |
| Cópias | `ERP_PASTA_COPIAS`, `ERP_COPIAS_CRON`, `ERP_COPIAS_RETER_*`, `ERP_COPIAS_CHAVE` | Ver secção 8 |
| Assistente IA | `ANTHROPIC_API_KEY`, `ERP_IA_MODELO`, `ERP_IA_ESFORCO`, `ERP_IA_TEMPO_LIMITE` (e, raramente, `ERP_IA_MAX_TOKENS`, `ERP_IA_MAX_FICHEIRO_KB`, `ERP_IA_MAX_CONTAS`, `ERP_IA_PRECO_ENTRADA`, `ERP_IA_PRECO_SAIDA`) | Opcional. Sem chave, o assistente só usa as regras internas. A chave só no `.env.prod` (nunca no Git). Ver secção 11-A |
| Capacidade | `PHP_FPM_*`, `PG_SHARED_BUFFERS`, `PG_EFFECTIVE_CACHE_SIZE` | Ajustar à RAM do servidor |

Depois de alterar o `.env.prod`, recrie os serviços com `prod.sh up -d` (o `config:cache` corre de novo no arranque).

## 5. HTTPS (reverse proxy)

O contentor `web` serve HTTP em `127.0.0.1:${WEB_PORTA}`. O TLS termina num reverse proxy no host, que tem de:
1. **definir** `X-Forwarded-For` com o IP do cliente, substituindo qualquer valor recebido (nunca acrescentar). O nginx do ERP confia neste cabeçalho quando vem de redes privadas, e o limite de tentativas de login usa esse IP;
2. definir `X-Forwarded-Proto https`. Com isso o nginx passa `HTTPS=on` ao Laravel e envia `Strict-Transport-Security`;
3. permitir pedidos até 100 MB e timeouts de 180 s.

**Nunca publique o contentor `web` directamente na Internet** (`WEB_ENDERECO=0.0.0.0`) sem o proxy à frente. O nginx confia no `X-Forwarded-For` vindo de redes privadas, e a rede Docker é uma delas. Sem o proxy, um cliente poderia falsificar o IP e contornar o limite de tentativas de login.

Exemplo com **Caddy** (certificado Let's Encrypt automático), em `/etc/caddy/Caddyfile`:

```caddy
erp.exemplo.ao {
    encode zstd gzip
    request_body { max_size 100MB }
    reverse_proxy 127.0.0.1:8080 {
        header_up X-Forwarded-For {remote_host}
        header_up X-Forwarded-Proto {scheme}
        transport http { read_timeout 180s }
    }
}
```

Exemplo com **nginx** no host:

```nginx
server {
    listen 443 ssl http2;
    server_name erp.exemplo.ao;
    ssl_certificate     /etc/letsencrypt/live/erp.exemplo.ao/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/erp.exemplo.ao/privkey.pem;
    client_max_body_size 100M;
    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $remote_addr;     # definir, não acrescentar
        proxy_set_header X-Forwarded-Proto https;
        proxy_read_timeout 180s;
    }
}
server { listen 80; server_name erp.exemplo.ao; return 301 https://$host$request_uri; }
```

Cabeçalhos enviados pelo ERP (`docker/nginx/producao/cabecalhos-seguranca.conf`):
- **CSP** própria do SPA: `script-src 'self'`, sem `unsafe-eval` e sem scripts inline. A única excepção é o hash do `onclick="window.print()"` da janela de reimpressão do POS. `style-src 'unsafe-inline'` é necessário, porque o Ant Design injecta estilos;
- `X-Content-Type-Options`, `X-Frame-Options SAMEORIGIN`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Resource-Policy`, `X-Request-Id`;
- HSTS (1 ano), só quando o pedido chegou por HTTPS.

Cache: os activos `/assets/*` (com hash) ficam em cache durante 1 ano (`immutable`); o `index.html` nunca fica em cache. Cada deploy é visto de imediato, sem activos antigos.

## 6. Implantar uma nova versão

O caminho normal é o script, que faz build, cópia, migrações, recriação dos serviços e verificação de saúde:

```bash
cd /srv/erp/app && git fetch && git checkout <tag-ou-commit>
sh ferramentas/operacao/deploy.sh                 # --manutencao para migrações longas; --sem-copia só em emergência
```

Passos manuais equivalentes:

```bash
export ERP_VERSAO=$(git describe --always --tags)
sh ferramentas/operacao/prod.sh build app web copias
sh ferramentas/operacao/prod.sh run --rm --no-deps copias /operacao/backup.sh          # cópia ANTES de migrar
sh ferramentas/operacao/prod.sh exec app php artisan down --retry=30                   # opcional
sh ferramentas/operacao/prod.sh run --rm --no-deps app php artisan migrate --force      # nova imagem
sh ferramentas/operacao/prod.sh up -d --wait --remove-orphans                          # recria app/web/worker/scheduler
sh ferramentas/operacao/prod.sh exec app php artisan up
curl -s http://127.0.0.1:8080/api/saude | jq '.dados.versao, .dados.estado'
```

Notas:
- **Caches.** O `config:cache` corre automaticamente no arranque de cada contentor; `route:cache` e `event:cache` vêm do build; o OPcache é recriado com a imagem. Nunca é preciso `cache:clear` para código novo. `php artisan cache:clear` limpa só dados em cache no Redis (empresas acessíveis por utilizador, plano de contas, catálogo de produtos, painéis; as permissões não estão em cache — são memorizadas por pedido) e usa-se sempre que dados de referência forem alterados por fora da aplicação (SQL directo, restauro de cópia, migração do legado — passo obrigatório na secção 13.4).
- **Worker.** Ao ser recriado, o worker recebe SIGTERM e termina o trabalho em curso (`stop_grace_period: 300s`). O `--max-time=3600` recicla-o de hora a hora. `php artisan queue:restart` só é preciso se o worker não for recriado.
- **Scheduler.** Os serviços PHP correm com `init: true` (tini), por isso o `schedule:work` pára em cerca de 1 s. Sem o tini ignorava o SIGTERM e só morria por SIGKILL, 60 s depois. Uma tarefa `withoutOverlapping` interrompida (ex.: `erp:agt:ciclo`) deixaria o mutex activo até 24 h. Por isso o `deploy.sh` corre `php artisan schedule:clear-cache` logo a seguir a recriar os serviços. Faça o mesmo à mão se o ciclo AGT deixar de correr (`schedule:list` / logs do scheduler).
- **Migrações.** Devem ser compatíveis com a versão anterior sempre que possível (expandir → migrar → contrair), para que o rollback não exija restauro.
- A versão em serviço aparece em `GET /api/saude` → `dados.versao` e fica registada em `.versoes-implantadas`.

## 7. Rollback

1. **Só código (sem migrações novas, ou com migrações compatíveis):**
   ```bash
   tail -n 5 .versoes-implantadas                         # versões anteriores
   ERP_VERSAO=<anterior> sh ferramentas/operacao/prod.sh up -d --wait   # as imagens antigas continuam locais
   ```
   Se a imagem já não existir: `git checkout <anterior> && sh ferramentas/operacao/deploy.sh --sem-copia`.
2. **Com migrações reversíveis:** `prod.sh run --rm --no-deps app php artisan migrate:rollback --step=<n> --force` (com a imagem **nova**, que conhece as migrações), seguido do passo 1.
3. **Com migrações destrutivas ou dados corrompidos:** restaurar a cópia feita pelo deploy (secção 9) para uma base nova, apontar `POSTGRES_DB` para ela e fazer o passo 1.

Imagens antigas: guardar pelo menos as 3 últimas versões. Limpeza: `docker image prune --filter "until=720h"`.

## 8. Cópias de segurança

O serviço `copias` corre `ferramentas/operacao/backup.sh` com o cron definido em `ERP_COPIAS_CRON` (por omissão às 02:30, hora de Luanda). Cada cópia fica em `ERP_PASTA_COPIAS/diarias/AAAAMMDD-HHMMSS/` com:

| Ficheiro | Conteúdo |
| :--- | :--- |
| `base_<base>.dump` | `pg_dump` em formato custom comprimido |
| `base_<base>.lista` | índice `pg_restore --list` (prova de legibilidade; a cópia falha se não houver dados de tabelas) |
| `storage.tar.gz` | `storage/app` do Laravel: relatórios da migração, cópias por empresa, importações |
| `redis.tar.gz` | opcional (`ERP_COPIAS_REDIS_DIR=/dados/redis`): filas pendentes |
| `segredos.tar.gz.enc` | opcional (`ERP_COPIAS_CHAVE`): chaves AGT cifradas em AES-256 (PBKDF2, 200 000 iterações), com a decifra verificada |
| `MANIFESTO.txt`, `SHA256SUMS` | versão do servidor, n.º de migrações, tamanhos e somas |

Rotação: as últimas `ERP_COPIAS_RETER_DIARIAS` (7). A cópia de domingo é guardada também em `semanais/` (4) e a do dia 1 em `mensais/` (12), por ligação física, sem ocupar espaço a dobrar.

```bash
sh ferramentas/operacao/prod.sh run --rm --no-deps copias /operacao/backup.sh   # cópia imediata
sh ferramentas/operacao/prod.sh logs --tail=50 copias                            # resultado das agendadas
ls -l /srv/erp/copias/diarias/
```

Obrigações:
- **Fora do servidor.** Sincronizar `ERP_PASTA_COPIAS` para outro local todos os dias (ex.: `rclone sync /srv/erp/copias remoto:erp-copias` num cron do host, ou um disco montado de outra máquina). Uma cópia no mesmo disco não protege contra a perda do servidor.
- **`ERP_COPIAS_CHAVE` num cofre**, fora do servidor. Sem ela, as chaves AGT copiadas são irrecuperáveis. Atenção: o `.env.prod` é lido pelos serviços PHP, que também vêem esta variável.
- **Teste de restauro mensal** (secção 9) para uma base descartável, com o resultado registado.

Alternativa ao serviço `copias` (cron do host): `30 2 * * * cd /srv/erp/app && sh ferramentas/operacao/prod.sh run --rm --no-deps copias /operacao/backup.sh >> /var/log/erp-copias.log 2>&1`.

## 9. Restauro

O `restaurar.sh` **só cria bases novas** e nunca escreve sobre uma base existente. Antes de criar a base verifica o `SHA256SUMS` e o índice do dump. Se falhar a meio, apaga a base parcial.

```bash
# 1. Restaurar para uma base nova (pede para escrever o nome da base; não interactivo: --confirmar=<base>)
sh ferramentas/operacao/prod.sh run --rm --no-deps copias \
   /operacao/restaurar.sh /copias/diarias/20261001-023000 erp_restauro_20261001

# 2. Conferir (contagens no fim do restauro; conferências da secção 13.5 se for um incidente)

# 3. Pôr em serviço: parar quem escreve, trocar a base e recriar
sh ferramentas/operacao/prod.sh stop app worker scheduler
#    no .env.prod: POSTGRES_DB=erp_restauro_20261001   (ou renomear as bases, ver abaixo)
sh ferramentas/operacao/prod.sh up -d --wait
```

Para manter o nome original: `ALTER DATABASE erp_consulvolt RENAME TO erp_consulvolt_incidente_<data>; ALTER DATABASE erp_restauro_20261001 RENAME TO erp_consulvolt;`, com os serviços PHP parados e ligado à base `postgres`.

Ficheiros: `restaurar.sh ... --storage-destino=/tmp/storage_restaurado` extrai o `storage.tar.gz`. Para repor no volume:
`docker run --rm -v erp_consulvolt_prod_erp_storage:/destino -v /srv/erp/copias/diarias/<cópia>:/c:ro alpine sh -c 'tar -C /destino -xzf /c/storage.tar.gz && chown -R 82:82 /destino'`.

Segredos: `openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -in segredos.tar.gz.enc | tar -C /srv/erp/segredos/agt -xz` (pede a `ERP_COPIAS_CHAVE`).

## 10. Monitorização, logs e manutenção periódica

**Saúde.** `GET /api/saude` (pública) devolve 200 com `dados.estado = OK`, `dados.versao` e os componentes `base_dados`, `redis`, `filas` (tamanho de cada fila e n.º de trabalhos falhados), `armazenamento` (storage gravável) e `processos` (batimentos do scheduler e do worker). Se algum componente falhar, devolve 503 `SERVICO_INDISPONIVEL`, com o estado de cada componente em `erros`. A rota **não passa pelo limitador da API** (que usa o Redis), para responder 503 com o detalhe mesmo com o Redis em baixo.

**Batimentos.** O scheduler grava `batimento:scheduler` na cache a cada minuto e agenda de 5 em 5 min um trabalho na fila `baixa` (`BatimentoWorker`) que grava `batimento:worker`. Em `processos`, um batimento com mais de 5 min (scheduler) ou 15 min (worker) é FALHA, com a idade no detalhe. «sem registo» (ambiente sem scheduler, ou logo depois de um `cache:clear`) é só informativo.

**Comportamento com o Redis em baixo** (o Redis é um ponto único de falha assumido — cache, locks, filas e rate limiting):
- todos os pedidos `/api/*` (incluindo `/api/autenticacao/entrar`) respondem **500 `ERRO_INTERNO`**, porque o limitador e a resolução da empresa activa usam o Redis; só `/api/saude` responde, com **503** e `erros.redis.estado = FALHA`;
- a numeração (vendas, lançamentos, POS, fecho Z) falha antes de gravar: a transacção é desfeita, **sem números perdidos nem duplicados**;
- uma factura emitida no instante da queda fica gravada; se o agendamento do envio à AGT falhar, é registado um aviso no log e o ciclo `erp:agt:ciclo` envia-a quando o Redis voltar (o utilizador recebe a resposta normal: não volta a emitir);
- o worker e o scheduler falham e reiniciam (`restart: unless-stopped`); o ciclo AGT fica parado até o Redis voltar e `processos` passa a FALHA ao fim de 5/15 min;
- com AOF ligado, um reinício do Redis não perde filas, locks únicos nem `empresas:versao`.

Recuperação: `prod.sh ps` / `prod.sh logs redis`; reiniciar com `prod.sh restart redis`; confirmar `GET /api/saude` = 200. Se o Redis tiver perdido os dados (volume apagado), correr `php artisan schedule:clear-cache` (mutexes do agendador) — a cache reconstrói-se sozinha. `GET /up` é o health-check nativo do Laravel, e `GET /nginx-saude` só verifica o nginx. Ligar um monitor externo (UptimeRobot, Uptime Kuma, …) a `https://<domínio>/api/saude`, com alerta ao fim de 2 falhas.

**Healthchecks dos contentores:**

| Serviço | Verificação |
| :--- | :--- |
| `app` | ping do PHP-FPM (`cgi-fcgi`) |
| `web` | `/nginx-saude` |
| `worker`, `scheduler` | processo `artisan queue:work` / `schedule:work` vivo |
| `postgres` | `pg_isready` |
| `redis` | `PING` autenticado |

`prod.sh ps` mostra o estado. Todos os serviços têm `restart: unless-stopped`.

**Logs.** Todos os serviços escrevem para stdout/stderr: o Laravel em JSON (Monolog) e o nginx em JSON (`erp_json`, com `pedido_id` igual ao cabeçalho `X-Request-Id`). O Docker roda os ficheiros (`json-file`, 5 × 20 MB por contentor). Consultar com `prod.sh logs -f --tail=200 app worker` ou `docker logs <contentor> | jq`. O PostgreSQL regista consultas com mais de 2 s e esperas por locks. Pedidos PHP com mais de 10 s geram um slowlog com stack trace.

**Trabalhos falhados.** `prod.sh exec app php artisan queue:failed`, `queue:retry <id|all>`, `queue:flush`. O número aparece em `/api/saude`.

**Partições anuais da auditoria (ADR-009).** O agendador corre `erp:auditoria:particoes --anos=2` a 1 de Dezembro às 02:00. Verificação anual, em Dezembro:
```bash
sh ferramentas/operacao/prod.sh exec app php artisan erp:auditoria:particoes --anos=2   # idempotente; move linhas da DEFAULT
sh ferramentas/operacao/prod.sh exec postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "\d+ logs_auditoria"'
```

**Agendador.** `prod.sh exec scheduler php artisan schedule:list`. Contém o ciclo AGT a cada 2 min (`erp:agt:ciclo`, sem sobreposição), as partições da auditoria e a expiração diária dos contratos de fornecedores às 00:15.

**PostgreSQL.** O autovacuum está activo. Depois da migração definitiva, correr `ANALYZE` (já incluído no restauro). Espaço: `docker system df -v`.

**Actualizações de segurança.** Reconstruir as imagens todos os meses (`deploy.sh` com o mesmo commit), para receber as correcções das imagens base `php:8.3-fpm-alpine`, `nginx-unprivileged`, `postgres:16-alpine` e `redis:7-alpine`. Uma mudança de versão maior do PostgreSQL exige `pg_dump`/restauro.

## 11. Chaves e credenciais da AGT (ADR-030)

- As credenciais (`AGT_UTILIZADOR`, `AGT_PALAVRA_PASSE`, `AGT_PRODUCT_*`, `AGT_PRODUTOR_NIF`, …) ficam só no `.env.prod`, nunca na base de dados nem no browser.
- As chaves PEM ficam em `AGT_PASTA_SEGREDOS` (ex.: `/srv/erp/segredos/agt`), montada só de leitura em `/run/segredos/agt`:
  - `produtor_privada.pem`: chave do produtor de software (assinatura JWS RS256);
  - `saft_privada.pem`: chave do Hash SAF-T (`AGT_VERSAO_CHAVE_SAFT`);
  - `contribuintes/<NIF>.pem`: chave de cada empresa emitente.
- Permissões: `chown -R 82:82` e `chmod -R u=rX,go=`. Ficam legíveis só pelo `www-data` dos contentores.
- Passagem a produção: `AGT_DRIVER=direto` e `AGT_AMBIENTE=homologacao` até a AGT homologar o software. Só depois `AGT_AMBIENTE=producao`. Recriar os serviços PHP a seguir e verificar em *Vendas › Facturação electrónica › Ligação* (`GET /api/vendas/faturacao-eletronica/ligacao`).
- Rotação de uma chave: substituir o ficheiro, incrementar `AGT_VERSAO_CHAVE_SAFT` (se for a do SAF-T) e fazer `prod.sh up -d --force-recreate app worker scheduler`.
- As chaves entram nas cópias só cifradas (`ERP_COPIAS_CHAVE`). Fazer também uma cópia offline, guardada em cofre.

## 11-A. Integrações: câmbios do BAI, Power BI e assistente IA (ronda 2)

### Câmbios do BAI automáticos
- A rotina manual mantém-se (*Configurações › Moedas e câmbios › Câmbios do BAI › Consultar BAI*). A obtenção automática diária liga-se no mesmo separador (quem tem `config_moedas_gerir`): interruptor e hora fixa (por omissão **desligada**, 08:30). Guarda-se em `configuracoes_sistema` (`cambios_bai_auto_ativo`, `cambios_bai_auto_hora`).
- O `scheduler` corre `sistema:cambios-bai` a cada minuto: a partir da hora configurada, se ainda não houve obtenção agendada com sucesso nesse dia, lê a página do BAI (no máximo 3 tentativas por dia, com 30 minutos de intervalo). Precisa de saída HTTPS para `www.bancobai.ao`.
- O resultado fica **só pendente** (`cambios_bai_pendentes`): nada entra nos câmbios sem alguém validar. O ecrã mostra o aviso «Câmbios do BAI por validar», a variação face ao último câmbio (alerta acima de 5 %) e permite validar as moedas escolhidas (gravadas para todas as empresas, origem BAI, na data da cotação) ou rejeitar. Tudo fica na auditoria (`Validação dos câmbios do BAI`, `Câmbios do BAI rejeitados`).
- Cada obtenção fica em `execucoes_cambios_bai`. A falha (ex.: `BAI_INDISPONIVEL`, `BAI_ESTRUTURA` se a página mudar) aparece no ecrã e em `GET /api/saude` → `dados.informacao.cambios_bai` (`DESACTIVADO`, `OK`, `PENDENTE` ou `FALHA`). É **só informativo**: nunca torna a saúde 503.

### Power BI (feed OData de leitura)
- Feed: `https://<domínio>/api/bi/odata` (documento de serviço), `/api/bi/odata/$metadata` (CSDL) e `/api/bi/odata/{conjunto}` — `contabilidade`, `vendas`, `compras`, `tesouraria`, `armazem`, `rh`, `projetos`, `ativos` (lista branca da Análise Dinâmica). 5 000 linhas por página com `@odata.nextLink`; `$top`, `$skip`, `$select`, `$count`; período opcional `data_inicio`/`data_fim` (AAAA-MM-DD); `incluir_apuramento=1` só na contabilidade. `$filter`/`$orderby` respondem 501 (filtrar no Power Query).
- Tokens: *menu do utilizador › Power BI (feed OData)* (`config_backup`). Um token só lê a empresa onde foi criado (opcionalmente só alguns conjuntos e com data de expiração); o valor é mostrado uma vez e na base fica só o SHA-256 (`tokens_bi`). Revogar é imediato. Limite: 120 pedidos/minuto por token.
- Power BI Desktop: *Obter dados › Feed OData* › URL do feed › autenticação **Básica** (utilizador qualquer, ex. `bi`; palavra-passe = token). No Power BI Service, configurar a actualização agendada com as mesmas credenciais (o feed é público na Internet via HTTPS; não é preciso gateway).
- O nginx não precisa de alterações (o feed está em `/api`). Em caso de fuga de um token, revogá-lo e criar outro.

### Assistente IA para lançamentos (Claude, Anthropic)
- **Desligado por omissão em cada empresa.** Activar: (1) definir `ANTHROPIC_API_KEY` no `.env.prod` (chave da consola da Anthropic, nunca no Git); (2) `prod.sh up -d --force-recreate app worker scheduler`; (3) na empresa, *menu do utilizador › Assistente IA › Configuração* (quem tem `config_empresas_gerir`).
- Modelo por omissão `claude-opus-5-5` (`ERP_IA_MODELO`), esforço `medium` (`ERP_IA_ESFORCO`), tempo limite 120 s. O servidor precisa de saída HTTPS para `api.anthropic.com`.
- Só propõe: as propostas abrem no formulário normal de lançamento e só são gravadas pelo utilizador. As regras internas (palavras-chave) funcionam sem chave e sem envio de dados.
- Dados enviados: o texto/documento escolhido pelo utilizador, as contas de movimento (código e descrição), os diários e as regras de negócio da empresa. Cada pedido fica em `utilizacoes_assistente_ia` (sem o conteúdo), com tokens e custo estimado (Opus 5.5: 4 USD por milhão de tokens de entrada e 20 USD por milhão de saída — tipicamente alguns cêntimos por proposta).

## 12. Integração contínua (GitHub Actions)

`.github/workflows/ci.yml` corre em cada push e pull request para `main`. O repositório é público, por isso o CI usa só dados fictícios e credenciais descartáveis.

| Job | Passos |
| :--- | :--- |
| `backend` | PHP 8.3 (extensões do Dockerfile), serviços `postgres:16` e `redis:7`, `composer install`, extensões `unaccent`/`pg_trgm`, `.env` de teste, `vendor/bin/pint --test`, `php artisan test` na base `erp_consulvolt_testes` (ADR-019) |
| `frontend` | Node 22, `npm ci --ignore-scripts=false` (o esbuild precisa do script de instalação), `npx tsc --noEmit -p .`, `npx vitest run`, `npm run build` |
| `producao` | ShellCheck dos scripts de operação, `docker compose config` do compose de produção (com o modelo de variáveis) e build das 3 imagens, **sem publicação** |

As dependências do Composer e do npm e as camadas Docker ficam em cache. Recomendação: proteger o ramo `main`, exigindo os 3 jobs verdes antes do merge.

## 13. Plano da migração definitiva (legado → produção)

A ETL `erp:migrar-backup-legado` (ADR-023) lê o export Dexie do legado (`wstb_payroll_backup_<data>.json`) e grava-o tudo numa única transacção. Valida antes do COMMIT: contagens lidas = migradas + quarentena, zero órfãos, D−C por empresa e sequências recalibradas. Se algo falhar, faz ROLLBACK total. Depois do COMMIT, a ETL fotografa os salários (ADR-036), acerta o stock (ADR-042), normaliza POS, hotelaria, lavandaria, CRM e projectos e aplica os passos pós-carga (permissões novas e contas 6621/7621 — ADR-068; repetíveis com `php artisan erp:migracao:pos-carga`). Regras aplicadas: dados mestre fictícios descartados (ADR-003), enumerações com código e texto original (ADR-004), inconsistências reportadas sem invenção (ADR-005), `delivery_items` com dois pais (ADR-020), arredondamento a 2 casas com ocorrência por fracção real (ADR-022).

### 13.1 Preparação (D−7 a D−1)

1. Infra-estrutura de produção instalada (secções 3 a 5), com HTTPS e o domínio final, cópias configuradas e um primeiro restauro testado.
2. **Ensaio geral** no servidor de produção, com o backup mais recente do legado:
   ```bash
   sh ferramentas/operacao/prod.sh run --rm --no-deps -v /srv/erp/legado:/dados/legado:ro app \
      php artisan erp:migrar-backup-legado /dados/legado/wstb_payroll_backup_<data>.json --simular --substituir --force
   ```
   Critério: `0` divergências de contagem, `0` órfãos, quarentena e ocorrências analisadas. Referência: com o backup de 2026-09-22, na imagem de produção, em 2026-10-01: 203 818 linhas lidas, 1 215 fictícias descartadas, 32 em quarentena, 97 MB de memória e 51–77 s.
   **Use sempre `--substituir --force` em produção**, mesmo numa base acabada de criar. A verificação de base vazia da ETL só olha para `empresas` e `lancamentos_contabeis`, mas qualquer actividade prévia escreve em `logs_auditoria`: um login falhado num teste de fumo, por exemplo. Essas linhas fazem divergir a contagem de `audit_logs` e a ETL termina com "Há divergências de contagem". O `--substituir` esvazia também `logs_auditoria`.
3. Ensaio com COMMIT na base de produção, seguido das conferências da secção 13.5 por cada responsável (contabilidade, RH, armazém) e do apagamento por re-execução no dia D (`--substituir --force`).
4. Utilizadores informados: data e hora da paragem do legado, URL novo, e as palavras-passe que se mantêm (hash PBKDF2 convertido para Argon2id no primeiro login, ADR-007).
5. Equipa e contactos definidos para o dia D: quem decide o go/no-go, quem confere cada área e quem executa.

### 13.2 Congelar o legado (dia D, T0)

1. Aviso final aos utilizadores e fecho de todas as sessões do legado.
2. Pôr o legado **só de leitura**: retirar o acesso ao URL do legado (servidor web) ou, no mínimo, retirar as permissões de escrita a todos os perfis. Como os dados estão no IndexedDB de **cada navegador**, identificar o posto (navegador) que tem a base de referência, normalmente o mesmo que gerou os backups anteriores.
3. Confirmar que não há operações pendentes: facturas por enviar à AGT, sessões POS abertas, processamentos salariais a meio. Fechar ou anotar cada uma.

### 13.3 Exportar o backup final do IndexedDB

1. No posto de referência: *Configurações e Backup › Baixar cópia de Segurança Global* (`window.exportDatabase()`, permissão `config_backup`). É gerado `wstb_payroll_backup_<AAAA-MM-DD>.json`.
2. Registar o SHA-256 do ficheiro (`sha256sum`, ou `Get-FileHash` no Windows) e o tamanho. Guardar duas cópias offline, cifradas: o ficheiro contém dados pessoais e bancários reais e **nunca** pode entrar no Git nem no CI.
3. Copiar para o servidor: `scp wstb_payroll_backup_<data>.json servidor:/srv/erp/legado/` e depois `chmod 640` e `chown root:82`.

### 13.4 Executar a ETL em produção

```bash
cd /srv/erp/app
# a) cópia da base antes (mesmo que vazia, ou com o ensaio da 13.1)
sh ferramentas/operacao/prod.sh run --rm --no-deps copias /operacao/backup.sh
# b) parar quem escreve na base (o worker e o scheduler enviariam documentos à AGT a meio)
sh ferramentas/operacao/prod.sh stop worker scheduler
sh ferramentas/operacao/prod.sh exec app php artisan down
# c) simulação com o ficheiro final
sh ferramentas/operacao/prod.sh run --rm --no-deps -v /srv/erp/legado:/dados/legado:ro app \
   php artisan erp:migrar-backup-legado /dados/legado/wstb_payroll_backup_<data>.json --simular --substituir --force
# d) migração real: --substituir apaga os dados de negócio do ensaio; --force dispensa a confirmação interactiva
sh ferramentas/operacao/prod.sh run --rm --no-deps -v /srv/erp/legado:/dados/legado:ro app \
   php artisan erp:migrar-backup-legado /dados/legado/wstb_payroll_backup_<data>.json --substituir --force \
   | tee /srv/erp/legado/etl_producao_<data>.log
# d2) pós-carga (ADR-068): a ETL já atribui, depois do COMMIT, as tarefas novas do catálogo aos perfis que faziam a acção
#     (rh_tabela_irt_gerir, vendas_alterar_preco e — só a quem gere moedas — cambio_manual_fora_tolerancia) e preenche as
#     contas 6621/7621. Confirmar «Pós-carga: …» na consola (`pos_carga` no relatório); se aparecer «NÃO APLICADA», repetir:
sh ferramentas/operacao/prod.sh exec app php artisan erp:migracao:pos-carga
# e) OBRIGATÓRIO: limpar a cache antes de reabrir a aplicação. O --substituir faz TRUNCATE … RESTART IDENTITY e reutiliza
#    ids: sem isto, um utilizador novo com o id de um antigo herdava durante até 1 h a lista de empresas acessíveis do antigo,
#    e o plano de contas (24 h) e o catálogo (6 h) ficavam os do ensaio. A ETL já tenta limpar (linha «Cache: …» na consola
#    e `cache` no relatório); este passo garante-o mesmo que essa limpeza tenha falhado.
sh ferramentas/operacao/prod.sh exec app php artisan cache:clear
# f) cópia imediatamente depois da migração (ponto de partida oficial)
sh ferramentas/operacao/prod.sh run --rm --no-deps copias /operacao/backup.sh
```

**Relatórios de validação:**
- a saída da consola (guardada em `etl_producao_<data>.log`): indicadores, contagens divergentes, órfãos, D−C por empresa, salários fotografados e conferidos, acertos de stock;
- o relatório JSON em `storage/app/private/migracao/relatorio_<execução>.json` (volume `erp_storage`, também incluído nas cópias) e em `execucoes_migracao.relatorio`, com o SHA-256 do ficheiro de origem. Conferir que é igual ao da 13.3;
- o detalhe em `ocorrencias_migracao` (cada correcção, com o payload original) e `quarentena_migracao` (linhas rejeitadas);
- as 46 validações de *Sistema › Validações de Dados* (`GET /api/sistema/validacoes`, ADR-015).

### 13.5 Conferências (go/no-go)

Cada responsável confere na aplicação nova (`php artisan up` só para os conferentes, ou pelo túnel `ssh -L 8080:127.0.0.1:8080`) e assina:

| Área | Conferência | Endpoint / ecrã | Critério de aceitação |
| :--- | :--- | :--- | :--- |
| Contagens | lidas = migradas + quarentena, por tabela | relatório da ETL | 0 divergências; quarentena igual à do ensaio (32 linhas com o backup de 2026-09-22), toda justificada |
| Integridade | FKs | relatório da ETL | 0 órfãos (a ETL não faz COMMIT de outra forma) |
| Contabilidade | **balancete** por empresa e exercício, comparado com o balancete do legado | `GET /api/contabilidade/relatorios/balancete` | totais D e C iguais ao cêntimo, salvo o efeito de arredondamento reportado (ADR-022, no máximo 3 cêntimos por empresa) |
| Contabilidade | lançamentos desequilibrados | `GET /api/contabilidade/relatorios/desequilibrios` | só os conhecidos: empresas 5, 8 e 10 (ADR-005/025) e cêntimos da empresa 1 |
| Stock | **stock** por armazém e produto, comparado com o legado | `GET /api/logistica/stock` | quantidades iguais ao saldo por armazém do legado (verdade usada no acerto, ADR-042); produtos sem custo listados nas validações |
| Salários | **folhas** migradas comparadas com o diário (lançamentos SAL) | `GET /api/rh/salarios/verificacao-legado` | as folhas que conferiam no ensaio continuam a conferir (referência: 38/44); as diferenças são as já conhecidas e documentadas |
| Activos | quotas de amortização | `GET /api/ativos/amortizacoes/verificacao` | referência: 1 435/1 448 reproduzidas; diferenças conhecidas |
| Tesouraria/Vendas | saldos de clientes e fornecedores, documentos pendentes | `GET /api/tesouraria/pendentes`, *Vendas › Facturação* | iguais ao legado para uma amostra de 10 terceiros por empresa |
| Acessos | login de 1 utilizador por perfil, empresas e menus visíveis | ecrã de entrada | permissões iguais às do legado (ADR-024) |
| Validações | 46 validações de dados | `GET /api/sistema/validacoes` | sem erros novos face ao ensaio |

**Decisão go:** todas as linhas aceites. Então: `prod.sh exec app php artisan up`, `prod.sh up -d worker scheduler`, comunicação aos utilizadores e legado mantido **só de leitura** durante 90 dias para consulta.

### 13.6 Plano de retorno (no-go)

- **Antes do go** (dia D): `php artisan down` mantém-se. A ETL é transaccional, por isso uma falha não deixa dados parciais. Para desfazer uma migração que fez COMMIT mas não foi aceite, restaurar a cópia da alínea 13.4 a) (secção 9). O legado volta a ser o sistema de registo: reabrir o acesso de escrita. Nenhum dado se perde, porque o legado esteve congelado.
- **Depois do go** (dias D+1 a D+n): o sistema novo é o de registo e não há sincronização inversa para o IndexedDB. Um retorno ao legado implicaria re-registar à mão as operações feitas no sistema novo. Por isso, uma falha depois do go corrige-se no sistema novo (correcção, ou restauro da cópia diária, secção 9). Fica como critério explícito: **o retorno ao legado só é possível até ao go**.
- Documentos fiscais: enquanto o retorno for possível, não emitir no sistema novo documentos que sejam enviados à AGT (`AGT_DRIVER=desligado` até ao go). Assim a numeração e a comunicação com a AGT continuam no legado.

### 13.7 Depois da migração

- Cópia diária a funcionar e fora do servidor (secção 8); restauro de teste na primeira semana.
- `AGT_DRIVER` passa ao modo definido e o ciclo de envio é verificado (`/api/vendas/faturacao-eletronica/resumo`).
- Ficheiro do backup legado: mantido offline e cifrado, segundo a política de retenção. Apagar de `/srv/erp/legado` no fim da janela de conferência.
- Anotar no ADR da Fase 6 a data, o SHA-256 do ficheiro, o id da execução e os números finais.

## 14. Lista de verificação rápida

- [ ] `.env.prod` com `chmod 600`; `APP_DEBUG=false`; `APP_KEY` guardada em cofre
- [ ] HTTPS activo; `curl -I https://<domínio>` mostra HSTS e CSP
- [ ] `GET /api/saude` → 200, todos os componentes `OK`, versão correcta
- [ ] PostgreSQL e Redis sem portas publicadas (`docker compose ps`)
- [ ] Cópia diária presente, sincronizada fora do servidor e restauro testado
- [ ] Chaves AGT com permissões 82:82 / `u=rX,go=` e copiadas cifradas
- [ ] Monitor externo a `/api/saude` com alertas
- [ ] CI verde no commit implantado
