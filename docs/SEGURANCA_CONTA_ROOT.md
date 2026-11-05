# Conta ROOT global (ADR-084)

A **conta root** é a conta mestra do dono do sistema: não pertence a nenhum cliente (tenant) e tem acesso a literalmente
tudo. É o único caso do produto em que uma conta atravessa a fronteira entre clientes — por isso tem salvaguardas próprias.

## O que é

- Identificada pela coluna booleana `utilizadores.conta_root` (migração `2026_11_26_090000_utilizadores_conta_root`,
  aditiva, idempotente, `default false`). **Nunca pelo nome** (`admin` não é root por se chamar `admin`).
- **A migração não marca ninguém.** Só o comando `erp:root:definir` o faz.
- Só pode ser root uma conta **activa, não eliminada e com papel `SUPER_ADMINISTRADOR`**.

## Poderes

| Barreira | Para a root |
|---|---|
| Tenant / RLS | atravessa: escolhe a empresa de qualquer cliente; a RLS continua activa e só mostra essa empresa/cliente |
| `ExigirPlataforma` (`/api/plataforma/*`), `ExigirSuporte` (`/api/suporte/*`) | abre, mesmo com a conta num cliente (nestas rotas opera como o tenant da plataforma) |
| Domínio (`DominioPedido`) | entra em qualquer endereço de cliente ou da consola |
| Permissões (`ServicoPermissoes::tem`) | `true` para tudo, incluindo chaves que ainda não existem, `plataforma_*`, `suporte_*`, `ia_*` |
| Menu (`/sistema/menu`) | todos os módulos e ecrãs do catálogo (salvo a regra de holding, que limita o *módulo* da empresa, não a pessoa) |
| Plano do cliente (módulos e limites) | não a limita |
| Licença suspensa/expirada | não bloqueia escritas (nem exportações) |
| Selector de empresas | `GET /api/plataforma/root/empresas` (só root; paginado; só id, nome, estado, cliente) |

A empresa de trabalho é sempre decidida **no servidor**: `X-Empresa-Id` (ou a empresa activa da sessão,
`PUT /autenticacao/empresa-activa`) é validado contra `plataforma_empresas()` (função `SECURITY DEFINER` que só devolve
metadados); o tenant vem da empresa, nunca de um cabeçalho de tenant. Empresa inexistente: 403 `SEM_ACESSO`; empresa
inactiva: 403 `EMPRESA_INATIVA`.

## Como se define e revoga

Só por linha de comando, no servidor, **com o utilizador da aplicação (nunca `root` do sistema operativo)**:

```bash
php artisan erp:root:definir 1                    # por ID; pede confirmação
php artisan erp:root:definir --nome=admin         # por nome; recusa se houver mais de uma conta (sem distinguir maiúsculas)
php artisan erp:root:definir 1 --confirmar        # sem pergunta (execução não interactiva)
php artisan erp:root:definir 1 --revogar          # retira a marca; recusa a última root
php artisan erp:root:definir 1 --revogar --ultima # permite revogar a última root (decisão consciente)
php artisan erp:root:listar                       # contas root em vigor
```

Cada alteração fica em `logs_auditoria` (módulo `Root`, `dados_novos.root = true`, operador do sistema operativo).
Depois de definir, a conta tem de **terminar a sessão e voltar a entrar** (permissões e lista de empresas vêm do login).

## Salvaguardas

1. **Nenhuma API escreve `conta_root`.** Está fora do `$fillable` (o modo estrito do Eloquent lança
   `MassAssignmentException`) e o hook `saving` do modelo recusa qualquer alteração fora do comando
   (`Utilizador::$autorizarAlteracaoRoot`, ligado só pelo `ServicoContaRoot`). Nenhum super-administrador, de cliente ou da
   plataforma, escala para root.
2. **A root só é alterada pelo seu titular**, e nem ele a desactiva, despromove ou elimina por API (hook do modelo,
   `CONTA_ROOT_PROTEGIDA`). Não aparece nas listagens de utilizadores de outros (`Utilizador::visivelPara`): nem em
   `/sistema/utilizadores`, nem na dos administradores da plataforma, nem em `/plataforma/clientes/{id}/utilizadores`.
3. **Última root:** como nenhuma API a desactiva, só o comando pode revogá-la, e recusa a última sem `--ultima`.
4. **Auditoria total.** O middleware `AuditarContaRoot` regista cada pedido autenticado da root (método, caminho sem query
   string nem corpo, estado HTTP, empresa e cliente alvo) com `dados_novos.root = true`; as alterações de dados feitas
   pelos modelos auditáveis levam a mesma marca (`ServicoAuditoria::registar`). Excluídas só as sondagens do SPA
   (`autenticacao/eu`, `sistema/notificacoes`, `saude`).
5. **Sessão normal.** Expiração por inactividade/absoluta, rotação de token, rate limit e lockout do login **intactos**.
   Uma conta root desactivada ou eliminada **não entra** e perde todos os poderes (`eRoot()` exige activa e não eliminada).
6. **MFA.** Se a conta tiver MFA activo, o login exige-o (mecanismo do ADR-069/082, também no login Google) e a root **não
   consegue desactivar o MFA pela API** (`ROOT_MFA_OBRIGATORIO`). **Recomendação de operação:** activar o MFA da conta
   root antes de a marcar. *Não* se força o MFA no próximo login de uma root sem MFA: sem ecrã de configuração no caminho
   isso trancava o dono fora do sistema; fica como decisão do operador (risco aceite, abaixo).
7. **Frontend só reflecte.** `conta_root`/`area_plataforma`/`area_suporte` vêm do servidor (`GET /autenticacao/eu`; a
   `conta_root` só ao próprio titular); o frontend mostra o selo «ROOT» e o selector de empresas, mas nunca decide acesso.

## Riscos aceites

- Uma conta com poderes totais sobre todos os clientes é, por natureza, um alvo: o comprometimento da palavra-passe dá
  acesso a todos os dados. Mitigação: MFA, auditoria completa e revogação por comando; **nunca partilhar**.
- A root sem MFA (até o operador o activar) depende só da palavra-passe + rate limit.
- A auditoria é por pedido: uma leitura grande fica registada como «acesso», sem listar os registos lidos.
- A RLS continua a valer: a root vê **uma empresa de cada vez** (a escolhida); não há «vista de todos os clientes ao mesmo
  tempo» nos ecrãs do ERP (a plataforma já agrega por funções `SECURITY DEFINER`).
- Empresas inactivas não se abrem pelo ERP (reactiva-se primeiro pela consola da plataforma).
- Numa holding o menu continua limitado ao módulo de consolidação (regra do módulo, não de permissão).

## Activar em produção (coordenador)

1. Deploy do código e `php artisan migrate` (acrescenta a coluna; não marca ninguém).
2. (Recomendado) A conta `admin` activa o MFA em *Gerir Dados Pessoais*.
3. `php artisan erp:root:definir --nome=admin` (confirmar), como o utilizador da aplicação, nunca como `root` do SO.
4. `php artisan erp:root:listar` para confirmar; a conta termina a sessão e volta a entrar.
5. Para retirar: `php artisan erp:root:definir --nome=admin --revogar --ultima`.

## Testes

`backend/tests/Feature/Plataforma/ContaRootTest.php` (percurso de todas as rotas GET sem parâmetros sob RLS, duas empresas
de dois clientes, não-root sem ganhos, auditoria, mass assignment/escalada, protecção da conta, comando);
`frontend/src/sessao/areas.test.ts`, `paginas/guardaEcra.test.ts`, `layout/SeloRoot.test.tsx`,
`paginas/SeletorEmpresasRoot.test.tsx`.
