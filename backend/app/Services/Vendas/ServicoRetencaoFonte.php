<?php

namespace App\Services\Vendas;

use App\Exceptions\ErroNegocio;
use App\Models\ReciboVenda;
use App\Models\ReciboVendaRetencao;
use App\Models\TipoRetencaoFonte;
use App\Services\Contabilidade\ServicoPlanoContas;
use Illuminate\Support\Collection;

/**
 * Retenção na fonte nos recibos de clientes (funcionalidade nova; o legado não a tinha).
 *
 * O cliente retém uma parte do pagamento e entrega-a ao Estado: o recibo liquida as facturas (ou o adiantamento) pelo valor
 * BRUTO; em caixa/banco entra o bruto menos a retenção; a retenção é imposto antecipado (D conta do imposto retido).
 *   - catálogo por empresa (tipos_retencao_fonte), semeado com o tipo de arranque na primeira consulta;
 *   - conta do imposto: a do tipo, senão a chave «retencao_fonte_clientes» das contas de vendas, senão CONTA_POR_OMISSAO;
 *   - base por omissão = montante do recibo (editável, nunca acima dele); percentagem 0–100; valor calculado com bcmath,
 *     arredondado a 2 casas, e editável (o comprovativo do cliente manda) mas nunca acima da base;
 *   - o recibo guarda um instantâneo (recibos_venda_retencoes): alterar o catálogo depois não muda recibos já emitidos.
 *
 * Os valores por omissão (6,5 % e a conta 34.1) são SUGESTÕES a confirmar com o contabilista da empresa.
 */
final class ServicoRetencaoFonte
{
    /** Chave nas contas de vendas (ServicoConfigVendas) da conta do imposto retido pelos clientes. */
    public const CHAVE_CONTA = 'retencao_fonte_clientes';

    /** PGCA 34.1 «Imposto sobre os lucros» (conta de movimento do plano padrão): sugestão a confirmar com o contabilista. */
    public const CONTA_POR_OMISSAO = '341';

    public function __construct(
        private readonly ServicoConfigVendas $config,
        private readonly ServicoPlanoContas $plano,
    ) {}

    // ---- Catálogo -------------------------------------------------------------------------------------------------

    /** @return Collection<int, TipoRetencaoFonte> */
    public function tipos(bool $soActivos = false): Collection
    {
        if (! TipoRetencaoFonte::query()->exists()) {
            TipoRetencaoFonte::create(['codigo' => 'IND_SERV', 'descricao' => 'Imposto Industrial — prestação de serviços', 'percentagem' => '6.5', 'ativo' => true]);
        }

        return TipoRetencaoFonte::query()->when($soActivos, fn ($q) => $q->where('ativo', true))->orderBy('codigo')->get();
    }

    /** @param  array{codigo: string, descricao: string, percentagem: string|float|int, conta_imposto?: ?string, ativo?: bool}  $d */
    public function guardarTipo(array $d, ?TipoRetencaoFonte $tipo = null): TipoRetencaoFonte
    {
        $this->exigirPercentagem((string) $d['percentagem']);
        $codigo = mb_strtoupper(trim($d['codigo']));
        if (TipoRetencaoFonte::query()->where('codigo', $codigo)->when($tipo, fn ($q) => $q->where('id', '!=', $tipo->id))->exists()) {
            throw new ErroNegocio("Já existe um tipo de retenção com o código {$codigo}.", 'RETENCAO_TIPO_DUPLICADO', 422);
        }
        if (! empty($d['conta_imposto'])) {
            $this->plano->contaDeMovimento($d['conta_imposto']);
        }
        $valores = ['codigo' => $codigo, 'descricao' => trim($d['descricao']), 'percentagem' => number_format((float) $d['percentagem'], 4, '.', ''),
            'conta_imposto' => ($d['conta_imposto'] ?? null) ?: null, 'ativo' => $d['ativo'] ?? true];

        return $tipo ? tap($tipo)->update($valores) : TipoRetencaoFonte::create($valores);
    }

    public function eliminarTipo(TipoRetencaoFonte $tipo): void
    {
        $tipo->delete();   // recibos já emitidos guardam o instantâneo (a ligação passa a nula)
    }

    /** Conta do imposto retido: a do tipo, a das contas de vendas ou a sugestão por omissão. */
    public function contaDoImposto(?TipoRetencaoFonte $tipo = null): string
    {
        return ($tipo?->conta_imposto ?: null) ?? $this->config->conta(self::CHAVE_CONTA) ?? self::CONTA_POR_OMISSAO;
    }

    // ---- Cálculo e validação --------------------------------------------------------------------------------------

    /** base × percentagem ÷ 100, arredondado a 2 casas (meio para cima, com bcmath). */
    public static function calcular(string $base, string $percentagem): string
    {
        $bruto = bcdiv(bcmul($base, $percentagem, 8), '100', 6);

        return bcadd(bcadd($bruto, '0.005', 6), '0', 2);   // bcadd trunca: somar meio cêntimo antes dá o arredondamento «meio para cima»
    }

    /**
     * Valida o bloco de retenção do pedido e devolve os valores normalizados (sem gravar).
     *
     * @param  array<string, mixed>  $b  {tipo_retencao_fonte_id?, codigo_tipo?, descricao_tipo?, percentagem, base_incidencia?, valor_retido?,
     *                                   referencia_comprovativo?, data_comprovativo?}
     * @return array<string, mixed>
     */
    public function normalizar(array $b, string $montante): array
    {
        $tipo = null;
        if (! empty($b['tipo_retencao_fonte_id'])) {
            $tipo = TipoRetencaoFonte::query()->find($b['tipo_retencao_fonte_id'])
                ?? throw new ErroNegocio('Tipo de retenção inexistente.', 'RETENCAO_TIPO_INVALIDO', 422);
        }
        $percentagem = (string) ($b['percentagem'] ?? ($tipo?->percentagem ?? ''));
        $this->exigirPercentagem($percentagem);
        $percentagem = number_format((float) $percentagem, 4, '.', '');

        $base = isset($b['base_incidencia']) && $b['base_incidencia'] !== '' ? number_format((float) $b['base_incidencia'], 2, '.', '') : $montante;
        if (bccomp($base, '0', 2) <= 0 || bccomp($base, $montante, 2) > 0) {
            throw new ErroNegocio("A base de incidência ({$base}) tem de ser positiva e não superior ao montante do recibo ({$montante}).", 'RETENCAO_BASE_INVALIDA', 422,
                ['base' => $base, 'montante' => $montante]);
        }
        $calculado = self::calcular($base, $percentagem);
        $valor = isset($b['valor_retido']) && $b['valor_retido'] !== '' ? number_format((float) $b['valor_retido'], 2, '.', '') : $calculado;
        if (bccomp($valor, '0', 2) < 0 || bccomp($valor, $base, 2) > 0) {
            throw new ErroNegocio("O valor retido ({$valor}) não pode exceder a base de incidência ({$base}).", 'RETENCAO_EXCEDE_BASE', 422, ['valor_retido' => $valor, 'base' => $base]);
        }
        if (bccomp(bcsub($montante, $valor, 2), '0', 2) <= 0) {
            throw new ErroNegocio('O valor retido não pode igualar ou exceder o montante do recibo: não entraria dinheiro em caixa/banco.', 'RETENCAO_EXCEDE_BASE', 422);
        }
        $codigo = $tipo?->codigo ?? mb_strtoupper(trim((string) ($b['codigo_tipo'] ?? 'OUTRA')));
        $descricao = $tipo?->descricao ?? trim((string) ($b['descricao_tipo'] ?? 'Retenção na fonte'));

        return [
            'tipo_retencao_fonte_id' => $tipo?->id, 'codigo_tipo' => mb_substr($codigo, 0, 30), 'descricao_tipo' => mb_substr($descricao, 0, 200),
            'percentagem' => $percentagem, 'base_incidencia' => $base, 'valor_retido' => $valor, 'valor_calculado' => $calculado,
            'referencia_comprovativo' => ($b['referencia_comprovativo'] ?? null) ?: null, 'data_comprovativo' => ($b['data_comprovativo'] ?? null) ?: null,
            'conta_imposto' => $this->contaDoImposto($tipo),
        ];
    }

    /** Valida e grava a retenção do recibo (chamado na transacção da emissão). Valor retido 0 = sem retenção. */
    public function gravar(ReciboVenda $recibo, array $bloco): ?ReciboVendaRetencao
    {
        $dados = $this->normalizar($bloco, (string) $recibo->montante_total);
        if (bccomp($dados['valor_retido'], '0', 2) === 0) {
            return null;
        }

        return ReciboVendaRetencao::create(['recibo_venda_id' => $recibo->id] + $dados);
    }

    // ---- Leitura --------------------------------------------------------------------------------------------------

    public function doRecibo(ReciboVenda $recibo): ?ReciboVendaRetencao
    {
        return $recibo->relationLoaded('retencao') ? $recibo->retencao : $recibo->retencao()->first();
    }

    public function valorRetido(ReciboVenda $recibo): string
    {
        return number_format((float) ($this->doRecibo($recibo)?->valor_retido ?? 0), 2, '.', '');
    }

    /** Dinheiro que entra em caixa/banco: o bruto menos a retenção. */
    public function valorLiquido(ReciboVenda $recibo): string
    {
        return bcsub(number_format((float) $recibo->montante_total, 2, '.', ''), $this->valorRetido($recibo), 2);
    }

    /** Conta do imposto retido a usar ao contabilizar: tem de existir e ser de movimento no plano da empresa. */
    public function contaParaContabilizar(ReciboVendaRetencao $r): string
    {
        $conta = $r->conta_imposto ?: $this->contaDoImposto();
        try {
            $this->plano->contaDeMovimento($conta);
        } catch (ErroNegocio $e) {
            throw new ErroNegocio("O recibo tem retenção na fonte mas a conta do imposto retido ({$conta}) não está disponível no plano de contas: {$e->getMessage()} "
                .'Crie a conta ou indique outra em Vendas › Configuração › Retenção na fonte.', 'RETENCAO_CONTA_EM_FALTA', 422, ['codigo_conta' => $conta]);
        }

        return $conta;
    }

    /** @return array<string, mixed>|null */
    public function paraApi(ReciboVenda $recibo): ?array
    {
        $r = $this->doRecibo($recibo);

        return $r ? [
            'tipo_retencao_fonte_id' => $r->tipo_retencao_fonte_id, 'codigo_tipo' => $r->codigo_tipo, 'descricao_tipo' => $r->descricao_tipo,
            'percentagem' => $r->percentagem, 'base_incidencia' => $r->base_incidencia, 'valor_retido' => $r->valor_retido, 'valor_calculado' => $r->valor_calculado,
            'referencia_comprovativo' => $r->referencia_comprovativo, 'data_comprovativo' => $r->data_comprovativo?->toDateString(), 'conta_imposto' => $r->conta_imposto,
        ] : null;
    }

    private function exigirPercentagem(string $p): void
    {
        if ($p === '' || ! is_numeric($p) || (float) $p < 0 || (float) $p > 100) {
            throw new ErroNegocio('A percentagem de retenção tem de estar entre 0 e 100.', 'RETENCAO_PERCENTAGEM_INVALIDA', 422);
        }
    }
}
