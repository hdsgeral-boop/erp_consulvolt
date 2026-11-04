# Cobrança e pagamentos

> Fases 0 e 3 da extensão SaaS (`segurança.txt`, secções 7-9). Fluxo de cobrança **manual**, por decisão explícita
> do utilizador: não existe geração automática de pedidos de renovação nem cobrança recorrente — só sincronização
> automática de *estado* a partir das datas já confirmadas. Ver
> [SAAS_PAGAMENTOS_NOTIFICACOES.md](SAAS_PAGAMENTOS_NOTIFICACOES.md) para o inventário de ecrãs/UI (nota: esse
> documento descreve a activação do pagamento pelo webhook como "instantânea" — desde a Fase 0 isso só acontece
> com assinatura HMAC válida, nunca com um payload não assinado; ver secção 2 abaixo).

## 1. Três formas de pagar

1. **Transferência bancária + comprovativo** (`ServicoComprovativosPagamento`): o cliente anexa o comprovativo
   (`POST /api/licenca/subscricoes/{id}/comprovativo`); fica pendente de revisão manual — **nunca activa a
   licença por si só**. Um super-administrador confirma depois de verificar (`confirmarPagamento`).
2. **Referência Multicaixa** (`GatewayReferenciaMulticaixa`): gera entidade + referência de 9 dígitos. Sem
   credenciais reais configuradas, degrada-se para modo manual (nunca inventa uma API que não existe).
3. **Multicaixa Express** (`GatewayMulticaixaExpress`): pedido de débito directo (push) para o número de telefone
   associado. Mesma degradação sem credenciais.

Em qualquer dos três casos, a confirmação final que estende a licença é sempre
`ServicoLicenca::confirmarPagamento()` — pelo webhook do gateway (2) ou manualmente por um super-administrador
(1 e 3). Ver [SUBSCRIPTION_MANAGEMENT.md](SUBSCRIPTION_MANAGEMENT.md) secção 3.

## 2. Segurança do webhook (Fase 0 — corrigida nesta extensão)

Antes da Fase 0, `GatewayPagamentoController::processarWebhook` confiava directamente em
`$payload['status']`/`$payload['amount']` do corpo do pedido, sem assinatura, sem protecção contra repetição, e
sem validar o montante contra o valor devido — um vector real de fraude (forjar uma confirmação falsa activava
uma licença de graça). Corrigido:

- `ProcessadorPagamentos::processarWebhook()` (contrato evoluído em 2026-11, integração da produção) exige:
  assinatura HMAC-SHA256 de `"{timestamp}.{corpo bruto}"` (cabeçalhos `X-Webhook-Timestamp`, `X-Webhook-Signature`
  — aceita o prefixo `sha256=` — e `X-Webhook-Event-Id`), comparada com `hash_equals` contra o segredo do gateway
  (`ref_webhook_segredo`/`mcx_webhook_segredo`, **cifrado em repouso** com prefixo `enc:` — `SegredosGateway`, chave
  `APP_KEY`); janela anti-replay de 300 s; evento único por gateway (`webhooks_pagamento.evento_unico`, idempotência);
  gateway da transacção igual ao da rota; valor e moeda **obrigatórios** e iguais aos da subscrição; subscrição
  `PENDENTE` (bloqueada com `lockForUpdate`). Respostas: 403 sem segredo configurado, 401 assinatura inválida, 400
  timestamp fora da janela / evento sem id, 409 evento duplicado ou subscrição não pendente, 422 gateway/valor/moeda.
  **Todas** as tentativas (aceites e rejeitadas) ficam em `webhooks_pagamento` (e as rejeições principais em log).
- O contrato antigo (`X-Webhook-Assinatura: sha256=<hmac do corpo>`) foi **retirado**: não protegia contra repetição.
  O fornecedor do gateway tem de enviar os três cabeçalhos novos.
- O montante nunca é aceite sem confronto: os drivers devolvem o valor/moeda declarados no payload e o processador
  compara-os (`number_format(…, 2)`) com os da subscrição; divergência → `VALOR_DIFERENTE`/`MOEDA_DIFERENTE` (422), sem
  confirmar nada.
- A configuração dos gateways nunca devolve segredos (nem os últimos 4 caracteres): só `configurado`/`tem_chave` e
  `webhook_segredo_configurado`/`tem_segredo_webhook`. Segredos de webhook novos exigem pelo menos 16 caracteres.
- Testes: `WebhookPagamentoSeguroTest` (assinatura inválida, replay, evento duplicado/sem id, valor, moeda, estado,
  gateway, segredos cifrados, migração de cifragem, simulação por flag) e `NotificacoesEGatewaysTest`.

## 3. Facturação electrónica da subscrição (AGT)

`ServicoFaturacaoSaas::emitirParaSubscricaoPaga()` reaproveita 100% o pipeline fiscal existente
(`ServicoDocumentosVenda`), emitindo a factura na empresa de facturação da **própria plataforma**
(`config('erp.faturacao_saas')`) — nunca na empresa do cliente, que está a pagar, não a vender. Corre **fora** da
transacção do pagamento: uma falha na emissão (AGT em baixo, configuração incompleta) nunca desfaz um pagamento
já confirmado; fica em log para reprocessamento manual. `empresa_id=0` desliga a emissão (ambientes sem a empresa
de facturação criada).

## 4. Cupões de desconto

`CupomDesconto`: validação prévia nunca bloqueia o checkout (`validarCupom` devolve `valido: false` em vez de
erro); aplicação usa `lockForUpdate()` para nunca deixar pedidos concorrentes esgotarem um cupão com limite de
usos para além do permitido. Ver `CupomDescontoTest`.

## 5. O que foi deliberadamente deixado fora (decisões do utilizador nesta sessão)

- **Geração automática de cobrança** (pontos de aviso/vencimento automáticos de novas subscrições): avaliado na
  Fase 3 e recusado — só a *sincronização de estado* (Fase 1→3) é automática; gerar pedidos de pagamento continua
  manual.
- **Prorata/crédito ao trocar de plano a meio do ciclo**: avaliado na Fase 8 e recusado — tocaria directamente em
  facturação sem aprovação prévia. Ver [SUBSCRIPTION_MANAGEMENT.md](SUBSCRIPTION_MANAGEMENT.md) secção 7.

## 6. Testes

`PagamentosWebhookSegurancaTest`, `NotificacoesEGatewaysTest`, `ComprovativoPagamentoTest`,
`Tests\Feature\Plataforma\FaturacaoSaasTest`, `LicencaCobrancaTest`, `CupomDescontoTest`.
