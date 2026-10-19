# Facturação electrónica AGT e SAF-T(AO) — normas, fontes e implementação

Ronda 4 (agente r4_agt), 2026-10-17. As fontes foram consultadas nessa data. Cada ponto está marcado assim:

- **[OFICIAL]**: confirmado na documentação técnica publicada pela AGT ou no XSD oficial.
- **[SECUNDÁRIA]**: indicado por consultoras ou imprensa que citam o diploma; o texto do Diário da República não foi lido directamente.
- **[INFERIDO]**: decisão de boa prática nossa, onde a norma é omissa ou ambígua.

## 1. Fontes

| # | Fonte | URL | Tipo |
|---|---|---|---|
| F1 | AGT — Documentação técnica da Facturação Electrónica (introdução, modelo, API, JWS, gestão de chaves, QR) | https://quiosqueagt.minfin.gov.ao/doc-agt/faturacao-electronica/1/ (`api.html`, `modelo.html`, `estrutura.html`, `gestao.html`, `qrcode.html`) | Oficial |
| F2 | AGT — Serviços: registarFactura, obterEstado, consultarFactura, solicitarSerie, listarSeries, validarDocumento | …/1/servicos/`registar.html`, `consultar.html`, `consultar_fatura.html`, `solicitar.html`, `listar.html`, `validar.html` | Oficial |
| F3 | XSD SAF-T(AO) 1.01_01 (esquema oficial publicado pela comunidade Assoft) | https://raw.githubusercontent.com/assoft-portugal/SAF-T-AO/master/XSD/SAFTAO1.01_01.xsd | Oficial (esquema) |
| F4 | Assoft SAF-T-AO, issue #22 «Documentos importados de outras aplicações» (2019-2020) | https://github.com/assoft-portugal/SAF-T-AO/issues/22 | Proposta de integradores, não confirmada pela AGT |
| F5 | EY Angola, «Novo Regime Jurídico das Facturas» (9/4/2025): Decreto Presidencial n.º 71/25, de 20 de Março | https://www.ey.com/pt_ao/technical/tax-alerts/novo-regime-juridico-das-facturas | Secundária |
| F6 | EY Angola, «Facturação Electrónica a partir de 1 de Janeiro de 2026» | https://www.ey.com/pt_ao/technical/tax-alerts/facturacao-electronica-a-partir-de-1-de-janeiro-de-2026 | Secundária |
| F7 | Cegid Angola (regime jurídico, anatomia da factura), Expansão, Novo Jornal (contingência de 40 dias) | cegid.com/ao/blog/novo-regime-juridico-facturas/, …/anatomia-factura/; expansao.co.ao; novojornal.co.ao | Secundária |

O texto integral dos Decretos Presidenciais 71/25 e 312/18 (Diário da República) não está disponível em acesso aberto que tenha sido possível ler. Por isso, os pontos que dependem só desses decretos estão marcados como secundários.

## 2. O que a norma diz

### 2.1 Regime jurídico [SECUNDÁRIA, F5 F6 F7]
- O Regime Jurídico das Facturas e Documentos Equivalentes foi aprovado pelo Decreto Presidencial n.º 71/25, de 20 de Março. A emissão é electrónica, em software validado pela AGT, com transmissão em tempo real.
- Desde 1/1/2026 a obrigatoriedade abrange os grandes contribuintes, os fornecedores do Estado e os contribuintes com facturas a partir de 25 M Kz. Os restantes sujeitos aos regimes geral e simplificado do IVA entram depois: as fontes divergem entre 21/9/2026 e 2027.
- O documento impresso tem de identificar o software validado, o código hash e o n.º de certificado ou validação.
- As facturas omitidas do SAF-T **consideram-se não emitidas** para efeitos de penalização (7 %, ou 15 % em reincidência).
- A anulação ou rectificação faz-se por nota de crédito, que indica o motivo e o documento original. Na NC electrónica, o adquirente confirma.
- Contingência (art. 18.º): pode emitir-se offline quando há falha de comunicação, falta de energia ou avaria, e as facturas são submetidas depois. **O prazo não foi confirmado.** A imprensa fala em 40 dias.

### 2.2 Serviços web [OFICIAL, F1 F2]
- **Ambientes**: homologação em `https://sifphml.minfin.gov.ao/sigt/fe/v1/<serviço>` e produção em `https://sifp.minfin.gov.ao/sigt/fe/v1/<serviço>`. A página do solicitarSerie mostra para homologação `…/sigt/fe/ws/v1/registarFactura`, o que parece um erro da documentação.
- **Autenticação**: HTTP Basic (`Authorization: Basic base64(user:pass)`), com as credenciais pedidas pelo produtor a `produtores.dfe.dcrr.agt@minfin.gov.ao`.
- **Modelo**: assíncrono. O `registarFactura` só valida a estrutura e devolve o `requestID`. O estado obtém-se por **consulta** (`obterEstado`).
- **Assinaturas**: JWS compacto RS256, cabeçalho `{"alg":"RS256","typ":"JWT"}`, sobre o objecto JSON completo (não sobre campos concatenados), em Base64URL sem padding. A chave RSA tem pelo menos 2048 bits (recomendam-se 4096).
  - `jwsSoftwareSignature` cobre o `softwareInfoDetail` e usa a chave do produtor. A chave pública é registada no Portal do Parceiro (`portaldoparceiro[.hml].minfin.gov.ao`). «A chave privada nunca deve sair do ambiente do produtor.»
  - `jwsDocumentSignature` cobre {documentNo, taxRegistrationNumber, documentType, documentDate, customerTaxID, customerCountry, companyName, documentTotals{taxPayable, netTotal, grossTotal}} e usa a chave do **contribuinte**, emitida pela AGT no Portal do Contribuinte.
  - `jwsSignature` dos pedidos usa a chave do contribuinte. Exemplos: {taxRegistrationNumber, requestID} no obterEstado; {taxRegistrationNumber, documentNo} no consultarFactura; {taxRegistrationNumber, establishmentNumber, seriesYear, documentType} no solicitarSerie.
- **registarFactura**:
  - Até **30 documentos** por pedido, com `numberOfEntries` igual ao número de documentos e `submissionUUID` (RFC 4122) por submissão.
  - `softwareInfoDetail` tem **`signatureVersion`** (obrigatório).
  - `documentStatus` é N ou **C**. C significa «documento gerado para correcção de documento anteriormente gerado e rejeitado pela AGT» e obriga a `rejectedDocumentNo`.
  - `settlementAmount` é obrigatório em cada linha. `lines` é obrigatório excepto em AR/RC/RG, que levam `paymentReceipt`.
  - Resposta 200 com `requestID`, que também é devolvido num **pedido duplicado** com o ID do pedido original. Resposta 400 com `errorList{idError, descriptionError, documentNo}`.
- **Códigos de erro** (selecção):
  - E01–E04: estrutura.
  - E05/E28/E29: NIF e adesão.
  - E06: data do pedido fora do período permitido.
  - E07/E08/E39: software ou assinatura do produtor.
  - **E09**: factura já existe.
  - E11: NIF desconhecido.
  - E13/E14: documento de referência.
  - E22–E25: totais.
  - E40: assinatura do pedido.
  - E41/E42: valores a regularizar ou anular.
  - **E46 (FE-RNG-073)**: «As correcções de documentos rejeitados deverão ser efectuados com a utilização de um novo número de documento».
- **obterEstado**:
  - `resultCode`: 0, 1 e 2 (concluído), **7** (prematuro ou repetitivo — aguardar), **8** (em curso), **9** (cancelado).
  - `documentStatusList[{documentNo, documentStatus V|I, errorList{errorCode, errorDescription}}]`.
  - Erros HTTP: 422 E96/E97/E95, **429 E98** (demasiados pedidos).
- **consultarFactura**: devolve `validationStatus` **V** ou **P**, em que P significa «válida com penalização»: enviada com **mais de 24 h** de atraso e sem contingência aprovada. A tabela chama ao campo `documentNo` e o exemplo `invoiceNo` — está por esclarecer.
- **solicitarSerie**:
  - `seriesYear` é um inteiro. Só se pede o ano corrente; depois de 15/12, também o seguinte.
  - `seriesContingencyIndicator` é N (normal) ou **C** (contingência).
  - O estabelecimento tem de estar registado.
  - Resposta: `seriesFEResult{seriesCode, authorizedQuantity, firstDocumentNo, lastDocumentNo}`.
  - Erros E30–E38, E48 e E49.
- **listarSeries** (só leitura) e **validarDocumento** (confirmação ou rejeição pelo adquirente; não implementado — é do lado de quem compra).
- **QR code**:
  - URL `https://quiosqueagt.minfin.gov.ao/facturacao-eletronica/consultar-fe?emissor=<NIF>&document=<documentNo>`, com os espaços como `%20`.
  - Modelo 2, versão 4, correcção M, PNG 350×350.
  - O logótipo da AGT é opcional e ocupa menos de 20 %.
- **Notificações (webhook)**: a introdução diz literalmente «Callback (se ativado) — A API faz POST para o endpoint do contribuinte quando o documento for validado. **(Disponível nas próximas versões)**». **Hoje o modelo é só por consulta** (polling). O formato e a autenticação do callback não estão publicados.
- **Rotação e compromisso de chaves** (`gestao.html`): a chave comprometida é revogada no portal e substituída por um par novo. Os documentos já aceites continuam válidos.

### 2.3 SAF-T(AO) e documentos de outro sistema [OFICIAL F3, SECUNDÁRIA F4 F5]
- `SourceBilling`: «**P** para documento produzido na aplicação, **I** para documento integrado e produzido noutra aplicação, **M** para documento proveniente de recuperação ou de emissão manual» [OFICIAL F3].
- `HashControl`: «deve ser preenchido com "0" (zero), caso o documento seja gerado por um programa não validado» [OFICIAL F3].
- `InvoiceNo` e `DocumentNumber` seguem o padrão `[^ ]+ [^/^ ]+/[0-9]+`, até 60 caracteres, e não podem repetir-se [OFICIAL F3]. Os números migrados «FR-POS/…», «FT-LAV/…» e «GR LOG …» **não cumprem** este padrão.
- `InvoiceStatus` «A» indica documento anulado [OFICIAL F3].
- Os integradores propuseram, para documentos importados, `HashControl = <n.º de validação do programa de origem>-<versão da chave>` [F4]. Não há confirmação de que a AGT o tenha adoptado. Como o sistema anterior não estava validado, o valor oficial aplicável é «0».
- Omitir facturas do SAF-T faz com que se considerem não emitidas [SECUNDÁRIA F5]. **Por isso não se excluem.**

## 3. O que foi implementado (por empresa)

### 3.1 Configuração e segredos
- A tabela `agt_ligacoes_empresa` guarda uma ligação por empresa:
  - ambiente (homologação/produção) e endereço opcional, só HTTPS em `*.minfin.gov.ao` (anti-SSRF);
  - credenciais Basic;
  - chave privada do contribuinte e chave pública ou certificado X.509;
  - webhook.
- Os segredos (`utilizador`, `palavra_passe`, `chave_privada`, `webhook_segredo`) usam o cast **`encrypted`** (APP_KEY):
  - estão em `$hidden`;
  - nunca saem na API nem na auditoria;
  - a interface só mostra «Configurado em … por …», a impressão digital SHA-256, os bits, a versão e a validade.
- Validação ao gravar a chave:
  - PEM legível, com frase-passe se a tiver;
  - RSA com pelo menos 2048 bits;
  - correspondência com a chave pública ou o certificado (`openssl_x509_check_private_key`);
  - validade do certificado;
  - prova de assinar e verificar.
- **Rotação**: `chave_versao` incrementa a cada troca, guarda-se a impressão digital da chave anterior e a anterior deixa de ser usada de imediato.
- Activar exige a chave do contribuinte, credenciais (da empresa ou do servidor) e os dados do software.
- **A chave do produtor e o software** (productId, versão, n.º de validação, `signatureVersion`) **ficam no servidor** (`AGT_*` e `/run/segredos/agt`), como manda F1: são do produtor, não da empresa.
- Sem ligação própria activa, continua a valer o modo anterior: `AGT_DRIVER` e `<NIF>.pem` na pasta de segredos (`ClienteAgtPorEmpresa`).

### 3.2 Comunicação
- `ClienteHttpAgt` trata do transporte:
  - TLS verificado;
  - `connectTimeout` (`AGT_TIMEOUT_LIGACAO`) e `timeout` (`AGT_TIMEOUT`);
  - **retentativas só em falhas transitórias** (sem ligação ou tempo esgotado, 500/502/503/504), com recuo exponencial e jitter (`AGT_RETENTATIVAS`, `AGT_RECUO_BASE_MS`, `AGT_RECUO_MAX_MS`) e respeito do `Retry-After`;
  - os 4xx não se repetem.
- **Outbox e idempotência**:
  - O documento fica `POR_ENVIAR` no mesmo COMMIT da emissão (selagem). O envio corre depois do commit (job `CicloAgt`, fila `agt`) e no agendador (`erp:agt:ciclo`, de 2 em 2 minutos).
  - Antes de cada `registarFactura`, o lote fica em `agt_submissoes` com um **submissionUUID fixo**. Se o resultado for incerto (sem ligação, 5xx ou 429), o mesmo lote é reenviado com o **mesmo UUID**: a AGT devolve o requestID original, ou E09, e aí consulta-se o estado.
  - Um lock Redis por empresa evita envios simultâneos.
- **Estados** (`MaquinaEstadosAgt`, com transições validadas):
  - POR_ENVIAR → ENVIADO → VALIDO (V, ou P com penalização) ou INVALIDO;
  - REJEITADO (recusado na recepção);
  - CONTINGENCIA (AGT indisponível: reenvio automático);
  - ERRO (configuração ou serviço);
  - SUBSTITUIDO (corrigido por um documento novo).
- **Consulta periódica**: `obterEstado`, com espera crescente nos resultCode 7/8 e E97/E98/E96, reenvio no 9 e `consultarFactura` depois de E09. Os erros `errorCode/errorDescription` são agora lidos (antes perdiam-se).
- **Correcção (E46)**:
  - Um documento INVALIDO ou REJEITADO já não se reenvia com o mesmo número.
  - `POST /vendas/documentos/{id}/correccao-agt` (`ServicoCorrecaoAgt`) emite um documento **novo**: mesma série, data de hoje, linhas e valores do original, dados de cliente e produtos corrigidos.
  - Esse documento vai com `documentStatus C` e `rejectedDocumentNo`.
  - O original não muda de número nem de valores: fica ANULADO no ERP e SUBSTITUIDO na AGT, ligado ao novo.
  - Exige que o original não esteja contabilizado e não tenha recibos, NC ou movimentos de tesouraria. Os movimentos de stock ficam no original.
- **Anulação**: um documento comunicado anula-se por NC, que também é comunicada com `referenceInfo`. Não existe serviço AGT de anulação directa.
- **Registo de comunicações** (`agt_comunicacoes`):
  - um registo por tentativa, com operação, ambiente, endpoint, HTTP, duração, código, mensagem, submissionUUID, requestID e documentos;
  - pedido e resposta com **assinaturas, segredos e NIF de clientes mascarados** e limite de 20 KB.
- **Monitorização** (`GET /vendas/faturacao-eletronica/monitor`):
  - documentos por comunicar, com idade, tentativas e contingência;
  - recusados com motivo e botão de correcção;
  - lotes incertos;
  - falhas das últimas 24 h;
  - alerta quando há documentos por comunicar há mais de `AGT_ALERTA_HORAS` (20 h; a penalização começa às 24 h).
  - No Início aparece o contador «Documentos recusados pela AGT».
- **Webhook** (`POST /api/agt/webhook/{token}`, preparado para quando a AGT o disponibilizar):
  - endereço por empresa com token aleatório de 256 bits, de que só fica o SHA-256;
  - HMAC-SHA256 do segredo da empresa sobre `carimbo.nonce.corpo` (cabeçalhos `X-AGT-Carimbo`, `X-AGT-Nonce`, `X-AGT-Assinatura: sha256=…`), comparado em tempo constante;
  - janela de ±300 s, **nonce único por empresa** (replay devolve 200 «duplicado» sem reprocessar) e limite de 64 KB;
  - responde 202 de imediato e processa na fila;
  - **o corpo nunca é tomado como estado oficial**: dispara uma `obterEstado` só para os documentos ENVIADO da empresa dona do endereço.
  - O formato da AGT é [INFERIDO]: quando for publicado, adapta-se `ServicoWebhookAgt`.

### 3.3 Séries e contingência
- Com o regime activo, **«exigir séries AGT» fica obrigatório** (recomendação aceite): nenhum documento fiscal sai numa série sem `agt_codigo`.
- O **modo de contingência** é declarado na configuração, com motivo, data e autor. Enquanto está activo, os documentos fiscais saem na **série de contingência** (`contingencia = true`, pedida com o indicador «C»), que pode estar activa ao lado da série normal.
- Os documentos são comunicados quando a ligação volta; o ciclo reenvia-os automaticamente.

### 3.4 Documento impresso
- QR code conforme F1, como já existia.
- [INFERIDO] Menção «`<4 caracteres do Hash>`-Processado por programa validado n.º `<n.º de validação>`», quando o n.º está configurado. Na falta dele, fica «Hash: XXXX».
- Na correcção, o documento impresso diz «Documento de correcção do documento X, recusado pela AGT».

### 3.5 Documentos migrados fora do formato SAF-T («FR-POS/…», «FT-LAV/…», «GR LOG …»)
- **Nunca são renumerados nem alterados.**
- `erp:saft:documentos-importados [--simular] [--empresa=]` marca-os como integrados:
  - `saft_source_billing = 'I'` e `sistema_origem`;
  - no caso de os números não cumprirem o padrão do XSD, grava o **n.º SAF-T** em `numero_documento_saft` (por exemplo, `FR-POS/T1/2026/5` → `FR POS-T1-2026/5`). A regra é determinística, o «-» garante que não colide com séries nativas e há verificação de unicidade.
- Critério: FT/FR/NC nunca selados por este sistema, ou GR/GD com número fora do padrão.
- No SAF-T saem com `SourceBilling` «I», `Hash` «0» e `HashControl` «0» (F3). Se forem anulados, saem com `InvoiceStatus` «A» e o motivo, fora dos totais. As referências das NC e dos recibos usam também o n.º SAF-T.
- **Nunca são enviados** à facturação electrónica.
- O relatório de validação `GET /vendas/saft/documentos-importados?inicio=&fim=` mostra o n.º original ao lado do n.º SAF-T. Os avisos do `/vendas/saft/validar` e do ficheiro referem os integrados.
- Os documentos anulados (de qualquer origem) passam a sair no SAF-T com «A» (antes eram omitidos).

## 4. O que falta obter da AGT (fica configurável)

| Item | Onde se configura |
|---|---|
| Credenciais Basic: se são por produtor ou por contribuinte (e-mail para produtores.dfe.dcrr.agt@minfin.gov.ao) | Servidor (`AGT_UTILIZADOR`/`AGT_PALAVRA_PASSE`) ou por empresa |
| N.º de validação do software, productId/versão, `signatureVersion` e registo da chave pública do produtor no Portal do Parceiro | `AGT_SOFTWARE_VALIDATION`, `AGT_PRODUCT_ID`, `AGT_PRODUCT_VERSION`, `AGT_SIGNATURE_VERSION` |
| Se o `signatureVersion` entra na `jwsSoftwareSignature` (a tabela do registar diz que se assina o `softwareInfoDetail` completo; a página JWS lista três campos) | Hoje é assinado com ele; `AGT_SIGNATURE_VERSION=` vazio retira-o |
| `typ` do JWS | `AGT_JWS_TYP` (JWT) |
| Nome do campo no consultarFactura (`documentNo` ou `invoiceNo`) | `AGT_CAMPO_CONSULTA_DOCUMENTO` (documentNo) |
| Caminho de homologação do solicitarSerie (`/fe/ws/v1/`?) | Endereço por empresa (domínio da AGT) ou `AGT_URL_BASE` |
| Formato e autenticação do callback | `ServicoWebhookAgt` (actualmente HMAC próprio) |
| Prazo legal de comunicação em contingência (art. 18.º; imprensa: 40 dias) e procedimento de «contingência aprovada» | Alerta `AGT_ALERTA_HORAS` |
| Redacção exacta da menção do software no impresso | `impressao/documentoVenda.ts` |
| Tratamento dos documentos integrados pelo validador SAF-T da AGT | Confirmar com o ficheiro de teste no validador |

## 5. Como configurar uma empresa
1. **Servidor**: definir `AGT_PRODUCT_ID`, `AGT_PRODUCT_VERSION`, `AGT_SOFTWARE_VALIDATION`, `AGT_SIGNATURE_VERSION`, colocar a chave do produtor em `/run/segredos/agt/produtor_privada.pem` e, se as credenciais forem do produtor, `AGT_UTILIZADOR`/`AGT_PALAVRA_PASSE`.
2. Em Vendas › Facturação › Facturação electrónica, na secção **Ligação da empresa à AGT** (permissão `vendas_agt_ligacao`):
   1. carregar a chave do contribuinte (e o certificado ou a chave pública);
   2. se for o caso, as credenciais;
   3. escolher o ambiente «Homologação» e activar;
   4. usar «Testar ligação» (listarSeries).
3. Pedir as séries à AGT em **Séries › Pedir à AGT**, incluindo as de contingência, e activar o regime. «Exigir séries AGT» passa a obrigatório.
4. Passar para «Produção» depois da homologação.
5. Opcional: activar o webhook quando a AGT o disponibilizar e registar nela o endereço e o segredo, que só são mostrados uma vez.
6. Uma vez por empresa: `php artisan erp:saft:documentos-importados --simular`, rever o resultado e depois correr sem `--simular`.
