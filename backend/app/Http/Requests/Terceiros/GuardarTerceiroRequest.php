<?php

namespace App\Http\Requests\Terceiros;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST/PUT /api/terceiros — `papel` indica a ficha que está a ser gravada: CLIENTE (Vendas › Clientes), FORNECEDOR
 * (Compras › Fornecedores) ou, nas Tabelas auxiliares › Terceiros, também CLIENTE_FORNECEDOR e COLABORADOR com
 * `substituir_tipo` (o tipo escolhido substitui o anterior, como saveThirdParty do legado novo, ui_aux.js:579-659).
 * Contas por papel (Fase A): conta_cliente (classe 31) e conta_fornecedor (32 ou 37.0); `codigo_conta` continua aceite
 * nas fichas antigas e é lido como a conta do papel. `morada`: endereço estruturado (enderecos_terceiros).
 */
final class GuardarTerceiroRequest extends FormRequest
{
    /** Permissões para gravar cada papel (qualquer uma serve). */
    public const GERIR = ['CLIENTE' => ['vendas_clientes_gerir', 'aux_gerir'], 'FORNECEDOR' => ['compras_forn_gerir', 'aux_gerir'],
        'CLIENTE_FORNECEDOR' => ['aux_gerir'], 'COLABORADOR' => ['aux_gerir']];

    /** Antes da validação (OWASP A01): sem permissão para o papel pedido → 403, mesmo com dados inválidos. */
    public function authorize(): bool
    {
        $papel = $this->input('papel');
        $permissoes = match (true) {
            $this->boolean('substituir_tipo') => ['aux_gerir'],
            is_string($papel) && isset(self::GERIR[$papel]) => self::GERIR[$papel],
            default => array_values(array_unique(array_merge(...array_values(self::GERIR)))),
        };

        return Gate::any($permissoes);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $criar = $this->isMethod('post');

        return [
            'papel' => ['required', Rule::in(['CLIENTE', 'FORNECEDOR', 'CLIENTE_FORNECEDOR', 'COLABORADOR'])],
            'substituir_tipo' => ['nullable', 'boolean'],
            'nome' => [$criar ? 'required' : 'sometimes', 'string', 'max:255'],
            'nif' => ['nullable', 'string', 'max:30'],
            'endereco' => ['nullable', 'string', 'max:1000'],
            'email' => ['nullable', 'email:rfc', 'max:150'],
            'telefone' => ['nullable', 'string', 'max:50'],
            'codigo_conta' => ['nullable', 'string', 'max:20'],
            'conta_cliente' => ['nullable', 'string', 'max:20'],
            'conta_fornecedor' => ['nullable', 'string', 'max:20'],
            'conta_compra_transitoria' => ['nullable', 'string', 'max:20'],
            'codigo_moeda' => ['nullable', 'string', Rule::exists('moedas', 'codigo')->whereNull('eliminado_em')],
            'fe_pais' => ['nullable', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'morada' => ['nullable', 'array:rua,numero_porta,bairro,municipio,provincia,pais'],
            'morada.rua' => ['nullable', 'string', 'max:150'],
            'morada.numero_porta' => ['nullable', 'string', 'max:50'],
            'morada.bairro' => ['nullable', 'string', 'max:150'],
            'morada.municipio' => ['nullable', 'string', 'max:150'],
            'morada.provincia' => ['nullable', 'string', 'max:150'],
            'morada.pais' => ['nullable', 'string', 'max:150'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'fe_pais.regex' => 'O país deve ter o código ISO de 2 letras (ex.: AO, PT).',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['nome' => 'nome', 'codigo_conta' => 'conta contabilística', 'conta_cliente' => 'conta de cliente', 'conta_fornecedor' => 'conta de fornecedor',
            'codigo_moeda' => 'moeda', 'fe_pais' => 'país', 'morada.rua' => 'rua', 'morada.numero_porta' => 'n.º de porta', 'morada.bairro' => 'bairro',
            'morada.municipio' => 'município', 'morada.provincia' => 'província', 'morada.pais' => 'país'];
    }

    public function substituirTipo(): bool
    {
        return $this->boolean('substituir_tipo');
    }

    /** @return array<string, mixed> */
    public function dados(): array
    {
        $d = collect($this->validated())->except(['papel', 'substituir_tipo'])->all();
        if (isset($d['fe_pais'])) {
            $d['fe_pais'] = mb_strtoupper($d['fe_pais']);
        }
        foreach (['codigo_conta', 'conta_cliente', 'conta_fornecedor', 'conta_compra_transitoria'] as $c) {
            if (array_key_exists($c, $d) && is_string($d[$c])) {
                $d[$c] = trim($d[$c]) === '' ? null : trim($d[$c]);
            }
        }

        return $d;
    }
}
