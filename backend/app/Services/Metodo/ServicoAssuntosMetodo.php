<?php

namespace App\Services\Metodo;

use App\Exceptions\ErroNegocio;
use App\Models\AssuntoMetodoTrabalho;
use App\Models\CentroCusto;
use App\Models\Colaborador;
use App\Models\HistoricoAssuntoMetodo;
use App\Models\PedidoCompra;
use App\Models\Produto;
use App\Models\Projeto;
use App\Models\Terceiro;
use App\Models\UnidadeNegocio;
use App\Models\Venda;
use App\Services\Sistema\ServicoNumeracao;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Método de Trabalho — Registo de Assunto 5W1H (js/modules/metodo/assuntos.js; UI assuntos_ui.js).
 * Paridade com o legado:
 *   - seis blocos (WHAT, WHO, WHEN, WHERE, WHY, HOW), número «AS AAAA/NNN» por ano (maior número + 1, :103-117);
 *   - em rascunho só o título é obrigatório; o responsável principal tem de estar na lista; prazo < 48 h é aviso;
 *     fornecedor em WHO ⇔ pedido de compra (erro se pedir PC sem fornecedor; aviso no inverso) (validar, :60-98);
 *   - estados RASCUNHO → EM_CURSO → CONCLUIDO; RASCUNHO/EM_CURSO → CANCELADO (com motivo); CANCELADO → RASCUNHO (:51);
 *   - histórico campo a campo (registarAlteracoes, :171-178) e anexos por URL;
 *   - encerramento bloqueado enquanto o projecto não estiver encerrado/cancelado e o pedido de compra não estiver aprovado;
 *   - permissões: criador edita em rascunho, responsável/criador em curso; `mt_aprovar` = gestor (vê e edita tudo) (:491-504);
 *   - alertas: prazo ultrapassado, a terminar em ≤ 48 h, à espera da aprovação do pedido de compra (:474-488).
 * Melhorias:
 *   - tudo validado no servidor (referências da própria empresa: colaboradores, fornecedores, clientes, unidade de negócio,
 *     centro de custo, encomenda, projecto, artigos) e anexos só http(s);
 *   - quem não é gestor vê só os assuntos que criou, de que é responsável ou que tem de aprovar (nota do catálogo);
 *   - à espera de aprovação o assunto não se edita (a decisão é sobre o que foi submetido); depois de pôr em curso,
 *     o método de resolução e o pedido de compra ficam fixos (já deram origem ao projecto / pedido);
 *   - reabrir um cancelado limpa a deliberação (tem de voltar a ser aprovado; fica tudo no histórico);
 *   - nada se apaga: cancela-se com motivo.
 */
final class ServicoAssuntosMetodo
{
    public const HORAS_AVISO = 48;

    public const ROTULOS_ESTADO = ['RASCUNHO' => 'Rascunho', 'EM_CURSO' => 'Em curso', 'CONCLUIDO' => 'Concluído', 'CANCELADO' => 'Cancelado'];

    /** Campos seguidos no histórico «Alterado» (ROTULOS, :163-170). */
    private const ROTULOS = [
        'titulo' => 'Título', 'descricao' => 'Descrição', 'responsaveis' => 'Responsáveis', 'responsavel_principal' => 'Responsável principal',
        'data_inicio' => 'Data de início', 'data_fim' => 'Data de fim', 'local_tipo' => 'Localização', 'unidade_negocio_id' => 'Unidade de negócio',
        'cliente_id' => 'Cliente', 'encomenda' => 'Encomenda', 'morada' => 'Morada', 'fundamentacao' => 'Fundamentação',
        'causa_raiz' => 'Causa raiz', 'recursos' => 'Recursos', 'custo_estimado' => 'Custo estimado', 'codigo_moeda' => 'Moeda',
        'metodo_resolucao' => 'Método de resolução', 'projeto_modo' => 'Projecto (novo ou existente)', 'projeto_id' => 'Projecto',
        'centro_custo_id' => 'Centro de custo', 'encomenda_venda_id' => 'Nota de encomenda',
        'gerar_pedido_compra' => 'Gerar pedido de compra', 'linhas_pedido_compra' => 'Artigos do pedido de compra', 'anexos' => 'Anexos',
    ];

    /** Campos que deixam de mudar depois de o assunto sair de rascunho (já originaram projecto/pedido). */
    private const FIXOS_FORA_DE_RASCUNHO = ['metodo_resolucao', 'projeto_modo', 'projeto_id', 'centro_custo_id', 'encomenda_venda_id', 'gerar_pedido_compra', 'linhas_pedido_compra'];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoNumeracao $numeracao,
    ) {}

    // ───────────── Quem é o utilizador ─────────────

    public function nomeUtilizador(): string
    {
        return (string) (Auth::user()?->nome_utilizador ?? '');
    }

    public function eGestor(): bool
    {
        return Gate::allows('mt_aprovar');
    }

    /** Colaborador ligado ao utilizador na empresa activa (utilizador_empresa.colaborador_id). */
    public function colaboradorDoUtilizador(?int $utilizador = null): ?int
    {
        $id = DB::table('utilizador_empresa')->where('utilizador_id', $utilizador ?? Auth::id())->where('empresa_id', $this->contexto->obrigatorio())->value('colaborador_id');

        return $id ? (int) $id : null;
    }

    public function souCriador(AssuntoMetodoTrabalho $a): bool
    {
        $u = $this->nomeUtilizador();

        return $u !== '' && mb_strtolower((string) $a->criado_por) === mb_strtolower($u);
    }

    /** Responsável: colaborador ligado ao utilizador ou nome igual ao do utilizador (permissoes, :495-496). */
    public function souResponsavel(AssuntoMetodoTrabalho $a): bool
    {
        $u = Auth::user();
        if (! $u) {
            return false;
        }
        $colab = $this->colaboradorDoUtilizador();
        $nomes = array_filter([mb_strtolower(trim((string) $u->nome_utilizador)), mb_strtolower(trim((string) $u->nome_completo))]);
        foreach ((array) $a->responsaveis as $r) {
            if ($colab && ($r['tipo'] ?? null) === 'COLABORADOR' && (int) ($r['ref_id'] ?? 0) === $colab) {
                return true;
            }
            if (in_array(mb_strtolower(trim((string) ($r['nome'] ?? ''))), $nomes, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * O utilizador é o aprovador designado (não inclui o gestor). Com $soPendente = false conta também quem já decidiu
     * (continua a ver o assunto que aprovou, devolveu ou recusou).
     */
    public function souAprovador(AssuntoMetodoTrabalho $a, bool $soPendente = true): bool
    {
        $d = (array) $a->deliberacao;
        if (! $d || ($soPendente && ($d['estado'] ?? null) !== 'PENDENTE')) {
            return false;
        }
        if (! empty($d['aprovador_utilizador_id']) && (int) $d['aprovador_utilizador_id'] === (int) Auth::id()) {
            return true;
        }
        $colab = $this->colaboradorDoUtilizador();
        if ($colab && ! empty($d['aprovador_colaborador_id']) && (int) $d['aprovador_colaborador_id'] === $colab) {
            return true;
        }
        $u = mb_strtolower($this->nomeUtilizador());

        return $u !== '' && mb_strtolower(trim((string) ($d['aprovador_utilizador'] ?? ''))) === $u;
    }

    /** Pode decidir: aprovador designado ou gestor (`mt_aprovar`), nunca quem criou o assunto. */
    public function podeDecidir(AssuntoMetodoTrabalho $a): bool
    {
        if ((((array) $a->deliberacao)['estado'] ?? null) !== 'PENDENTE' || $this->souCriador($a)) {
            return false;
        }

        return $this->souAprovador($a) || $this->eGestor();
    }

    /** @return array<string, bool> */
    public function permissoes(AssuntoMetodoTrabalho $a): array
    {
        $gestor = $this->eGestor();
        $criador = $this->souCriador($a);
        $resp = $this->souResponsavel($a);
        $del = ((array) $a->deliberacao)['estado'] ?? null;
        $pendente = $del === 'PENDENTE';
        $criar = Gate::any(['mt_criar', 'mt_aprovar']);

        return [
            'ver' => $gestor || $criador || $resp || $this->souAprovador($a, false),
            'editar' => ! $pendente && in_array($a->estado, ['RASCUNHO', 'EM_CURSO'], true)
                && ($gestor || ($a->estado === 'RASCUNHO' && $criador && $criar) || ($a->estado === 'EM_CURSO' && ($resp || $criador))),
            'submeter' => $a->estado === 'RASCUNHO' && in_array($del, [null, 'DEVOLVIDO'], true) && ($gestor || (($criador || $resp) && $criar)),
            'decidir' => $this->podeDecidir($a),
            'concluir' => $a->estado === 'EM_CURSO' && ($gestor || $resp),
            'cancelar' => in_array($a->estado, ['RASCUNHO', 'EM_CURSO'], true) && ($gestor || $criador || $resp),
            'reabrir' => $a->estado === 'CANCELADO' && ($gestor || $criador),
            'gestor' => $gestor,
        ];
    }

    public function exigirVer(AssuntoMetodoTrabalho $a): void
    {
        if (! $this->permissoes($a)['ver']) {
            throw new AccessDeniedHttpException('Este assunto não lhe está atribuído.');
        }
    }

    public function exigirEditar(AssuntoMetodoTrabalho $a): void
    {
        if (! $this->permissoes($a)['editar']) {
            throw new AccessDeniedHttpException((((array) $a->deliberacao)['estado'] ?? null) === 'PENDENTE'
                ? 'O assunto aguarda aprovação: não se altera até haver decisão.'
                : 'Neste estado, a edição cabe ao criador (em rascunho) ou ao responsável (em curso).');
        }
    }

    // ───────────── Validação ─────────────

    /**
     * Regras do legado (validar, :60-98) + as que o erp_laravel exige para o projecto e o pedido de compra.
     *
     * @return array{erros: list<string>, avisos: list<string>}
     */
    public function validar(array $d, bool $rascunho): array
    {
        $erros = [];
        $avisos = [];
        $titulo = trim((string) ($d['titulo'] ?? ''));
        if ($titulo === '') {
            $erros[] = 'WHAT: indique o título do assunto.';
        }
        if (mb_strlen($titulo) > 120) {
            $erros[] = 'WHAT: o título não pode ter mais de 120 caracteres.';
        }

        $resp = array_values(array_filter((array) ($d['responsaveis'] ?? []), fn ($r) => trim((string) ($r['nome'] ?? '')) !== ''));
        if (! $resp) {
            $erros[] = 'WHO: indique pelo menos um responsável.';
        }
        $principal = (string) ($d['responsavel_principal'] ?? '');
        if ($resp && $principal === '') {
            $erros[] = 'WHO: escolha o responsável principal.';
        }
        if ($principal !== '' && ! collect($resp)->contains(fn ($r) => AssuntoMetodoTrabalho::chaveResponsavel($r) === $principal)) {
            $erros[] = 'WHO: o responsável principal tem de estar na lista de responsáveis.';
        }

        $ini = self::data($d['data_inicio'] ?? null);
        $fim = self::data($d['data_fim'] ?? null);
        if (! $ini) {
            $erros[] = 'WHEN: indique a data de início.';
        }
        if (! $fim) {
            $erros[] = 'WHEN: indique a data de fim.';
        }
        if ($ini && $fim) {
            $horas = (strtotime($fim) - strtotime($ini)) / 3600;
            if ($horas < 0) {
                $erros[] = 'WHEN: a data de fim é anterior à data de início.';
            } elseif ($horas < self::HORAS_AVISO) {
                $avisos[] = 'WHEN: o prazo é de menos de '.self::HORAS_AVISO.' horas. Confirme que é mesmo assim.';
            }
        }

        $local = $d['local_tipo'] ?? null;
        if (! in_array($local, AssuntoMetodoTrabalho::LOCAIS, true)) {
            $erros[] = 'WHERE: escolha se o assunto é interno ou externo.';
        }
        if ($local === 'INTERNO' && empty($d['unidade_negocio_id'])) {
            $erros[] = 'WHERE: escolha a unidade de negócio (departamento).';
        }
        if ($local === 'EXTERNO' && empty($d['cliente_id'])) {
            $erros[] = 'WHERE: escolha o cliente.';
        }

        $fund = mb_strlen(trim((string) ($d['fundamentacao'] ?? '')));
        if ($fund < 50) {
            $erros[] = "WHY: a fundamentação tem de ter pelo menos 50 caracteres (tem {$fund}).";
        }

        if (trim((string) ($d['recursos'] ?? '')) === '') {
            $erros[] = 'HOW: descreva os recursos envolvidos.';
        }
        if (! ((float) ($d['custo_estimado'] ?? 0) > 0)) {
            $erros[] = 'HOW: indique o custo estimado.';
        }

        if (($d['metodo_resolucao'] ?? 'ACCAO') === 'PROJECTO') {
            if (($d['projeto_modo'] ?? 'NOVO') === 'EXISTENTE') {
                if (empty($d['projeto_id'])) {
                    $erros[] = 'HOW: escolha o projecto existente que vai resolver este assunto.';
                }
            } elseif ($local === 'INTERNO' && empty($d['centro_custo_id'])) {
                $erros[] = 'HOW: o projecto interno precisa de centro de custo.';
            } elseif ($local === 'EXTERNO' && empty($d['encomenda_venda_id'])) {
                $erros[] = 'HOW: o projecto externo precisa da nota de encomenda (NE) do cliente.';
            }
        }

        // Fornecedor e pedido de compra andam sempre juntos
        $temFornecedor = collect($resp)->contains(fn ($r) => ($r['tipo'] ?? null) === 'FORNECEDOR');
        if (! empty($d['gerar_pedido_compra'])) {
            if (! $temFornecedor) {
                $erros[] = 'HOW: para gerar pedido de compra é preciso um fornecedor em WHO.';
            }
            if (! array_filter((array) ($d['linhas_pedido_compra'] ?? []), fn ($l) => ! empty($l['produto_id']))) {
                $erros[] = 'HOW: indique pelo menos um artigo para o pedido de compra.';
            }
        }
        if ($temFornecedor && empty($d['gerar_pedido_compra'])) {
            $avisos[] = 'WHO: há um fornecedor indicado — normalmente isso implica gerar pedido de compra.';
        }

        // Em rascunho só se exige o título: é um rascunho, não um compromisso
        return ['erros' => $rascunho ? array_values(array_filter($erros, fn ($e) => str_starts_with($e, 'WHAT'))) : $erros, 'avisos' => $avisos];
    }

    /** Dados do assunto na forma que validar() espera. */
    public function dadosDe(AssuntoMetodoTrabalho $a): array
    {
        return $a->only(['titulo', 'responsaveis', 'responsavel_principal', 'local_tipo', 'unidade_negocio_id', 'cliente_id', 'fundamentacao', 'recursos',
            'custo_estimado', 'metodo_resolucao', 'projeto_modo', 'projeto_id', 'centro_custo_id', 'encomenda_venda_id', 'gerar_pedido_compra', 'linhas_pedido_compra'])
            + ['data_inicio' => $a->data_inicio?->toDateString(), 'data_fim' => $a->data_fim?->toDateString()];
    }

    /** Validação completa (para submeter e pôr em curso); erro 422 com a lista. */
    public function exigirCompleto(AssuntoMetodoTrabalho $a, string $titulo): void
    {
        $erros = $this->validar($this->dadosDe($a), false)['erros'];
        if ($erros) {
            throw new ErroNegocio($titulo."\n".implode("\n", $erros), 'ASSUNTO_INCOMPLETO', 422, ['validacao' => $erros]);
        }
    }

    // ───────────── Gravar ─────────────

    /**
     * Cria ou altera o assunto (gravar, :119-160). `validar_tudo` pede a validação completa mesmo em rascunho.
     *
     * @return array{assunto: AssuntoMetodoTrabalho, avisos: list<string>}
     */
    public function gravar(array $d, ?AssuntoMetodoTrabalho $a = null): array
    {
        if ($a) {
            $this->exigirEditar($a);
        }
        $estado = $a?->estado ?? 'RASCUNHO';
        $reg = $this->normalizar($d, $a);
        $val = $this->validar($reg, $estado === 'RASCUNHO' && empty($d['validar_tudo']));
        if ($val['erros']) {
            throw new ErroNegocio(implode("\n", $val['erros']), 'ASSUNTO_INVALIDO', 422, ['validacao' => $val['erros']]);
        }
        $this->verificarReferencias($reg, $a);

        $assunto = DB::transaction(function () use ($reg, $a) {
            $u = $this->nomeUtilizador();
            $reg['atualizado_por'] = $u;
            $novas = array_intersect_key($reg, array_flip(['centro_custo_id', 'encomenda_venda_id', 'linhas_pedido_compra']));
            $base = array_diff_key($reg, $novas);
            if ($a) {
                $a = AssuntoMetodoTrabalho::query()->lockForUpdate()->findOrFail($a->id);
                $antes = $a->replicate();
                $a->fill($base)->forceFill($novas)->save();
                $mudou = [];
                foreach (self::ROTULOS as $campo => $rotulo) {
                    if (self::comparavel($antes->getAttribute($campo)) !== self::comparavel($a->getAttribute($campo))) {
                        $mudou[] = $rotulo;
                    }
                }
                if ($mudou) {
                    $this->registar($a, 'Alterado', implode(', ', $mudou));
                }

                return $a;
            }
            $novo = new AssuntoMetodoTrabalho($base + ['numero' => $this->proximoNumero(), 'estado' => 'RASCUNHO', 'criado_por' => $u]);
            $novo->forceFill($novas)->save();
            $this->registar($novo, 'Criado', 'Assunto criado em rascunho.');

            return $novo;
        });

        return ['assunto' => $assunto->refresh(), 'avisos' => $val['avisos']];
    }

    /** Normaliza o pedido como o legado (reg, :127-148); fora de rascunho mantém os campos que já originaram integrações. */
    private function normalizar(array $d, ?AssuntoMetodoTrabalho $a): array
    {
        $txt = fn ($k) => trim((string) ($d[$k] ?? ''));
        $resp = [];
        foreach ((array) ($d['responsaveis'] ?? []) as $r) {
            $nome = trim((string) ($r['nome'] ?? ''));
            if ($nome === '') {
                continue;
            }
            $ref = isset($r['ref_id']) && $r['ref_id'] !== '' && $r['ref_id'] !== null ? (int) $r['ref_id'] : null;
            $item = ['tipo' => (string) $r['tipo'], 'ref_id' => $r['tipo'] === 'EXTERNO' ? null : $ref, 'nome' => mb_substr($nome, 0, 150)];
            if (! collect($resp)->contains(fn ($x) => AssuntoMetodoTrabalho::chaveResponsavel($x) === AssuntoMetodoTrabalho::chaveResponsavel($item))) {
                $resp[] = $item;
            }
        }
        $principal = $txt('responsavel_principal');
        $refPrincipal = collect($resp)->first(fn ($r) => AssuntoMetodoTrabalho::chaveResponsavel($r) === $principal)['ref_id'] ?? null;
        $local = in_array($d['local_tipo'] ?? null, AssuntoMetodoTrabalho::LOCAIS, true) ? $d['local_tipo'] : null;
        $metodo = in_array($d['metodo_resolucao'] ?? null, AssuntoMetodoTrabalho::METODOS, true) ? $d['metodo_resolucao'] : 'ACCAO';
        $modo = in_array($d['projeto_modo'] ?? null, AssuntoMetodoTrabalho::MODOS_PROJETO, true) ? $d['projeto_modo'] : 'NOVO';
        $linhas = [];
        foreach ((array) ($d['linhas_pedido_compra'] ?? []) as $l) {
            if (empty($l['produto_id'])) {
                continue;
            }
            $linhas[] = ['produto_id' => (int) $l['produto_id'], 'quantidade' => round((float) ($l['quantidade'] ?? 1), 3),
                'preco_unitario' => round((float) ($l['preco_unitario'] ?? 0), 2), 'descricao' => trim((string) ($l['descricao'] ?? '')) ?: null];
        }
        $anexos = [];
        foreach ((array) ($d['anexos'] ?? []) as $x) {
            $url = trim((string) ($x['url'] ?? ''));
            if ($url !== '') {
                $anexos[] = ['nome' => mb_substr(trim((string) ($x['nome'] ?? '')) ?: $url, 0, 150), 'url' => $url];
            }
        }

        $reg = [
            'titulo' => mb_substr($txt('titulo'), 0, 255), 'descricao' => $txt('descricao') ?: null,
            'responsaveis' => $resp, 'responsavel_principal' => $principal ?: null, 'responsavel_principal_ref' => $refPrincipal,
            'data_inicio' => self::data($d['data_inicio'] ?? null), 'data_fim' => self::data($d['data_fim'] ?? null),
            'local_tipo' => $local,
            'unidade_negocio_id' => $local === 'INTERNO' && ! empty($d['unidade_negocio_id']) ? (int) $d['unidade_negocio_id'] : null,
            'cliente_id' => $local === 'EXTERNO' && ! empty($d['cliente_id']) ? (int) $d['cliente_id'] : null,
            'encomenda' => $txt('encomenda') ?: null, 'morada' => $txt('morada') ?: null,
            'fundamentacao' => $txt('fundamentacao') ?: null, 'causa_raiz' => $txt('causa_raiz') ?: null,
            'recursos' => $txt('recursos') ?: null, 'custo_estimado' => number_format(round((float) ($d['custo_estimado'] ?? 0), 2), 2, '.', ''),
            'codigo_moeda' => mb_strtoupper($txt('codigo_moeda')) ?: 'AOA',
            'metodo_resolucao' => $metodo, 'projeto_modo' => $metodo === 'PROJECTO' ? $modo : null,
            'centro_custo_id' => $metodo === 'PROJECTO' && $modo === 'NOVO' && $local === 'INTERNO' && ! empty($d['centro_custo_id']) ? (int) $d['centro_custo_id'] : null,
            'encomenda_venda_id' => $metodo === 'PROJECTO' && $modo === 'NOVO' && $local === 'EXTERNO' && ! empty($d['encomenda_venda_id']) ? (int) $d['encomenda_venda_id'] : null,
            'gerar_pedido_compra' => ! empty($d['gerar_pedido_compra']),
            'linhas_pedido_compra' => ! empty($d['gerar_pedido_compra']) ? $linhas : [],
            'anexos' => $anexos,
        ];
        // Projecto: o existente escolhe-se à mão; o novo só nasce ao pôr em curso. Um projecto escolhido e ainda não ligado
        // (rascunho) larga-se quando o método muda; o que já foi criado/ligado por este assunto mantém-se.
        $ligado = $a && $a->projeto_id && Projeto::query()->whereKey($a->projeto_id)->where('assunto_metodo_id', $a->id)->exists();
        if ($metodo === 'PROJECTO' && $modo === 'EXISTENTE' && ! empty($d['projeto_id']) && ! $ligado) {
            $reg['projeto_id'] = (int) $d['projeto_id'];
        } elseif (! $ligado) {
            $reg['projeto_id'] = null;
        }

        if ($a && $a->estado !== 'RASCUNHO') {
            foreach (self::FIXOS_FORA_DE_RASCUNHO as $campo) {
                $reg[$campo] = $a->getAttribute($campo);
            }
        }

        return $reg;
    }

    /** As referências têm de existir na empresa activa (os modelos filtram pela empresa — EscopoEmpresa). */
    private function verificarReferencias(array $r, ?AssuntoMetodoTrabalho $a): void
    {
        $falha = fn (string $m) => throw new ErroNegocio($m, 'REFERENCIA_INVALIDA', 422);
        foreach ($r['responsaveis'] as $x) {
            if (! in_array($x['tipo'], AssuntoMetodoTrabalho::TIPOS_RESPONSAVEL, true)) {
                $falha('WHO: tipo de responsável inválido.');
            }
            if ($x['tipo'] === 'COLABORADOR' && (! $x['ref_id'] || ! Colaborador::query()->whereKey($x['ref_id'])->exists())) {
                $falha("WHO: o colaborador «{$x['nome']}» não existe nesta empresa.");
            }
            if ($x['tipo'] === 'FORNECEDOR' && (! $x['ref_id'] || ! Terceiro::query()->find($x['ref_id'])?->eFornecedor())) {
                $falha("WHO: «{$x['nome']}» não é um fornecedor desta empresa.");
            }
        }
        if ($r['unidade_negocio_id'] && ! UnidadeNegocio::query()->whereKey($r['unidade_negocio_id'])->exists()) {
            $falha('WHERE: a unidade de negócio não existe nesta empresa.');
        }
        if ($r['cliente_id'] && ! Terceiro::query()->find($r['cliente_id'])?->eCliente()) {
            $falha('WHERE: o terceiro escolhido não é cliente desta empresa.');
        }
        if ($r['centro_custo_id'] && ! CentroCusto::query()->whereKey($r['centro_custo_id'])->exists()) {
            $falha('HOW: o centro de custo não existe nesta empresa.');
        }
        if ($r['encomenda_venda_id']) {
            $enc = Venda::query()->find($r['encomenda_venda_id']);
            if (! $enc || $enc->tipo_documento !== 'NE' || $enc->estado === 'ANULADO' || (int) $enc->cliente_id !== (int) $r['cliente_id']) {
                $falha('HOW: a encomenda tem de ser uma nota de encomenda (NE) não anulada do cliente escolhido.');
            }
        }
        if (! empty($r['projeto_id']) && ($a === null || (int) $r['projeto_id'] !== (int) $a->projeto_id)) {
            $p = Projeto::query()->find($r['projeto_id']);
            if (! $p) {
                $falha('HOW: o projecto escolhido não existe nesta empresa.');
            }
            if ($p->assunto_metodo_id && (int) $p->assunto_metodo_id !== (int) $a?->id) {
                $falha("HOW: o projecto {$p->codigo} já resolve outro assunto.");
            }
        }
        $produtos = array_column($r['linhas_pedido_compra'] ?? [], 'produto_id');
        if ($produtos && Produto::query()->whereIn('id', $produtos)->count() !== count(array_unique($produtos))) {
            $falha('HOW: há artigos do pedido de compra que não existem nesta empresa.');
        }
        foreach ($r['linhas_pedido_compra'] ?? [] as $i => $l) {
            if ($l['quantidade'] <= 0) {
                $falha('HOW: a quantidade do artigo '.($i + 1).' do pedido de compra tem de ser positiva.');
            }
        }
        foreach ($r['anexos'] as $x) {
            if (! preg_match('#^https?://[^\s<>"]+$#i', $x['url'])) {
                $falha("Anexos: o endereço «{$x['nome']}» tem de começar por http:// ou https://.");
            }
        }
    }

    /** Número «AS AAAA/NNN»: o maior número do ano + 1 (proximoNumero, :103-117), sem duplicados (ServicoNumeracao). */
    private function proximoNumero(): string
    {
        $empresa = $this->contexto->obrigatorio();
        $ano = now()->year;
        $n = $this->numeracao->proximo($empresa, "metodo_assuntos:{$ano}", fn () => (int) DB::table('assuntos_metodo_trabalho')->where('empresa_id', $empresa)
            ->whereRaw('numero ~ ?', ["^AS {$ano}/[0-9]+$"])->selectRaw('MAX(CAST(SUBSTRING(numero FROM 9) AS INTEGER)) AS m')->value('m'));

        return sprintf('AS %d/%03d', $ano, $n);
    }

    // ───────────── Estados ─────────────

    /**
     * Concluir, cancelar (com motivo) ou reabrir (mudarEstado, :269-318). Pôr EM_CURSO só pela deliberação.
     */
    public function mudarEstado(AssuntoMetodoTrabalho $a, string $novo, ?string $nota): AssuntoMetodoTrabalho
    {
        $perm = $this->permissoes($a);
        $chave = ['CONCLUIDO' => 'concluir', 'CANCELADO' => 'cancelar', 'RASCUNHO' => 'reabrir'][$novo] ?? null;
        if ($novo === 'EM_CURSO') {
            throw new ErroNegocio('O assunto passa a «Em curso» pela aprovação: submeta-o a aprovação do responsável do nível acima.', 'TRANSICAO_INVALIDA', 422);
        }
        if (! in_array($novo, AssuntoMetodoTrabalho::TRANSICOES[$a->estado] ?? [], true) || ! $chave) {
            throw new ErroNegocio('Não é possível passar de «'.(self::ROTULOS_ESTADO[$a->estado] ?? $a->estado).'» para «'.(self::ROTULOS_ESTADO[$novo] ?? $novo).'».', 'TRANSICAO_INVALIDA', 422);
        }
        if (! $perm[$chave]) {
            throw new AccessDeniedHttpException($novo === 'CONCLUIDO' ? 'Só o responsável ou o gestor (aprovação) concluem o assunto.' : 'Não pode mudar o estado deste assunto.');
        }
        $nota = trim((string) $nota);
        if ($novo === 'CANCELADO' && mb_strlen($nota) < 5) {
            throw new ErroNegocio('Indique o motivo do cancelamento.', 'MOTIVO_OBRIGATORIO', 422);
        }

        return DB::transaction(function () use ($a, $novo, $nota) {
            $a = AssuntoMetodoTrabalho::query()->lockForUpdate()->findOrFail($a->id);
            if ($novo === 'CONCLUIDO') {
                $this->exigirIntegracoesFechadas($a);
            }
            $u = $this->nomeUtilizador();
            $campos = ['estado' => $novo, 'atualizado_por' => $u];
            if ($novo === 'CONCLUIDO') {
                $campos += ['concluido_em' => now(), 'concluido_por' => $u];
            } elseif ($novo === 'CANCELADO') {
                $campos += ['cancelado_em' => now(), 'cancelado_por' => $u, 'motivo_cancelamento' => $nota];
            } else {
                // reabrir: volta a precisar de aprovação; a deliberação anterior fica no histórico
                $campos += ['deliberacao' => null];
            }
            $a->forceFill($campos)->save();
            $this->registar($a, 'Estado: '.self::ROTULOS_ESTADO[$novo], $nota ?: null);

            return $a->refresh();
        });
    }

    /** O assunto não encerra com o projecto aberto nem com o pedido de compra por aprovar (:296-309). */
    private function exigirIntegracoesFechadas(AssuntoMetodoTrabalho $a): void
    {
        if ($a->projeto_id) {
            $p = Projeto::query()->find($a->projeto_id) ?? throw new ErroNegocio('O projecto associado a este assunto não foi encontrado.', 'PROJETO_INEXISTENTE', 422);
            if (! in_array($p->estado, Projeto::FECHADOS, true)) {
                throw new ErroNegocio("O assunto não encerra enquanto o projecto {$p->codigo} «{$p->nome}» não estiver encerrado (está {$p->estado}).", 'PROJETO_ABERTO', 422);
            }
        }
        if ($a->pedido_compra_id) {
            $pc = PedidoCompra::query()->find($a->pedido_compra_id) ?? throw new ErroNegocio('O pedido de compra associado não foi encontrado.', 'PEDIDO_INEXISTENTE', 422);
            if (! in_array($pc->estado, ['APROVADO', 'ADJUDICADO', 'EM_PROCESSAMENTO', 'CONCLUIDO'], true)) {
                throw new ErroNegocio("O assunto não encerra enquanto o pedido de compra {$pc->numero_pedido} não estiver aprovado (está {$pc->estado}).", 'PEDIDO_NAO_APROVADO', 422);
            }
        }
    }

    // ───────────── Histórico ─────────────

    public function registar(AssuntoMetodoTrabalho $a, string $acao, ?string $detalhe): void
    {
        HistoricoAssuntoMetodo::create(['assunto_id' => $a->id, 'acao' => mb_substr($acao, 0, 50), 'detalhe' => $detalhe === null ? null : mb_substr(trim($detalhe), 0, 1000),
            'por' => mb_substr($this->nomeUtilizador(), 0, 100) ?: null, 'em' => now()]);
    }

    public function historico(AssuntoMetodoTrabalho $a): array
    {
        return HistoricoAssuntoMetodo::query()->where('assunto_id', $a->id)->orderByDesc('em')->orderByDesc('id')->get(['id', 'acao', 'detalhe', 'por', 'em'])->all();
    }

    // ───────────── Consultas ─────────────

    /**
     * Assuntos visíveis ao utilizador (listar, :461-463) com os nomes resolvidos e os alertas (:474-488).
     *
     * @return array{assuntos: list<array<string, mixed>>, alertas: array<string, mixed>}
     */
    public function listar(array $f = []): array
    {
        $q = AssuntoMetodoTrabalho::query()->whereNotNull('numero')->orderByDesc('criado_em')->orderByDesc('id')
            ->when($f['estado'] ?? null, fn ($q, $e) => $q->where('estado', $e))
            ->when(isset($f['pesquisa']) && $f['pesquisa'] !== '', function ($q) use ($f) {
                $t = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], (string) $f['pesquisa']).'%';
                $q->where(fn ($x) => $x->where('numero', 'ilike', $t)->orWhere('titulo', 'ilike', $t));
            });
        $todos = $q->get();
        if (! $this->eGestor()) {
            $todos = $todos->filter(fn ($a) => $this->souCriador($a) || $this->souResponsavel($a) || $this->souAprovador($a, false))->values();
        }
        $nomes = $this->nomes($todos);
        $linhas = $todos->map(fn ($a) => $this->resumo($a, $nomes) + ['pode_decidir' => $this->podeDecidir($a)])->all();

        return ['assuntos' => $linhas, 'alertas' => $this->alertas($linhas)];
    }

    /** @return array<string, mixed> */
    public function detalhe(AssuntoMetodoTrabalho $a): array
    {
        $nomes = $this->nomes(collect([$a]));

        return array_merge($a->toArray(), $this->resumo($a, $nomes), [
            'permissoes' => $this->permissoes($a),
            'centro_custo' => $a->centro_custo_id ? CentroCusto::query()->find($a->centro_custo_id, ['id', 'codigo', 'descricao']) : null,
            'encomenda_venda' => $a->encomenda_venda_id ? Venda::query()->find($a->encomenda_venda_id, ['id', 'numero_documento', 'data_emissao']) : null,
        ]);
    }

    /** Projectos que se podem escolher: os da empresa ainda não presos a outro assunto (projectosDisponiveis, :451-457). */
    public function projetosDisponiveis(?int $assunto): array
    {
        return Projeto::query()->where(fn ($q) => $q->whereNull('assunto_metodo_id')->when($assunto, fn ($x) => $x->orWhere('assunto_metodo_id', $assunto)))
            ->orderBy('codigo')->get(['id', 'codigo', 'nome', 'estado', 'tipo'])->all();
    }

    private function nomes($assuntos): array
    {
        return [
            'un' => UnidadeNegocio::query()->whereIn('id', $assuntos->pluck('unidade_negocio_id')->filter()->unique())->get()->mapWithKeys(fn ($u) => [$u->id => $u->nome ?? $u->descricao ?? $u->codigo])->all(),
            'cli' => Terceiro::query()->withTrashed()->whereIn('id', $assuntos->pluck('cliente_id')->filter()->unique())->pluck('nome', 'id')->all(),
            'proj' => Projeto::query()->whereIn('id', $assuntos->pluck('projeto_id')->filter()->unique())->get(['id', 'codigo', 'nome', 'estado'])->keyBy('id')->all(),
            'pc' => PedidoCompra::query()->whereIn('id', $assuntos->pluck('pedido_compra_id')->filter()->unique())->get(['id', 'numero_pedido', 'estado'])->keyBy('id')->all(),
        ];
    }

    private function resumo(AssuntoMetodoTrabalho $a, array $n): array
    {
        $hoje = now()->toDateString();
        $fim = $a->data_fim?->toDateString();
        $p = $a->projeto_id ? ($n['proj'][$a->projeto_id] ?? null) : null;
        $pc = $a->pedido_compra_id ? ($n['pc'][$a->pedido_compra_id] ?? null) : null;

        return [
            'id' => $a->id, 'numero' => $a->numero, 'titulo' => $a->titulo, 'estado' => $a->estado,
            'responsavel_principal_nome' => $a->nomePrincipal() ?: null,
            'local_tipo' => $a->local_tipo, 'unidade_negocio_id' => $a->unidade_negocio_id, 'unidade_negocio_nome' => $a->unidade_negocio_id ? ($n['un'][$a->unidade_negocio_id] ?? null) : null,
            'cliente_id' => $a->cliente_id, 'cliente_nome' => $a->cliente_id ? ($n['cli'][$a->cliente_id] ?? null) : null, 'encomenda' => $a->encomenda,
            'data_inicio' => $a->data_inicio?->toDateString(), 'data_fim' => $fim, 'custo_estimado' => $a->custo_estimado, 'codigo_moeda' => $a->codigo_moeda,
            'metodo_resolucao' => $a->metodo_resolucao, 'projeto_id' => $a->projeto_id,
            'projeto' => $p ? ['id' => $p->id, 'codigo' => $p->codigo, 'nome' => $p->nome, 'estado' => $p->estado] : null,
            'pedido_compra_id' => $a->pedido_compra_id, 'pedido_compra' => $pc ? ['id' => $pc->id, 'numero' => $pc->numero_pedido, 'estado' => $pc->estado] : null,
            'deliberacao_estado' => ((array) $a->deliberacao)['estado'] ?? null,
            'em_atraso' => $a->estado === 'EM_CURSO' && $fim !== null && $fim < $hoje,
            'criado_em' => $a->criado_em?->toIso8601String(), 'criado_por' => $a->criado_por,
        ];
    }

    /** Alertas apurados ao abrir (alertas, :474-488): em atraso, a terminar em ≤ 48 h, bloqueados pelo pedido de compra. */
    private function alertas(array $linhas): array
    {
        $agora = Carbon::now();
        $emCurso = array_filter($linhas, fn ($l) => $l['estado'] === 'EM_CURSO' && $l['data_fim']);
        $fimDia = fn ($l) => Carbon::parse($l['data_fim'].' 23:59:59');
        $porVencer = array_values(array_filter($emCurso, fn ($l) => $fimDia($l)->greaterThan($agora) && $agora->diffInHours($fimDia($l), true) <= self::HORAS_AVISO));
        $atrasados = array_values(array_filter($emCurso, fn ($l) => $fimDia($l)->lessThan($agora)));
        $bloqueados = array_values(array_filter($linhas, fn ($l) => $l['estado'] === 'EM_CURSO' && $l['pedido_compra'] && $l['pedido_compra']['estado'] !== 'APROVADO'
            && ! in_array($l['pedido_compra']['estado'], ['ADJUDICADO', 'EM_PROCESSAMENTO', 'CONCLUIDO'], true)));
        $numeros = fn ($xs) => array_map(fn ($l) => $l['numero'], $xs);

        return ['por_vencer' => $numeros($porVencer), 'atrasados' => $numeros($atrasados), 'bloqueados' => $numeros($bloqueados),
            'aguardam_minha_decisao' => $numeros(array_values(array_filter($linhas, fn ($l) => ! empty($l['pode_decidir'])))), 'horas_aviso' => self::HORAS_AVISO];
    }

    // ───────────── Apoio ─────────────

    public static function data(mixed $v): ?string
    {
        $s = trim((string) $v);

        return preg_match('/^\d{4}-\d{2}-\d{2}/', $s) ? substr($s, 0, 10) : null;
    }

    private static function comparavel(mixed $v): string
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }

        return json_encode($v === '' || $v === [] ? null : self::canonico($v), JSON_UNESCAPED_UNICODE);
    }

    /** O jsonb do PostgreSQL reordena as chaves: compara-se com as chaves ordenadas e os números como texto. */
    private static function canonico(mixed $v): mixed
    {
        if (is_array($v)) {
            if (! array_is_list($v)) {
                ksort($v);
            }

            return array_map(fn ($x) => self::canonico($x), $v);
        }

        return is_int($v) || is_float($v) ? (string) $v : $v;
    }
}
