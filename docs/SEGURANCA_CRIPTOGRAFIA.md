# Criptografia, tokens e segredos — inventário e procedimentos

Data: 2026-11-05 · ADR-085 · Complementa `arquitetura/SESSOES_E_COOKIES.md` (ADR-082), `SEGURANCA_BLINDAGEM_2026-11.md` (ADR-083)
e `arquitetura/SEGURANCA_OWASP.md`. Nada aqui introduz criptografia própria: tudo assenta em `password_hash`/`password_verify`,
`random_bytes`, `hash`/`hash_hmac` e no `Illuminate\Encryption\Encrypter` do Laravel.

## 1. Regra de ouro (hashing ≠ cifra reversível)

| Preciso de… | Uso | Porquê |
|---|---|---|
| **verificar** um segredo que o utilizador digita (palavra-passe, código de recuperação) | hash lento com sal (**Argon2id**; bcrypt custo ≥ 12 aceite) | nunca se recupera o original |
| **identificar** um token aleatório de alta entropia (sessão, chave de integração, webhook) | **SHA-256** do token (fingerprint); o token só existe em claro uma vez, quando é entregue | 256 bits de entropia: um hash rápido chega; comparação em tempo constante |
| **reler** um segredo para o usar noutro sistema (chave de IA, credenciais AGT, segredo TOTP, chave de gateway) | **cifra autenticada** do Laravel (AES-256, `APP_KEY`) | tem de ser recuperável; a integridade vai no MAC/tag |
| assinar um valor do cliente (cookie do dispositivo, CSRF) | **HMAC-SHA-256** com sub-chave derivada da `APP_KEY` | não se guarda; só se valida |

## 2. Inventário dos dados sensíveis

| Dado | Tabela.coluna | Protecção | Reversível? | Rotação |
|---|---|---|---|---|
| Palavra-passe | `utilizadores.palavra_passe` | **Argon2id** (`HASH_DRIVER=argon2id`); hash antigo (bcrypt/PBKDF2 do legado) é trocado no login (`modernizarHash`, transparente) | não | rehash no login |
| Código de recuperação MFA | `utilizadores.mfa_codigos_recuperacao` | `Hash::make` de cada código (uso único: removido ao usar) | não | — |
| Segredo TOTP | `utilizadores.mfa_segredo` | cast `encrypted` (cifra do Laravel) | sim | `erp:seguranca:rodar-chave` |
| Token de sessão | `tokens_acesso.token` (+ `tokens_anteriores`) | **SHA-256** do segredo de 256 bits; só o hash está na base | não | rotação do token a cada 10 min (ADR-082) |
| Token Power BI / API | `tokens_bi.hash_token` | **SHA-256**; valor mostrado uma única vez | não | revogar e criar outro |
| Endereço do webhook AGT | `agt_ligacoes_empresa.webhook_token_hash` | **SHA-256** do identificador público | não | gerar de novo |
| Segredo HMAC do webhook AGT | `agt_ligacoes_empresa.webhook_segredo` | cast `encrypted` | sim | `rodar-chave` / gerar de novo |
| Credenciais e chave privada AGT | `agt_ligacoes_empresa.{utilizador,palavra_passe,chave_privada}` | cast `encrypted` | sim | `rodar-chave`; a chave RSA do contribuinte tem rotação própria (`chave_versao`) |
| Chaves de gateways de pagamento | `configuracoes_gateway.valor` (chaves `ref_*`, `mcx_*`) | `enc:` + cifra do Laravel (`SegredosGateway`) | sim | `rodar-chave` |
| Chaves de fornecedores de IA | `ia_fornecedores.chave` | `enc:` + cifra do Laravel; aceita o payload legado da produção sem prefixo | sim | `rodar-chave` |
| Cookie do dispositivo | cookie `erp_dev` | `id.HMAC-SHA256` (sub-chave da `APP_KEY`) | não | muda com a `APP_KEY` (os dispositivos passam a «novos») |
| CSRF | cookie `XSRF-TOKEN` | `nonce.HMAC(id da sessão, nonce)` | não | muda com a `APP_KEY` (1 novo login) |
| Passo TOTP gasto | cache `mfa:passo:{utilizador}:{contador}` | só o id e o contador; TTL 120 s | — | — |
| Cópias de segurança | ficheiros de `ferramentas/operacao/backup.sh` | **AES-256-CBC + PBKDF2 (200 000)** com `ERP_COPIAS_CHAVE` (ver §6) | sim | trocar a frase-passe e refazer a cópia |
| `APP_KEY`, `APP_PREVIOUS_KEYS`, `ERP_COPIAS_CHAVE`, segredos de gateway | `.env` do servidor / SSM Parameter Store | **nunca no repositório** (`.env.example` tem `APP_KEY=` vazio; o `.env` está no `.gitignore`) | — | §4 |

Fora do inventário de propósito: SAF-T (`saft_hash*`) é a cadeia de hashes fiscal da AGT (SHA-1/RSA por norma fiscal), não é
credencial; os `md5`/`sha1` que existem no `app/` servem chaves de cache e ETag (nunca credenciais — `CriptografiaTokensTest`
varre os caminhos de credenciais e falha se aparecer MD5/SHA-1); o TOTP usa HMAC-SHA1 porque a RFC 6238 e as apps de
autenticação o impõem.

## 3. Cifra dos segredos reversíveis: CBC ou GCM

- Omissão: `APP_CIPHER=AES-256-CBC` (com HMAC-SHA-256 do Laravel) — é o formato dos segredos já gravados na produção
  (`SegredosGateway::decifrar` continua a aceitar o payload legado sem prefixo `enc:`).
- Opcional: `APP_CIPHER=AES-256-GCM` (AEAD). O `EncrypterCompativel` (registado em `AppServiceProvider`) lê **as duas** cifras com as
  chaves de `APP_KEY` e `APP_PREVIOUS_KEYS`; grava sempre com a configurada. Por isso mudar a cifra não deixa segredos ilegíveis,
  nem tem janela de indisponibilidade. Depois corre-se `erp:seguranca:rodar-chave` para converter o que ficou em CBC.
- XChaCha20-Poly1305 não é suportado pelo `Encrypter` do Laravel; não se acrescentou uma implementação própria. AES-256-GCM cumpre o pedido.

## 4. Rotação da `APP_KEY` (procedimento)

1. Gerar a chave nova: `php artisan key:generate --show` (ou `docker run --rm php:8.3-cli-alpine php -r 'echo "base64:".base64_encode(random_bytes(32));'`). Guardá-la no cofre.
2. No `.env` do servidor: `APP_PREVIOUS_KEYS=<chave antiga>` e `APP_KEY=<chave nova>`. Reiniciar a app (`php artisan config:clear`; filas e agendador também).
   O Laravel decifra com qualquer das duas e cifra com a primeira — **nada fica ilegível**.
3. `php artisan erp:seguranca:rodar-chave --simular` (conta), depois `php artisan erp:seguranca:rodar-chave` (recifra, com *compare-and-swap* por linha;
   pode correr com a app em serviço). Mostra a **versão da chave** (`HMAC-SHA-256("erp:versao-da-chave:v1", APP_KEY)`, 12 hex — não revela a chave).
4. `php artisan erp:seguranca:rodar-chave --estado`: tem de dar `0 a rodar` e `0 ilegíveis`. Se houver ilegíveis (segredos gravados com uma chave que já não existe) o
   comando falha **e não se deve retirar a chave antiga**; esses segredos têm de ser introduzidos de novo (chaves de IA, AGT, etc.).
5. Só então retirar a chave antiga de `APP_PREVIOUS_KEYS`.
6. Efeito colateral esperado: o cookie do dispositivo e o CSRF derivam da `APP_KEY` — cada utilizador volta a entrar uma vez e vê um aviso de «novo dispositivo».
   Planear a rotação fora do horário de ponta e avisar. Os tokens de sessão (SHA-256 aleatório) **não** dependem da `APP_KEY`.

Quando rodar: pelo menos anualmente, e de imediato se houver suspeita de fuga do `.env`/cópias, saída de pessoa com acesso ao servidor, ou ao restaurar uma cópia noutro ambiente.

## 5. Tokens, OTP, API keys e integrações — política

| Tipo | Geração | Guarda | Validade | Uso | Comparação |
|---|---|---|---|---|---|
| Sessão | `random_bytes(32)` | SHA-256 | 2 h absolutas, 15 min de inactividade; roda a cada 10 min | n pedidos | `hash_equals` |
| OTP (TOTP) | RFC 6238, 6 dígitos, janela ±30 s | segredo cifrado; o código não se guarda | 30 s | **uso único**: o passo gasto fica registado 120 s (login e reautenticação) — `ServicoReautenticacao` | `hash_equals` |
| Código de recuperação MFA | 8 códigos aleatórios | bcrypt/Argon2 | até ser usado | **uso único** (removido) | `password_verify` |
| Chave Power BI | `erpbi_` + 48 aleatórios | SHA-256 | opcional (`expira_em`), revogável | n | pesquisa pelo hash |
| Webhook AGT | identificador aleatório + segredo HMAC | SHA-256 do id; segredo cifrado | até desactivar | n, anti-replay por nonce | HMAC |
| Recuperação de palavra-passe | a palavra-passe nova é gerada pelo sistema e enviada (não há ligação de recuperação) | hash | troca obrigatória no 1.º acesso | — | — |

A recuperação por **ligação de uso único** (token aleatório de 256 bits, hash, validade 30 min, ligado à conta) continua descrita no ADR-082 como
alternativa por decidir: muda a experiência (um passo extra) e a decisão é do utilizador do produto.

## 6. Cópias de segurança

`backup.sh` cifra o dump, o `storage` e o Redis com `openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt` quando `ERP_COPIAS_CHAVE` está definida
(omissão de produção; `ERP_COPIAS_CIFRAR=0` desliga). Cada ficheiro cifrado é decifrado e comparado antes de apagar o claro. O índice (`.lista`), o manifesto e as
somas SHA-256 ficam em claro (não têm dados). `restaurar.sh` reconhece `.dump.enc`/`storage.tar.gz.enc` e pede `ERP_COPIAS_CHAVE`.
A frase-passe fica **fora do servidor** (cofre/SSM SecureString); sem ela a cópia não se recupera. Sem a variável, o script avisa
(«cópia SEM cifra»): só é aceitável com volume cifrado (EBS/RDS com KMS). Cópias já existentes mantêm-se em claro até serem refeitas.

## 7. O que nunca se faz

Gravar `APP_KEY`/chaves privadas/segredos no repositório (nem em exemplos); usar MD5/SHA-1 para credenciais; guardar tokens de autenticação em claro (base, log ou `localStorage`);
comparar segredos com `==`; registar o valor de um segredo (o comando de rotação só conta); reutilizar a mesma chave para fins diferentes (cookies, CSRF e cifra usam sub-chaves/rótulos distintos).
