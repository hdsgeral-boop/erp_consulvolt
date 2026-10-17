<?php

namespace App\Services\Tesouraria;

use App\Exceptions\ErroNegocio;
use App\Models\ConfigContabilTesouraria;
use App\Models\NotaDemonstracao;
use App\Services\Contabilidade\ServicoPlanoContas;
use Illuminate\Support\Facades\DB;

/**
 * Contas de tesouraria por omissão. No legado estavam fixas; as sobras de caixa iam para uma conta 78 (custo) e as quebras
 * para 68 (proveito), trocadas face ao PGC angolano (6 = proveitos, 7 = custos). Aqui são configuráveis e validadas como
 * contas de movimento.
 *
 * Diferenças de câmbio (decisão 19 do utilizador): o legado usava 6621 (favoráveis, proveito) e 7621 (desfavoráveis, custo),
 * o que ESTÁ CORRECTO no PGC angolano (a justificação «invertidas» dos ADR-033/034 estava errada). preencherDiferencasCambio()
 * pré-preenche estas contas — em tesouraria e em compras — nas empresas que as têm no plano como contas de movimento e onde
 * a configuração está vazia; as que não as têm aparecem nas Validações de dados (config_diferencas_cambio_em_falta).
 */
final class ServicoConfigTesouraria
{
    public const CHAVES = [
        'caixa_sobras' => 'Sobras de caixa (proveito, conta 68)',
        'caixa_quebras' => 'Quebras de caixa (custo, conta 78)',
        'diferencas_cambio_favoraveis' => 'Diferenças de câmbio favoráveis (proveito)',
        'diferencas_cambio_desfavoraveis' => 'Diferenças de câmbio desfavoráveis (custo)',
    ];

    /**
     * Ronda 3 (pedido do utilizador): sobras de caixa só em contas 68 (outros proveitos operacionais) e quebras só em contas
     * 78 (outros custos operacionais) — validado ao gravar a configuração e ao lançar (Folha de Caixa e Conferência).
     */
    public const PREFIXOS = ['caixa_sobras' => '68', 'caixa_quebras' => '78'];

    /** Contas do legado (decisão 19): chave => código. */
    public const DIFERENCAS_CAMBIO_LEGADO = ['diferencas_cambio_favoraveis' => '6621', 'diferencas_cambio_desfavoraveis' => '7621'];

    public function __construct(private readonly ServicoPlanoContas $plano) {}

    /**
     * Pré-preenche 6621/7621 nas configurações de tesouraria e de compras de todas as empresas (ou de uma), só onde a conta
     * existe no plano como conta de movimento e a chave está vazia. Idempotente; não toca em configurações já definidas.
     *
     * @return array{preenchidas: int, empresas_sem_contas: list<int>}
     */
    public static function preencherDiferencasCambio(?int $empresaId = null): array
    {
        $preenchidas = 0;
        $semContas = [];
        $empresas = DB::table('empresas')->when($empresaId, fn ($q) => $q->where('id', $empresaId))->pluck('id');
        foreach ($empresas as $empresa) {
            $existentes = DB::table('plano_contas')->where('empresa_id', $empresa)->whereNull('eliminado_em')
                ->whereIn('codigo', array_values(self::DIFERENCAS_CAMBIO_LEGADO))
                ->where(fn ($q) => $q->whereNull('tipo')->orWhere('tipo', 'M'))->pluck('codigo')->map(fn ($c) => trim((string) $c))->all();
            if (count(array_intersect(self::DIFERENCAS_CAMBIO_LEGADO, $existentes)) < 2) {
                $semContas[] = (int) $empresa;
            }
            foreach (['configuracoes_contabeis_tesouraria', 'configuracoes_contabeis_compras'] as $tabela) {
                foreach (self::DIFERENCAS_CAMBIO_LEGADO as $chave => $conta) {
                    if (! in_array($conta, $existentes, true)) {
                        continue;
                    }
                    $actual = DB::table($tabela)->where('empresa_id', $empresa)->where('chave', $chave)->first();
                    if ($actual && $actual->codigo_conta) {
                        continue;
                    }
                    $actual
                        ? DB::table($tabela)->where('id', $actual->id)->update(['codigo_conta' => $conta, 'atualizado_em' => now()])
                        : DB::table($tabela)->insert(['empresa_id' => $empresa, 'chave' => $chave, 'codigo_conta' => $conta, 'criado_em' => now(), 'atualizado_em' => now()]);
                    $preenchidas++;
                }
            }
        }

        return ['preenchidas' => $preenchidas, 'empresas_sem_contas' => $semContas];
    }

    public function exigir(string $chave, string $motivo): string
    {
        $conta = ConfigContabilTesouraria::query()->where('chave', $chave)->value('codigo_conta')
            ?: throw new ErroNegocio("{$motivo} Configure a conta \"".self::CHAVES[$chave].'" nas contas de tesouraria.', 'CONFIG_TESOURARIA_EM_FALTA', 422, ['chave' => $chave]);
        $this->exigirPrefixo($chave, (string) $conta);

        return $conta;
    }

    /**
     * Nota às demonstrações 10 (Disponibilidades) da empresa, posta pelo legado nas linhas das contas 43/45 geradas pela
     * Tesouraria e pela folha de caixa (js/ui_tesouraria.js:1411-1413; js/ui_folha_caixa.js:1309-1319). Sem ela as linhas
     * saíam do Balanço («movimentos por mapear»). Null se a empresa não tiver a nota.
     */
    public function notaDisponibilidades(): ?int
    {
        $id = NotaDemonstracao::query()->whereRaw("TRIM(codigo) = '10'")->orderBy('id')->value('id');

        return $id !== null ? (int) $id : null;
    }

    public function todas(): array
    {
        $v = ConfigContabilTesouraria::query()->pluck('codigo_conta', 'chave');

        return collect(self::CHAVES)->map(fn ($d, $k) => ['descricao' => $d, 'codigo_conta' => $v[$k] ?? null, 'prefixos' => isset(self::PREFIXOS[$k]) ? [self::PREFIXOS[$k]] : null])->all();
    }

    /** Sobras só em 68, quebras só em 78 (PREFIXOS); as outras chaves não têm restrição de prefixo. */
    public function exigirPrefixo(string $chave, string $conta): void
    {
        $prefixo = self::PREFIXOS[$chave] ?? null;
        if ($prefixo !== null && ! str_starts_with(trim($conta), $prefixo)) {
            throw new ErroNegocio(self::CHAVES[$chave]." tem de ser uma conta {$prefixo} (recebida: {$conta}).", 'CONTA_PREFIXO_INVALIDO', 422,
                ['chave' => $chave, 'prefixo' => $prefixo, 'conta' => $conta]);
        }
    }

    public function definir(array $contas): void
    {
        DB::transaction(function () use ($contas) {
            foreach ($contas as $chave => $codigo) {
                if (! isset(self::CHAVES[$chave])) {
                    throw new ErroNegocio("Chave de configuração desconhecida: {$chave}", 'CONFIG_DESCONHECIDA', 422);
                }
                if ($codigo) {
                    $this->plano->contaDeMovimento($codigo);
                    $this->exigirPrefixo($chave, (string) $codigo);
                }
                ConfigContabilTesouraria::query()->updateOrCreate(['chave' => $chave], ['codigo_conta' => $codigo ?: null]);
            }
        });
    }
}
