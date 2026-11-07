<?php

namespace Tests\Support;

use App\Models\Armazem;
use App\Models\EncomendaCompra;
use App\Models\ItemCompra;
use App\Models\Produto;
use App\Services\Logistica\ServicoStock;

/**
 * Montagem comum dos testes da Onda 4 (F5) da Gestão Imobiliária: junta à da F4 (empresa, plano de contas, cliente, artigo da renda, sessão,
 * imóvel, fracções, contrato) os artigos (dois de armazém e um serviço), o armazém, as permissões das obras e as simulações das duas origens da
 * recepção (a entrega do armazém faz-se pelo próprio ecrã de Manutenção de Activos; a recepção de Compras escreve-se na encomenda).
 */
trait FixturesImobiliarioF5
{
    use FixturesImobiliarioF4;

    private int $cama;

    private int $colchao;

    private int $servico;

    private int $armazem;

    /** @var array<string, mixed> sessão de quem entrega o material no armazém (ecrã de Manutenção de Activos) */
    private array $sArm;

    /** @var list<string> */
    private const PERMS_OBRAS = ['imo_obras_view', 'imo_fichas_view', 'imo_ficha_editar', 'imo_obra_editar', 'imo_obra_aprovar', 'imo_obra_pedir', 'imo_obra_receber', 'imo_obra_fechar',
        'imo_bens_editar', 'imo_plano_editar', 'imo_plano_gerar', 'imo_sub_debitar'];

    protected function montarObras(): void
    {
        $this->montar();
        $this->ctx(function () {
            $this->cama = (int) Produto::create(['codigo' => 'CAMA', 'nome' => 'Cama de casal', 'preco_unitario' => 0, 'movimenta_stock' => true])->id;
            $this->colchao = (int) Produto::create(['codigo' => 'COLCH', 'nome' => 'Colchão', 'preco_unitario' => 0, 'movimenta_stock' => true])->id;
            $this->servico = (int) Produto::create(['codigo' => 'MONT', 'nome' => 'Montagem de mobiliário', 'preco_unitario' => 0, 'movimenta_stock' => false])->id;
            $this->armazem = (int) Armazem::create(['nome' => 'Armazém central'])->id;
        });
        $this->s = $this->sessao(array_merge($this->perms, self::PERMS_OBRAS));
        $this->sArm = $this->sessao(['activos_manutencao_view', 'activos_manut_requisitar']);
    }

    private function darStock(int $produto, string $quantidade, string $custo = '500'): void
    {
        $this->ctx(fn () => app(ServicoStock::class)->entrada($produto, $this->armazem, $quantidade, $custo, now()->toDateString(), 'Stock inicial de teste'));
    }

    /** @param  array<string, mixed>  $o @return array<string, mixed> */
    private function ordem(array $o = []): array
    {
        return $this->postJson('/api/imobiliario/obras/ordens', $o + ['tipo' => 'CORRECTIVA', 'descricao' => 'Reparar a canalização da cozinha', 'imovel_id' => $this->im], $this->s)->assertCreated()->json('dados');
    }

    /** @param  list<array<string, mixed>>  $linhas */
    private function necessidades(int $ordem, array $linhas): void
    {
        $this->putJson("/api/imobiliario/obras/ordens/{$ordem}/necessidades", ['linhas' => $linhas], $this->s)->assertOk();
    }

    private function aprovar(int $ordem): void
    {
        $this->postJson("/api/imobiliario/obras/ordens/{$ordem}/aprovar", [], $this->s)->assertOk();
    }

    /** @return array<string, mixed> a ficha da ordem */
    private function ficha(int $ordem): array
    {
        return $this->getJson("/api/imobiliario/obras/ordens/{$ordem}", $this->s)->assertOk()->json('dados');
    }

    /** @return array<string, array<string, mixed>> as necessidades da ordem por descrição ou artigo */
    private function porNome(int $ordem): array
    {
        $r = [];
        foreach ($this->ficha($ordem)['necessidades_lista'] as $n) {
            $r[$n['nome']] = $n;
        }

        return $r;
    }

    /**
     * Entrega todo o material de uma requisição pelo ecrã de Manutenção de Activos (saída de stock valorizada ao custo médio).
     *
     * @param  array<int|string, string|int|float>  $entregas  id da linha => quantidade (vazio = tudo)
     */
    private function entregarRequisicao(int $requisicao, array $entregas = []): void
    {
        $this->postJson("/api/ativos/manutencao/requisicoes/{$requisicao}/entregar", ['armazem_id' => $this->armazem, 'entregas' => $entregas], $this->sArm)->assertOk();
    }

    /**
     * Regista na encomenda do pedido o que as Compras já receberam (cria a encomenda na primeira vez e actualiza a seguir).
     *
     * @param  array<int, array{0: string, 1: string, 2: string}>  $linhas  artigo => [quantidade da encomenda, preço, quantidade recebida]
     */
    private function receberCompras(int $pedido, array $linhas): void
    {
        $this->ctx(function () use ($pedido, $linhas) {
            $e = EncomendaCompra::query()->where('pedido_compra_id', $pedido)->first()
                ?? EncomendaCompra::create(['pedido_compra_id' => $pedido, 'numero_encomenda' => 'EC-T'.$pedido, 'estado' => 'EM_PROCESSAMENTO', 'data' => now()]);
            foreach ($linhas as $produto => [$qtd, $preco, $recebida]) {
                $item = ItemCompra::query()->where('encomenda_compra_id', $e->id)->where('produto_id', $produto)->first();
                $campos = ['quantidade' => $qtd, 'preco_unitario' => $preco, 'total' => bcmul($qtd, $preco, 2), 'quantidade_recebida' => $recebida];
                $item ? $item->update($campos) : ItemCompra::create($campos + ['tipo_documento_origem' => 'ENCOMENDA', 'encomenda_compra_id' => $e->id, 'produto_id' => $produto]);
            }
        });
    }
}
