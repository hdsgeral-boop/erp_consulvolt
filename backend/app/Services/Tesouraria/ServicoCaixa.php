<?php

namespace App\Services\Tesouraria;

use App\Exceptions\ErroNegocio;
use App\Models\LiquidacaoPOS;
use App\Models\MovimentoCaixa;
use App\Models\SessaoCaixa;
use App\Services\Contabilidade\LocalizadorLancamentos;
use App\Services\Contabilidade\ServicoExercicios;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Services\Contabilidade\ServicoPlanoContas;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Folha de caixa (js/ui_folha_caixa.js do legado), corrigida:
 *   - uma sessão ABERTA por CONTA de caixa, garantida com lock (o legado: uma por empresa e duplicável com duplo clique);
 *   - movimentos com contrapartida de movimento, exercício aberto e — quando liquidam uma factura — valor ≤ saldo em
 *     aberto, descontando também os movimentos de caixa ainda por contabilizar de QUALQUER sessão;
 *   - fecho grava a diferença (físico − sistema) e a contabilização lança-a (sobras/quebras pela configuração;
 *     o legado não gravava nem lançava a diferença);
 *   - contabilização numa transacção, um lançamento por data de movimento (o legado: um por linha, datado da abertura),
 *     via ServicoLancamentos; actualiza vendas/facturas liquidadas; descontabilizar = estorno (o legado não tinha).
 */
final class ServicoCaixa
{
    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoExercicios $exercicios,
        private readonly ServicoPlanoContas $plano,
        private readonly ServicoLancamentos $lancamentos,
        private readonly LocalizadorLancamentos $localizador,
        private readonly ServicoPendentes $pendentes,
        private readonly ServicoLiquidacoes $liquidacoes,
        private readonly ServicoConfigTesouraria $config,
    ) {}

    public function abrir(string $conta, string $data, ?string $saldoAbertura = null): array
    {
        $empresa = $this->contexto->obrigatorio();
        $this->exigirContaCaixa($conta);
        $this->exercicios->exigirAberto($empresa, $data);

        return DB::transaction(function () use ($conta, $data, $saldoAbertura, $empresa) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ["caixa:{$empresa}:{$conta}"]);
            if (SessaoCaixa::query()->where('codigo_conta', $conta)->where('estado', 'ABERTA')->exists()) {
                throw new ErroNegocio("Já existe uma sessão aberta na caixa {$conta}: feche-a primeiro.", 'SESSAO_JA_ABERTA', 422);
            }
            $ultima = SessaoCaixa::query()->where('codigo_conta', $conta)->whereIn('estado', ['FECHADA', 'CONTABILIZADA'])->orderByDesc('data_fecho')->orderByDesc('id')->first();
            $sugerido = number_format((float) ($ultima?->saldo_fisico ?? $ultima?->saldo_fecho ?? 0), 2, '.', '');
            $abertura = $saldoAbertura !== null ? number_format((float) $saldoAbertura, 2, '.', '') : $sugerido;
            $sessao = SessaoCaixa::create(['codigo_conta' => $conta, 'operador' => Auth::user()?->nome_utilizador, 'data_abertura' => $data,
                'saldo_abertura' => $abertura, 'estado' => 'ABERTA', 'codigo_moeda' => 'AOA']);
            $aviso = bccomp($abertura, $sugerido, 2) !== 0 ? "O saldo de abertura ({$abertura}) difere da contagem do último fecho ({$sugerido})." : null;

            return ['sessao' => $sessao, 'aviso' => $aviso];
        });
    }

    /** @param  array{tipo: string, data_documento: string, conta_contrapartida: string, valor: float|string, descricao: string, terceiro_id?: ?int, numero_documento?: ?string, venda_id?: ?int, fatura_compra_id?: ?int}  $d */
    public function registarMovimento(SessaoCaixa $sessao, array $d): MovimentoCaixa
    {
        $empresa = $this->contexto->obrigatorio();
        $data = substr($d['data_documento'], 0, 10);
        $this->exercicios->exigirAberto($empresa, $data);
        $this->plano->contaDeMovimento($d['conta_contrapartida']);
        $valor = number_format((float) $d['valor'], 2, '.', '');
        if (bccomp($valor, '0', 2) <= 0) {
            throw new ErroNegocio('O valor tem de ser positivo.', 'VALOR_INVALIDO', 422);
        }
        $numero = $this->liquidacoes->validarLigacao($d['venda_id'] ?? null, $d['fatura_compra_id'] ?? null, $d['terceiro_id'] ?? null, 'Movimento:')
            ?? ($d['numero_documento'] ?? null);

        return DB::transaction(function () use ($sessao, $d, $data, $valor, $numero) {
            $sessao = SessaoCaixa::query()->lockForUpdate()->findOrFail($sessao->id);
            if ($sessao->estado !== 'ABERTA') {
                throw new ErroNegocio('A sessão de caixa não está aberta.', 'SESSAO_NAO_ABERTA', 422);
            }
            if ($data < $sessao->data_abertura->toDateString()) {
                throw new ErroNegocio('A data do movimento é anterior à abertura da sessão.', 'DATA_INVALIDA', 422);
            }
            $rec = $d['tipo'] === 'REC';
            if ($numero && ! empty($d['terceiro_id'])) {
                $aberto = $this->pendentes->saldo((int) $d['terceiro_id'], $d['conta_contrapartida'], $numero);
                if ($aberto && bccomp($valor, bcadd($aberto['saldo'], '0.01', 2), 2) > 0) {
                    throw new ErroNegocio("O valor ({$valor}) excede o saldo em aberto do documento {$numero} ({$aberto['saldo']}).", 'VALOR_SUPERIOR_EM_ABERTO', 422);
                }
                if ($aberto && $aberto['liquidar_a'] !== ($rec ? 'C' : 'D')) {
                    throw new ErroNegocio("O documento {$numero} é ".($aberto['natureza'] === 'A_RECEBER' ? 'a receber' : 'a pagar').': sentido do movimento errado.', 'SENTIDO_INVALIDO', 422);
                }
            }

            return MovimentoCaixa::create([
                'sessao_caixa_id' => $sessao->id, 'tipo' => $d['tipo'], 'data_documento' => $data, 'numero_documento' => $numero, 'referencia' => $d['referencia'] ?? null,
                'terceiro_id' => $d['terceiro_id'] ?? null, 'conta_debito' => $rec ? $sessao->codigo_conta : $d['conta_contrapartida'],
                'conta_credito' => $rec ? $d['conta_contrapartida'] : $sessao->codigo_conta, 'descricao' => $d['descricao'], 'valor' => $valor,
                'unidade_negocio_id' => $d['unidade_negocio_id'] ?? null, 'centro_custo_id' => $d['centro_custo_id'] ?? null,
                'tipo_origem' => ($d['venda_id'] ?? null) || ($d['fatura_compra_id'] ?? null) ? 'CONTABILIDADE' : 'MANUAL', 'contabilizado' => false,
                'codigo_moeda' => 'AOA', 'valor_kz' => $valor, 'venda_id' => $d['venda_id'] ?? null, 'fatura_compra_id' => $d['fatura_compra_id'] ?? null,
            ]);
        });
    }

    /** Remove um movimento de uma sessão ABERTA (rascunho; fica no registo de auditoria). */
    public function removerMovimento(MovimentoCaixa $m): void
    {
        DB::transaction(function () use ($m) {
            $sessao = SessaoCaixa::query()->lockForUpdate()->findOrFail($m->sessao_caixa_id);
            if ($sessao->estado !== 'ABERTA' || $m->contabilizado) {
                throw new ErroNegocio('Só se removem movimentos de sessões abertas.', 'SESSAO_NAO_ABERTA', 422);
            }
            if (LiquidacaoPOS::query()->where('movimento_caixa_id', $m->id)->where('estado', 'REGISTADO')->exists()) {
                throw new ErroNegocio('Movimento da prestação de contas do POS: anule-o na prestação de contas.', 'MOVIMENTO_POS', 422);
            }
            $m->delete();
        });
    }

    public function fechar(SessaoCaixa $sessao, string $saldoFisico, string $data): SessaoCaixa
    {
        return DB::transaction(function () use ($sessao, $saldoFisico, $data) {
            $sessao = SessaoCaixa::query()->lockForUpdate()->findOrFail($sessao->id);
            if ($sessao->estado !== 'ABERTA') {
                throw new ErroNegocio('A sessão não está aberta.', 'SESSAO_NAO_ABERTA', 422);
            }
            $ultimo = MovimentoCaixa::query()->where('sessao_caixa_id', $sessao->id)->max('data_documento');
            if ($data < $sessao->data_abertura->toDateString() || ($ultimo && $data < substr((string) $ultimo, 0, 10))) {
                throw new ErroNegocio('A data de fecho é anterior à abertura ou a movimentos da sessão.', 'DATA_INVALIDA', 422);
            }
            $sessao->update(['estado' => 'FECHADA', 'data_fecho' => $data, 'saldo_fecho' => $this->saldoSistema($sessao),
                'saldo_fisico' => number_format((float) $saldoFisico, 2, '.', ''), 'fechado_por' => Auth::user()?->nome_utilizador]);

            return $sessao;
        });
    }

    public function contabilizar(SessaoCaixa $sessao): SessaoCaixa
    {
        return DB::transaction(function () use ($sessao) {
            $sessao = SessaoCaixa::query()->lockForUpdate()->findOrFail($sessao->id);
            if ($sessao->estado !== 'FECHADA') {
                throw new ErroNegocio('Só se contabilizam sessões fechadas.', 'SESSAO_NAO_FECHADA', 422);
            }
            $movimentos = MovimentoCaixa::query()->where('sessao_caixa_id', $sessao->id)->orderBy('data_documento')->orderBy('id')->get();
            $diario = $this->localizador->diario('CX', 'Caixa');
            $numeros = [];
            foreach ($movimentos->groupBy(fn ($m) => $m->data_documento->toDateString()) as $data => $grupo) {
                $linhas = [];
                foreach ($grupo as $m) {
                    $comum = ['valor' => $m->valor, 'descricao' => mb_substr((string) $m->descricao, 0, 1000), 'terceiro_id' => $m->terceiro_id,
                        'unidade_negocio_id' => $m->unidade_negocio_id, 'centro_custo_id' => $m->centro_custo_id];
                    $linhas[] = ['codigo_conta' => $m->conta_debito, 'tipo_dc' => 'D', 'numero_documento' => $m->tipo === 'PAG' ? $m->numero_documento : null] + $comum;
                    $linhas[] = ['codigo_conta' => $m->conta_credito, 'tipo_dc' => 'C', 'numero_documento' => $m->tipo === 'REC' ? $m->numero_documento : null] + $comum;
                }
                $numeros[] = $this->lancamentos->criar(['diario_id' => $diario->id, 'data_documento' => $data, 'numero_documento' => "CX-{$sessao->id}-{$data}",
                    'descricao' => "Folha de caixa {$sessao->codigo_conta} — sessão {$sessao->id}", 'tipo_origem' => 'CAIXA', 'linhas' => $linhas])->first()->numero_lan;
            }
            $diferenca = bcsub((string) $sessao->saldo_fisico, (string) $sessao->saldo_fecho, 2);
            if (bccomp($diferenca, '0', 2) !== 0) {
                $sobra = bccomp($diferenca, '0', 2) > 0;
                $valor = ltrim($diferenca, '-');
                $contra = $sobra ? $this->config->exigir('caixa_sobras', 'Há uma sobra de caixa a lançar.') : $this->config->exigir('caixa_quebras', 'Há uma quebra de caixa a lançar.');
                $numeros[] = $this->lancamentos->criar(['diario_id' => $diario->id, 'data_documento' => $sessao->data_fecho->toDateString(),
                    'numero_documento' => "CX-{$sessao->id}-DIF", 'descricao' => ($sobra ? 'Sobra' : 'Quebra')." de caixa no fecho da sessão {$sessao->id}", 'tipo_origem' => 'CAIXA',
                    'linhas' => [['codigo_conta' => $sobra ? $sessao->codigo_conta : $contra, 'tipo_dc' => 'D', 'valor' => $valor],
                        ['codigo_conta' => $sobra ? $contra : $sessao->codigo_conta, 'tipo_dc' => 'C', 'valor' => $valor]]])->first()->numero_lan;
            }
            MovimentoCaixa::query()->where('sessao_caixa_id', $sessao->id)->update(['contabilizado' => true]);
            $sessao->update(['estado' => 'CONTABILIZADA', 'numeros_lan_contabilizacao' => $numeros, 'contabilizado_em' => now()]);
            $this->liquidacoes->aplicar($this->ligacoes($movimentos), +1);

            return $sessao;
        });
    }

    public function descontabilizar(SessaoCaixa $sessao, string $motivo): SessaoCaixa
    {
        return DB::transaction(function () use ($sessao, $motivo) {
            $sessao = SessaoCaixa::query()->lockForUpdate()->findOrFail($sessao->id);
            if ($sessao->estado !== 'CONTABILIZADA') {
                throw new ErroNegocio('A sessão não está contabilizada.', 'SESSAO_NAO_CONTABILIZADA', 422);
            }
            $numeros = $sessao->numeros_lan_contabilizacao ?: [];
            if (! $numeros) {
                throw new ErroNegocio('Sessão contabilizada no legado (um lançamento por linha, sem ligação): estorne os lançamentos na Contabilidade.', 'SESSAO_LEGADO', 422);
            }
            foreach ($numeros as $n) {
                $this->lancamentos->estornar($this->localizador->localizar($n, ''), $motivo);
            }
            MovimentoCaixa::query()->where('sessao_caixa_id', $sessao->id)->update(['contabilizado' => false]);
            $sessao->update(['estado' => 'FECHADA', 'numeros_lan_contabilizacao' => null, 'contabilizado_em' => null]);
            $this->liquidacoes->aplicar($this->ligacoes(MovimentoCaixa::query()->where('sessao_caixa_id', $sessao->id)->get()), -1);

            return $sessao;
        });
    }

    public function eliminar(SessaoCaixa $sessao): void
    {
        if ($sessao->estado !== 'ABERTA' || MovimentoCaixa::query()->where('sessao_caixa_id', $sessao->id)->exists()) {
            throw new ErroNegocio('Só se elimina uma sessão aberta e sem movimentos.', 'SESSAO_NAO_ELIMINAVEL', 422);
        }
        $sessao->delete();
    }

    public function saldoSistema(SessaoCaixa $sessao): string
    {
        $s = MovimentoCaixa::query()->where('sessao_caixa_id', $sessao->id)->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'REC' THEN valor ELSE -valor END), 0) AS s")->value('s');

        return bcadd((string) $sessao->saldo_abertura, number_format((float) $s, 2, '.', ''), 2);
    }

    private function ligacoes($movimentos): array
    {
        return $movimentos->filter(fn ($m) => $m->venda_id || $m->fatura_compra_id)
            ->map(fn ($m) => ['venda_id' => $m->venda_id, 'fatura_compra_id' => $m->fatura_compra_id, 'tipo_dc' => $m->tipo === 'REC' ? 'C' : 'D', 'valor' => $m->valor])->values()->all();
    }

    private function exigirContaCaixa(string $conta): void
    {
        $c = $this->plano->contaDeMovimento($conta);
        if (! str_starts_with($conta, '45')) {
            throw new ErroNegocio('A folha de caixa usa contas de caixa (45).', 'CONTA_NAO_CAIXA', 422);
        }
        if (($c['codigo_moeda'] ?? null) && $c['codigo_moeda'] !== 'AOA') {
            throw new ErroNegocio("A caixa {$conta} é em {$c['codigo_moeda']}: caixas em moeda estrangeira chegam com a multi-moeda.", 'MOEDA_NAO_SUPORTADA', 422);
        }
    }
}
