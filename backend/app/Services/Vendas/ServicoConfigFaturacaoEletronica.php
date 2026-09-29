<?php

namespace App\Services\Vendas;

use App\Exceptions\ErroNegocio;
use App\Models\ConfigFaturacaoEletronica;
use App\Models\Empresa;
use App\Models\ReciboVenda;
use App\Models\SerieFaturacaoEletronica;
use App\Models\Venda;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Regime de facturação electrónica e séries (gravarConfig / gravarSerie / eliminarSerie, js/facturacao_agt.js:86-170).
 * Os dados do software e a ligação à AGT são do servidor (config/erp.php), não da empresa: aqui só se guardam
 * o regime, os estabelecimentos, as omissões e as opções de envio (automático, exigir séries AGT).
 */
final class ServicoConfigFaturacaoEletronica
{
    public function __construct(private readonly ContextoEmpresa $contexto) {}

    public function obter(): array
    {
        $c = ConfigFaturacaoEletronica::query()->first();

        return [
            'ativo' => (bool) $c?->ativo, 'data_inicio' => $c?->data_inicio?->toDateString(),
            'estabelecimentos' => $c?->estabelecimentos ?: [['numero' => '1', 'nome' => 'Sede']],
            'pais_padrao' => $c?->pais_padrao ?: 'AO', 'isencao_padrao' => $c?->isencao_padrao ?: null,
            'servico' => ['auto' => ! empty($c?->servico['auto']), 'exigir_series_agt' => ! empty($c?->servico['exigir_series_agt'])],
            'isencoes' => CatalogoAgt::ISENCOES,
        ];
    }

    public function gravar(array $d): array
    {
        return DB::transaction(function () use ($d) {
            $atual = ConfigFaturacaoEletronica::query()->lockForUpdate()->first();
            $estabelecimentos = collect($d['estabelecimentos'] ?? $atual?->estabelecimentos ?? [])
                ->map(fn ($e) => ['numero' => trim((string) ($e['numero'] ?? '')), 'nome' => trim((string) ($e['nome'] ?? ''))])
                ->filter(fn ($e) => $e['numero'] !== '')->values()->all();
            if (! $estabelecimentos) {
                throw new ErroNegocio('Indique pelo menos um estabelecimento.', 'SEM_ESTABELECIMENTOS', 422);
            }
            $isencao = strtoupper(trim((string) ($d['isencao_padrao'] ?? '')));
            if ($isencao !== '' && ! isset(CatalogoAgt::ISENCOES[$isencao])) {
                throw new ErroNegocio("Código de isenção desconhecido ({$isencao}).", 'ISENCAO_INVALIDA', 422);
            }
            $ativo = (bool) ($d['ativo'] ?? false);
            $inicio = $d['data_inicio'] ?? $atual?->data_inicio?->toDateString();
            $noRegime = Venda::query()->where('fe_regime', true)->count();

            if ($ativo) {
                $nif = (string) Empresa::query()->whereKey($this->contexto->obrigatorio())->value('nif');
                if (! preg_match('/^[0-9A-Za-z]{9,15}$/', trim($nif))) {
                    throw new ErroNegocio('A empresa não tem um NIF válido (9 a 15 letras e números). Corrija a ficha da empresa antes de activar o regime.', 'NIF_INVALIDO', 422);
                }
                if (! $inicio) {
                    throw new ErroNegocio('Indique a data de início do regime.', 'SEM_DATA_INICIO', 422);
                }
                // documentos fiscais fora do regime com data igual/posterior ao início misturariam a numeração
                $posteriores = Venda::query()->whereIn('tipo_documento', Venda::FISCAIS)->where(fn ($q) => $q->whereNull('fe_regime')->orWhere('fe_regime', false))
                    ->where('data_emissao', '>=', $inicio)->count();
                if ($posteriores && ! ($atual?->ativo && $atual->data_inicio?->toDateString() === $inicio)) {
                    throw new ErroNegocio("Já existem {$posteriores} documento(s) fiscal(is) com data igual ou posterior a {$inicio} emitidos fora do regime. Escolha uma data de início posterior ao último documento.",
                        'DOCUMENTOS_FORA_DO_REGIME', 422);
                }
            }
            if ($atual?->ativo && ! $ativo && $noRegime) {
                throw new ErroNegocio("Já foram emitidos {$noRegime} documento(s) no regime de facturação electrónica: o regime não pode ser desactivado.", 'REGIME_COM_DOCUMENTOS', 422);
            }
            if ($atual?->ativo && $noRegime && $inicio !== $atual->data_inicio?->toDateString()) {
                throw new ErroNegocio('Já há documentos emitidos no regime: a data de início não pode ser alterada.', 'REGIME_COM_DOCUMENTOS', 422);
            }

            $dados = [
                'ativo' => $ativo, 'data_inicio' => $inicio, 'estabelecimentos' => $estabelecimentos,
                'pais_padrao' => strtoupper($d['pais_padrao'] ?? $atual?->pais_padrao ?? 'AO'), 'isencao_padrao' => $isencao ?: null,
                'servico' => ['auto' => (bool) ($d['servico']['auto'] ?? $atual?->servico['auto'] ?? false),
                    'exigir_series_agt' => (bool) ($d['servico']['exigir_series_agt'] ?? $atual?->servico['exigir_series_agt'] ?? false)],
                'atualizado_por' => Auth::user()?->nome_utilizador,
            ];
            $atual ? $atual->update($dados) : ConfigFaturacaoEletronica::create($dados);

            return $this->obter();
        });
    }

    public function gravarSerie(array $d, ?SerieFaturacaoEletronica $serie = null): SerieFaturacaoEletronica
    {
        $tipo = strtoupper((string) $d['tipo']);
        $ano = (int) $d['ano'];
        $codigo = trim((string) $d['codigo']);
        $origem = (string) ($d['origem'] ?? 'GERAL');
        $estabelecimento = (string) ($d['estabelecimento'] ?? ($this->obter()['estabelecimentos'][0]['numero'] ?? '1'));
        $estado = ($d['estado'] ?? 'ATIVA') === 'FECHADA' ? 'FECHADA' : 'ATIVA';

        return DB::transaction(function () use ($d, $tipo, $ano, $codigo, $origem, $estabelecimento, $estado, $serie) {
            $outras = SerieFaturacaoEletronica::query()->when($serie, fn ($q) => $q->where('id', '<>', $serie->id))->lockForUpdate()->get();
            if ($serie && (int) $serie->proximo_numero > 1
                && ($codigo !== $serie->codigo || $tipo !== $serie->tipo || $ano !== $serie->ano || $origem !== $serie->origem)) {
                throw new ErroNegocio('A série já tem documentos: só pode mudar o estado ou a indicação de contingência.', 'SERIE_USADA', 422);
            }
            if ($estado === 'ATIVA' && $outras->contains(fn ($x) => $x->estado === 'ATIVA' && $x->tipo === $tipo && $x->ano === $ano && $x->origem === $origem)) {
                throw new ErroNegocio('Já existe outra série activa para este tipo, ano e origem. Feche-a antes.', 'SERIE_ATIVA_DUPLICADA', 422);
            }
            if ($outras->contains(fn ($x) => $x->tipo === $tipo && $x->ano === $ano && $x->codigo === $codigo)) {
                throw new ErroNegocio('Já existe uma série com este código para este tipo e ano.', 'SERIE_DUPLICADA', 422);
            }
            $dados = ['codigo' => $codigo, 'tipo' => $tipo, 'ano' => $ano, 'origem' => $origem,
                'origem_nome' => $d['origem_nome'] ?? $serie?->origem_nome ?? ($origem === 'GERAL' ? 'Facturação (geral)' : $origem),
                'estabelecimento' => $estabelecimento, 'contingencia' => (bool) ($d['contingencia'] ?? false), 'estado' => $estado];

            return $serie ? tap($serie)->update($dados)
                : SerieFaturacaoEletronica::create($dados + ['proximo_numero' => 1, 'criado_por' => Auth::user()?->nome_utilizador]);
        });
    }

    public function eliminarSerie(SerieFaturacaoEletronica $serie): void
    {
        if ((int) $serie->proximo_numero > 1 || Venda::query()->where('serie_faturacao_eletronica_id', $serie->id)->exists()
            || ReciboVenda::query()->where('serie_faturacao_eletronica_id', $serie->id)->exists()) {
            throw new ErroNegocio('A série já tem documentos emitidos e não pode ser eliminada. Pode fechá-la.', 'SERIE_USADA', 422);
        }
        $serie->delete();
    }
}
