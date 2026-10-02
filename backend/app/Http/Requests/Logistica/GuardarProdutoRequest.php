<?php

namespace App\Http\Requests\Logistica;

use App\Rules\TaxaIvaLegal;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST/PUT /api/logistica/produtos. O stock não é editável aqui (só movimentos de inventário). */
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
        $conta = ['nullable', 'string', 'max:20'];

        return [
            'codigo' => [$criar ? 'required' : 'sometimes', 'string', 'max:50'],
            'nome' => [$criar ? 'required' : 'sometimes', 'string', 'max:255'],
            'preco_unitario' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'taxa_imposto' => ['nullable', 'numeric', new TaxaIvaLegal],
            'movimenta_stock' => ['nullable', 'boolean'],
            'e_servico' => ['nullable', 'boolean'],
            'categoria_produto_id' => ['nullable', 'integer', Rule::exists('categorias_produtos', 'id')->where('empresa_id', $empresa)->whereNull('eliminado_em')],
            'codigo_conta' => $conta, 'conta_compra' => $conta, 'conta_inventario' => $conta, 'conta_custo' => $conta,
            'conta_iva_liquidado' => $conta, 'conta_iva_dedutivel' => $conta, 'conta_quebra' => $conta, 'conta_sobra' => $conta,
            'e_ativo_imobilizado' => ['nullable', 'boolean'], 'conta_ativo' => $conta,
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
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['codigo_isencao_fe.regex' => 'O motivo de isenção deve ser um código AGT (M00 a M99).'];
    }
}
