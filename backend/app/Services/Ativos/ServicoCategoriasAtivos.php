<?php

namespace App\Services\Ativos;

use App\Exceptions\ErroNegocio;
use App\Models\CategoriaAtivo;
use App\Services\Contabilidade\ServicoPlanoContas;
use App\Support\Dados\VerificadorReferencias;

/**
 * Categorias de imobilizado (renderAssetCategories / saveAssetCategory / deleteAssetCategory, js/ui_assets.js:912-1095).
 * As contas seguem os filtros do formulário do legado: gasto 73, amortização acumulada 18, venda classe 6, perda classe 7
 * (ui_assets.js:926-929); a conta do activo (11/12/14) é nova e serve o abate quando o activo não tem lançamento de compra.
 * Correcções:
 *   - as contas têm de existir e ser de movimento (o legado aceitava qualquer texto e a integração caía em «73.1»/«18.1»);
 *   - nome obrigatório e único (o legado gravava categorias sem nome e duplicadas);
 *   - eliminar só sem activos (o legado apagava e deixava os activos sem categoria — ex.: activo #74 migrado sem categoria).
 */
final class ServicoCategoriasAtivos
{
    public const CONTAS = [
        'conta_gasto' => ['73', 'Amortização do exercício (73)'],
        'conta_amortizacao_acumulada' => ['18', 'Amortização acumulada (18)'],
        'conta_venda' => ['6', 'Venda / ganho (classe 6)'],
        'conta_perda' => ['7', 'Perda / sinistro (classe 7)'],
        'conta_ativo' => ['1', 'Activo (classe 1)'],
    ];

    public function __construct(
        private readonly ServicoPlanoContas $planoContas,
        private readonly VerificadorReferencias $referencias,
    ) {}

    public function guardar(array $d, ?CategoriaAtivo $c = null): CategoriaAtivo
    {
        if (array_key_exists('nome', $d) || ! $c) {
            $d['nome'] = trim((string) ($d['nome'] ?? ''));
            if ($d['nome'] === '') {
                throw new ErroNegocio('Indique o nome da categoria.', 'DADOS_INVALIDOS', 422);
            }
            if (CategoriaAtivo::query()->whereRaw('lower(nome) = lower(?)', [$d['nome']])->when($c, fn ($q) => $q->whereKeyNot($c->id))->exists()) {
                throw new ErroNegocio("Já existe a categoria «{$d['nome']}».", 'CATEGORIA_DUPLICADA', 422);
            }
        }
        foreach (self::CONTAS as $campo => [$prefixo, $rotulo]) {
            if (! array_key_exists($campo, $d)) {
                continue;
            }
            $d[$campo] = trim((string) $d[$campo]) ?: null;
            if ($d[$campo] === null) {
                continue;
            }
            if (! str_starts_with($d[$campo], $prefixo)) {
                throw new ErroNegocio("{$rotulo}: a conta {$d[$campo]} não pertence à classe/grupo {$prefixo}.", 'CONTA_INVALIDA', 422, ['campo' => $campo]);
            }
            $this->planoContas->contaDeMovimento($d[$campo]);
        }

        if ($c) {
            $c->update($d);

            return $c->refresh();
        }

        return CategoriaAtivo::create($d + ['taxa_anual' => 0]);
    }

    public function eliminar(CategoriaAtivo $c): void
    {
        $this->referencias->exigirLivre('categorias_ativos', $c->id, "a categoria «{$c->nome}»", [], [], 'Mude primeiro a categoria desses activos.');
        $c->delete();
    }

    /** Contas de gasto e de amortização acumulada, obrigatórias para calcular e integrar. */
    public static function exigirContasAmortizacao(?CategoriaAtivo $c, string $ativo): array
    {
        if (! $c) {
            throw new ErroNegocio("O activo {$ativo} não tem categoria.", 'ATIVO_SEM_CATEGORIA', 422, ['ativo' => $ativo]);
        }
        if (! $c->conta_gasto || ! $c->conta_amortizacao_acumulada) {
            throw new ErroNegocio("A categoria «{$c->nome}» não tem a conta de gasto ou a de amortização acumulada.", 'CATEGORIA_SEM_CONTAS', 422,
                ['categoria_ativo_id' => $c->id, 'ativo' => $ativo]);
        }

        return [$c->conta_gasto, $c->conta_amortizacao_acumulada];
    }
}
