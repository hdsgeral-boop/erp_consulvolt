# Entrada no sistema: login, registo de empresa, recuperação e troca de palavra-passe

Auditoria de 2026-11-06 (ramo `paridade-bd-front`). O servidor é a autoridade; o formulário repete as regras só para
responder logo. Cada regra tem um validador único no backend e um espelho no frontend, com os mesmos casos de teste.

## Validadores reutilizáveis

| Assunto | Backend (autoridade) | Frontend (espelho) | Testes |
|---|---|---|---|
| NIF angolano | `App\Support\Angola\NifAngolano` + regra `App\Rules\NifAngolano` | `utilitarios/nifAngolano.ts` (`regraNifAngolano`) | `tests/Unit/Angola/ValidadoresAngolanosTest`, `utilitarios/validadoresEntrada.test.ts` |
| Telefone | `App\Support\Angola\TelefoneAngolano` + regra | `utilitarios/telefoneAngolano.ts` | idem |
| Província | `App\Support\Angola\ProvinciasAngola` (21 províncias) | `utilitarios/provinciasAngola.ts` | idem |
| Nome de utilizador (contas novas) | regra `App\Rules\NomeUtilizador` | `utilitarios/nomeUtilizador.ts` | idem |
| Palavra-passe | `App\Support\Seguranca\PoliticaPalavraPasse` (usada por `ServicoUtilizadores::problemaDeForca`) | `utilitarios/palavraPasse.ts` (`problemaPalavraPasse`, `forcaPalavraPasse`) | idem |
| Limpeza de texto | `App\Support\Texto\TextoEntrada::limpar` | (o ecrã limpa espaços; o servidor repete) | idem |

**NIF.** Aceita 10 dígitos (empresa), 9 dígitos (antigo/consumidor final) e o formato do BI da pessoa singular (9 dígitos,
2 letras, 3 dígitos, ex.: `000755848LA037`) — os mesmos formatos que `ServicoParidadeVendas` (empresa) e o SAF-T/AGT
(`^[0-9A-Za-z]{9,15}$`) já tratavam. Normaliza (sem espaços, pontos, hífenes nem barras; maiúsculas). Recusa só os
impossíveis: todos os dígitos iguais e o `999999999` («consumidor final»; a regra tem a opção `permitirConsumidorFinal`).
Um NIF já gravado e repetido sem alteração passa (`inalterado`): um registo antigo não fica bloqueado para editar o resto.
Antes, o registo aceitava `^\d{9,14}$`, o que recusava o NIF de pessoa singular e aceitava 11 a 14 dígitos que não existem.

## Tabela campo × regra

### Registo de empresa (`POST /api/autenticacao/registar-empresa`, `RegistarEmpresaRequest`, ecrã `RegistarEmpresa.tsx`)

| Campo | Frontend | Backend | Divergência encontrada | Correcção |
|---|---|---|---|---|
| Nome comercial | obrigatório | obrigatório, 2–255, sem `<` `>`, com letra/algarismo; espaços limpos | só `required` no ecrã | regras iguais nos dois lados; nome «O'Brien», «João & Filhos, Lda», «Select Consultores» passam |
| NIF | `^\d{9,14}$` | `^\d{9,14}$` + único | recusava BI (pessoa singular), aceitava 11–14 dígitos | validador único; normalizado; duplicado → 422 no campo `nif` sem revelar a empresa |
| Telefone | obrigatório | obrigatório, máx. 30, **sem formato** | sem validação de formato | +244 opcional, 9 dígitos (móvel `9…`, fixo `2…`) ou internacional `+código`; gravado compacto |
| Província | texto livre | texto livre | grafias soltas | lista das 21 províncias (seleccionável, pesquisável sem acentos); o servidor aceita «Huila», «Kwanza Sul» e grava o nome canónico |
| Nome completo | obrigatório | obrigatório, máx. 200 | sem formato | 3–200, só letras, espaços e `. ' -`; sem algarismos nem emojis |
| Nome de utilizador | obrigatório | obrigatório, máx. 100, único | sem formato, reservados nem mínimo; duplicado entre clientes dava **500** (a RLS esconde os outros clientes) | 3–50, `a-z 0-9 . _ -`, sem `@`, reservados (admin, root, suporte…); gravado em minúsculas; duplicado → 422 no campo |
| E-mail | `type: email` | `email:rfc`, **máx. 255** | a coluna tem 150: um e-mail de 151–255 caracteres dava **500** | máx. 150 nos dois lados; minúsculas; único em toda a instalação (função do login, não a RLS) |
| Palavra-passe | mín. 10 | mín. 10 (sem classes) | o registo exigia só 10 caracteres, a troca exigia classes: políticas diferentes | `PoliticaPalavraPasse`: mín. 10 (8 na troca), maiúscula+minúscula+algarismo, máx. 128, sem trivial/sequência, sem o utilizador nem o e-mail; indicador de força e campo de confirmação |
| Plano | (não existe) | `exists` activo | — | `max:50` |

### Login (`POST /api/autenticacao/entrar`, `EntrarRequest`, `Entrar.tsx`, `PlataformaLogin.tsx`, `SuporteLogin.tsx`)

| Campo | Regra | Notas |
|---|---|---|
| Utilizador ou e-mail | obrigatório, sem só espaços, máx. **150** (antes 100: um e-mail de 101–150 caracteres não entrava) | espaços e invisíveis nas pontas são retirados (telemóvel); o nome continua comparado de forma **exacta** (paridade com o legado, `AutenticacaoTest`); o e-mail não distingue maiúsculas |
| Palavra-passe | obrigatório, máx. 255, nunca alterada pelo servidor | o `TrimStrings` do Laravel retira espaços das pontas em todos os pedidos (registo e login iguais) |
| Código MFA | obrigatório, máx. 32 | **bug**: com MFA activo o servidor devolvia só um token limitado e o ecrã tratava-o como sessão completa (ecrã em branco); agora há o 2.º passo (`PassoMfa`, `SessaoContexto.concluirMfa`) nos três logins |

Segurança do login, confirmada por teste: mesma mensagem e mesmo código (`CREDENCIAIS_INVALIDAS`, 401) para utilizador
inexistente, palavra-passe errada e conta inactiva; verificação Argon2id contra um hash fictício quando a conta não existe
(tempo semelhante); limite de 5 tentativas/min por utilizador+IP e 60/min por IP; sem 500 com NUL, emojis, `' OR '1'='1`,
150 caracteres ou listas. A auditoria da falha corta o nome tentado a 100 caracteres (a coluna tem 100: com um e-mail de
101–150 caracteres a própria auditoria dava 500).

### Recuperação, troca e utilizadores criados por administradores

| Campo | Regra |
|---|---|
| Recuperação — identificador | obrigatório, máx. 255, limpo de invisíveis; resposta **igual** exista ou não a conta (`mensagem` idêntica, testado); e-mail sem distinguir maiúsculas; com mais de uma conta com o mesmo e-mail nada se faz (não se adivinha); duplo envio bloqueado no ecrã |
| Troca obrigatória / Dados pessoais | política única (8+, classes, sem triviais, sem utilizador/e-mail, máx. 128), indicador de força, diferente da actual |
| Utilizador criado por administrador | nome de utilizador com o mesmo formato do registo (só em contas **novas**; as antigas editam-se); gravado em minúsculas; espaços e invisíveis retirados de nome/e-mail; a palavra-passe é sempre gerada pelo servidor |
| Empresa (criar/editar), copiar/importar empresa, holding, cliente da plataforma, colaborador (RH) | NIF pelo validador único |

## Decisões e limites

- **Terceiros (clientes/fornecedores) não usam o validador do NIF**: incluem estrangeiros, consumidor final e identificações
  fora do formato; mantêm `max:30` e o NIF duplicado por empresa.
- **Unicidade global sob RLS.** O índice `uq_empresas_nif_ativas` e o de `utilizadores.nome_utilizador` são globais; a
  validação normal não vê outros clientes. Nome de utilizador e e-mail verificam-se pelas funções `SECURITY DEFINER` do login;
  o NIF repetido descobre-se na base e volta como erro do campo `nif` (422), sem dados de terceiros. Isto revela se um
  NIF/utilizador/e-mail **existe** (inevitável num registo público), mas nunca de quem é; o registo tem 6 pedidos/min por IP.
- A palavra-passe do registo não é enviada por e-mail (é a escolhida); a das contas criadas e das recuperadas continua a ser
  gerada pelo servidor.
- O `SanitizarEntrada` só actua em parâmetros de pesquisa/filtro/ordenação, não nos campos de registo/login: «O'Brien»,
  «D'Avila», «João & Filhos, Lda», «select» e «union» são aceites (testado).
