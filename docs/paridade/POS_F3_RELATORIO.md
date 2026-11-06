# POS — Fase F3: POS de armazém

Ramo `paridade-bd-front`. Referência do legado: `v_boss/.../js/ui_pos_armazem.js` (inventário: `POS_LEGADO_INVENTARIO.md` §2.B). Matriz: `POS_MATRIZ_LACUNAS.md` (linhas F-137…F-160).

## O que mudou

| Linha | Antes | Agora |
|---|---|---|
| F-150 Guia de Saída A4 | Só em Logística › Guias de Saída (com custo) | Ao confirmar a saída (balcão e picking) imprime-se a **Guia de Saída A4** pelo motor comum: empresa real no cabeçalho (nome, NIF, morada, telefone, email da identidade; o legado tinha telefone/email/morada de exemplo), destinatário, dados de expedição, tabela `# · Código · Descrição do Artigo · Quantidade · Preço Unit. · Total Líquido`, `TOTAL GERAL`, aviso «Este documento não serve de fatura…», assinaturas «O Operador do Armazém» e «O Cliente / Recebedor». Sem QR/ATCUD/hash (não é documento fiscal) |
| F-151 Talão de levantamento | EM FALTA | **Talão de Levantamento / Picking** (largura e formato do posto, como o talão do POS): documento, data e hora, encomenda (picking), destinatário, armazém, «Operação: Logística / Balcão», artigos (código · nome, qtd × preço, total), `TOTAL GERAL`, «Obrigado pelo seu atendimento no nosso Balcão de Armazém!» |
| F-152 Voz | Só nas senhas | «Atendimento de X concluído com sucesso. Guia N gerada.» (balcão) e «Picking concluído e expedido para X.» (picking), `pt-PT`, velocidade 1; respeita «Voz ligada/desligada» do monitor |
| F-144 Cliente de balcão | Confirmado: `POST pos/armazem/vendas` e o ecrã já aceitam cliente | Acrescentado: o nome e o NIF do cliente escolhido vão para a guia e o talão; sem cliente sai «Cliente de balcão». Teste PHPUnit dos dois caminhos |
| F-138 Nenhum armazém | Texto certo, botão «Configurar Armazens» | Botão «Configurar Armazéns» (sem acento corrompido) e teste |
| F-141 Filtro, pesquisa, grelha/lista | Já existiam | Verificados em teste. O **zoom `pos_armazem` fica para a F8** (decisão da matriz) |
| F-153 Fila de picking | `total_bruto` (BUG-03 já corrigido) | Mantido; teste PHPUnit com número à mão (5 × 1000 + 1 × 500, IVA 14 % = 6270,00) e teste Vitest que mostra o valor |
| F-154/F-158 EM PICKING / Pausar | NÃO PORTAR | Mantido (Q8: recomendação do plano) |
| F-146 Acerto, F-159/F-160 senhas | Já portados | Sem alteração funcional (a voz foi extraída para `posArmazem/voz.ts`, comum às senhas e às conclusões) |

## Preferência do posto

Na janela de conclusão: «Ao expedir, imprimir: Guia A4 e talão / Só a guia A4 / Só o talão / Nada» (`localStorage` `pos_wh_impressao_auto`, omissão «Guia A4 e talão», como o legado, que imprimia os dois). Os botões «Imprimir Documento A4» e «Imprimir Talão» reimprimem a qualquer momento.

## Correcção ao legado que exigiu BD

A guia de saída guardava só o custo por linha; o impresso do cliente com custo seria uma fuga de informação, e o legado mostrava o preço de venda. Migração **`2026_12_08_090000_pos_armazem_preco_guia_saida`** (aditiva, idempotente): `itens_guia_saida.preco_unitario_kz numeric(15,2) NULL`. A tabela já tem RLS e GRANT. É preenchida só na venda ao balcão, pelo preço do produto na altura da saída (alterações posteriores ao preço não mudam a guia). Guias anteriores ficam com NULL: o A4 mostra então só as quantidades, sem preço nem total. Actualizados: `ItemGuiaSaidaBase`, `database/legado/esquema.json`, dicionário de dados. No picking os preços vêm da guia de remessa das Vendas (já os tinha).

## Contrato JSON

Único campo novo: `linhas[].preco_unitario_kz` em `POST pos/armazem/vendas` (201), acrescentado deliberadamente a `backend/tests/Fixtures/contrato_pos.json` (só essa linha; a linha 43 do catálogo é de outro agente e não foi incluída no commit). `GET pos/armazem/vendas/{guia}` devolve o mesmo campo (fora do contrato gravado). `POS_CONTRATO=verificar` verde.

## Ficheiros

Backend: `ServicoGuiasSaida` (preço na linha da venda ao balcão), `ItemGuiaSaidaBase`, migração acima, `POSArmazemTest`, fixture do contrato.
Frontend (`frontend/src/modulos/stock`): `posArmazem/impressao.ts` (conclusões, guia A4, talão, frases), `posArmazem/Conclusao.tsx` (janela, voz, impressão automática), `posArmazem/voz.ts`, `posArmazem/Balcao.tsx`, `posArmazem/Senhas.tsx`, `PosArmazem.tsx` (picking), `comum/tipos.ts`. Reutiliza `htmlTalaoGenerico`, `useCabecalhoTalao`, `lerPreferencias`, `imprimirHtml` de `modulos/pos/comum/impressao.ts` (só importa; não foi alterado) e o motor `componentes/impressao`.

## Testes

- PHPUnit (`--filter='POSArmazem|ParidadePosArmazem|GuiasRemessaArmazem'` com `POS_CONTRATO=verificar`): 12 verdes. Número à mão novo: 3 × 1000,00 → preço 1000,00, custo 600,00, valor 1800,00; preço alterado para 1500 só afecta guias seguintes; NE 6270,00. Também `EsquemaContrato|GuiaSaida|GuiasSaida` verdes. Pint verde nos ficheiros PHP tocados.
- Vitest: `posArmazem/impressao.test.ts` (7: totais 3 × 1000 + 2,5 × 19,90 = 3 049,75, guia A4 sem custo, guias antigas sem preço, talão, frases, picking), `posArmazem.test.tsx` (balcão com voz e talão), `pickingArmazem.test.tsx` (expedição e aviso sem armazéns): 19 verdes. `tsc` sem erros nos ficheiros de stock (os erros que restam são de `pos/frente`, F1 em curso).

## Notas

- Durante os testes, duas corridas minhas de PHPUnit coincidiram com testes de outro agente (deadlocks no `migrate:fresh`); foram repetidas isoladamente e passaram. Não houve alteração de dados.
- Não se reconstruiu `frontend/dist`; fica para quem fizer a ronda final.
- Não se tocou em produção nem no teste AWS; a migração ainda não foi aplicada em nenhum deles (ensaiar antes: é uma só coluna anulável).
