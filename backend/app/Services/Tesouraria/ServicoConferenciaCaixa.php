<?php

namespace App\Services\Tesouraria;

use App\Exceptions\ErroNegocio;
use App\Models\ConferenciaCaixa;
use App\Models\LancamentoContabil;
use App\Services\Contabilidade\LocalizadorLancamentos;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Services\Contabilidade\ServicoPlanoContas;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Conferência de caixa (js/ui_tesouraria.js:6948-7749), corrigida:
 *   - total físico calculado das denominações (notas e moedas em Kz) em decimal exacto;
 *   - saldo do sistema = saldo da conta no diário ATÉ À DATA da conferência (o legado não tinha data de corte);
 *   - diferença ≠ 0 exige justificação; a regularização (opcional) usa as contas da configuração no sentido certo
 *     (o legado tinha sobra→78 custo e quebra→68 proveito, invertidas) num lançamento do diário CX com n.º próprio
 *     (o legado usava o primeiro diário que aparecesse, sem n.º, e apagava linhas pela referência ao editar);
 *   - assinatura do gerente por outra pessoa que não o operador; reabrir = estorno da regularização.
 */
final class ServicoConferenciaCaixa
{
    public const DENOMINACOES = ['N5000' => 5000, 'N2000' => 2000, 'N1000' => 1000, 'N500' => 500, 'N200' => 200,
        'M200' => 200, 'M100' => 100, 'M50' => 50, 'M20' => 20, 'M10' => 10, 'M5' => 5, 'M1' => 1];

    public function __construct(
        private readonly ServicoPlanoContas $plano,
        private readonly ServicoLancamentos $lancamentos,
        private readonly LocalizadorLancamentos $localizador,
        private readonly ServicoConfigTesouraria $config,
    ) {}

    /** @param  array{codigo_conta: string, data_conferencia: string, denominacoes: array<string, int>, saldo_externo?: ?float, justificacao?: ?string}  $d */
    public function gravar(array $d, ?ConferenciaCaixa $c = null): ConferenciaCaixa
    {
        if ($c && $c->estado !== 'RASCUNHO') {
            throw new ErroNegocio('Uma conferência finalizada não se altera: reabra-a primeiro.', 'CONFERENCIA_FINALIZADA', 422);
        }
        $conta = $this->plano->contaDeMovimento($d['codigo_conta']);
        if (! str_starts_with($d['codigo_conta'], '45')) {
            throw new ErroNegocio('A conferência é feita em contas de caixa (45).', 'CONTA_NAO_CAIXA', 422);
        }
        $fisico = '0.00';
        $denominacoes = [];
        foreach ($d['denominacoes'] as $k => $qtd) {
            if (! isset(self::DENOMINACOES[$k]) || (int) $qtd < 0) {
                throw new ErroNegocio("Denominação inválida: {$k}.", 'DENOMINACAO_INVALIDA', 422);
            }
            $denominacoes[$k] = (int) $qtd;
            $fisico = bcadd($fisico, bcmul((string) self::DENOMINACOES[$k], (string) (int) $qtd, 2), 2);
        }
        $data = substr($d['data_conferencia'], 0, 10);
        $sistema = $this->saldoConta($d['codigo_conta'], $data);
        $dados = ['codigo_conta' => $d['codigo_conta'], 'nome_conta' => $conta['descricao'], 'data_conferencia' => $data, 'nome_operador' => Auth::user()?->nome_utilizador,
            'denominacoes' => json_encode($denominacoes), 'total_fisico' => $fisico, 'total_sistema' => $sistema,
            'saldo_externo' => isset($d['saldo_externo']) ? number_format((float) $d['saldo_externo'], 2, '.', '') : null,
            'diferenca' => bcsub($fisico, $sistema, 2), 'justificacao' => $d['justificacao'] ?? null, 'estado' => 'RASCUNHO'];

        return $c ? tap($c)->update($dados) : ConferenciaCaixa::create($dados);
    }

    public function finalizar(ConferenciaCaixa $c, bool $regularizar): ConferenciaCaixa
    {
        return DB::transaction(function () use ($c, $regularizar) {
            $c = ConferenciaCaixa::query()->lockForUpdate()->findOrFail($c->id);
            if ($c->estado !== 'RASCUNHO') {
                throw new ErroNegocio('A conferência já está finalizada.', 'CONFERENCIA_FINALIZADA', 422);
            }
            // o saldo do sistema pode ter mudado desde o rascunho
            $sistema = $this->saldoConta($c->codigo_conta, $c->data_conferencia->toDateString());
            $diferenca = bcsub((string) $c->total_fisico, $sistema, 2);
            if (bccomp($diferenca, '0', 2) !== 0 && mb_strlen(trim(strip_tags((string) $c->justificacao))) < 10) {
                throw new ErroNegocio('Há diferença entre o físico e o sistema: indique a justificação (mín. 10 caracteres).', 'JUSTIFICACAO_EM_FALTA', 422);
            }
            $numero = null;
            $contra = null;
            if ($regularizar && bccomp($diferenca, '0', 2) !== 0) {
                $sobra = bccomp($diferenca, '0', 2) > 0;
                $valor = ltrim($diferenca, '-');
                $contra = $sobra ? $this->config->exigir('caixa_sobras', 'Há uma sobra a regularizar.') : $this->config->exigir('caixa_quebras', 'Há uma quebra a regularizar.');
                $numero = $this->lancamentos->criar(['diario_id' => $this->localizador->diario('CX', 'Caixa')->id, 'data_documento' => $c->data_conferencia->toDateString(),
                    'numero_documento' => "CONF-{$c->id}", 'descricao' => ($sobra ? 'Sobra' : 'Quebra')." na conferência de caixa {$c->codigo_conta}", 'tipo_origem' => 'CAIXA',
                    'linhas' => [['codigo_conta' => $sobra ? $c->codigo_conta : $contra, 'tipo_dc' => 'D', 'valor' => $valor],
                        ['codigo_conta' => $sobra ? $contra : $c->codigo_conta, 'tipo_dc' => 'C', 'valor' => $valor]]])->first()->numero_lan;
            }
            $c->update(['estado' => 'FINALIZADO', 'total_sistema' => $sistema, 'diferenca' => $diferenca, 'referencia_lancamento' => $numero,
                'conta_regularizacao' => $contra]);

            return $c;
        });
    }

    public function assinar(ConferenciaCaixa $c): ConferenciaCaixa
    {
        if ($c->estado !== 'FINALIZADO') {
            throw new ErroNegocio('Só se assinam conferências finalizadas.', 'CONFERENCIA_NAO_FINALIZADA', 422);
        }
        if ($c->nome_operador === Auth::user()?->nome_utilizador) {
            throw new ErroNegocio('A assinatura do gerente tem de ser de outra pessoa que não o operador.', 'AUTO_ASSINATURA', 403);
        }
        $c->update(['nome_gerente' => Auth::user()?->nome_utilizador, 'assinado_gerente_em' => now()]);

        return $c;
    }

    public function reabrir(ConferenciaCaixa $c, string $motivo): ConferenciaCaixa
    {
        return DB::transaction(function () use ($c, $motivo) {
            $c = ConferenciaCaixa::query()->lockForUpdate()->findOrFail($c->id);
            if ($c->estado !== 'FINALIZADO') {
                throw new ErroNegocio('A conferência não está finalizada.', 'CONFERENCIA_NAO_FINALIZADA', 422);
            }
            if ($c->referencia_lancamento && LancamentoContabil::query()->where('numero_lan', $c->referencia_lancamento)->whereNull('estornado_por_id')->exists()) {
                $this->lancamentos->estornar($this->localizador->localizar($c->referencia_lancamento, ''), $motivo);
            }
            $c->update(['estado' => 'RASCUNHO', 'referencia_lancamento' => null, 'conta_regularizacao' => null, 'nome_gerente' => null, 'assinado_gerente_em' => null]);

            return $c;
        });
    }

    public function saldoConta(string $conta, string $data): string
    {
        $s = LancamentoContabil::query()->where('codigo_conta', $conta)->where('data_documento', '<=', $data)
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo_dc = 'D' THEN valor ELSE -valor END), 0) AS s")->value('s');

        return number_format((float) $s, 2, '.', '');
    }
}
