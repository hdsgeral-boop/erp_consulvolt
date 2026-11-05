# Auditoria dos Form.List do frontend (antd 6)

Regra (fixada em `frontend/src/formListaSubcampos.test.tsx`): no `onFinish`/`validateFields` os itens de um `Form.List`
só trazem os subcampos com `<Form.Item name={[c.name,'x']}>`. Ids e descrições carregados com `setFieldsValue` e sem
Form.Item perdem-se no envio. Correcção: reler `form.getFieldValue('<lista>')` ou registar o subcampo com `hidden`.

| Ecrã (ficheiro em `frontend/src/modulos/`) | Estado | Razão |
|---|---|---|
| compras/prospeccao/NovaProposta | CORRIGIDO (commit 463ef30) | `pedido_compra_id`, `item_pedido_id`, `produto_id` perdiam-se; lê-se a lista completa. |
| compras/comum/ModaisEncomenda — Recepção | CORRIGIDO | `item_encomenda_id` perdia-se: a API recebia linhas sem o item. Lê-se `getFieldValue('linhas')`. Teste: `ModaisEncomenda.test.tsx`. |
| compras/comum/ModaisEncomenda — Factura | CORRIGIDO | Idem (`item_encomenda_id`; `quantidade` e `taxa_imposto` já estavam registadas). |
| activos/manutencao/Planos (material previsto) | CORRIGIDO | `descricao` do item não tinha Form.Item: ao editar, itens só com descrição desapareciam e a descrição perdia-se. Teste: `Planos.test.tsx`. |
| rh/comum/AMinhaEquipa (avaliação da chefia) | CORRIGIDO | `chave` de critérios/objectivos não estava registada: a avaliação seguia sem chaves. Hidden Form.Item. Teste: `AMinhaEquipa.test.tsx`. |
| compras/comum/LinhasProdutos (NovoPedido, NovaFatura, NiveisStock) | OK | produto_id, descricao, quantidade, preco_unitario, taxa_imposto e UN/CC registados; o payload só usa estes. |
| componentes/reparticao/CamposLinhaUNCC | OK | Regista unidade_negocio_id e centro_custo_id. |
| compras/pedidos/ModalEscaloes | OK | nome e limite registados. |
| vendas/EmitirDocumento | OK | produto_id, descricao, observacoes, quantidade, preco_unitario, UN/CC e `preco_ficha` (hidden) registados. |
| contab/comum/EditorLinhasDC (Lançamentos, Tesouraria) | OK | Todos os campos enviados registados; `_terceiro` só serve de rótulo (e não se envia). |
| crm/comum/Oportunidade | OK | produto_id, descricao, quantidade, preco, taxa registados. |
| crm/ConfigCRM (etapas, tarefas, passos) | OK | `id` da etapa é hidden; restantes campos registados. |
| orcamento/Rubricas | OK | codigo e prefixo registados. |
| pos/lavandaria/Tabelas (precos_servico) | OK | produto_id e preco registados. |
| pos/Terminais (meios_pagamento) | OK | `id` e `tipo` são hidden; os campos específicos do TPA aparecem quando o tipo o exige. |
| projectos/separadores/Requisicoes | OK | produto_id, quantidade e tarefa_projeto_id registados. |
| rh/Avaliacao | OK | `chave` hidden. |
| rh/comum/PortalAvaliacao | OK | Lê `form.getFieldsValue(true)`. |
| rh/comum/ContratosMassa | OK | infotipo_salarial_id e valor_mes registados. |
| rh/produtividade/ElegibilidadeMassa | OK | item_id e preco_unitario registados. |
| rh/Contratos (remuneracoes) | OK | infotipo_salarial_id e valor_mes registados. |
| rh/Portal (dependentes) | OK | nome, parentesco, data_nascimento, dependente_fiscal registados. |
| rh/Colaboradores (dependentes, habilitacoes) | OK | O `id` não segue, mas o servidor substitui as listas por inteiro. |
| vendas/agt/FaturacaoEletronica (estabelecimentos) | OK | numero e nome registados. |
| vendas/produtos/Produtos (caracteristicas) | OK | chave e valor registados. |
| activos/manutencao/Ordens (tarefas) | OK | texto e obrigatoria registados. |
| Listas por estado React (activos/ModaisActivos, contab/Rotinas, metodo/FormularioAssunto, orcamento/DetalheOrcamento) | OK | Enviam directamente o estado React, não o `v` do formulário. |

Nota: o mesmo defeito aplica-se a campos soltos (fora de listas) sem Form.Item lidos em `v.*`; não se encontrou nenhum caso nos ecrãs acima.
