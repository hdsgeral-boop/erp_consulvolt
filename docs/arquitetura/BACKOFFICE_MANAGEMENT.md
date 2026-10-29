# Back-office da plataforma: RBAC, MFA, dashboard e monitorização

> Fases 4, 5 e 7 da extensão SaaS (`segurança.txt`, secções 11, 12, 16).

## 1. Quem entra em `/api/plataforma/*`

`App\Http\Middleware\ExigirPlataforma` (barreira de entrada, nega por omissão): o utilizador tem de pertencer ao
tenant da plataforma (`config('erp.dominio.plataforma_tenant_id')`) **e** ter `papel` `SUPER_ADMINISTRADOR`,
`OPERADOR_PLATAFORMA` ou `AGENTE_SUPORTE`. Dentro da área, cada operação sensível ainda exige a sua chave
`plataforma_*_gerir` específica no perfil do operador — o super-administrador continua com acesso total
(`ServicoPermissoes::total()`), um operador só com o que o seu perfil conceder.

## 2. RBAC granular (Fase 4, reaproveitando o padrão ADR-077 do suporte)

- `Utilizador::PAPEL_OPERADOR_PLATAFORMA` (novo nesta fase) — conta de back-office sem ser super-administrador.
- Perfis (`PerfilUtilizador`, `permissoes` JSON, `restrito_plataforma=true`) com chaves do catálogo
  (`backend/resources/permissoes/catalogo.json`) por área: `plataforma_clientes_gerir`,
  `plataforma_planos_gerir`, `plataforma_cupoes_gerir`, `plataforma_definicoes_gerir`,
  `plataforma_painel_ver`, `plataforma_monitorizacao_ver`, `suporte_tickets_{geral,financeiro,tecnico}_gerir`
  (ADR-077 original), etc. — princípio do menor privilégio: um operador de suporte não tem, por omissão, acesso a
  dados financeiros.
- Gestão de operadores: `OperadoresPlataformaController` (CRUD completo), palavra-passe sempre gerada pelo
  sistema (`Str::password(14)`, nunca escolhida pelo criador), email de boas-vindas
  (`OperadorPlataformaCriadoMail`) com a credencial.
- **Lacuna conhecida, fora de âmbito**: `ClientesController`/`ServicoClientes::criar()` ainda aceitam uma palavra-
  passe escolhida pelo cliente para o administrador de uma conta nova (a mesma falha corrigida em todos os
  outros pontos de criação de conta nesta extensão). Deixado por fazer nesta sessão por implicar actualizar
  vários testes existentes que dependem de conhecer essa palavra-passe para entrar — maior risco do que o ganho
  imediato de segurança justificava sem confirmação explícita.

## 3. MFA por TOTP (Fase 4, opcional)

- `App\Services\Autenticacao\ServicoTotp` — implementação manual de RFC 6238 (TOTP) e RFC 4226 (HOTP), sem
  dependência externa (evita o risco já observado de `composer require` reorganizar dependências de forma
  destrutiva). Validado contra os vectores de teste oficiais do RFC 6238 Apêndice B.
- `mfa_segredo` (encriptado), `mfa_ativo`, `mfa_codigos_recuperacao` (hashes, `array` simples — não precisam de
  uma segunda camada de encriptação por cima de um hash), `mfa_ativado_em` em `Utilizador`.
- Fluxo: `MfaController` (`iniciarSetup`, `confirmarSetup`, `desactivar`, `verificar`). Com MFA activo,
  `ServicoAutenticacao::entrar()` emite um **token limitado** (`mfa_pendente`); só depois de verificar o código
  é que `emitirTokenCompletoPosMfa()` dá o token com todas as abilities (`'*'`).
- `BloquearTokenMfaPendente` (middleware global, antes de `ResolverTenantAtivo`): bloqueia qualquer rota que não
  seja de MFA enquanto o token for limitado. **Nota de implementação**: verificar "token limitado" é
  `! $token->can('*')`, nunca `$token->can('<ability-específica>')` — um token `['*']` do Sanctum satisfaz
  `can()` para qualquer string pedida, incluindo abilities que nunca lhe foram dadas explicitamente.
- **Decisão do utilizador**: MFA fica **opcional** por agora, mesmo para contas privilegiadas — torná-lo
  obrigatório quebraria todos os testes existentes que usam esses papéis sem MFA configurado. Avaliar de novo
  quando houver confirmação explícita.

## 4. Dashboard executivo (Fase 5)

`App\Services\Plataforma\ServicoDashboardExecutivo`, exposto em `GET /api/plataforma/painel`
(`plataforma_painel_ver`). Indicadores, cada um com a fórmula documentada no próprio serviço (nunca confundir
MRR com receita recebida, nem ARR com facturação anual efectiva):
- **MRR/ARR**: a partir da última subscrição `PAGO` de cada empresa `ACTIVA` (nunca de empresas canceladas,
  mesmo com uma subscrição paga no passado).
- **Receita recebida no mês**, **taxa de cancelamento**, **taxa de conversão de trial**.
- **Tickets**: abertos, críticos (`URGENTE`), vencidos (SLA expirado).
- **Disponibilidade**: resumo dos mesmos componentes de `ServicoSaude` (ver secção 6).

## 5. Monitorização operacional (Fase 7)

`App\Services\Sistema\ServicoSaude` — partilhado por dois consumidores:
- `GET /api/saude` (público, sem autenticação): usado pelos *healthchecks* de infra-estrutura
  (`docs/PRODUCAO.md`). Responde `503` se algum componente falhar. As versões exactas de PostgreSQL/Redis só
  aparecem em modo de depuração ou a um utilizador autenticado com um perfil privilegiado
  (`Utilizador::ePrivilegiado()`) — **corrigido nesta fase**: antes, qualquer utilizador autenticado (mesmo de
  uma empresa cliente comum) via essas versões, um detalhe de reconhecimento útil a um atacante (OWASP A05).
- `GET /api/plataforma/monitorizacao` (back-office, `plataforma_monitorizacao_ver`): a mesma verificação, sempre
  com detalhe completo (já protegido por autenticação e permissão). Responde sempre `200`, com `estado` dentro
  do corpo — pensado para uma página de leitura humana, não para um healthcheck de infra-estrutura.

Componentes verificados: base de dados (ping real), Redis (ping real), filas (tamanho de cada fila +
trabalhos falhados), armazenamento (`storage/` gravável), batimentos do scheduler e do worker (`Batimentos`, R11
— um processo sem batimento recente é `FALHA`, não silêncio).

**Decisão deliberada de não construir**: sem Horizon/Telescope — não se justificam à escala actual (um só
servidor Docker, volume modesto de filas). Reavaliar se o volume crescer.

## 6. Testes

`RbacPlataformaTest`, `MfaTest`, `ServicoTotpTest` (Unit, vectores RFC), `DashboardExecutivoTest`, `SaudeTest`,
`MonitorizacaoPlataformaTest`.
