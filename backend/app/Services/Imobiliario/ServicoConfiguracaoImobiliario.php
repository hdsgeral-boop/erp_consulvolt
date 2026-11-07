<?php

namespace App\Services\Imobiliario;

use App\Exceptions\ErroNegocio;
use App\Models\ConfiguracaoImobiliario;
use App\Models\Produto;
use App\Services\Contabilidade\ServicoPlanoContas;
use Illuminate\Support\Facades\DB;

/**
 * Configuração do módulo por empresa: o artigo de serviço «Renda» que a facturação usa (um com IVA isento para o arrendamento e,
 * opcionalmente, outro com a taxa do alojamento) e as contas da caução e do Imposto Predial retido. Nada vem pré-preenchido
 * (decisão P4: sem contas inventadas no código); sem a configuração a facturação e os movimentos da caução recusam com a
 * indicação do que falta. Alterar exige a permissão imo_taxas_editar (a mesma das taxas fiscais).
 */
final class ServicoConfiguracaoImobiliario
{
    public function __construct(private readonly ServicoPlanoContas $plano) {}

    /** @return array<string, mixed> */
    public function obter(): array
    {
        $c = ConfiguracaoImobiliario::query()->first();
        $ids = array_values(array_filter([$c?->produto_renda_id, $c?->produto_renda_alojamento_id]));
        $produtos = $ids ? Produto::query()->whereIn('id', $ids)->get(['id', 'codigo', 'nome', 'taxa_imposto', 'bloqueado'])->keyBy('id') : collect();
        $todas = $this->plano->todas();
        $conta = fn (?string $codigo) => $codigo ? ['codigo' => $codigo, 'descricao' => $todas[$codigo]['descricao'] ?? null] : null;
        $produto = fn (?int $id) => $id && isset($produtos[$id]) ? ['id' => $id, 'codigo' => $produtos[$id]->codigo, 'nome' => $produtos[$id]->nome, 'taxa_imposto' => (float) $produtos[$id]->taxa_imposto] : null;

        return [
            'produto_renda' => $produto($c?->produto_renda_id), 'produto_renda_alojamento' => $produto($c?->produto_renda_alojamento_id),
            'conta_caucao_passivo' => $conta($c?->conta_caucao_passivo), 'conta_caucao_activo' => $conta($c?->conta_caucao_activo),
            'conta_caucao_receita' => $conta($c?->conta_caucao_receita), 'conta_ip_credito' => $conta($c?->conta_ip_credito),
            'completa_facturacao' => $c?->produto_renda_id !== null, 'completa_caucao' => $c?->conta_caucao_passivo !== null && $c?->conta_caucao_receita !== null,
            'aviso' => 'O ERP não traz contas nem artigos pré-escolhidos: indique os do plano de contas e do catálogo da empresa. As contas e o tratamento do imposto retido devem ser validados pelo contabilista.',
        ];
    }

    /**
     * @param  array<string, mixed>  $d  produto_renda_id, produto_renda_alojamento_id, conta_caucao_passivo|activo|receita, conta_ip_credito (null apaga)
     * @return array<string, mixed>
     */
    public function gravar(array $d): array
    {
        $valores = [];
        foreach (['produto_renda_id', 'produto_renda_alojamento_id'] as $k) {
            if (! array_key_exists($k, $d)) {
                continue;
            }
            $id = $d[$k] !== null && $d[$k] !== '' ? (int) $d[$k] : null;
            if ($id !== null) {
                $p = Produto::query()->find($id) ?? throw new ErroNegocio('O artigo escolhido não existe nesta empresa.', 'IMO_PRODUTO_INEXISTENTE');
                if ($p->bloqueado) {
                    throw new ErroNegocio("O artigo {$p->codigo} está bloqueado.", 'IMO_PRODUTO_BLOQUEADO');
                }
                if ($k === 'produto_renda_id' && (float) $p->taxa_imposto !== 0.0) {
                    throw new ErroNegocio('O artigo da renda de arrendamento tem de ter IVA 0 % com o motivo de isenção AGT (o arrendamento está isento).', 'IMO_PRODUTO_IVA');
                }
                if ($k === 'produto_renda_alojamento_id' && (float) $p->taxa_imposto <= 0.0) {
                    throw new ErroNegocio('O artigo da renda de alojamento hoteleiro tem de ter a taxa de IVA do alojamento.', 'IMO_PRODUTO_IVA');
                }
            }
            $valores[$k] = $id;
        }
        foreach (ConfiguracaoImobiliario::CAMPOS_CONTA as $k) {
            if (! array_key_exists($k, $d)) {
                continue;
            }
            $codigo = trim((string) ($d[$k] ?? ''));
            if ($codigo !== '') {
                $this->plano->contaDeMovimento($codigo);
            }
            $valores[$k] = $codigo !== '' ? $codigo : null;
        }
        DB::transaction(function () use ($valores) {
            $c = ConfiguracaoImobiliario::query()->lockForUpdate()->first();
            $c ? $c->update($valores + ['atualizado_por' => Autor::nome()]) : ConfiguracaoImobiliario::create($valores + ['criado_por' => Autor::nome(), 'atualizado_por' => Autor::nome()]);
        });

        return $this->obter();
    }

    public function actual(): ?ConfiguracaoImobiliario
    {
        return ConfiguracaoImobiliario::query()->first();
    }

    /** Artigo da renda para a taxa de IVA da renda (isento: o geral; com IVA: o do alojamento). */
    public function produtoDaRenda(float $ivaTaxa): Produto
    {
        $c = $this->actual();
        $id = $ivaTaxa > 0 ? $c?->produto_renda_alojamento_id : $c?->produto_renda_id;
        if (! $id) {
            throw new ErroNegocio($ivaTaxa > 0 ? 'Falta o artigo de serviço da renda com IVA (alojamento): indique-o na configuração do módulo imobiliário.'
                : 'Falta o artigo de serviço «Renda»: indique-o na configuração do módulo imobiliário antes de facturar.', 'IMO_SEM_ARTIGO_RENDA');
        }
        $p = Produto::query()->find($id) ?? throw new ErroNegocio('O artigo configurado para a renda já não existe. Reveja a configuração.', 'IMO_SEM_ARTIGO_RENDA');
        if (abs((float) $p->taxa_imposto - $ivaTaxa) > 0.0001) {
            throw new ErroNegocio("O IVA do artigo {$p->codigo} ({$p->taxa_imposto} %) não bate com o da renda ({$ivaTaxa} %). Reveja a configuração.", 'IMO_PRODUTO_IVA');
        }

        return $p;
    }
}
