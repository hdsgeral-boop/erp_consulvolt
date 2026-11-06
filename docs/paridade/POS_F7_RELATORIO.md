# POS — Fase F7: configuração de impressão, PDF no servidor e exportações

Objectivo: a configuração de impressão deixa de viver só no navegador, os impressos A4 do POS ganham PDF gerado no servidor, os
relatórios do POS exportam para Excel e os talões da hotelaria e da lavandaria saem com QR da AGT e IBAN. Sem mudar o aspecto
dos impressos aprovados (só acrescenta QR/IBAN/«Processado por computador»). ESC/POS (talão térmico directo) continua fora de âmbito (Q11/Q18).

## 1. Configuração de impressão na BD (Q11, D-13, F-104)

- **Tabela nova** `configuracoes_impressao_pos` (migração `2026_12_12_090000_pos_configuracao_impressao`, aditiva e idempotente, RLS e GRANT
  como as vizinhas): uma linha por empresa (`terminal_pos_id` nulo) e, opcionalmente, uma **sobreposição por terminal** (campos nulos herdam da
  empresa). Único por `(empresa_id, COALESCE(terminal_pos_id, 0))`; CHECK em formato, largura (58/80) e consulta; FK do terminal com `ON DELETE CASCADE`.
  Não alterou `configuracoes_pos` (a tabela das contas/tolerância dos desvios): são assuntos diferentes e essa fica por empresa numa só linha.
- Valores efectivos de um posto: **padrão do sistema < empresa < campos preenchidos do terminal** (`ServicoConfigImpressaoPOS`).
- API (`ConfigImpressaoPOSController`, Requests e Resource com construtor `mixed`): `GET /api/pos/impressao/config[?terminal_pos_id=]` (leitura: qualquer
  permissão de POS/lavandaria/hotelaria/armazém POS), `PUT /api/pos/impressao/config` e `DELETE /api/pos/impressao/config/terminal/{terminal}`
  (permissão `pos_print_config`, que já existia no catálogo: **sem alterações ao `catalogo.json`**). O rodapé é guardado sem marcação. Auditado (`Auditavel`, módulo POS).
- **Frontend** (`modulos/pos/comum/impressao.ts`): `lerPreferencias`/`gravarPreferencias` mantêm-se síncronos mas passam a ser a **cópia local**;
  `sincronizarPreferencias(empresa, terminal)` actualiza-a a partir do servidor (ao abrir qualquer ecrã do POS pela `BarraPos`, ao escolher o terminal na
  frente de caixa e ao concluir uma saída no POS de armazém). Offline ou sem permissão: fica a cópia como estava.
- **Migração dos valores locais**: se o servidor não tem configuração e o navegador tem a sua, o ecrã «Configuração de impressão» mostra a local com o
  aviso «Esta configuração só existe neste navegador» e o botão **«Guardar no servidor»**. O ecrã ganhou o selector «Âmbito» (toda a empresa ou um terminal;
  no terminal só se sobrepõe o que difere da empresa) e «Usar a da empresa» para remover a sobreposição.
- `localStorage`: só continua em `impressao.ts` (já na lista autorizada de `semAutenticacaoNoNavegador.test.ts`; apenas preferências de interface).

## 2. PDF A4 no servidor (F-072, D-12)

- **Motor escolhido: dompdf 3.1.6** (`dompdf/dompdf ^3`, PHP puro; traz `php-font-lib`, `php-svg-lib`, `masterminds/html5`, `sabberworm/php-css-parser`).
  Justificação: não havia nenhum motor de PDF (nem Chromium, wkhtmltopdf ou Gotenberg) nos contentores nem no `composer.json`, e a instância de teste da AWS
  é uma t3.micro sem espaço para um navegador sem cabeça; o dompdf não precisa de binários externos nem de rede. Custo: ~11 MB em `vendor`.
  **Deploy**: o `composer.lock` mudou; o build tem de correr `composer install`. (No contentor de testes o GitHub estava lento: o zip do dompdf veio de uma
  cópia local com o mesmo commit `6d4b4eb`, posta na cache do Composer; o resultado é o do `composer.lock`.)
- `POST /api/pos/impressao/pdf` (`PdfPOSController`, `ServicoPdfA4POS`, `GerarPdfPosRequest`): corpo `{tipo, titulo, subtitulo?, html, css?, orientacao?, nome_ficheiro?}`.
  Descarga com `Content-Disposition: attachment` (nome ASCII + `filename*`), `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`, só A4
  (retrato ou paisagem).
- **O cliente envia só o corpo** (o mesmo `PedidoImpressao` que o «Imprimir» usa); o servidor monta o cabeçalho com a **identidade da empresa activa lida da BD**
  (nunca do pedido), o título, o rodapé da empresa e «Página X de Y» desenhado pelo dompdf (sem scripts no HTML).
- **Segurança**: o HTML é não fiabilizado → lista de elementos e atributos permitidos (sem `script`, `style`, `iframe`, `object`, `link`, `form`, `svg` embutido, ligações,
  `on*`, `srcset`…); `img` só com `data:` png/jpeg/gif/svg (o SVG sem `<script>`, `<image>`, `href`, `<!ENTITY>`, `<use>`, `@import`; o SVG do QR vem urlencoded e é
  reescrito em base64); CSS sem `@import`, `@font-face`, `url()` externo, `expression`/`behavior`; dompdf com `isRemoteEnabled=false`, `isPhpEnabled=false`,
  `isJavascriptEnabled=false`, `chroot` numa pasta vazia e `allowedProtocols` só `data://`. Tecto de 1,5 MB de HTML. Rate limit `throttle:20,1`, middleware
  `licenca_exportacao` (como as outras exportações), isolamento por empresa (RLS + identidade do contexto), auditoria («Gerar PDF»: tipo, título, páginas e bytes; **nunca o conteúdo**).
- **Permissão por tipo** (`GerarPdfPosRequest::TIPOS`): `sessao` (X/Z), `relatorio`, `talao`, `recibo` e `guia_saida`, cada um com as permissões de quem já vê/imprime esse documento.
- **Botão** `BotaoPdfServidor` («Descarregar PDF») em: modal de sucesso da venda (F-072, talão A4 com QR e IBAN), fecho Z, Relatório X, detalhe da sessão e «Saída concluída» do POS de
  armazém (guia de saída A4). O «Guardar como PDF» do navegador continua a existir nos botões de impressão.
- Limite conhecido: o dompdf não faz `flex`/`grid`; por isso o cabeçalho do servidor é próprio (tabela) e o corpo tem de ser tabelas/blocos (é o que o POS imprime). O PDF **não é
  pixel a pixel** igual ao do navegador (a quebra de páginas é do dompdf); o conteúdo, os totais e os números são os mesmos.

## 3. Exportações (Excel)

- `GET /api/pos/relatorios/excel` (`ExportacaoRelatoriosPOSController`, `ServicoExportacaoRelatoriosPOS`): o painel de Relatórios POS em **7 folhas** (Resumo, Por meio, Fechos Z,
  Por produto, Por terminal, Por operador e **Diferenças TPA**), com os mesmos filtros e as mesmas permissões que `GET /api/pos/relatorios`; números como números; texto que
  começa por `=`, `+`, `-` ou `@` gravado como texto (nunca fórmula). Botão «Excel» em POS › Relatórios. O Excel da lavandaria (7 folhas) e os modelos de importação já eram da F5.
- A matriz não atribuía outras exportações à F7 (só F-072 e F-104); o CSV de fechos Z e de produtos já existia e mantém-se.

## 4. Talões fiscais da hotelaria e da lavandaria (F-181)

- Check-out da hotelaria (`Checkout.tsx`): `await obterTalaoFiscal(venda.id)` como 5.º argumento de `htmlTalaoVenda` em cada factura; no **talão único** usa-se
  `obterTalaoFiscalUnico(vendas)` e o talão mostra **um QR e uma série por documento** (`TalaoFiscal.documentos`). Com um só documento o talão é igual ao de sempre.
- Factura da lavandaria (`DetalheOrdem.imprimirFactura`): idem. O recibo RC-LAV e o talão da OS não são documentos de venda com QR e não mudaram.
- Sem resposta do servidor o talão imprime-se igual ao de antes (sem QR nem IBAN): nunca se perde um talão por causa deste pedido. O endpoint do talão já aceitava vendas da lavandaria e do check-out.

## 5. Testes

- Backend: `POSConfigImpressaoTest` (4), `POSPdfA4Test` (7: cabeçalhos, A4 retrato/paisagem, permissão por tipo, validação, auditoria sem conteúdo, limpeza de HTML/CSS/SVG, marcação hostil),
  `POSExportacaoRelatoriosTest` (4). `--filter='POS|Pos'` com `POS_CONTRATO=verificar`: 189 verdes (3204 asserções). Varredores de rotas (`OwaspControloAcessoTest`,
  `EntradasMaliciosasListagensTest`, `AcessoCruzadoGeradoTest`, `ContaRootTest`, `ConsultasPorEndpointTest`) verdes, cada um isolado. Pint limpo.
- Frontend: `configServidor.test.ts`, `pdfServidor.test.ts`, `f7.test.ts` (QR por documento, IBAN, sem fiscal); `vitest` de `modulos/pos`, `posArmazem`, `sessao`, `api`: 169 verdes; `tsc` limpo; `npm run build` refeito.
- Contrato JSON (`contrato_pos.json`): não alterado (rotas novas, nenhuma resposta existente mudou).

## 6. O que fica de fora ou por decidir

- ESC/POS directo, e PDF do talão térmico: fora de âmbito (decisão Q11/Q18).
- Os impressos do POS que usam `BotoesExportar` (relatórios da lavandaria, estadias) ficaram só com o «Guardar como PDF» do navegador; o `BotaoPdfServidor` pode ser posto onde fizer falta
  (só precisa do `PedidoImpressao` e do tipo).
- A configuração de impressão não tem ainda «trilho do que se imprimiu» (registo de cada impressão): só a geração de PDF fica na auditoria.
