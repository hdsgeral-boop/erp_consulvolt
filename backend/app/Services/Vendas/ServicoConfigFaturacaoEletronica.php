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

    /** Limite do número inicial da série (proximoValido, js/facturacao_agt.js:127-133 do legado novo). */
    public const NUMERO_MAXIMO = 99_999_999;

    /**
     * Cria/altera uma série. `proximo_numero` (opcional) é o número inicial — o próximo documento leva-o — e só se altera
     * enquanto a série não tem documentos emitidos («usada» = tem vendas ou recibos com esta série, e não proximo_numero > 1:
     * uma série virgem pode arrancar em 501 para continuar um livro em papel ou outro programa).
     */
    public function gravarSerie(array $d, ?SerieFaturacaoEletronica $serie = null): SerieFaturacaoEletronica
    {
        $tipo = strtoupper((string) $d['tipo']);
        $ano = (int) $d['ano'];
        $codigo = trim((string) $d['codigo']);
        $origem = (string) ($d['origem'] ?? 'GERAL');
        $estabelecimento = (string) ($d['estabelecimento'] ?? ($this->obter()['estabelecimentos'][0]['numero'] ?? '1'));
        $estado = ($d['estado'] ?? 'ATIVA') === 'FECHADA' ? 'FECHADA' : 'ATIVA';
        $proximo = isset($d['proximo_numero']) && $d['proximo_numero'] !== '' ? self::numeroInicial($d['proximo_numero']) : null;

        return DB::transaction(function () use ($d, $tipo, $ano, $codigo, $origem, $estabelecimento, $estado, $serie, $proximo) {
            $outras = SerieFaturacaoEletronica::query()->when($serie, fn ($q) => $q->where('id', '<>', $serie->id))->lockForUpdate()->get();
            if ($serie) {
                $serie = SerieFaturacaoEletronica::query()->lockForUpdate()->findOrFail($serie->id);
            }
            $emitidos = $serie ? ($this->emitidos([$serie->id])[$serie->id] ?? 0) : 0;
            if ($emitidos > 0 && ($codigo !== $serie->codigo || $tipo !== $serie->tipo || $ano !== $serie->ano || $origem !== $serie->origem)) {
                throw new ErroNegocio('A série já tem documentos: só pode mudar o estado ou a indicação de contingência.', 'SERIE_USADA', 422);
            }
            if ($serie && $proximo !== null && $proximo !== (int) $serie->proximo_numero && $emitidos > 0) {
                throw new ErroNegocio("A série {$serie->tipo} {$serie->codigo} já emitiu {$emitidos} documento(s): o número já não pode ser alterado. Feche-a e crie outra série com o número que pretende.",
                    'SERIE_USADA', 422);
            }
            if ($proximo !== null && $serie?->agt_ultimo_numero && ($proximo > (int) $serie->agt_ultimo_numero || $proximo < (int) ($serie->agt_primeiro_numero ?: 1))) {
                throw new ErroNegocio("O número inicial tem de estar dentro do intervalo autorizado pela AGT ({$serie->agt_primeiro_numero} a {$serie->agt_ultimo_numero}).",
                    'SERIE_FORA_INTERVALO_AGT', 422);
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
            if ($serie && $proximo !== null) {
                $dados['proximo_numero'] = $proximo;
            }

            return $serie ? tap($serie)->update($dados)
                : SerieFaturacaoEletronica::create($dados + ['proximo_numero' => $proximo ?? 1, 'criado_por' => Auth::user()?->nome_utilizador]);
        });
    }

    /** Série com documentos não se apaga: fecha-se (conta os documentos, não olha ao «próximo», que pode arrancar acima de 1). */
    public function eliminarSerie(SerieFaturacaoEletronica $serie): void
    {
        $emitidos = $this->emitidos([$serie->id])[$serie->id] ?? 0;
        if ($emitidos > 0) {
            throw new ErroNegocio("A série já emitiu {$emitidos} documento(s) e não pode ser eliminada. Pode fechá-la.", 'SERIE_USADA', 422);
        }
        $serie->delete();
    }

    /**
     * Documentos emitidos por série (vendas + recibos), da empresa activa.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    public function emitidos(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $contar = fn (string $modelo) => $modelo::query()->whereIn('serie_faturacao_eletronica_id', $ids)
            ->groupBy('serie_faturacao_eletronica_id')->selectRaw('serie_faturacao_eletronica_id as id, count(*) as n')->pluck('n', 'id');
        $total = [];
        foreach ([$contar(Venda::class), $contar(ReciboVenda::class)] as $parcial) {
            foreach ($parcial as $id => $n) {
                $total[(int) $id] = ($total[(int) $id] ?? 0) + (int) $n;
            }
        }

        return $total;
    }

    /** Número inicial: inteiro entre 1 e 99 999 999 (proximoValido do legado novo). */
    public static function numeroInicial(mixed $valor): int
    {
        $t = trim((string) $valor);
        if (! preg_match('/^\d{1,8}$/', $t) || (int) $t < 1 || (int) $t > self::NUMERO_MAXIMO) {
            throw new ErroNegocio('O número inicial da série tem de ser um inteiro entre 1 e 99 999 999.', 'NUMERO_INICIAL_INVALIDO', 422);
        }

        return (int) $t;
    }
}
