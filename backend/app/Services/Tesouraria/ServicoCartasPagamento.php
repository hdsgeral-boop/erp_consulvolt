<?php

namespace App\Services\Tesouraria;

use App\Exceptions\ErroNegocio;
use App\Models\CartaPagamentoBancario;
use App\Models\Colaborador;
use App\Models\ContaBancariaTerceiro;
use App\Models\CoordenadaBancariaColaborador;
use App\Models\DocumentoTesouraria;
use App\Models\Empresa;
use App\Models\ItemCartaPagamento;
use App\Models\ItemDocumentoTesouraria;
use App\Models\ModeloCartaPagamento;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\PlanoConta;
use App\Models\ResultadoFolhaSalarial;
use App\Models\Terceiro;
use App\Services\RH\ServicoColaboradores;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Cartas de ordem de pagamento (Fase D, ADR-071; legado novo js/cartas_pagamento.js e cartas_pagamento_ui.js).
 * A carta é a ordem que a empresa dirige ao seu banco para executar um pagamento JÁ GRAVADO na tesouraria:
 *   - nasce sempre de um documento PAGAMENTO não anulado (documento_tesouraria_id obrigatório) e NUNCA gera lançamentos;
 *   - a conta financeira tem de ser uma conta bancária 42/43 de movimento com IBAN no plano de contas (ServicoContasBancarias:
 *     daí sai o cabeçalho da carta — banco, balcão, n.º de conta, IBAN, SWIFT e titular; titular vazio = nome da empresa);
 *   - beneficiários: pagamento a terceiros → os terceiros das linhas do pagamento (débito − crédito, por terceiro), com a conta
 *     bancária predefinida do terceiro (pode escolher-se outra conta do mesmo terceiro); pagamento de salários (documento ligado
 *     a um período) → os colaboradores do período com líquido > 0 e as suas coordenadas bancárias;
 *   - quem já entrou noutra carta activa do mesmo pagamento (terceiros) ou do mesmo período (salários) aparece assinalado e só
 *     entra com confirmação explícita; sem conta bancária do beneficiário a carta é recusada e diz-se o que falta;
 *   - numeração própria por empresa e ano (CP 2026/001), estados EMITIDA / ANULADA (com motivo; as cartas não se apagam);
 *   - os dados do banco e dos beneficiários ficam fotografados na carta; os valores são sempre recalculados no servidor.
 * Correcções face ao legado: o total das cartas activas de um pagamento não pode exceder o valor do pagamento; o pagamento
 * anulado não emite cartas; a escolha do beneficiário é validada no servidor (o legado confiava nas linhas do ecrã).
 * O texto (assunto/corpo) é TEXTO com variáveis {{…}}, substituídas e escapadas na impressão (frontend); modelos por empresa e
 * tipo, um predefinido por tipo.
 */
final class ServicoCartasPagamento
{
    public const TIPOS = [CartaPagamentoBancario::TIPO_TERCEIRO => 'Pagamento a terceiros', CartaPagamentoBancario::TIPO_SALARIOS => 'Pagamento de salários'];

    /** Texto padrão (cartas_pagamento.js MODELOS_PADRAO), na forma habitual das cartas dirigidas aos bancos em Angola. */
    public const MODELOS_PADRAO = [
        CartaPagamentoBancario::TIPO_TERCEIRO => [
            'assunto' => 'Ordem de transferência bancária',
            'corpo' => "Exmos. Senhores,\n\nVimos por este meio solicitar a V. Exas. que se dignem proceder à transferência da quantia de {{TOTAL}} ({{TOTAL_EXTENSO}}), por débito da nossa conta n.º {{CONTA}}, a favor do(s) beneficiário(s) identificado(s) no quadro abaixo, com os respectivos valores e números de conta.\n\nMais solicitamos que nos seja remetido o comprovativo da operação logo que esta se encontre executada.\n\nSem outro assunto de momento, subscrevemo-nos com elevada estima e consideração.",
            'cargo_assinatura' => 'A Direcção',
        ],
        CartaPagamentoBancario::TIPO_SALARIOS => [
            'assunto' => 'Ordem de pagamento de salários — {{MES}}',
            'corpo' => "Exmos. Senhores,\n\nVimos por este meio solicitar a V. Exas. que se dignem proceder ao pagamento dos salários referentes ao mês de {{MES}}, no valor global de {{TOTAL}} ({{TOTAL_EXTENSO}}), por débito da nossa conta n.º {{CONTA}}, a favor dos {{N_BENEFICIARIOS}} colaboradores constantes da relação em anexo, creditando a cada um o valor indicado na respectiva conta.\n\nMais solicitamos que nos seja remetido o comprovativo da operação logo que esta se encontre executada.\n\nSem outro assunto de momento, subscrevemo-nos com elevada estima e consideração.",
            'cargo_assinatura' => 'A Direcção',
        ],
    ];

    /** Variáveis do texto (cartas_pagamento.js VARIAVEIS + beneficiário e IBAN do beneficiário). */
    public const VARIAVEIS = [
        'EMPRESA' => 'Nome da empresa', 'NIF_EMPRESA' => 'NIF da empresa', 'BANCO' => 'Banco da conta emissora', 'CONTA' => 'N.º da conta emissora',
        'IBAN' => 'IBAN da conta emissora', 'SWIFT' => 'SWIFT/BIC da conta emissora', 'TITULAR' => 'Titular da conta emissora',
        'BENEFICIARIO' => 'Beneficiário (ou «diversos beneficiários»)', 'IBAN_BENEFICIARIO' => 'IBAN do beneficiário (só com um beneficiário)',
        'TOTAL' => 'Valor total da carta', 'TOTAL_EXTENSO' => 'Valor total por extenso', 'MOEDA' => 'Moeda da carta', 'N_BENEFICIARIOS' => 'N.º de beneficiários',
        'MES' => 'Mês do processamento (salários)', 'NUMERO' => 'Número da carta', 'DATA' => 'Data da carta', 'LOCAL' => 'Localidade',
        'REFERENCIA' => 'Referência do pagamento de tesouraria',
    ];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoContasBancarias $contasBancarias,
    ) {}

    // ------------------------------------------------------------------ pagamentos que podem emitir cartas

    /**
     * Pagamentos (não anulados) sobre contas 42/43, para escolher na emissão.
     *
     * @param  array{q?: ?string, data_de?: ?string, data_ate?: ?string, por_pagina?: ?int}  $f
     */
    public function documentosElegiveis(array $f): LengthAwarePaginator
    {
        $q = DocumentoTesouraria::query()->where('tipo', 'PAGAMENTO')->where('estado', '<>', 'ANULADO')
            ->where(function ($w) {
                foreach (PlanoConta::PREFIXOS_BANCARIOS as $p) {
                    $w->orWhere('conta_financeira', 'like', "{$p}%");
                }
            })
            ->when($f['q'] ?? null, function ($w, string $texto) {
                $like = '%'.addcslashes(trim($texto), '\\%_').'%';
                $w->where(fn ($x) => $x->where('numero_documento', 'ilike', $like)->orWhere('referencia', 'ilike', $like)->orWhere('descricao', 'ilike', $like));
            })
            ->when($f['data_de'] ?? null, fn ($w, $d) => $w->where('data_documento', '>=', $d))
            ->when($f['data_ate'] ?? null, fn ($w, $d) => $w->where('data_documento', '<=', $d))
            ->select(['id', 'numero_documento', 'data_documento', 'conta_financeira', 'descricao', 'referencia', 'valor_total', 'valor_total_moeda', 'codigo_moeda', 'estado', 'periodo_processamento_salarial_id'])
            ->selectSub(CartaPagamentoBancario::query()->selectRaw('count(*)')->whereColumn('cartas_pagamento_bancario.documento_tesouraria_id', 'documentos_tesouraria.id')
                ->where(fn ($w) => $w->whereNull('estado')->orWhere('estado', '<>', CartaPagamentoBancario::ANULADA)), 'cartas_activas')
            ->orderByDesc('data_documento')->orderByDesc('id');

        $pagina = $q->paginate(min(max((int) ($f['por_pagina'] ?? 20), 1), 100));
        $codigos = $pagina->getCollection()->pluck('conta_financeira')->unique()->values()->all();
        $contas = PlanoConta::query()->whereIn('codigo', $codigos)->get(['codigo', 'descricao', 'banco_nome', 'banco_iban'])->keyBy('codigo');
        $pagina->getCollection()->transform(function (DocumentoTesouraria $d) use ($contas) {
            $c = $contas[$d->conta_financeira] ?? null;

            return $d->toArray() + ['tipo_carta' => $d->periodo_processamento_salarial_id ? CartaPagamentoBancario::TIPO_SALARIOS : CartaPagamentoBancario::TIPO_TERCEIRO,
                'conta_descricao' => $c?->descricao, 'banco_nome' => $c?->banco_nome, 'conta_com_iban' => trim((string) $c?->banco_iban) !== ''];
        });

        return $pagina;
    }

    /**
     * Pode este pagamento emitir carta? Devolve o motivo (e a sugestão) quando não pode, a conta emissora, o tipo, os
     * beneficiários (com e sem conta), o valor ainda disponível e o modelo de texto por omissão.
     *
     * @return array<string, mixed>
     */
    public function elegibilidade(DocumentoTesouraria $doc): array
    {
        $base = ['pode' => false, 'documento' => $this->resumoDocumento($doc), 'tipo' => $this->tipoDoDocumento($doc)];
        if ($doc->tipo !== 'PAGAMENTO') {
            return $base + $this->motivo('DOCUMENTO_NAO_PAGAMENTO', 'A carta de ordem de pagamento só se emite a partir de um pagamento.');
        }
        if ($doc->estado === 'ANULADO') {
            return $base + $this->motivo('DOCUMENTO_ANULADO', "O pagamento {$doc->numero_documento} está anulado: não pode emitir cartas.");
        }
        try {
            $conta = $this->contasBancarias->dados((string) $doc->conta_financeira);
        } catch (ErroNegocio $e) {
            return $base + $this->motivo('CONTA_NAO_BANCARIA', "A conta {$doc->conta_financeira} não é uma conta bancária (42 ou 43). A caixa paga-se em numerário, sem carta.");
        }
        $base['conta'] = $conta;
        if (! $conta['completa']) {
            return $base + $this->motivo('CONTA_SEM_IBAN', "A conta {$conta['codigo']} — {$conta['descricao']} ainda não tem IBAN.",
                'Preencha os dados bancários em Configurações › Plano de Contas, ao editar a conta.');
        }

        $beneficiarios = $this->beneficiarios($doc, $base['tipo']);
        $base['beneficiarios'] = $beneficiarios;
        $base['valor_documento'] = $this->valorDocumento($doc);
        $base['valor_em_cartas'] = $this->valorEmCartas($doc->id);
        $base['disponivel'] = bcsub($base['valor_documento'], $base['valor_em_cartas'], 2);
        $base['modelo'] = $this->modeloPara($base['tipo']);
        $base['local_padrao'] = $base['modelo']['local'] ?: $this->localEmpresa();

        if (! array_filter($beneficiarios, fn ($b) => $b['iban'])) {
            return $base + ($base['tipo'] === CartaPagamentoBancario::TIPO_SALARIOS
                ? $this->motivo('SEM_BENEFICIARIOS_COM_CONTA', 'Nenhum colaborador deste processamento tem IBAN configurado.', 'Configure os IBAN em RH › Dados Bancários.')
                : $this->motivo('SEM_BENEFICIARIOS_COM_CONTA', $beneficiarios ? 'Nenhum terceiro deste pagamento tem conta bancária mapeada.' : 'As linhas deste pagamento não têm terceiros a pagar.',
                    'Mapeie a conta bancária em Configurações › Tabelas Auxiliares › Terceiros (Contas bancárias).'));
        }
        if (bccomp($base['disponivel'], '0', 2) <= 0) {
            return $base + $this->motivo('SEM_VALOR_DISPONIVEL', "O valor do pagamento {$doc->numero_documento} já está todo em cartas activas.", 'Anule a carta anterior se precisar de a refazer.');
        }

        return ['pode' => true] + $base;
    }

    // ------------------------------------------------------------------ emissão

    /**
     * Emite a carta a partir do pagamento gravado. Os valores, o IBAN e os nomes são recalculados aqui; do pedido só vem a
     * escolha dos beneficiários (e, para terceiros, a conta bancária alternativa) e o texto.
     *
     * @param  array{beneficiarios: list<array{terceiro_id?: ?int, colaborador_id?: ?int, conta_bancaria_id?: ?int}>, modelo_id?: ?int, assunto?: ?string,
     *     corpo?: ?string, nome_assinatura?: ?string, cargo_assinatura?: ?string, local?: ?string, data?: ?string, confirmar_repetidos?: ?bool}  $d
     */
    public function emitir(DocumentoTesouraria $doc, array $d): CartaPagamentoBancario
    {
        $empresa = $this->contexto->obrigatorio();

        return DB::transaction(function () use ($doc, $d, $empresa) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ["cartas_pagamento:{$empresa}"]);
            $doc = DocumentoTesouraria::query()->lockForUpdate()->findOrFail($doc->id);
            $el = $this->elegibilidade($doc);
            if (! $el['pode']) {
                throw new ErroNegocio($el['motivo'].(! empty($el['sugestao']) ? ' '.$el['sugestao'] : ''), $el['codigo'], 422);
            }
            $tipo = $el['tipo'];
            $linhas = $this->escolher($el['beneficiarios'], $d['beneficiarios'] ?? [], $tipo);

            $repetidos = array_values(array_filter($linhas, fn ($l) => $l['ja_na_carta']));
            if ($repetidos && empty($d['confirmar_repetidos'])) {
                throw new ErroNegocio(count($repetidos).' beneficiário(s) já consta(m) de outra carta activa: '
                    .implode(', ', array_map(fn ($l) => "{$l['nome']} ({$l['ja_na_carta']})", $repetidos)).'. Confirme para incluir mesmo assim.',
                    'BENEFICIARIO_REPETIDO', 422, ['beneficiarios' => array_map(fn ($l) => ['nome' => $l['nome'], 'carta' => $l['ja_na_carta']], $repetidos)]);
            }
            $total = array_reduce($linhas, fn ($s, $l) => bcadd($s, $l['valor'], 2), '0.00');
            if (bccomp($total, $el['disponivel'], 2) > 0) {
                throw new ErroNegocio("O total da carta ({$total}) excede o valor do pagamento ainda sem carta ({$el['disponivel']}).", 'CARTA_EXCEDE_PAGAMENTO', 422,
                    ['total' => $total, 'disponivel' => $el['disponivel']]);
            }

            $modelo = $this->modeloPara($tipo, isset($d['modelo_id']) ? (int) $d['modelo_id'] : null);
            $data = substr((string) ($d['data'] ?? $doc->data_documento->toDateString()), 0, 10);
            $ano = (int) substr($data, 0, 4);
            $sequencia = (int) CartaPagamentoBancario::query()->where('ano', $ano)->max('sequencia') + 1;
            $conta = $el['conta'];
            $periodo = $doc->periodo_processamento_salarial_id ? PeriodoProcessamentoSalarial::query()->find($doc->periodo_processamento_salarial_id) : null;
            $texto = fn (string $campo) => array_key_exists($campo, $d) && $d[$campo] !== null ? trim((string) $d[$campo]) : ($modelo[$campo] ?? '');

            $carta = CartaPagamentoBancario::create([
                'tipo' => $tipo, 'numero' => sprintf('CP %d/%03d', $ano, $sequencia), 'sequencia' => $sequencia, 'ano' => $ano,
                'estado' => CartaPagamentoBancario::EMITIDA, 'data' => $data, 'documento_tesouraria_id' => $doc->id,
                'periodo_processamento_salarial_id' => $periodo?->id, 'mes_ano' => $periodo?->mes_ano ?? substr($data, 0, 7),
                'referencia_movimento' => mb_substr((string) ($doc->numero_documento ?: ($doc->referencia ?: "TES-{$doc->id}")), 0, 50),
                // fotografia da conta emissora: alterar o plano mais tarde não muda o que foi enviado ao banco
                'codigo_conta_bancaria' => $conta['codigo'], 'banco_nome' => $conta['banco_nome'], 'banco_balcao' => $conta['banco_balcao'],
                'banco_numero_conta' => $conta['banco_numero_conta'], 'banco_iban' => $conta['banco_iban'], 'banco_swift' => $conta['banco_swift'],
                'banco_titular' => $conta['titular_efectivo'] !== null ? mb_substr((string) $conta['titular_efectivo'], 0, 150) : null,
                'codigo_moeda' => $doc->codigo_moeda ?: ($conta['codigo_moeda'] ?? 'AOA'),
                'assunto' => mb_substr($texto('assunto'), 0, 100), 'corpo' => $texto('corpo'),
                'nome_assinatura' => $texto('nome_assinatura') ?: null, 'cargo_assinatura' => mb_substr($texto('cargo_assinatura'), 0, 100) ?: null,
                'local' => ($texto('local') ?: $this->localEmpresa()) ?: null,
                'montante_total' => $total, 'criado_por' => Auth::user()?->nome_utilizador,
            ]);
            foreach ($linhas as $l) {
                ItemCartaPagamento::create([
                    'carta_pagamento_bancario_id' => $carta->id, 'colaborador_id' => $l['colaborador_id'], 'terceiro_id' => $l['terceiro_id'],
                    'nome' => mb_substr((string) $l['nome'], 0, 255), 'nif' => $l['nif'] !== null ? mb_substr((string) $l['nif'], 0, 30) : null,
                    'banco' => $l['banco'] !== null ? mb_substr((string) $l['banco'], 0, 100) : null, 'iban' => $l['iban'],
                    'numero_conta' => $l['numero_conta'] !== null ? mb_substr((string) $l['numero_conta'], 0, 40) : null,
                    'titular' => $l['titular'] !== null ? mb_substr((string) $l['titular'], 0, 150) : null,
                    'referencia' => $l['referencia'] !== null ? mb_substr((string) $l['referencia'], 0, 100) : null, 'montante' => $l['valor'],
                ]);
            }

            return $carta->refresh();
        });
    }

    /** As cartas não se apagam: anulam-se com motivo (pode já ter seguido para o banco). */
    public function anular(CartaPagamentoBancario $carta, string $motivo): CartaPagamentoBancario
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 3) {
            throw new ErroNegocio('Indique o motivo da anulação.', 'MOTIVO_OBRIGATORIO', 422);
        }

        return DB::transaction(function () use ($carta, $motivo) {
            $carta = CartaPagamentoBancario::query()->lockForUpdate()->findOrFail($carta->id);
            if (! $carta->activa()) {
                throw new ErroNegocio('Esta carta já está anulada.', 'CARTA_ANULADA', 422);
            }
            $carta->update(['estado' => CartaPagamentoBancario::ANULADA, 'anulada_em' => now(), 'anulada_por' => Auth::user()?->nome_utilizador,
                'motivo_anulacao' => mb_substr($motivo, 0, 1000)]);

            return $carta->refresh();
        });
    }

    // ------------------------------------------------------------------ consulta

    /** @param  array{estado?: ?string, tipo?: ?string, ano?: ?int, documento_tesouraria_id?: ?int, q?: ?string, por_pagina?: ?int}  $f */
    public function listar(array $f): LengthAwarePaginator
    {
        $q = CartaPagamentoBancario::query()->withCount('itensCartaPagamento as n_beneficiarios')
            ->with('documentoTesouraria:id,numero_documento,estado')
            ->when($f['estado'] ?? null, fn ($w, $e) => $e === CartaPagamentoBancario::ANULADA ? $w->where('estado', $e)
                : $w->where(fn ($x) => $x->whereNull('estado')->orWhere('estado', '<>', CartaPagamentoBancario::ANULADA)))
            ->when($f['tipo'] ?? null, fn ($w, $t) => $t === CartaPagamentoBancario::TIPO_SALARIOS
                ? $w->where(fn ($x) => $x->where('tipo', $t)->orWhere(fn ($y) => $y->whereNull('tipo')->whereNotNull('periodo_processamento_salarial_id')))
                : $w->where('tipo', $t))
            ->when($f['ano'] ?? null, fn ($w, $a) => $w->where(fn ($x) => $x->where('ano', $a)->orWhere(fn ($y) => $y->whereNull('ano')->whereYear('data', $a))))
            ->when($f['documento_tesouraria_id'] ?? null, fn ($w, $id) => $w->where('documento_tesouraria_id', $id))
            ->when($f['q'] ?? null, function ($w, string $texto) {
                $like = '%'.addcslashes(trim($texto), '\\%_').'%';
                $w->where(fn ($x) => $x->where('numero', 'ilike', $like)->orWhere('referencia_movimento', 'ilike', $like)->orWhere('banco_nome', 'ilike', $like));
            })
            ->orderByDesc('data')->orderByDesc('ano')->orderByDesc('sequencia')->orderByDesc('id');

        $pagina = $q->paginate(min(max((int) ($f['por_pagina'] ?? 20), 1), 100));
        $pagina->getCollection()->transform(fn (CartaPagamentoBancario $c) => $this->resumo($c));

        return $pagina;
    }

    /** @return array<string, mixed> carta, linhas e dados para a impressão */
    public function detalhe(CartaPagamentoBancario $c): array
    {
        $itens = ItemCartaPagamento::query()->where('carta_pagamento_bancario_id', $c->id)->orderBy('id')->get();
        $nomes = Colaborador::query()->withTrashed()->whereIn('id', $itens->pluck('colaborador_id')->filter()->all())->pluck('nome_completo', 'id');
        $dados = $this->resumo($c);
        // cartas antigas do RH (sem fotografia da conta): mostram os dados bancários actuais da conta, assinalados
        if (! $c->banco_iban && $c->codigo_conta_bancaria) {
            try {
                $conta = $this->contasBancarias->dados((string) $c->codigo_conta_bancaria);
                $dados = array_merge($dados, ['banco_nome' => $conta['banco_nome'], 'banco_balcao' => $conta['banco_balcao'], 'banco_numero_conta' => $conta['banco_numero_conta'],
                    'banco_iban' => $conta['banco_iban'], 'banco_swift' => $conta['banco_swift'], 'banco_titular' => $conta['titular_efectivo'], 'dados_banco_actuais' => true]);
            } catch (ErroNegocio) {
                // conta já não é bancária: fica sem cabeçalho bancário
            }
        }
        $empresa = Empresa::query()->find($this->contexto->obrigatorio(), ['id', 'nome', 'nif', 'endereco', 'municipio', 'provincia', 'telefone', 'email']);

        return $dados + [
            'itens' => $itens->map(fn (ItemCartaPagamento $i) => $i->toArray() + ['nome' => $i->nome ?: ($nomes[$i->colaborador_id] ?? null)])->sortBy('nome', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            'empresa' => $empresa?->only(['nome', 'nif', 'endereco', 'municipio', 'provincia', 'telefone', 'email']),
        ];
    }

    // ------------------------------------------------------------------ modelos de texto

    /** @return array{modelos: list<array<string, mixed>>, tipos: array<string, string>, padrao: array<string, mixed>, variaveis: array<string, string>} */
    public function modelos(): array
    {
        return [
            'modelos' => ModeloCartaPagamento::query()->orderBy('tipo')->orderByDesc('predefinido')->orderBy('id')->get()
                ->map(fn (ModeloCartaPagamento $m) => $this->formatarModelo($m))->values()->all(),
            'tipos' => self::TIPOS, 'padrao' => self::MODELOS_PADRAO, 'variaveis' => self::VARIAVEIS,
        ];
    }

    /** @param  array<string, mixed>  $d */
    public function gravarModelo(array $d, ?ModeloCartaPagamento $m = null): ModeloCartaPagamento
    {
        $empresa = $this->contexto->obrigatorio();
        $tipo = $m ? (string) $m->tipo : (string) $d['tipo'];
        $texto = fn (string $c) => isset($d[$c]) && trim((string) $d[$c]) !== '' ? trim((string) $d[$c]) : null;

        return DB::transaction(function () use ($d, $m, $tipo, $texto, $empresa) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ["modelos_cartas:{$empresa}:{$tipo}"]);
            $existe = ModeloCartaPagamento::query()->where('tipo', $tipo)->when($m, fn ($q) => $q->whereKeyNot($m->id))->exists();
            $predefinido = ! $existe || ! empty($d['predefinido']) || (bool) $m?->predefinido;
            if ($predefinido && $existe) {
                ModeloCartaPagamento::query()->where('tipo', $tipo)->when($m, fn ($q) => $q->whereKeyNot($m->id))->where('predefinido', true)->update(['predefinido' => false]);
            }
            $m ??= new ModeloCartaPagamento(['tipo' => $tipo]);
            $m->fill(['assunto' => $texto('assunto'), 'corpo' => $texto('corpo'), 'nome_assinatura' => $texto('nome_assinatura'),
                'cargo_assinatura' => $texto('cargo_assinatura'), 'local' => $texto('local')])
                ->forceFill(['nome' => $texto('nome'), 'predefinido' => $predefinido])->save();

            return $m->refresh();
        });
    }

    public function predefinirModelo(ModeloCartaPagamento $m): ModeloCartaPagamento
    {
        return DB::transaction(function () use ($m) {
            ModeloCartaPagamento::query()->where('tipo', $m->tipo)->whereKeyNot($m->id)->where('predefinido', true)->update(['predefinido' => false]);
            $m->forceFill(['predefinido' => true])->save();

            return $m->refresh();
        });
    }

    public function eliminarModelo(ModeloCartaPagamento $m): void
    {
        DB::transaction(function () use ($m) {
            $era = (bool) $m->predefinido;
            $tipo = $m->tipo;
            $m->delete();
            if ($era) {   // o tipo continua a ter um predefinido (o mais antigo que restar)
                ModeloCartaPagamento::query()->where('tipo', $tipo)->orderBy('id')->first()?->forceFill(['predefinido' => true])->save();
            }
        });
    }

    /**
     * Texto a usar num tipo: o modelo indicado, o predefinido, o mais antigo, ou o texto padrão.
     *
     * @return array{id: ?int, nome: ?string, assunto: string, corpo: string, nome_assinatura: string, cargo_assinatura: string, local: string}
     */
    public function modeloPara(string $tipo, ?int $id = null): array
    {
        $m = $id !== null
            ? (ModeloCartaPagamento::query()->where('tipo', $tipo)->find($id) ?? throw new ErroNegocio('O modelo de texto indicado não existe para este tipo de carta.', 'MODELO_INEXISTENTE', 422))
            : ModeloCartaPagamento::query()->where('tipo', $tipo)->orderByDesc('predefinido')->orderBy('id')->first();
        $p = self::MODELOS_PADRAO[$tipo] ?? self::MODELOS_PADRAO[CartaPagamentoBancario::TIPO_TERCEIRO];

        return ['id' => $m?->id, 'nome' => $m?->getAttribute('nome'), 'assunto' => $m?->assunto ?: $p['assunto'], 'corpo' => $m?->corpo ?: $p['corpo'],
            'nome_assinatura' => (string) ($m?->nome_assinatura ?? ''), 'cargo_assinatura' => $m?->cargo_assinatura ?: $p['cargo_assinatura'], 'local' => (string) ($m?->local ?? '')];
    }

    // ------------------------------------------------------------------ internos

    /** @return list<array<string, mixed>> */
    private function beneficiarios(DocumentoTesouraria $doc, string $tipo): array
    {
        return $tipo === CartaPagamentoBancario::TIPO_SALARIOS ? $this->beneficiariosSalarios($doc) : $this->beneficiariosTerceiros($doc);
    }

    /**
     * Uma linha por terceiro: num pagamento, o débito na conta do terceiro é o que lhe está a ser pago (os créditos abatem).
     * Valor na moeda do documento (valor introduzido; nos documentos migrados, valor em moeda ou em Kz).
     *
     * @return list<array<string, mixed>>
     */
    private function beneficiariosTerceiros(DocumentoTesouraria $doc): array
    {
        $estrangeira = $doc->codigo_moeda && $doc->codigo_moeda !== 'AOA';
        $agregado = [];
        foreach (ItemDocumentoTesouraria::query()->where('documento_tesouraria_id', $doc->id)->whereNotNull('terceiro_id')->orderBy('id')->get() as $it) {
            $valor = (string) ($it->valor_introduzido ?? ($estrangeira ? ($it->valor_moeda ?? $it->valor) : $it->valor));
            $k = (int) $it->terceiro_id;
            $agregado[$k] ??= ['valor' => '0.00', 'docs' => []];
            $agregado[$k]['valor'] = $it->tipo_dc === 'C' ? bcsub($agregado[$k]['valor'], $valor, 2) : bcadd($agregado[$k]['valor'], $valor, 2);
            if ($it->numero_documento) {
                $agregado[$k]['docs'][] = (string) $it->numero_documento;
            }
        }
        $agregado = array_filter($agregado, fn ($a) => bccomp($a['valor'], '0', 2) > 0);
        if (! $agregado) {
            return [];
        }
        $ids = array_keys($agregado);
        $terceiros = Terceiro::query()->whereIn('id', $ids)->get(['id', 'nome', 'nif'])->keyBy('id');
        $contas = ContaBancariaTerceiro::query()->whereIn('terceiro_id', $ids)->orderByDesc('predefinida')->orderBy('id')->get()->groupBy('terceiro_id');
        $jaPago = $this->jaEmCarta('terceiro_id', fn ($q) => $q->where('c.documento_tesouraria_id', $doc->id));

        $linhas = [];
        foreach ($agregado as $id => $a) {
            $t = $terceiros[$id] ?? null;
            $lista = $contas[$id] ?? collect();
            $pref = $lista->first();
            $linhas[] = ['tipo' => CartaPagamentoBancario::TIPO_TERCEIRO, 'terceiro_id' => $id, 'colaborador_id' => null,
                'nome' => trim((string) ($t?->nome ?? "Terceiro #{$id}")), 'nif' => $t?->nif, 'valor' => $a['valor'],
                'referencia' => implode(', ', array_unique($a['docs'])) ?: null,
                'conta_bancaria_id' => $pref?->id, 'banco' => $pref?->banco, 'iban' => $pref?->iban ?: null, 'numero_conta' => $pref?->numero_conta, 'titular' => $pref?->titular,
                'contas' => $lista->map(fn (ContaBancariaTerceiro $c) => ['id' => $c->id, 'banco' => $c->banco, 'iban' => $c->iban, 'numero_conta' => $c->numero_conta,
                    'titular' => $c->titular, 'codigo_moeda' => $c->codigo_moeda, 'predefinida' => (bool) $c->predefinida])->values()->all(),
                'ja_na_carta' => $jaPago[$id] ?? null];
        }
        usort($linhas, fn ($x, $y) => strnatcasecmp($x['nome'], $y['nome']));

        return $linhas;
    }

    /** @return list<array<string, mixed>> colaboradores do período pago, com o líquido e as coordenadas bancárias */
    private function beneficiariosSalarios(DocumentoTesouraria $doc): array
    {
        $periodo = PeriodoProcessamentoSalarial::query()->find($doc->periodo_processamento_salarial_id);
        if (! $periodo) {
            return [];
        }
        $resultados = ResultadoFolhaSalarial::query()->where('periodo_processamento_salarial_id', $periodo->id)->where('liquido', '>', 0)->get();
        $ids = $resultados->pluck('colaborador_id')->all();
        $colaboradores = Colaborador::query()->withTrashed()->whereIn('id', $ids)->get(['id', 'nome_completo', 'nif'])->keyBy('id');
        $coordenadas = CoordenadaBancariaColaborador::query()->with('banco:id,nome')->whereIn('colaborador_id', $ids)->get()->keyBy('colaborador_id');
        $jaPago = $this->jaEmCarta('colaborador_id', fn ($q) => $q->where('c.periodo_processamento_salarial_id', $periodo->id));

        $linhas = [];
        foreach ($resultados as $r) {
            $cb = $coordenadas[$r->colaborador_id] ?? null;
            $iban = $cb && trim((string) $cb->iban) !== '' ? ServicoColaboradores::normalizarIban((string) $cb->iban) : null;
            $col = $colaboradores[$r->colaborador_id] ?? null;
            $linhas[] = ['tipo' => CartaPagamentoBancario::TIPO_SALARIOS, 'terceiro_id' => null, 'colaborador_id' => (int) $r->colaborador_id,
                'nome' => trim((string) ($col?->nome_completo ?? "Colaborador #{$r->colaborador_id}")), 'nif' => $col?->nif,
                'valor' => number_format((float) $r->liquido, 2, '.', ''), 'avencado' => (bool) $r->avencado, 'referencia' => $periodo->mes_ano,
                'conta_bancaria_id' => null, 'banco' => $cb?->banco?->nome, 'iban' => $iban, 'numero_conta' => null, 'titular' => null, 'contas' => [],
                'ja_na_carta' => $jaPago[(int) $r->colaborador_id] ?? null];
        }
        usort($linhas, fn ($x, $y) => strnatcasecmp($x['nome'], $y['nome']));

        return $linhas;
    }

    /** @return array<int, string> beneficiário → número da carta activa em que já está */
    private function jaEmCarta(string $coluna, callable $filtro): array
    {
        $q = ItemCartaPagamento::query()->join('cartas_pagamento_bancario as c', 'c.id', '=', 'itens_carta_pagamento.carta_pagamento_bancario_id')
            ->whereNotNull("itens_carta_pagamento.{$coluna}")
            ->where(fn ($w) => $w->whereNull('c.estado')->orWhere('c.estado', '<>', CartaPagamentoBancario::ANULADA));
        $filtro($q);

        return $q->get(["itens_carta_pagamento.{$coluna} as beneficiario", 'c.numero', 'c.id as carta_id'])
            ->mapWithKeys(fn ($l) => [(int) $l->beneficiario => $l->numero ?: "#{$l->carta_id}"])->all();
    }

    /**
     * Valida a escolha do pedido contra os beneficiários elegíveis (nada de valores vindos do ecrã).
     *
     * @param  list<array<string, mixed>>  $elegiveis
     * @param  list<array<string, mixed>>  $escolha
     * @return list<array<string, mixed>>
     */
    private function escolher(array $elegiveis, array $escolha, string $tipo): array
    {
        if (! $escolha) {
            throw new ErroNegocio('Escolha pelo menos um beneficiário.', 'SEM_BENEFICIARIOS', 422);
        }
        $chave = $tipo === CartaPagamentoBancario::TIPO_SALARIOS ? 'colaborador_id' : 'terceiro_id';
        $porId = collect($elegiveis)->keyBy($chave);
        $linhas = [];
        $semConta = [];
        foreach ($escolha as $e) {
            $id = isset($e[$chave]) ? (int) $e[$chave] : 0;
            $b = $porId[$id] ?? throw new ErroNegocio($tipo === CartaPagamentoBancario::TIPO_SALARIOS
                ? 'Há colaboradores escolhidos que não têm salário líquido no período deste pagamento.'
                : 'Há terceiros escolhidos que não constam das linhas deste pagamento.', 'BENEFICIARIO_INVALIDO', 422, [$chave => $id]);
            if (isset($linhas[$id])) {
                continue;
            }
            if ($tipo === CartaPagamentoBancario::TIPO_TERCEIRO && ! empty($e['conta_bancaria_id'])) {
                $conta = collect($b['contas'])->firstWhere('id', (int) $e['conta_bancaria_id'])
                    ?? throw new ErroNegocio("A conta bancária escolhida não pertence a {$b['nome']}.", 'CONTA_BANCARIA_DE_OUTRO', 422);
                $b = array_merge($b, ['conta_bancaria_id' => $conta['id'], 'banco' => $conta['banco'], 'iban' => $conta['iban'] ?: null,
                    'numero_conta' => $conta['numero_conta'], 'titular' => $conta['titular']]);
            }
            if (! $b['iban']) {
                $semConta[] = $b['nome'];
            }
            $linhas[$id] = $b;
        }
        if ($semConta) {
            throw new ErroNegocio(($tipo === CartaPagamentoBancario::TIPO_SALARIOS ? 'Colaboradores sem IBAN: ' : 'Terceiros sem conta bancária: ').implode(', ', $semConta).'. '
                .($tipo === CartaPagamentoBancario::TIPO_SALARIOS ? 'Registe as coordenadas bancárias em RH › Dados Bancários.' : 'Registe a conta bancária na ficha do terceiro (Contas bancárias).'),
                $tipo === CartaPagamentoBancario::TIPO_SALARIOS ? 'COLABORADOR_SEM_IBAN' : 'BENEFICIARIO_SEM_CONTA', 422, ['beneficiarios' => $semConta]);
        }

        return array_values($linhas);
    }

    private function tipoDoDocumento(DocumentoTesouraria $doc): string
    {
        return $doc->periodo_processamento_salarial_id ? CartaPagamentoBancario::TIPO_SALARIOS : CartaPagamentoBancario::TIPO_TERCEIRO;
    }

    /** Valor do pagamento na moeda do documento (a da conta bancária). */
    private function valorDocumento(DocumentoTesouraria $doc): string
    {
        $estrangeira = $doc->codigo_moeda && $doc->codigo_moeda !== 'AOA';

        return number_format((float) ($estrangeira && $doc->valor_total_moeda !== null ? $doc->valor_total_moeda : $doc->valor_total), 2, '.', '');
    }

    private function valorEmCartas(int $docId): string
    {
        return number_format((float) CartaPagamentoBancario::query()->where('documento_tesouraria_id', $docId)
            ->where(fn ($w) => $w->whereNull('estado')->orWhere('estado', '<>', CartaPagamentoBancario::ANULADA))->sum('montante_total'), 2, '.', '');
    }

    /** @return array<string, mixed> */
    private function resumoDocumento(DocumentoTesouraria $doc): array
    {
        return $doc->only(['id', 'numero_documento', 'tipo', 'estado', 'data_documento', 'conta_financeira', 'descricao', 'referencia', 'valor_total', 'valor_total_moeda',
            'codigo_moeda', 'periodo_processamento_salarial_id']);
    }

    /** @return array<string, mixed> */
    private function resumo(CartaPagamentoBancario $c): array
    {
        $doc = $c->relationLoaded('documentoTesouraria') ? $c->documentoTesouraria
            : ($c->documento_tesouraria_id ? DocumentoTesouraria::query()->find($c->documento_tesouraria_id, ['id', 'numero_documento', 'estado']) : null);

        return $c->toArray() + [
            'tipo_efectivo' => $c->tipo ?: ($c->periodo_processamento_salarial_id ? CartaPagamentoBancario::TIPO_SALARIOS : CartaPagamentoBancario::TIPO_TERCEIRO),
            'estado_efectivo' => $c->activa() ? CartaPagamentoBancario::EMITIDA : CartaPagamentoBancario::ANULADA,
            'documento' => $doc?->only(['id', 'numero_documento', 'estado']),
        ];
    }

    /** @return array<string, mixed> */
    private function formatarModelo(ModeloCartaPagamento $m): array
    {
        return $m->toArray() + ['nome' => $m->getAttribute('nome'), 'predefinido' => (bool) $m->getAttribute('predefinido')];
    }

    /** @return array{codigo: string, motivo: string, sugestao: ?string} */
    private function motivo(string $codigo, string $motivo, ?string $sugestao = null): array
    {
        return ['codigo' => $codigo, 'motivo' => $motivo, 'sugestao' => $sugestao];
    }

    private function localEmpresa(): string
    {
        $e = Empresa::query()->find($this->contexto->obrigatorio(), ['id', 'municipio', 'provincia']);

        return trim((string) ($e?->municipio ?: $e?->provincia ?: ''));
    }
}
