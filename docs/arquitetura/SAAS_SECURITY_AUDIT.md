# Auditoria de segurança da extensão SaaS

> Fase 9 da extensão SaaS (`segurança.txt`, secção 24). Resumo das decisões e correcções de segurança feitas ao
> longo das Fases 0-8; ver [SEGURANCA_OWASP.md](SEGURANCA_OWASP.md) para o inventário geral de segurança do
> sistema, não específico desta extensão.

## 1. Vulnerabilidades reais corrigidas

| # | Problema | Correcção | Fase |
|---|---|---|---|
| 1 | Webhook de pagamento sem assinatura: confiava em `status`/`amount` do payload, sem HMAC, sem protecção contra repetição, sem validar o montante devido. Forjar uma confirmação falsa activava uma licença de graça. | HMAC-SHA256 obrigatório sobre `timestamp.corpo` (`X-Webhook-Signature`/`-Timestamp`/`-Event-Id`, anti-replay e idempotência por evento), montante sempre comparado com o valor interno (`bccomp`), nunca aceite do payload. | 0 |
| 2 | Qualquer utilizador autenticado (mesmo de uma empresa cliente comum) via as versões exactas de PostgreSQL/Redis em `/api/saude` — informação de reconhecimento útil a um atacante (OWASP A05). | Versões só visíveis a um perfil privilegiado (`Utilizador::ePrivilegiado()`) ou em modo de depuração. | 7 |
| 3 | `GatewayPagamentoController::guardarConfig` guardava o segredo do webhook sem o mascarar na leitura seguinte (risco de exposição em logs/capturas de ecrã do backoffice). | `webhook_segredo` mascarado em `obterConfiguracoes()`. | 0 |
| 4 | Verificação "token MFA pendente" invertida: `$token->can('mfa:pendente')` devolve `true` mesmo para um token completo (`['*']` satisfaz qualquer `can()`), deixando passar tokens que deviam estar bloqueados. | Verificação correcta é `! $token->can('*')` (token limitado = não tem a ability total), nunca `$token->can('<ability-específica>')`. | 4 |
| 5 | `mfa_codigos_recuperacao` com `encrypted:array`, incompatível com a coluna `jsonb` (SQLSTATE 22P02) — os códigos já são hashes, uma segunda camada de encriptação era redundante e partia a gravação. | Cast simplificado para `array`. | 4 |

## 2. Controlos de segurança já existentes, confirmados intactos

- **Isolamento multi-tenant**: RLS do PostgreSQL por `empresa_id`, fail-closed (`EscopoEmpresa` lança em vez de
  devolver vazio/tudo sem contexto). Contrato garantido por `IsolamentoRlsContratoTest`, não tocado por esta
  extensão.
- **Geração de palavras-passe**: `Str::password(14)`, nunca escolhidas pelo criador, em todos os pontos novos
  desta extensão (operadores de plataforma, administradores, agentes de suporte). Ver lacuna conhecida na
  secção 4.
- **Anexos de suporte**: validação de MIME pelo conteúdo real, nunca pela extensão.
- **Notas internas**: num modelo/endpoint arquitecturalmente separado da conversa do cliente — nunca um campo
  opcional na mesma mensagem que pudesse escapar por engano.
- **Separação de funções na cobrança**: quem pede uma renovação não pode confirmar o próprio pagamento
  (`exigirSuperAdministrador`).

## 3. RBAC e princípio do menor privilégio (Fase 4)

Perfis granulares por chave (`plataforma_*_gerir`, `suporte_tickets_*_gerir`) em vez de um binário
super-admin/nada. Um operador de suporte não tem, por omissão, acesso a dados financeiros; um operador de
clientes não gere planos. Ver [BACKOFFICE_MANAGEMENT.md](BACKOFFICE_MANAGEMENT.md).

## 4. Lacunas conhecidas, deixadas fora de âmbito (deliberadamente, com justificação)

- **`ClientesController::store()`/`redefinirSenha()` e `ServicoClientes::criar()`** ainda aceitam uma palavra-
  passe escolhida pelo criador para o administrador de um cliente novo — a mesma falha corrigida em todos os
  outros pontos de criação de conta (administradores da plataforma, agentes de suporte, operadores de
  plataforma). Não corrigido nesta sessão por implicar actualizar vários testes existentes que dependem de
  conhecer essa palavra-passe para simular o login do cliente — risco de regressão maior do que o ganho
  imediato justificava sem confirmação explícita do utilizador. **Recomendação**: corrigir numa sessão
  dedicada, migrando os testes afectados para o padrão `palavraPasseDoEmail()` já usado noutros testes desta
  extensão.
- **MFA não obrigatório** para contas privilegiadas (super-admin/operador/agente) — decisão explícita do
  utilizador, para não quebrar testes existentes; a funcionalidade está implementada e disponível, só não é
  imposta.
- **Impersonação/acesso assistido**: não existe, de propósito — o próprio documento-fonte recomenda evitá-la.

## 5. Testes que fixam estas garantias

`PagamentosWebhookSegurancaTest`, `OwaspSegurancaTest` (inclui `a05 cors nao autoriza origens externas e saude
anonima sem versoes`), `MfaTest`, `IsolamentoRlsContratoTest` (pré-existente, não tocado), `SaudeTest`.
