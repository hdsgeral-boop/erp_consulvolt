# TLS, cabeçalhos, WAF e rede — o que activar na AWS (ADR-083)

Estado verificado em **2026-11-05, só em leitura** (`openssl s_client`, `curl -I`, `aws … describe/list/get`, perfil `erp-cli`, eu-north-1). Nada foi alterado na AWS: tudo o que está abaixo em «Acção» é do coordenador.

## 1. Estado actual

| Ponto | Produção `meuerp.ao` (EC2 `i-021a07e6f24932c8a`) | Teste `teste.consulvolt.meuerp.ao` (EC2 `i-00eb77058b5a5296e`) |
|---|---|---|
| Certificado | Let's Encrypt (YE1), ECDSA P-256, 21/10/2026 → 19/01/2027, SAN `meuerp.ao` (e certificado próprio para `www`) | Let's Encrypt (YE2), ECDSA P-256, 28/10/2026 → 26/01/2027, SAN do próprio nome |
| Cadeia / verificação | OK (`Verify return code: 0`) | OK |
| Protocolos | TLS 1.3 (`TLS_AES_128_GCM_SHA256`) e 1.2 (`ECDHE-ECDSA-AES128-GCM-SHA256`) | TLS 1.3 (`AES_256_GCM_SHA384`) e 1.2 (`ECDHE-ECDSA-AES256-GCM-SHA384`) |
| HTTP → HTTPS | 308 (Caddy) | 301 (nginx) |
| HSTS | **Ausente** | `max-age=31536000; includeSubDomains` (sem `preload`) |
| CSP | **Ausente** | Presente, sem `unsafe-eval`, `frame-ancestors 'self'` |
| Permissions-Policy / CORP / COOP | **Ausentes** / ausentes / ausente | Presentes / presente / **COOP ausente** |
| `Server` | duplicado (`Caddy` + `nginx`) | `nginx/1.24.0 (Ubuntu)` (versão exposta) |
| OCSP stapling | Não enviado (a Let's Encrypt terminou o OCSP em 2025; não é acção) | idem |
| `/.env`, `/.git/config`, `/vendor/…`, `/storage/logs/…`, `/telescope`, `/horizon`, `/phpinfo.php` | 200 com o `index.html` do SPA (4719 bytes — nenhum ficheiro real) | `/.env` e `/.git/config` 403; os restantes 200 com o `index.html` (4719 bytes) |
| `/api/saude` | 200 JSON (sem versões internas) | idem |
| CORS com `Origin` estranho | sem `Access-Control-Allow-Origin` | idem |
| CloudFront | **Não existe nenhuma distribuição** (conta inteira) | idem |
| WAF | não existe | não existe |
| CAA (Route53) | **Sem registos CAA** em `meuerp.ao` | idem |

**Causa do HSTS/CSP em falta na produção:** `docker-compose.aws.yml` herdava o `web` do compose de desenvolvimento (`docker/nginx/default.conf`: só `nosniff`, `X-Frame-Options`, `Referrer-Policy`; 100 MB de corpo em todo o lado; sem limite no login). **Corrigido no repositório** (ver §2): o `web` do AWS passa a montar a configuração de produção (`docker/nginx/producao/*`), a mesma que a imagem de produção usa.

Infra (só leitura): RDS `meuerp-db` privado, cifrado, backups 7 dias, protecção contra eliminação; S3 `meuerp-transferencia-…` com Block Public Access nas 4 opções; instâncias com IMDSv2 obrigatório; SG `erp-aws-app` (produção) só 80/443; SG `erp-teste-app` e `erp-web` têm SSH aberto a um único IP (/32); **SG `launch-wizard-2` tem SSH 22 aberto a `0.0.0.0/0`** (não está ligado às duas instâncias do ERP); volume `vol-012b0aab0a15e096f` (20 GB) **sem cifra** (o de 40 GB está cifrado).

## 2. O que já está preparado no repositório (a implantar)

1. `docker-compose.aws.yml` — `web` com a configuração de produção (CSP sem `unsafe-eval`, HSTS, Permissions-Policy, CORP, COOP, `X-Request-Id`, `limit_req` no login, 25 MB de corpo e 100 MB só em `/api/sistema/copias`, logs JSON); `no-new-privileges` no `web` e no `caddy`; UDP 443 (HTTP/3).
2. `deploy/caddy/Caddyfile.aws` — `reverse_proxy web:8080`; TLS 1.2/1.3 explícito; chaves ECDSA P-256; HTTP/1.1+2+3; tempos limite (`read_header 20s`, `read_body 2m`, `idle 2m`); corpo máx. 110 MB; remove o `Server` duplicado. Validado com `caddy validate` (2.8).
3. `docker/nginx/producao/00-http.conf` — HSTS `max-age=63072000; includeSubDomains; preload`. `cabecalhos-seguranca.conf` — `Cross-Origin-Opener-Policy: same-origin-allow-popups`.
4. Aplicação (Laravel): `SanitizarEntrada`, `ValidarUploadsSeguros`/`ValidadorUploadSeguro` (ver ADR-083).

**Deploy na produção (por esta ordem):** `git pull` → `docker compose -f docker-compose.yml -f docker-compose.aws.yml up -d web caddy` (o `web` muda de porta interna 80 → 8080; o Caddy muda na mesma operação) → testar `curl -sI https://meuerp.ao/ | grep -iE 'strict|content-security|permissions|cross-origin'`. Reverter = voltar ao commit anterior e repetir o comando. Ensaiar primeiro no teste (que usa nginx nativo — ver §3).

## 3. Acções do coordenador (não feitas por mim)

### 3.1 Teste (nginx nativo no Ubuntu, fora do repositório)
- Em `server { … }` HTTPS: `add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload" always;` e `add_header Cross-Origin-Opener-Policy "same-origin-allow-popups" always;` (repetir em cada `location` que tenha `add_header`).
- `server_tokens off;` no `http { }` (hoje expõe `nginx/1.24.0 (Ubuntu)`).
- `ssl_protocols TLSv1.2 TLSv1.3; ssl_prefer_server_ciphers off; ssl_session_tickets off;` e `ssl_stapling off` (Let's Encrypt já não tem OCSP).
- `/vendor/…`, `/storage/…`, `/telescope`, `/horizon`, `/phpinfo.php` respondem 200 com o SPA (não há ficheiro): para ficarem 404 (mais limpo para scanners), copiar o bloco «Ficheiros ocultos» de `docker/nginx/producao/erp.conf` e acrescentar `location ~ ^/(vendor|storage|telescope|horizon|_ignition|phpinfo\.php|\.git|\.env) { return 404; }`.

### 3.2 DNS — CAA (Route53, zona `meuerp.ao`)
Registos CAA na raiz (restringem quem pode emitir certificados; incluem os wildcard e o `iodef` para avisos):
```
meuerp.ao.  CAA  0 issue "letsencrypt.org"
meuerp.ao.  CAA  0 issuewild ";"
meuerp.ao.  CAA  0 issue "amazon.com"        ; só se vier a usar ACM/CloudFront
meuerp.ao.  CAA  0 iodef "mailto:seguranca@meuerp.ao"
```
(O Caddy pede um certificado por nome — emissão normal, nunca wildcard: por isso `issuewild ";"`.)

### 3.3 CloudFront + ACM + WAF (recomendado; hoje não existe)
O Caddyfile já confia nos IP da CloudFront (`trusted_proxies`), mas **não há distribuição**. Se for criada (fica à frente da EC2, esconde o IP de origem e absorve DDoS de camada 7):
- **Certificado ACM em `us-east-1`** para `meuerp.ao` e `*.meuerp.ao` (validação DNS; renovação automática). Já existe um certificado ISSUED para `meuerp.ao` nessa região.
- **Política de segurança do viewer: `TLSv1.2_2021`** (TLS 1.2 mínimo, só cifras AEAD/ECDHE) ou superior; HTTP/2 e HTTP/3 activos; `redirect-to-https`; ECDSA P-256 ou RSA 2048+.
- Origem = `origem.meuerp.ao` (registo A já existe) em HTTPS, `OriginProtocolPolicy: https-only`, TLS 1.2; cabeçalho secreto de origem (`X-Origem-Secreto`) verificado no Caddy para recusar tráfego que não passe pela CloudFront; **SG da EC2 de produção só com a lista de prefixos gerida `com.amazonaws.global.cloudfront.origin-facing`** em 80/443.
- Caching: política `CachingDisabled` para `/api/*` e `/auth/*`; `CachingOptimized` só para `/assets/*`. Encaminhar `Host`, `Origin`, `Cookie`, `Authorization` e `X-Forwarded-Proto` (política de pedido `AllViewer`).
- **Response headers policy** gerida (`SecurityHeadersPolicy`) como rede de segurança, ou `deploy/aws/cloudfront-response-headers.json` (HSTS 2 anos + preload, nosniff, frame DENY→SAMEORIGIN, referrer, COOP) — o nginx já os envia; a política só garante a presença.
- **AWS WAF (scope CLOUDFRONT, us-east-1)** — conjunto em `deploy/aws/waf-regras.json`: `AWSManagedRulesCommonRuleSet`, `AWSManagedRulesSQLiRuleSet`, `AWSManagedRulesKnownBadInputsRuleSet`, `AWSManagedRulesAmazonIpReputationList`, `AWSManagedRulesAnonymousIpList` (opcional: em contagem), regra de **taxa por IP** (2000 pedidos/5 min global e 100/5 min em `/api/autenticacao/*`), e `SizeRestrictions_BODY` em *count* para `/api/sistema/copias` e `/api/*import*` (uploads grandes legítimos). **Arrancar tudo em modo `Count` durante 48 h** e rever o `sampled requests` antes de passar a `Block` (o `SizeRestrictions_BODY` e o `CrossSiteScripting_BODY` do CommonRuleSet geram falsos positivos em cópias/importações).
- HSTS `preload`: só submeter em hstspreload.org depois de confirmar que **todos** os subdomínios (`*.meuerp.ao`) servem HTTPS; é praticamente irreversível.

### 3.4 Rede e contas
- **Apagar o SG `launch-wizard-2`** (SSH aberto ao mundo) se nada o usar, e substituir o SSH por SSM Session Manager (fechar a 22 em `erp-web`, `erp-teste-app`, `launch-wizard-1`).
- Cifrar o volume `vol-012b0aab0a15e096f` (snapshot → cópia cifrada → substituir) após confirmar a que instância pertence (não é de nenhuma das duas do ERP; **não tocar se for do ODK**).
- RDS: manter privado/cifrado; subir a retenção para 14–35 dias; activar *Performance Insights*/logs `postgresql` para o CloudWatch; CA do RDS `rds-ca-rsa2048-g1` → migrar para `rds-ca-rsa4096-g1` ou `rds-ca-ecc384-g1` na janela de manutenção (e `DB_SSLMODE=verify-full` com o bundle).
- IAM: `erp-cli` com política só de leitura para auditorias futuras; MFA obrigatório nos utilizadores humanos; sem chaves de acesso nas instâncias (usam perfil de instância).
- GuardDuty + Security Hub (eu-north-1) e alarme de CloudWatch para `5xx` e para tentativas de login (`seguranca.entrada_recusada` nos logs JSON).
- Fail2ban na EC2 (opcional): `/var/log` do Docker não é lido por defeito; com o WAF/limite de taxa o ganho é pequeno.

## 4. Verificações a repetir depois do deploy (só leitura)
```
curl -sI https://meuerp.ao/ | grep -iE 'strict-transport|content-security|permissions-policy|cross-origin|^server'
echo | openssl s_client -connect meuerp.ao:443 -servername meuerp.ao 2>/dev/null | openssl x509 -noout -issuer -dates -ext subjectAltName
for v in tls1_2 tls1_3; do echo | openssl s_client -connect meuerp.ao:443 -$v 2>&1 | grep -E '^New,'; done
curl -s -o /dev/null -w '%{http_code}\n' https://meuerp.ao/.env      # SPA (200, 4719 bytes) ou 404/403 — nunca conteúdo real
```
Teste externo recomendado: SSL Labs (alvo **A+**), securityheaders.com (alvo A+), Mozilla Observatory.
