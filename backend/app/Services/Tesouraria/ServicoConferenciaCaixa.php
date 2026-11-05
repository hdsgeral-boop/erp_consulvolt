<?php

namespace App\Services\Tesouraria;

use App\Exceptions\ErroNegocio;
use App\Models\ConferenciaCaixa;
use App\Models\LancamentoContabil;
use App\Services\Contabilidade\LocalizadorLancamentos;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Services\Contabilidade\ServicoPlanoContas;
use App\Services\Sistema\ServicoCambios;
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
        private readonly ServicoCaixaMoedas $moedas,
    ) {}

    /** Moeda da conta de caixa (Kz quando não tem moeda própria). */
    public function moedaDaConta(string $codigoConta): string
    {
        return (string) (DB::table('plano_contas')->where('codigo', $codigoConta)->whereNull('eliminado_em')->value('codigo_moeda') ?: ServicoCambios::BASE);
    }

    /** @param  array{codigo_conta: string, data_conferencia: string, nome_operador?: ?string, denominacoes: array<string, int>, saldo_externo?: ?float, justificacao?: ?string}  $d */
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
        // caixa em moeda estrangeira: conta-se o total na moeda (as notas do Kz não se aplicam) e compara-se com o saldo na moeda
        $moeda = $this->moedaDaConta($d['codigo_conta']);
        $estrangeira = ServicoCaixaMoedas::eEstrangeira($moeda);
        if ($estrangeira) {
            $this->moedas->exigirDisponivel($moeda);
            if (! isset($d['total_contado']) || ! is_numeric($d['total_contado']) || (float) $d['total_contado'] < 0) {
                throw new ErroNegocio("Indique o total contado em {$moeda}.", 'TOTAL_CONTADO_EM_FALTA', 422);
            }
            $fisico = number_format((float) $d['total_contado'], 2, '.', '');
        } elseif (! isset($d['denominacoes'])) {
            throw new ErroNegocio('Indique a contagem por denominação.', 'DENOMINACOES_EM_FALTA', 422);
        }
        foreach ($estrangeira ? [] : $d['denominacoes'] as $k => $qtd) {
            if (! isset(self::DENOMINACOES[$k]) || (int) $qtd < 0) {
                throw new ErroNegocio("Denominação inválida: {$k}.", 'DENOMINACAO_INVALIDA', 422);
            }
            $denominacoes[$k] = (int) $qtd;
            $fisico = bcadd($fisico, bcmul((string) self::DENOMINACOES[$k], (string) (int) $qtd, 2), 2);
        }
        $data = substr($d['data_conferencia'], 0, 10);
        $sistema = $estrangeira ? $this->saldoContaMoeda($d['codigo_conta'], $data, $moeda) : $this->saldoConta($d['codigo_conta'], $data);
        // «Nome do Tesoureiro / Operador» (ui_tesouraria.js:7255): texto do formulário; por omissão o utilizador da sessão
        $operador = trim(strip_tags((string) ($d['nome_operador'] ?? '')));
        $dados = ['codigo_conta' => $d['codigo_conta'], 'nome_conta' => $conta['descricao'], 'data_conferencia' => $data,
            'nome_operador' => $operador !== '' ? mb_substr($operador, 0, 255) : Auth::user()?->nomeApresentacao(),
            'denominacoes' => json_encode($denominacoes), 'total_fisico' => $fisico, 'total_sistema' => $sistema,
            'saldo_externo' => isset($d['saldo_externo']) ? number_format((float) $d['saldo_externo'], 2, '.', '') : null,
            'diferenca' => bcsub($fisico, $sistema, 2), 'justificacao' => $d['justificacao'] ?? null, 'estado' => 'RASCUNHO'];

        $c ??= new ConferenciaCaixa;
        // registado_por fica fora do $fillable do contrato gerado: quem registou primeiro mantém-se
        $c->fill($dados)->forceFill(['registado_por' => $c->registado_por ?? Auth::user()?->nome_utilizador])->save();

        return $c;
    }

    public function finalizar(ConferenciaCaixa $c, bool $regularizar): ConferenciaCaixa
    {
        return DB::transaction(function () use ($c, $regularizar) {
            $c = ConferenciaCaixa::query()->lockForUpdate()->findOrFail($c->id);
            if ($c->estado !== 'RASCUNHO') {
                throw new ErroNegocio('A conferência já está finalizada.', 'CONFERENCIA_FINALIZADA', 422);
            }
            // o saldo do sistema pode ter mudado desde o rascunho
            $moeda = $this->moedaDaConta($c->codigo_conta);
            $estrangeira = ServicoCaixaMoedas::eEstrangeira($moeda);
            if ($estrangeira) {
                $this->moedas->exigirDisponivel($moeda);
            }
            $sistema = $estrangeira ? $this->saldoContaMoeda($c->codigo_conta, $c->data_conferencia->toDateString(), $moeda)
                : $this->saldoConta($c->codigo_conta, $c->data_conferencia->toDateString());
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
                // em moeda estrangeira a diferença é na moeda: o Kz é ao câmbio do dia da conferência e a linha da caixa leva os campos de moeda
                $campos = [];
                if ($estrangeira) {
                    $taxa = $this->moedas->taxa($moeda, $c->data_conferencia->toDateString(), null, 'Conferência da caixa '.$c->codigo_conta);
                    $campos = ['codigo_moeda' => $moeda, 'valor_moeda' => $valor, 'taxa_cambio' => $taxa['taxa']];
                    $valor = ServicoCaixaMoedas::kz($valor, $taxa['taxa']);
                }
                $numero = $this->lancamentos->criar(['diario_id' => $this->localizador->diario('CX', 'Caixa')->id, 'data_documento' => $c->data_conferencia->toDateString(),
                    'numero_documento' => "CONF-{$c->id}", 'descricao' => ($sobra ? 'Sobra' : 'Quebra')." na conferência de caixa {$c->codigo_conta}", 'tipo_origem' => 'CAIXA',
                    // a linha da caixa (45) leva a nota 10 (Disponibilidades), como no legado (E-CON-1)
                    'linhas' => [['codigo_conta' => $sobra ? $c->codigo_conta : $contra, 'tipo_dc' => 'D', 'valor' => $valor, 'nota_demonstracao_id' => $sobra ? $this->config->notaDisponibilidades() : null] + ($sobra ? $campos : []),
                        ['codigo_conta' => $sobra ? $contra : $c->codigo_conta, 'tipo_dc' => 'C', 'valor' => $valor, 'nota_demonstracao_id' => $sobra ? null : $this->config->notaDisponibilidades()] + ($sobra ? [] : $campos)]])->first()->numero_lan;
            }
            $c->update(['estado' => 'FINALIZADO', 'total_sistema' => $sistema, 'diferenca' => $diferenca, 'referencia_lancamento' => $numero,
                'conta_regularizacao' => $contra, 'nome_conta_regularizacao' => $contra ? $this->plano->contaDeMovimento($contra)['descricao'] : null]);

            return $c;
        });
    }

    public function assinar(ConferenciaCaixa $c): ConferenciaCaixa
    {
        if ($c->estado !== 'FINALIZADO') {
            throw new ErroNegocio('Só se assinam conferências finalizadas.', 'CONFERENCIA_NAO_FINALIZADA', 422);
        }
        if ($this->eDoOperador($c)) {
            throw new ErroNegocio('A assinatura do gerente tem de ser de outra pessoa que não o operador.', 'AUTO_ASSINATURA', 403);
        }
        $c->update(['nome_gerente' => Auth::user()?->nome_utilizador, 'assinado_gerente_em' => now()]);

        return $c;
    }

    /**
     * O gerente não pode ser quem registou a conferência nem a pessoa indicada como operador (o operador é texto livre:
     * compara-se com o utilizador e com o nome de apresentação, sem distinguir maiúsculas nem espaços).
     */
    private function eDoOperador(ConferenciaCaixa $c): bool
    {
        $u = Auth::user();
        if (! $u) {
            return false;
        }
        $norm = fn (?string $x) => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $x)));
        $eu = array_filter([$norm($u->nome_utilizador), $norm($u->nomeApresentacao())]);

        return ($c->registado_por !== null && $norm($c->registado_por) === $norm($u->nome_utilizador))
            || in_array($norm($c->nome_operador), $eu, true);
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
            $c->update(['estado' => 'RASCUNHO', 'referencia_lancamento' => null, 'conta_regularizacao' => null, 'nome_conta_regularizacao' => null, 'nome_gerente' => null, 'assinado_gerente_em' => null]);

            return $c;
        });
    }

    /** Saldo da conta de caixa NA MOEDA (soma dos valores em moeda das linhas nessa moeda, a débito − a crédito) até à data. */
    public function saldoContaMoeda(string $conta, string $data, string $moeda): string
    {
        $s = LancamentoContabil::query()->where('codigo_conta', $conta)->where('data_documento', '<=', $data)->where('codigo_moeda', $moeda)
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo_dc = 'D' THEN COALESCE(valor_moeda, 0) ELSE -COALESCE(valor_moeda, 0) END), 0) AS s")->value('s');

        return number_format((float) $s, 2, '.', '');
    }

    public function saldoConta(string $conta, string $data): string
    {
        $s = LancamentoContabil::query()->where('codigo_conta', $conta)->where('data_documento', '<=', $data)
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo_dc = 'D' THEN valor ELSE -valor END), 0) AS s")->value('s');

        return number_format((float) $s, 2, '.', '');
    }
}
