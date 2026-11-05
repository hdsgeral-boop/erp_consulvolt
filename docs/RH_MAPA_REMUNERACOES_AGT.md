# Mapa de Remunerações no modelo da AGT (IRT A2.1)

Botão **«Exportar Mapa de Remunerações — Modelo AGT (IRT A2.1)»** em RH › Relatórios e Recibos › *Mapa Fiscal de IRT (Modelo Oficial)*
(`/m/rh/relatorios`). Descarrega `Mapa Remuneração AAAA - Mês.xlsx` com a estrutura do ficheiro oficial da AGT (submissão mensal de IRT e
Segurança Social), preenchido com a folha **validada** do período.

- Serviço: `backend/app/Services/RH/ServicoMapaRemuneracoesAgt.php` · Controlador: `Api/RH/MapaRemuneracoesAgtController.php`
- Rotas (`routes/api/ronda2_g2.php`): `GET /api/rh/salarios/periodos/{id}/mapa-agt` (ficheiro) e `.../mapa-agt/verificacao` (JSON de avisos)
- Permissão: a do Mapa Fiscal de IRT (`rh_rel_irt_view`); empresa activa/RLS pelo contexto; middlewares `throttle:pesado`, `licenca_exportacao`,
  `mfa_recente` (como os recibos em ZIP). Auditoria: módulo RH, «Exportação do Mapa de Remunerações (Modelo AGT IRT A2.1)».
- Frontend: `frontend/src/modulos/rh/relatorios/BotaoMapaAgt.tsx` (botão + aviso). Sinónimos para a pesquisa/ajuda: «Imprimir Formato AGT», «IRT A2.1».
- Fixture da estrutura (sem dados pessoais): `backend/tests/Fixtures/mapa_remuneracao_agt_estrutura.json`.

## Estrutura do modelo

Uma única folha **«Mapa de Remuneração»**, sem totais, sem validações de dados, sem células fundidas.

| Linha | Conteúdo |
|---|---|
| 1 | A1 = NIF da empresa (negrito, à direita); B1 = «NIF do Contribuinte» |
| 2 | A2 = período `AAAA-M` (mês sem zero à esquerda, como no ficheiro de referência, ex.: `2026-8`); B2 = «Período (AAAA-MM)» |
| 3 | agrupamento (vazia; negrito, centrada, trama `lightTrellis`) |
| 4 | cabeçalhos A..AF (negrito, centrados, trama, altura 30) |
| 5+ | um trabalhador por linha, por ordem alfabética do nome |

Larguras de coluna iguais às do modelo. Colunas (A..AF):

| Col. | Cabeçalho | Fonte |
|---|---|---|
| A, B, C | NIF Trabalhador, Nome, Nº Segurança Social | ficha do colaborador (vazio se em falta: aviso) |
| D, E | Província, Município | ficha do colaborador |
| F | Salário Base | rubricas de VENCIMENTO «Salário/Vencimento Base» |
| G | Descontos por Falta | rubricas de desconto de faltas (`falta`, sem as `de_isento`), as mesmas que o motor tira à base |
| H, I | Subsídio Alimentação / Transporte | rubricas pelo nome (valor pago, mesmo acima de 30 000 Kz) |
| J, K | Abono Família / Reembolso de Despesas | rubricas pelo nome |
| L | Outros (não sujeitos) | rubricas marcadas como não tributáveis (`irt = false`) sem coluna própria |
| M | Cálculo Manual de Excesso? | sempre `N` |
| N | Excesso Subsídios Não Sujeitos | **derivado**: valor deduzido à base do IRT além do INSS = isenção dos subsídios (30 000 Kz por categoria, alimentação/transporte) + rubricas não tributáveis. `N = Y − AC − base_irt` (≥ 0) |
| O..W | Abono de Falhas, Renda de Casa, Compensação por Rescisão, Férias, Horas Extras, Atavio, Representação, Prémios, Natal | rubricas pelo nome (`ServicoMapaRemuneracoesAgt::CLASSES`) |
| X | Outros Subsídios Sujeitos | resíduo: `bruto − (tudo o que tem coluna)`; inclui arredondamentos |
| Y | Salário Ilíquido | **fórmula** `=F-G+H+I+J+K+L+O+P+Q+R+S+T+U+V+W+X` (= bruto da folha − faltas) |
| Z | Registo Manual de Contribuição SS? | sempre `N` |
| AA | Base Tributável Segurança Social | `base_inss` do motor (0 em reformados) |
| AB | Não Sujeito a Segurança Social? | `S` em reformados (aposentados), senão `N` |
| AC | Contribuição Segurança Social | `inss_trabalhador` do motor (3 % da base; 0 em reformados) |
| AD | Base Tributável IRT | **fórmula** `=MAX(0,Y-AC-N)` (= `base_irt` do motor/recibo) |
| AE | Isento IRT? | `N` (como no modelo de referência, mesmo com IRT 0) |
| AF | IRT Apurado | `irt` do motor (tabela progressiva configurada em RH › Tabela de IRT, `MotorSalarial::irt`; nada é recalculado aqui) |

Valores monetários são números (2 casas); NIF, nomes, n.º de SS, localização e flags são texto. Y e AD são fórmulas reais do Excel (o binder global
`ValorSemFormulas` do ERP trata «=» como texto, por isso gravam-se com `DataType::TYPE_FORMULA`).

## Regras e consistência

- **Fonte única**: `ServicoFolhaSalarial::resultados()` (a fotografia do período validado, a mesma do recibo e do Mapa Fiscal de IRT). Não há
  segunda cópia da tabela de IRT nem do INSS. Validado em teste: `AD = base_irt`, `AC = INSS`, `AF = IRT do recibo`, `Y = bruto − faltas` por
  colaborador e as somas de AC/AD/AF = totais da folha (sem avençados).
- Só períodos **VALIDADOS** (422 `PERIODO_NAO_VALIDADO` caso contrário).
- **Avençados** (Grupo B, 6,5 %) não entram: o modelo é de trabalhadores por conta de outrem; o número exclui-se e é devolvido em `avencados_excluidos`.
- **Aposentados**: `AB = S`, AA e AC = 0 (o motor já não calcula INSS); a base do IRT mantém-se.
- **Isenções**: alimentação/transporte com limite 30 000 Kz (`irt = conditional_30k`) e rubricas não tributáveis entram em N; o excesso acima do limite
  fica na base do IRT.
- **Arredondamento**: 2 casas, ao cêntimo, como o motor (`MotorSalarial::arred`).
- **Avisos** (`/mapa-agt/verificacao`): `SEM_NIF`, `SEM_NSS`, `SEM_LOCALIZACAO` por colaborador. O ficheiro gera-se na mesma, com essas células
  vazias; o ecrã mostra o aviso e permite descarregar a lista em CSV.
- **Segurança**: `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`, `Cache-Control: no-store`; nome de ficheiro fixo gerado no
  servidor (sem input do utilizador); texto livre começado por `= + - @ TAB CR` leva um apóstrofo inicial; ficheiro temporário apagado após envio.

## Limitações

- «Compensação Por Rescisão» (Q) só se preenche se uma rubrica tiver «rescis/indemniz» no nome; entra na fórmula de Y para fechar com o bruto da folha.
- A classificação das rubricas é pelo nome (as rubricas são da empresa); o que não bate com nenhuma regra vai para X (e para L se for não tributável).
- Z e M ficam sempre `N` (o sistema calcula sempre o INSS e o excesso); AE `N`.
- O período usa o formato do ficheiro de referência (`2026-8`); se a AGT exigir `2026-08`, mudar `ServicoMapaRemuneracoesAgt::periodo()`.
- Sem alterações ao dicionário de dados (nenhum campo novo na base de dados).
