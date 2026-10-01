<?php

namespace App\Services\Sistema;

use App\Exceptions\ErroNegocio;
use App\Models\DocumentoTesouraria;
use App\Models\Empresa;
use App\Models\PedidoManutencaoEquipamento;
use App\Models\Utilizador;
use App\Services\Tesouraria\ServicoDocumentosTesouraria;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Manutenção de dados com deliberação (js/manutencao.js; tabela pedidos_manutencao_equipamentos — ADR-021: apesar
 * do nome contratual, são estes pedidos).
 *
 * Fluxo (paridade): 1) pedido com parâmetros, impacto calculado e justificação (≥ 20 caracteres) e "ciente";
 * 2) aprovação ou rejeição (motivo ≥ 10) por OUTRO administrador, com a sua palavra-passe; o impacto é recalculado e um
 * bloqueio impede aprovar; 3) execução por quem pediu, quem aprovou ou um Super Administrador, até 24 h depois da
 * aprovação, com a empresa do pedido activa, cópia de segurança confirmada e a frase "CONFIRMO #<n.º>"; 4) histórico no
 * pedido e auditoria. Pedidos pendentes expiram ao fim de 7 dias; cancelamento por quem pediu (ou Super Admin).
 *
 * Decisão do projecto (ADR-015/016): as rotinas destrutivas não são portadas como destrutivas. Das 13 acções do
 * legado, 5 têm equivalente seguro e passam por este fluxo; as restantes estão em NAO_PORTADAS com o substituto.
 *
 * Correcções face ao legado:
 *  - a aprovação era feita no ecrã de quem pediu, escrevendo ali a palavra-passe do outro administrador; agora o
 *    aprovador decide na SUA sessão e confirma com a sua palavra-passe (segregação real; a palavra-passe não circula);
 *  - estados lidos e escritos com bloqueio da linha (o legado permitia aprovar/executar duas vezes em paralelo);
 *  - a expiração é calculada na leitura (nenhuma leitura altera dados — ADR-015) e gravada quando o pedido é tocado;
 *  - "limpar pendentes" ANULA os documentos (com motivo) em vez de os apagar; "descontabilizar" é estorno (ADR-016);
 *    "eliminar empresa" é eliminação lógica e só sem movimentos;
 *  - um pedido sem nada a fazer (impacto zero) não pode ser submetido nem aprovado.
 */
final class ServicoManutencaoDados
{
    public const VALIDADE_APROVACAO_HORAS = 24;

    public const VALIDADE_PEDIDO_DIAS = 7;

    public const ESTADOS = ['PENDENTE', 'APROVADO', 'EXECUTADO', 'REJEITADO', 'CANCELADO', 'EXPIRADO'];

    /** Acções portadas (com equivalente seguro). */
    public const ACOES = [
        'ANULAR_PENDENTES_TESOURARIA' => [
            'legado' => 'TESOURARIA_LIMPAR_PENDENTES', 'grupo' => 'Tesouraria', 'ambito' => 'empresa', 'gravidade' => 'alta',
            'titulo' => 'Anular todos os documentos de tesouraria pendentes',
            'descricao' => 'Anula (com motivo, sem apagar) os pagamentos e recebimentos ainda não integrados da empresa activa. Os documentos da prestação de contas do POS ficam de fora.',
            'parametros' => [],
        ],
        'ANULAR_PENDENTES_TESOURARIA_DATA' => [
            'legado' => 'TESOURARIA_LIMPAR_DATA', 'grupo' => 'Tesouraria', 'ambito' => 'empresa', 'gravidade' => 'media',
            'titulo' => 'Anular os documentos de tesouraria pendentes de uma data',
            'descricao' => 'Anula os documentos de tesouraria pendentes com a data indicada (útil para refazer uma importação).',
            'parametros' => [['id' => 'data', 'rotulo' => 'Data dos documentos', 'tipo' => 'date', 'obrigatorio' => true]],
        ],
        'DESINTEGRAR_TESOURARIA' => [
            'legado' => 'TESOURARIA_ANULAR_TODOS', 'grupo' => 'Tesouraria', 'ambito' => 'empresa', 'gravidade' => 'media',
            'titulo' => 'Descontabilizar (estornar) todos os documentos de tesouraria integrados',
            'descricao' => 'Estorna a integração contabilística dos pagamentos e/ou recebimentos integrados, que voltam a pendentes. Os reconciliados com o banco ficam de fora.',
            'parametros' => [['id' => 'tipo', 'rotulo' => 'Documentos', 'tipo' => 'select', 'obrigatorio' => true,
                'opcoes' => [['PAGAMENTO', 'Pagamentos'], ['RECEBIMENTO', 'Recebimentos'], ['TODOS', 'Pagamentos e recebimentos']]]],
        ],
        'LIMPAR_RECONCILIACOES_ORFAS' => [
            'legado' => 'LIMPAR_RECONCILIACOES', 'grupo' => 'Tesouraria', 'ambito' => 'empresa', 'gravidade' => 'media',
            'titulo' => 'Retirar marcas de reconciliação sem extracto bancário (contas 43 e 45)',
            'descricao' => 'Volta a pôr como não reconciliadas as linhas das contas 43 e 45 cujo código de reconciliação não pertence a uma reconciliação bancária válida (CONCILIADO_BANCO). Os valores dos lançamentos não mudam.',
            'parametros' => [],
        ],
        'ELIMINAR_EMPRESA' => [
            'legado' => 'ELIMINAR_EMPRESA', 'grupo' => 'Sistema', 'ambito' => 'sistema', 'gravidade' => 'alta',
            'titulo' => 'Eliminar uma empresa sem movimentos',
            'descricao' => 'Elimina (eliminação lógica) uma empresa que ainda não tem colaboradores, processamentos, documentos nem lançamentos. O NIF fica livre.',
            'parametros' => [['id' => 'empresa_id', 'rotulo' => 'Empresa a eliminar', 'tipo' => 'empresa', 'obrigatorio' => true]],
        ],
    ];

    /** As outras 8 acções do legado: tratamento no sistema novo. */
    public const NAO_PORTADAS = [
        'RESET_COMERCIAL' => ['titulo' => 'Reset global de transacções comerciais', 'tratamento' => 'NAO_PORTADA',
            'substituto' => 'Apagava facturas (incluindo fiscais), compras, guias e stock. Não há equivalente seguro: os documentos anulam-se um a um nos módulos (NC, anulação, estorno) e as incoerências aparecem em Sistema › Validações de Dados.'],
        'LIMPAR_PROCESSAMENTOS_RH' => ['titulo' => 'Limpar todos os processamentos salariais', 'tratamento' => 'SUBSTITUIDA',
            'substituto' => 'Reabertura/anulação de cada período em RH › Salários (descontabilizar = estorno, ADR-016) e a validação folhas_salariais_vs_diario.'],
        'LIMPAR_LANCAMENTOS' => ['titulo' => 'Limpar lançamentos contabilísticos', 'tratamento' => 'SUBSTITUIDA',
            'substituto' => 'Estorno de lançamentos (ADR-016) e as validações lancamentos_desequilibrados, lancamentos_conta_inexistente e lancamentos_valor_zero.'],
        'ESVAZIAR_RECICLAGEM' => ['titulo' => 'Esvaziar a reciclagem de lançamentos', 'tratamento' => 'NAO_PORTADA',
            'substituto' => 'A reciclagem passou a lancamentos_estornados, o arquivo histórico dos estornos (ADR-016): não se apaga.'],
        'APAGAR_EMPRESA' => ['titulo' => 'Apagar todos os dados da empresa activa', 'tratamento' => 'SUBSTITUIDA',
            'substituto' => 'Desactivar a empresa (PUT /api/sistema/empresas/{id}/estado) depois de exportar a cópia de segurança; os dados mantêm-se. Sem movimentos: ELIMINAR_EMPRESA.'],
        'RESTAURAR_EMPRESA' => ['titulo' => 'Restaurar cópia de segurança na empresa activa', 'tratamento' => 'SUBSTITUIDA',
            'substituto' => 'Importar a cópia de segurança para uma empresa NOVA ou vazia (Sistema › Geral › Cópias de segurança); nunca por cima de dados existentes.'],
        'RESTAURAR_GLOBAL' => ['titulo' => 'Restaurar cópia de segurança global', 'tratamento' => 'NAO_PORTADA',
            'substituto' => 'O restauro da base de dados completa é uma operação de infra-estrutura (pg_dump/pg_restore dos backups do servidor), fora da aplicação.'],
        'APAGAR_BD' => ['titulo' => 'Apagar a base de dados completa', 'tratamento' => 'NAO_PORTADA',
            'substituto' => 'Não existe na aplicação. Numa instalação nova a base é criada pelas migrations.'],
    ];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoPermissoes $permissoes,
        private readonly ServicoEmpresas $empresas,
        private readonly ServicoAuditoria $auditoria,
        private readonly ServicoDocumentosTesouraria $tesouraria,
    ) {}

    /** @return array{acoes: list<array<string, mixed>>, nao_portadas: list<array<string, mixed>>} */
    public function catalogo(): array
    {
        return [
            'acoes' => array_map(fn ($c, $d) => ['codigo' => $c] + $d, array_keys(self::ACOES), self::ACOES),
            'nao_portadas' => array_map(fn ($c, $d) => ['codigo_legado' => $c] + $d, array_keys(self::NAO_PORTADAS), self::NAO_PORTADAS),
        ];
    }

    /** Pedidos da empresa activa e de âmbito sistema. $estado: ACTIVOS (pendentes/aprovados), TODOS ou um estado. */
    public function listar(string $estado = 'ACTIVOS'): array
    {
        $empresa = $this->contexto->obrigatorio();

        return PedidoManutencaoEquipamento::query()->where(fn ($q) => $q->where('empresa_id', $empresa)->orWhere('ambito', 'sistema'))
            ->orderByDesc('id')->get()->map(fn ($p) => $this->apresentar($p))
            ->filter(fn ($p) => match ($estado) {
                'TODOS' => true,
                'ACTIVOS' => in_array($p['estado'], ['PENDENTE', 'APROVADO'], true),
                default => $p['estado'] === $estado,
            })->values()->all();
    }

    /** @return array<string, mixed> */
    public function apresentar(PedidoManutencaoEquipamento $p, bool $comImpactoActual = false): array
    {
        $estado = $this->estadoEfectivo($p);
        $dados = ['id' => $p->id, 'acao' => $p->acao, 'rotulo_acao' => $p->rotulo_acao, 'ambito' => $p->ambito, 'empresa_id' => $p->empresa_id,
            'nome_empresa_ambito' => $p->nome_empresa_ambito, 'estado' => $estado, 'estado_gravado' => $p->estado,
            'parametros' => $this->json($p->parametros), 'resumo_parametros' => $p->resumo_parametros, 'justificacao' => $p->justificacao,
            'impacto_no_pedido' => $this->json($p->impacto_no_pedido), 'pedido_por' => $this->json($p->pedido_por), 'pedido_em' => $p->criado_em?->toAtomString(),
            'aprovado_por' => $p->aprovado_por, 'aprovado_em' => $p->aprovado_em?->toAtomString(), 'nota_aprovacao' => $p->nota_aprovacao,
            'expira_em' => $estado === 'PENDENTE' ? $p->criado_em?->copy()->addDays(self::VALIDADE_PEDIDO_DIAS)->toAtomString() : $p->expira_em?->toAtomString(),
            'impacto_na_aprovacao' => $this->json($p->impacto_na_aprovacao), 'rejeitado_por' => $p->rejeitado_por, 'rejeitado_em' => $p->rejeitado_em?->toAtomString(),
            'motivo_rejeicao' => $p->motivo_rejeicao, 'executado_por' => $this->json($p->executado_por), 'executado_em' => $p->executado_em?->toAtomString(),
            'impacto_na_execucao' => $this->json($p->impacto_na_execucao), 'resultado' => $p->resultado, 'cancelado_por' => $this->json($p->cancelado_por),
            'cancelado_em' => $p->cancelado_em?->toAtomString(), 'historico' => $p->historico_alteracoes ?? []];
        if ($comImpactoActual && in_array($estado, ['PENDENTE', 'APROVADO'], true)) {
            $dados['impacto_actual'] = $this->impactoDoPedido($p);
        }

        return $dados;
    }

    /**
     * Impacto de uma acção (pré-visualização do pedido) na empresa activa.
     *
     * @param  array<string, mixed>  $parametros
     * @return array{linhas: array<string, int>, bloqueio: ?string}
     */
    public function impacto(string $acao, array $parametros): array
    {
        $this->definicao($acao);
        $this->validarParametros($acao, $parametros);
        /** @var Utilizador $actor */
        $actor = auth()->user();
        $this->exigirAcessoEmpresas($acao, $parametros, $actor);

        return $this->calcularImpacto($acao, $parametros, $this->contexto->obrigatorio());
    }

    /** @param  array{acao: string, parametros?: array, justificacao: string, ciente: bool}  $d */
    public function pedir(array $d, Utilizador $actor): PedidoManutencaoEquipamento
    {
        $def = $this->definicao($d['acao']);
        $parametros = $d['parametros'] ?? [];
        $this->validarParametros($d['acao'], $parametros);
        $this->exigirAcessoEmpresas($d['acao'], $parametros, $actor);
        $justificacao = trim((string) $d['justificacao']);
        if (mb_strlen($justificacao) < 20) {
            throw new ErroNegocio('Escreva uma justificação com pelo menos 20 caracteres.', 'JUSTIFICACAO_CURTA', 422);
        }
        if (! ($d['ciente'] ?? false)) {
            throw new ErroNegocio('Confirme que compreende o efeito da acção.', 'CONFIRMACAO_EM_FALTA', 422);
        }
        $empresa = $this->contexto->obrigatorio();
        $imp = $this->calcularImpacto($d['acao'], $parametros, $empresa);
        if ($imp['bloqueio']) {
            throw new ErroNegocio("Não é possível pedir esta acção agora: {$imp['bloqueio']}", 'ACAO_BLOQUEADA', 422, ['impacto' => $imp]);
        }
        $resumo = $this->resumoParametros($d['acao'], $parametros);

        return DB::transaction(function () use ($d, $def, $parametros, $justificacao, $empresa, $imp, $resumo, $actor) {
            $p = PedidoManutencaoEquipamento::create([
                'empresa_id' => $def['ambito'] === 'empresa' ? $empresa : null, 'ambito' => $def['ambito'],
                'nome_empresa_ambito' => $def['ambito'] === 'empresa' ? (string) Empresa::query()->whereKey($empresa)->value('nome') : '',
                'acao' => $d['acao'], 'rotulo_acao' => $def['titulo'], 'parametros' => $this->compacto($parametros), 'resumo_parametros' => $resumo,
                'justificacao' => $justificacao, 'impacto_no_pedido' => $this->compacto($imp['linhas']), 'estado' => 'PENDENTE',
                'pedido_por' => $this->compacto($this->quem($actor)),
                'historico_alteracoes' => [$this->entrada($actor, "Pedido criado: {$def['titulo']}".($resumo ? " ({$resumo})" : '').". Justificação: {$justificacao}")],
            ]);
            $this->auditoria->registar('Manutenção de dados', 'Pedido criado', "#{$p->id} {$def['titulo']} — {$justificacao}", 'pedidos_manutencao_equipamentos', $p->id);

            return $p;
        });
    }

    public function aprovar(int $id, string $palavraPasse, ?string $nota, Utilizador $actor): PedidoManutencaoEquipamento
    {
        return $this->decidir($id, true, $palavraPasse, $nota, $actor);
    }

    public function rejeitar(int $id, string $palavraPasse, string $motivo, Utilizador $actor): PedidoManutencaoEquipamento
    {
        return $this->decidir($id, false, $palavraPasse, $motivo, $actor);
    }

    public function executar(int $id, string $confirmacao, bool $copiaConfirmada, Utilizador $actor): PedidoManutencaoEquipamento
    {
        $this->expirarSeNecessario($id);

        return DB::transaction(function () use ($id, $confirmacao, $copiaConfirmada, $actor) {
            $p = $this->bloquearPedido($id);
            if ($p->estado !== 'APROVADO' || $this->estadoEfectivo($p) !== 'APROVADO') {
                throw new ErroNegocio($p->estado === 'EXPIRADO' ? 'A aprovação expirou. Faça um novo pedido.' : 'O pedido não está aprovado.', 'PEDIDO_NAO_APROVADO', 422);
            }
            $pedinte = $this->json($p->pedido_por);
            $souPedinte = (int) ($pedinte['id'] ?? 0) === $actor->id;
            $souAprovador = (int) ($p->aprovado_por['id'] ?? 0) === $actor->id;
            if (! ($souPedinte || $souAprovador || $actor->eSuperAdministrador())) {
                throw new ErroNegocio('Só quem pediu ou quem aprovou pode executar este pedido.', 'SEM_PERMISSAO_ADMINISTRATIVA', 403);
            }
            if ($p->ambito === 'empresa' && $p->empresa_id !== $this->contexto->obrigatorio()) {
                throw new ErroNegocio("Este pedido é da empresa «{$p->nome_empresa_ambito}». Seleccione essa empresa como activa antes de executar.", 'EMPRESA_DO_PEDIDO', 422);
            }
            if (! $copiaConfirmada) {
                throw new ErroNegocio('Confirme que tem uma cópia de segurança recente.', 'CONFIRMACAO_EM_FALTA', 422);
            }
            if (mb_strtoupper(trim($confirmacao)) !== "CONFIRMO #{$p->id}") {
                throw new ErroNegocio("Escreva exactamente: CONFIRMO #{$p->id}", 'CONFIRMACAO_EM_FALTA', 422);
            }
            $imp = $this->impactoDoPedido($p);
            if ($imp['bloqueio']) {
                throw new ErroNegocio("Execução bloqueada: {$imp['bloqueio']}", 'ACAO_BLOQUEADA', 422, ['impacto' => $imp]);
            }
            $this->exigirAcessoEmpresas($p->acao, $this->json($p->parametros), $actor);
            $execucao = $this->executarAcao($p);
            $p->update(['estado' => 'EXECUTADO', 'executado_por' => $this->compacto($this->quem($actor)), 'executado_em' => now(),
                'impacto_na_execucao' => $this->compacto($imp['linhas']), 'resultado' => mb_substr($execucao['resultado'], 0, 255),
                'historico_alteracoes' => array_merge($p->historico_alteracoes ?? [], [$this->entrada($actor, "Executado: {$execucao['resultado']}") + ['detalhes' => $execucao['detalhes']]])]);
            $this->auditoria->registar('Manutenção de dados', 'Pedido executado', "#{$p->id} {$p->rotulo_acao} — {$execucao['resultado']}", 'pedidos_manutencao_equipamentos', $p->id,
                null, $execucao['detalhes']);

            return $p;
        });
    }

    public function cancelar(int $id, string $motivo, Utilizador $actor): PedidoManutencaoEquipamento
    {
        $this->expirarSeNecessario($id);

        return DB::transaction(function () use ($id, $motivo, $actor) {
            $p = $this->bloquearPedido($id);
            if (! in_array($this->estadoEfectivo($p), ['PENDENTE', 'APROVADO'], true)) {
                throw new ErroNegocio("O pedido está {$p->estado}: não pode ser cancelado.", 'PEDIDO_NAO_CANCELAVEL', 422);
            }
            if ((int) ($this->json($p->pedido_por)['id'] ?? 0) !== $actor->id && ! $actor->eSuperAdministrador()) {
                throw new ErroNegocio('Só quem fez o pedido pode cancelá-lo.', 'SEM_PERMISSAO_ADMINISTRATIVA', 403);
            }
            if (mb_strlen(trim($motivo)) < 5) {
                throw new ErroNegocio('Indique o motivo do cancelamento (mínimo 5 caracteres).', 'MOTIVO_CURTO', 422);
            }
            $p->update(['estado' => 'CANCELADO', 'cancelado_por' => $this->compacto($this->quem($actor)), 'cancelado_em' => now(),
                'historico_alteracoes' => array_merge($p->historico_alteracoes ?? [], [$this->entrada($actor, 'Cancelado: '.trim($motivo))])]);
            $this->auditoria->registar('Manutenção de dados', 'Pedido cancelado', "#{$p->id}: ".trim($motivo), 'pedidos_manutencao_equipamentos', $p->id);

            return $p;
        });
    }

    public function obter(int $id): PedidoManutencaoEquipamento
    {
        $p = PedidoManutencaoEquipamento::query()->find($id);
        if (! $p || ($p->ambito === 'empresa' && $p->empresa_id !== $this->contexto->obrigatorio())) {
            throw new ErroNegocio('Pedido não encontrado.', 'NAO_ENCONTRADO', 404);
        }

        return $p;
    }

    // ───────────── regras internas ─────────────

    private function decidir(int $id, bool $aprovar, string $palavraPasse, ?string $nota, Utilizador $actor): PedidoManutencaoEquipamento
    {
        // verificações e palavra-passe FORA da transacção: a tentativa falhada fica na auditoria mesmo com a recusa
        $p = $this->expirarSeNecessario($id);
        $this->exigirDecisor($p, $actor);
        if ($palavraPasse === '' || $actor->palavra_passe === null || ! Hash::check($palavraPasse, $actor->palavra_passe)) {
            $this->auditoria->registar('Manutenção de dados', 'Tentativa de aprovação falhada', "#{$p->id}: palavra-passe incorrecta de {$actor->nome_utilizador}.",
                'pedidos_manutencao_equipamentos', $p->id);
            throw new ErroNegocio('Palavra-passe incorrecta.', 'PALAVRA_PASSE_INCORRECTA', 422);
        }

        return DB::transaction(function () use ($id, $aprovar, $nota, $actor) {
            $p = $this->bloquearPedido($id);
            $this->exigirDecisor($p, $actor);
            $nota = trim((string) $nota);
            $quem = $this->quem($actor);
            if (! $aprovar) {
                if (mb_strlen($nota) < 10) {
                    throw new ErroNegocio('Indique o motivo da rejeição (mínimo 10 caracteres).', 'MOTIVO_CURTO', 422);
                }
                $p->update(['estado' => 'REJEITADO', 'rejeitado_por' => $quem, 'rejeitado_em' => now(), 'motivo_rejeicao' => $nota,
                    'historico_alteracoes' => array_merge($p->historico_alteracoes ?? [], [$this->entrada($actor, "Rejeitado: {$nota}")])]);
                $this->auditoria->registar('Manutenção de dados', 'Pedido rejeitado', "#{$p->id} {$p->rotulo_acao} — rejeitado por {$actor->nome_utilizador}: {$nota}",
                    'pedidos_manutencao_equipamentos', $p->id);

                return $p;
            }
            $imp = $this->impactoDoPedido($p);
            if ($imp['bloqueio']) {
                throw new ErroNegocio("Não é possível aprovar: {$imp['bloqueio']}", 'ACAO_BLOQUEADA', 422, ['impacto' => $imp]);
            }
            $p->update(['estado' => 'APROVADO', 'aprovado_por' => $quem, 'aprovado_em' => now(), 'nota_aprovacao' => $nota !== '' ? mb_substr($nota, 0, 255) : null,
                'expira_em' => now()->addHours(self::VALIDADE_APROVACAO_HORAS), 'impacto_na_aprovacao' => $this->compacto($imp['linhas']),
                'historico_alteracoes' => array_merge($p->historico_alteracoes ?? [], [$this->entrada($actor, 'Aprovado'.($nota !== '' ? ": {$nota}" : '').'. Válido por '
                    .self::VALIDADE_APROVACAO_HORAS.' h.')])]);
            $this->auditoria->registar('Manutenção de dados', 'Pedido aprovado', "#{$p->id} {$p->rotulo_acao} — aprovado por {$actor->nome_utilizador}",
                'pedidos_manutencao_equipamentos', $p->id);

            return $p;
        });
    }

    private function exigirDecisor(PedidoManutencaoEquipamento $p, Utilizador $actor): void
    {
        if ($this->estadoEfectivo($p) !== 'PENDENTE') {
            throw new ErroNegocio('O pedido já não está a aguardar aprovação.', 'PEDIDO_NAO_PENDENTE', 422);
        }
        if (! $this->permissoes->administrador($actor)) {
            throw new ErroNegocio('A decisão tem de ser tomada por um utilizador administrador.', 'SEM_PERMISSAO_ADMINISTRATIVA', 403);
        }
        if ((int) ($this->json($p->pedido_por)['id'] ?? 0) === $actor->id) {
            throw new ErroNegocio('A decisão tem de ser tomada por um utilizador diferente de quem fez o pedido.', 'SEGREGACAO_FUNCOES', 403);
        }
        if ($p->ambito === 'empresa' && ! $this->empresas->podeAceder($actor, (int) $p->empresa_id)) {
            throw new ErroNegocio('Não tem acesso à empresa deste pedido.', 'EMPRESA_SEM_ACESSO', 403);
        }
        $this->exigirAcessoEmpresas($p->acao, $this->json($p->parametros), $actor);
    }

    /**
     * Parâmetros do tipo «empresa» (ex.: ELIMINAR_EMPRESA) só podem apontar para empresas a que o actor tem acesso.
     *
     * @param  array<string, mixed>  $parametros
     */
    private function exigirAcessoEmpresas(string $acao, array $parametros, Utilizador $actor): void
    {
        foreach (self::ACOES[$acao]['parametros'] ?? [] as $def) {
            $v = $parametros[$def['id']] ?? null;
            if ($def['tipo'] === 'empresa' && is_numeric($v) && ! $this->empresas->podeAceder($actor, (int) $v)) {
                throw new ErroNegocio('Não tem acesso à empresa indicada.', 'EMPRESA_SEM_ACESSO', 403);
            }
        }
    }

    /** Grava a expiração (em transacção própria, para sobreviver à recusa que se segue) e devolve o pedido. */
    private function expirarSeNecessario(int $id): PedidoManutencaoEquipamento
    {
        return DB::transaction(function () use ($id) {
            $p = $this->bloquearPedido($id);
            $this->persistirExpiracao($p);

            return $p;
        });
    }

    private function estadoEfectivo(PedidoManutencaoEquipamento $p): string
    {
        if ($p->estado === 'APROVADO' && $p->expira_em && $p->expira_em->isPast()) {
            return 'EXPIRADO';
        }
        if ($p->estado === 'PENDENTE' && $p->criado_em && $p->criado_em->copy()->addDays(self::VALIDADE_PEDIDO_DIAS)->isPast()) {
            return 'EXPIRADO';
        }

        return (string) $p->estado;
    }

    private function persistirExpiracao(PedidoManutencaoEquipamento $p): void
    {
        if ($this->estadoEfectivo($p) === 'EXPIRADO' && $p->estado !== 'EXPIRADO') {
            $texto = $p->estado === 'APROVADO' ? 'Aprovação expirada ('.self::VALIDADE_APROVACAO_HORAS.' h sem execução).' : 'Pedido expirado ('.self::VALIDADE_PEDIDO_DIAS.' dias sem aprovação).';
            $p->update(['estado' => 'EXPIRADO', 'historico_alteracoes' => array_merge($p->historico_alteracoes ?? [], [['em' => now()->toAtomString(), 'por' => 'sistema', 'texto' => $texto]])]);
        }
    }

    private function bloquearPedido(int $id): PedidoManutencaoEquipamento
    {
        $this->obter($id);

        return PedidoManutencaoEquipamento::query()->lockForUpdate()->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function definicao(string $acao): array
    {
        if (isset(self::ACOES[$acao])) {
            return self::ACOES[$acao];
        }
        if (isset(self::NAO_PORTADAS[$acao])) {
            throw new ErroNegocio('Esta acção do sistema antigo não foi portada: '.self::NAO_PORTADAS[$acao]['substituto'], 'ACAO_NAO_PORTADA', 422);
        }

        throw new ErroNegocio("Acção de manutenção desconhecida: {$acao}.", 'ACAO_DESCONHECIDA', 422);
    }

    private function validarParametros(string $acao, array $parametros): void
    {
        foreach (self::ACOES[$acao]['parametros'] as $def) {
            $v = $parametros[$def['id']] ?? null;
            if (($def['obrigatorio'] ?? false) && ($v === null || $v === '')) {
                throw new ErroNegocio("Indique: {$def['rotulo']}.", 'PARAMETRO_EM_FALTA', 422);
            }
            if ($def['tipo'] === 'date' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v)) {
                throw new ErroNegocio("{$def['rotulo']}: data inválida.", 'PARAMETRO_INVALIDO', 422);
            }
            if ($def['tipo'] === 'select' && ! in_array($v, array_column($def['opcoes'], 0), true)) {
                throw new ErroNegocio("{$def['rotulo']}: valor inválido.", 'PARAMETRO_INVALIDO', 422);
            }
            if ($def['tipo'] === 'empresa' && (! is_numeric($v) || (int) $v <= 0)) {
                throw new ErroNegocio("{$def['rotulo']}: empresa inválida.", 'PARAMETRO_INVALIDO', 422);
            }
        }
    }

    private function resumoParametros(string $acao, array $p): string
    {
        return match ($acao) {
            'ANULAR_PENDENTES_TESOURARIA_DATA' => 'Data: '.($p['data'] ?? '—'),
            'DESINTEGRAR_TESOURARIA' => 'Documentos: '.(['PAGAMENTO' => 'pagamentos', 'RECEBIMENTO' => 'recebimentos', 'TODOS' => 'pagamentos e recebimentos'][$p['tipo'] ?? ''] ?? '—'),
            'ELIMINAR_EMPRESA' => 'Empresa: #'.($p['empresa_id'] ?? '—').' '.(string) Empresa::query()->whereKey((int) ($p['empresa_id'] ?? 0))->value('nome'),
            default => '',
        };
    }

    /** @return array{linhas: array<string, int>, bloqueio: ?string} */
    private function impactoDoPedido(PedidoManutencaoEquipamento $p): array
    {
        return $this->calcularImpacto($p->acao, $this->json($p->parametros), $p->empresa_id ?? $this->contexto->obrigatorio());
    }

    /** @return array{linhas: array<string, int>, bloqueio: ?string} */
    private function calcularImpacto(string $acao, array $parametros, int $empresa): array
    {
        return $this->contexto->executarComo($empresa, function () use ($acao, $parametros, $empresa) {
            switch ($acao) {
                case 'ANULAR_PENDENTES_TESOURARIA':
                case 'ANULAR_PENDENTES_TESOURARIA_DATA':
                    $q = $this->pendentes($acao, $parametros);
                    $total = $q->count();
                    $pos = $q->clone()->whereExists(fn ($e) => $e->selectRaw('1')->from('liquidacoes_pos as l')->where('l.estado', 'REGISTADO')
                        ->where(fn ($w) => $w->whereColumn('l.documento_tesouraria_id', 'documentos_tesouraria.id')->orWhereColumn('l.documento_comissao_id', 'documentos_tesouraria.id')))->count();

                    return ['linhas' => ['Documentos pendentes a anular' => $total - $pos, 'Documentos da prestação de contas do POS (não anulados)' => $pos],
                        'bloqueio' => $total - $pos === 0 ? 'Não há documentos pendentes a anular.' : null];
                case 'DESINTEGRAR_TESOURARIA':
                    $q = DocumentoTesouraria::query()->where('estado', 'INTEGRADO')->when(($parametros['tipo'] ?? 'TODOS') !== 'TODOS', fn ($w) => $w->where('tipo', $parametros['tipo']));
                    $total = $q->count();

                    return ['linhas' => ['Documentos integrados a estornar' => $total], 'bloqueio' => $total === 0 ? 'Não há documentos integrados.' : null];
                case 'LIMPAR_RECONCILIACOES_ORFAS':
                    $n = $this->reconciliacoesOrfas($empresa)->count();

                    return ['linhas' => ['Linhas 43/45 com reconciliação sem extracto' => $n], 'bloqueio' => $n === 0 ? 'Não há marcas de reconciliação órfãs.' : null];
                case 'ELIMINAR_EMPRESA':
                    $alvo = (int) ($parametros['empresa_id'] ?? 0);
                    $e = Empresa::query()->find($alvo);
                    if (! $e) {
                        return ['linhas' => [], 'bloqueio' => 'A empresa indicada não existe.'];
                    }
                    $linhas = [];
                    foreach ([['colaboradores', 'Colaboradores'], ['periodos_processamento_salarial', 'Períodos salariais'], ['vendas', 'Documentos de venda'],
                        ['faturas_compra', 'Facturas de compra'], ['documentos_tesouraria', 'Documentos de tesouraria'], ['lancamentos_contabeis', 'Linhas de lançamento'],
                        ['movimentos_inventario', 'Movimentos de stock']] as [$tabela, $rotulo]) {
                        $linhas[$rotulo] = DB::table($tabela)->where('empresa_id', $alvo)->count();
                    }
                    $bloqueio = match (true) {
                        array_sum($linhas) > 0 => 'A empresa tem dados reais: desactive-a em vez de a eliminar.',
                        $e->estado === Empresa::ESTADO_ATIVO && Empresa::query()->where('estado', Empresa::ESTADO_ATIVO)->whereKeyNot($alvo)->doesntExist() => 'É a única empresa activa.',
                        (bool) $e->e_consolidacao && DB::table('grupos_consolidacao')->where('empresa_holding_id', $alvo)->exists() => 'É a holding de um grupo de consolidação.',
                        default => null,
                    };

                    return ['linhas' => $linhas, 'bloqueio' => $bloqueio];
            }

            throw new ErroNegocio("Acção de manutenção desconhecida: {$acao}.", 'ACAO_DESCONHECIDA', 422);
        });
    }

    /** @return array{resultado: string, detalhes: array<string, mixed>} */
    private function executarAcao(PedidoManutencaoEquipamento $p): array
    {
        $parametros = $this->json($p->parametros);
        $motivo = mb_substr("Manutenção de dados — pedido #{$p->id}: {$p->justificacao}", 0, 1000);

        return $this->contexto->executarComo($p->empresa_id ?? $this->contexto->obrigatorio(), function () use ($p, $parametros, $motivo) {
            switch ($p->acao) {
                case 'ANULAR_PENDENTES_TESOURARIA':
                case 'ANULAR_PENDENTES_TESOURARIA_DATA':
                case 'DESINTEGRAR_TESOURARIA':
                    $desintegrar = $p->acao === 'DESINTEGRAR_TESOURARIA';
                    $docs = $desintegrar
                        ? DocumentoTesouraria::query()->where('estado', 'INTEGRADO')->when(($parametros['tipo'] ?? 'TODOS') !== 'TODOS', fn ($w) => $w->where('tipo', $parametros['tipo']))->orderBy('id')->get()
                        : $this->pendentes($p->acao, $parametros)->orderBy('id')->get();
                    $ok = [];
                    $falhas = [];
                    foreach ($docs as $doc) {
                        try {
                            $desintegrar ? $this->tesouraria->desintegrar($doc, $motivo) : $this->tesouraria->anular($doc, $motivo);
                            $ok[] = $doc->id;
                        } catch (ErroNegocio $e) {
                            $falhas[] = ['documento_tesouraria_id' => $doc->id, 'motivo' => $e->getMessage()];
                        }
                    }
                    $verbo = $desintegrar ? 'estornado(s)' : 'anulado(s)';

                    return ['resultado' => count($ok)." documento(s) {$verbo}".($falhas ? ', '.count($falhas).' não processado(s) (ver detalhes)' : '').'.',
                        'detalhes' => ['processados' => $ok, 'nao_processados' => $falhas]];
                case 'LIMPAR_RECONCILIACOES_ORFAS':
                    $linhas = $this->reconciliacoesOrfas((int) $p->empresa_id)->orderBy('id')->get(['id', 'reconciliacao_codigo']);
                    DB::table('lancamentos_contabeis')->whereIn('id', $linhas->pluck('id'))->update(['reconciliacao_codigo' => null, 'atualizado_em' => now()]);

                    return ['resultado' => $linhas->count().' linha(s) de tesouraria voltaram a não reconciliadas.',
                        'detalhes' => ['linhas' => $linhas->map(fn ($l) => ['id' => (int) $l->id, 'reconciliacao_codigo' => $l->reconciliacao_codigo])->all()]];
                case 'ELIMINAR_EMPRESA':
                    $e = Empresa::query()->lockForUpdate()->findOrFail((int) $parametros['empresa_id']);
                    $e->update(['estado' => Empresa::ESTADO_INATIVO]);
                    $e->delete();

                    return ['resultado' => "Empresa #{$e->id} «{$e->nome}» eliminada (eliminação lógica).", 'detalhes' => ['empresa_id' => $e->id, 'nif' => $e->nif]];
            }

            throw new ErroNegocio("Acção de manutenção desconhecida: {$p->acao}.", 'ACAO_DESCONHECIDA', 422);
        });
    }

    private function pendentes(string $acao, array $parametros)
    {
        return DocumentoTesouraria::query()->where('estado', 'PENDENTE')
            ->when($acao === 'ANULAR_PENDENTES_TESOURARIA_DATA', fn ($q) => $q->whereDate('data_documento', $parametros['data']));
    }

    /** Linhas 43/45 com código de reconciliação que não pertence a uma reconciliação bancária válida (ui_rotinas.js:1225-1270). */
    private function reconciliacoesOrfas(int $empresa)
    {
        return DB::table('lancamentos_contabeis as l')->where('l.empresa_id', $empresa)->whereNotNull('l.reconciliacao_codigo')
            ->where(fn ($q) => $q->where('l.codigo_conta', 'like', '43%')->orWhere('l.codigo_conta', 'like', '45%'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('reconciliacoes_bancarias as r')->whereColumn('r.empresa_id', 'l.empresa_id')
                ->whereColumn('r.reconciliacao_codigo', 'l.reconciliacao_codigo')->where('r.estado', 'CONCILIADO_BANCO'))
            ->select('l.id', 'l.reconciliacao_codigo');
    }

    /** @return array{id: int, nome_utilizador: string} */
    private function quem(Utilizador $u): array
    {
        return ['id' => $u->id, 'nome_utilizador' => $u->nome_utilizador];
    }

    /** @return array{em: string, por: string, texto: string} */
    private function entrada(Utilizador $u, string $texto): array
    {
        return ['em' => now()->toAtomString(), 'por' => $u->nome_utilizador, 'texto' => $texto];
    }

    /** Parâmetros, impactos e autores do pedido: colunas jsonb (objectos, como no legado). */
    private function compacto(array $v): array
    {
        return $v;
    }

    private function json(mixed $v): array
    {
        if (is_array($v)) {
            return $v;
        }
        $d = json_decode((string) $v, true);

        return is_array($d) ? $d : [];
    }
}
