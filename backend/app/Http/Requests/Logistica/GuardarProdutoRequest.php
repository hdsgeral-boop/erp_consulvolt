<?php

namespace App\Http\Requests\Logistica;

use App\Models\Produto;
use App\Rules\TaxaIvaLegal;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST/PUT /api/logistica/produtos. O stock não é editável aqui (só movimentos de inventário).
 * Ronda 3: a categoria é obrigatória (ao criar, e ao editar um produto que ainda não a tem) e as contas contabilísticas
 * já não se gravam no produto — definem-se na categoria (o pedido que as traga é recusado, para não se perderem em silêncio).
 */
final class GuardarProdutoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $criar = $this->isMethod('post');
        $empresa = app(ContextoEmpresa::class)->obrigatorio();
        $semCategoria = ! $criar && Produto::query()->whereKey((int) $this->route('produto'))->whereNull('categoria_produto_id')->exists();
        $nivel = ['nullable', 'numeric', 'min:0', 'max:999999999'];
        $medida = ['nullable', 'numeric', 'min:0', 'max:999999999'];

        return [
            'codigo' => [$criar ? 'required' : 'sometimes', 'string', 'max:50'],
            'nome' => [$criar ? 'required' : 'sometimes', 'string', 'max:255'],
            'preco_unitario' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'taxa_imposto' => ['nullable', 'numeric', new TaxaIvaLegal],
            'movimenta_stock' => ['nullable', 'boolean'],
            'e_servico' => ['nullable', 'boolean'],
            'categoria_produto_id' => [$criar || $semCategoria ? 'required' : 'sometimes', 'required', 'integer',
                Rule::exists('categorias_produtos', 'id')->where('empresa_id', $empresa)->whereNull('eliminado_em')],
            ...array_fill_keys(Produto::CAMPOS_CONTA, ['prohibited']),
            'e_ativo_imobilizado' => ['nullable', 'boolean'],
            'e_quarto' => ['nullable', 'boolean'],
            'preco_por_hora' => ['nullable', 'numeric', 'min:0'], 'preco_por_dia' => ['nullable', 'numeric', 'min:0'], 'horas_minimas' => ['nullable', 'numeric', 'min:0'],
            'imagem_base64' => ['nullable', 'string', 'max:2000000'],
            'unidade_fe' => ['nullable', 'string', 'max:10'],
            'tipo_operacao_fe' => ['nullable', 'string', 'max:20'],
            'codigo_isencao_fe' => ['nullable', 'string', 'regex:/^M\d{2}$/'],
            'lavandaria_ativa' => ['nullable', 'boolean'], 'lavandaria_grupo' => ['nullable', 'string', 'max:50'],
            'lavandaria_unidade' => ['nullable', 'string', 'max:20'], 'lavandaria_dias_entrega' => ['nullable', 'integer', 'min:0'],
            'lavandaria_requer_orcamento' => ['nullable', 'boolean'],
            'lavandaria_preco_peca' => ['nullable', 'numeric', 'min:0'], 'lavandaria_preco_kg' => ['nullable', 'numeric', 'min:0'],
            // níveis de stock (a ordem mínimo ≤ segurança ≤ ponto de encomenda ≤ máximo é validada no serviço)
            'stock_minimo' => $nivel, 'stock_seguranca' => $nivel, 'ponto_encomenda' => $nivel, 'stock_maximo' => $nivel,
            // ficha técnica
            'marca' => ['nullable', 'string', 'max:100'], 'modelo' => ['nullable', 'string', 'max:100'],
            'comprimento' => $medida, 'largura' => $medida, 'altura' => $medida, 'unidade_dimensao' => ['nullable', Rule::in(['mm', 'cm', 'm'])],
            'peso_liquido' => $medida, 'peso_bruto' => $medida,
            'caracteristicas' => ['nullable', 'array', 'max:60'],
            'caracteristicas.*.chave' => ['nullable', 'string', 'max:100'], 'caracteristicas.*.valor' => ['nullable', 'string', 'max:500'],
            'observacoes_tecnicas' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['codigo_isencao_fe.regex' => 'O motivo de isenção deve ser um código AGT (M00 a M99).',
            'categoria_produto_id.required' => 'Escolha a categoria do produto (é a categoria que define as contas contabilísticas).']
            + array_fill_keys(array_map(fn ($c) => "{$c}.prohibited", Produto::CAMPOS_CONTA), 'As contas contabilísticas definem-se na categoria do produto (Vendas › Categorias).');
    }
}
