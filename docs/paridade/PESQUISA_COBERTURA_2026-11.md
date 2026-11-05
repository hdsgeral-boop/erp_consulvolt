# Cobertura da pesquisa «Procurar» (Ctrl+K) — Novembro de 2026

Auditoria automatizada do que a pesquisa do cabeçalho cobre, lacunas encontradas e correcção. Números reproduzíveis com
`node ferramentas/pesquisa/auditar_pesquisa.mjs` (o «antes» audita a revisão anterior:
`git show c4cc212:frontend/src/layout/indicePesquisa.gerado.ts > antes.ts` e `--indice antes.ts`).

## 1. Como a pesquisa funciona (princípio mantido)

| Fonte | Origem | Autoridade das permissões |
|---|---|---|
| Ecrãs | `GET /sistema/menu` (já filtrado por perfil, plano, holding, papel) | servidor |
| Funções | tarefas do catálogo que `/sistema/menu` envia por ecrã (só as permitidas) + `pode()` | servidor |
| Botões, separadores, janelas, configurações, relatórios, ajuda, páginas | índice local `indicePesquisa.gerado.ts` (só descreve; gerado do código) | só se o ecrã está no menu **e** `pode()` quando a permissão é conhecida |

O índice local nunca abre nada por si: uma entrada só aparece se o ecrã vem no menu do utilizador. Os ecrãs `plataforma_*`,
`suporte_tickets_*` e `ia_chat` nunca vêm no menu do ERP do cliente (servidor) e agora também não estão no índice.

## 2. Números antes / depois (entradas do índice por tipo)

| Tipo | Antes | Depois |
|---|---:|---:|
| ecrã (`e`: todos os ecrãs do catálogo com componente, com palavras-chave) | 0 (só vinham do menu) | 126 |
| separador / vista (`s`) | 239 | 270 |
| acção — botão (`b`) | 1960 | 1324 |
| acção — janela/diálogo/formulário (`d`) | 0 | 384 |
| configuração (`c`) | 0 | 33 |
| relatório / mapa / exportação (`r`) | 0 | 110 |
| ajuda do ecrã (`a`) | 0 | 80 |
| página fora de `/m` (`p`: Dados pessoais, As minhas sessões, palavra-passe, Início) | 0 | 4 |
| **Total** | **2199** | **2331** |
| tarefas de permissão pesquisáveis (vêm do menu do servidor, não do índice) | 228 de 247 | 228 de 247 (as 19 restantes são `plataforma_*`, `ia_chat` e `suporte_tickets_*`, fora do ERP do cliente) |

O total dos botões desceu porque a repartição de componentes partilhados deixou de repetir a mesma acção em ecrãs que
só a importam de longe (ver 4.3) e porque se retiraram rótulos de mera «mobília» de interface; as acções reclassificadas
passaram a `c` e `r`.

Contra-prova independente (rótulos estáticos do código que existem no índice, em qualquer ecrã):

| Elemento no código | Distintos | Indexados antes | Indexados depois |
|---|---:|---:|---:|
| separadores (`Tabs`) | 138 | 107 | 122 (os 16 restantes não são separadores: `Descriptions`/colunas com `key`+`label`) |
| janelas (`Modal`/`Drawer`/`ModalFormulario`) | 128 | 32 | 125 (restantes: dinâmicos ou fora dos módulos) |
| vistas em pílulas (`Segmented`) | 38 | 1 | 38 |
| botões com texto | 32 | 30 | 29 («Limpar filtros» e «Guardar…» removidos de propósito por genéricos) |

Pesquisa de 25 termos reais (resultados ecrãs/funções/acções, utilizador com acesso total): 124 antes, 387 depois. Exemplos
(antes → depois): «retenção» 0/1/2 → 4/2/7; «delegar» 0/1/0 → 2/1/8; «licença» 1/1/0 → 8/10/19; «ausências» 0/1/1 → 4/5/13;
«recibo de vencimento» 0/0/1 → 2/1/12; «conta e subscrição» 0/0/0 → resultados; «elegibilidade» 0/0/0 → 1/0/2; «SAF-T»
0/0/1 → 3/0/1.

## 3. Lacunas encontradas

### Corrigidas
1. **Ecrãs registados no frontend mas inexistentes no catálogo** — `inventario_agt` (Exportar Inventário AGT) e
   `saft_contabilidade` (Exportar SAF-T AO da Contabilidade): o servidor nunca os devolvia no menu, logo a guarda
   `/m/<módulo>/<ecrã>` dava «sem acesso» e as 4 entradas do índice eram rotas mortas. Acrescentados ao `catalogo.json`
   (à mão) com `pai` e a consulta própria (`inventario_agt_view`, `saft_contabilidade_view`). **Nota operacional:** perfis
   que não são «acesso total» têm de receber estas duas consultas no editor de perfis para verem os dois ecrãs.
2. **Janelas/diálogos sem endereço não eram pesquisáveis** (96 % dos títulos). Indexadas como tipo `d`, a abrir o ecrã onde
   se executam («Janela › abre em Ecrã › Módulo»). O título de uma janela herda a permissão do botão com o mesmo rótulo.
3. **Itens dos menus «Mais»/Importar** (`<Dropdown menu={{items}}>`: «Importar multas», «Importar dos contratos»,
   «Importar efectividade», «Anular empréstimos importados»…) não eram extraídos. Agora são.
4. **Separadores gerados por `ABAS.map(...)`** (Configuração do CRM: Pipelines e etapas, Modelos de email, Sequências, Motivos e
   origens) não eram extraídos; o gerador segue agora a constante que alimenta o `.map`, com ligação `?sep=`.
5. **Vistas em pílulas (`Segmented`)** — 37 de 38 em falta (Fluxo de Processos, filtros Activas/Inactivas/Todas, Gantt…).
6. **Sem sinónimos**: «retenção», «IRT», «INSS», «NIF», «SAF-T», «AGT», «férias/ausências/licença», «recibo de vencimento»,
   «extracto/reconciliação», «delegar/multa»… não encontravam nada. Há agora 45 grupos de sinónimos PT-AO/PT-PT num único
   ficheiro (`ferramentas/pesquisa/indice.config.json`), aplicados a ecrãs, funções (tarefas) e acções; palavras-chave extra
   por ecrã (mapas IRT/INSS/remunerações, recibos com retenção na fonte, portal do colaborador, estrutura orgânica, método de
   trabalho, assistente IA, conta e subscrição) e por tarefa (`est_multas_gerir`, `est_delegar`).
7. **Relatórios, mapas e exportações** e **configurações** misturados com os botões: classificados (`r`, `c`) e mostrados em
   grupos próprios («Relatórios e exportações», «Configurações»), com limite por grupo (um grupo cheio não esconde os outros).
8. **Ajuda** (`conteudo.json`) não era pesquisável: 80 tópicos indexados (o texto «para que serve» e os passos servem de
   palavras-chave), em «Ajuda e outras páginas».
9. **Páginas fora de `/m/…`** (Dados pessoais, Alterar a palavra-passe, **As minhas sessões**, Início) sem entrada.
10. **Duplicados confusos**: 1431 entradas (297 rótulos) repetidas por componentes partilhados (ex.: Estrutura/Organigrama/Mapa
    de pessoal mostravam as mesmas 65 acções); agora cada acção fica no ecrã onde está mais perto do ficheiro de entrada
    (empates mantêm-se): 963 entradas (249 rótulos). O que resta são ecrãs que de facto têm o mesmo botão («Exportar CSV»,
    «Importar», «Validar NIF na AGT»…).
11. **Rótulos de mera interface** que apareciam em dezenas de ecrãs («Escolher ficheiro», «Limpar filtros», «Expandir tudo»,
    «Remover linha»…) retirados; «Importar Excel» deixou de ser classificado como exportação.
12. **Permissões**: entradas sem permissão que partilham o rótulo com um controlo protegido (janela de «Nova empresa», botão
    dentro de um separador protegido) herdam a permissão do irmão protegido. O teste novo prova que nenhuma entrada com
    permissão aparece a quem não a tem.
13. Ordenação dos ecrãs: o nome com todas as palavras vem antes; palavras de ligação («e», «de», «na»…) já não restringem
    («conta e subscrição»).
14. Acessibilidade: o campo anuncia o número de resultados (`role="status"`); mantêm-se `role="search"`, `combobox` com
    nome acessível, ↑/↓/Enter/Esc e Ctrl+K/⌘K (já testados em `LayoutPrincipal.test.tsx`).

### Não corrigidas (e porquê)
- **111 acções sensíveis sem permissão conhecida** («Anular», «Eliminar», «Reabrir»…): o gerador só vê `pode('x')`
  literais; quando o botão é protegido por uma variável vinda de um hook (`ac.lancar`, `acesso.anular`) não há chave a
  gravar. Um mapeamento por palavras às tarefas do catálogo deu 48 palpites, vários errados (p. ex. «Estornar» →
  `contab_lanc_transferir`) — um palpite errado esconderia a acção a quem pode usá-la, por isso não se fez. A garantia
  mantém-se: o ecrã só aparece se está no menu do servidor e o servidor valida cada pedido; o pior caso é a pesquisa mostrar
  o ecrã a quem não pode fazer aquela acção dentro dele. Recomendação: expor a chave nesses ecrãs com `pode('…')` literal
  (o gerador aproveita-a sozinho).
- **Separadores sem ligação directa** (154 dos 270 separadores/vistas; 116 abrem por `?sep=`): vivem em detalhes/modais ou o ecrã não lê `?sep=`; a pesquisa abre o
  ecrã (nota «abre em»). Os ecrãs novos usam `useSeparadorUrl`; migrar os restantes é trabalho de cada ecrã.
- **Consola `/admin` (plataforma) e `/suporte`** não têm caixa de pesquisa própria e o ERP do cliente nunca as indexa
  (princípio pedido: «ecrãs da plataforma só na consola»). Pesquisa própria da consola fica como evolução.
- `config_logs` (só Super Administrador) tem entrada de ecrã mas nenhuma de botão: o menu do servidor decide quem o vê.
- Modais abertos a partir de componentes partilhados com título dinâmico (`Editar ${nome}`, `Registo #12`) não são
  indexados (não há texto estável).

## 4. O que mudou

### 4.1 Gerador `ferramentas/pesquisa/gerar_indice_pesquisa.mjs`
Determinístico e idempotente (testado: duas execuções, ficheiro idêntico; ~10 s por execução). Novo: extracção de janelas, itens de
`Dropdown`, `Segmented`, constantes seguidas por `.map`; distância ao ficheiro de entrada (repartição de componentes
partilhados); classificação `c`/`r` por expressões do ficheiro de configuração; entradas `e` (todos os ecrãs do catálogo com
componente, excepto os justificados em `ecrasSemPesquisa`), `a` (ajuda) e `p` (páginas); exporta também `SINONIMOS_PESQUISA`
e `PALAVRAS_PESQUISA`. Formato da entrada: `[ecrã|rota, tipo, rótulo, permissões, separador?, palavras?]`.

### 4.2 Auditor `ferramentas/pesquisa/auditar_pesquisa.mjs`
Contra-prova independente (regex sobre o código, catálogo e registo); imprime números e lacunas (`--json`, `--indice`).

### 4.3 Componente `frontend/src/layout/PesquisaEcras.tsx`
Sinónimos (membros curtos como «irt» só valem no início de palavra: «irt» não encontra «virtual»), palavras-chave por ecrã
e por tarefa, palavras de ligação ignoradas, grupos «Botões e separadores / Configurações / Relatórios e exportações / Ajuda
e outras páginas» com limite por grupo (`limiteAccoes` 12, `limiteOutros` 6), «abre em Ecrã» nas acções sem endereço, aviso
de contagem para leitores de ecrã. O endpoint `/sistema/menu` **não precisou de alterações**: já devolve por ecrã as tarefas
permitidas; o servidor continua a ser a autoridade.

### 4.4 Testes
- `indicePesquisa.test.ts` (14): índice em dia; idempotência; extracção (modais, Dropdown, Segmented, `.map`, componente
  partilhado); cobertura de 100 % dos ecrãs do registo e do catálogo (lista de excepções com justificação obrigatória);
  nenhuma rota morta; nenhum `ia_chat`/`plataforma_*`/`suporte_tickets_*`; permissões do índice são chaves reais; `?sep=`
  existe no código; funcionalidades novas indexadas.
- `pesquisaComportamento.test.ts` (36): sinónimos, acentos, prefixos, ordem das palavras, limites, ligações `?sep=`;
  todas as tarefas sensíveis do catálogo só aparecem a quem as tem; nenhuma entrada protegida aparece sem permissão; ecrã
  fora do menu nunca aparece; plataforma/`ia_chat` nunca; desempenho (média < 150 ms por pesquisa com 5000+ entradas;
  medido em poucos milissegundos).
