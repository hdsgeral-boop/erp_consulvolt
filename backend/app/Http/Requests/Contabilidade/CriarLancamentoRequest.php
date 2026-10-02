<?php

namespace App\Http\Requests\Contabilidade;

use App\Services\Sistema\ServicoCambios;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/contabilidade/lancamentos — um lançamento (documento) com N linhas em partida dobrada. */
final class CriarLancamentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // permissão verificada no controller (catálogo do legado)
    }

    private function emMoeda(): bool
    {
        $m = strtoupper(trim((string) $this->input('codigo_moeda', '')));

        return $m !== '' && $m !== ServicoCambios::BASE;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $empresa = app(ContextoEmpresa::class)->obrigatorio();
        $daEmpresa = fn (string $tabela) => Rule::exists($tabela, 'id')->where('empresa_id', $empresa);

        return [
            'diario_id' => ['required', 'integer', Rule::exists('diarios_contabeis', 'id')->where('empresa_id', $empresa)->whereNull('eliminado_em')],
            'data_documento' => ['required', 'date_format:Y-m-d'],
            'numero_documento' => ['nullable', 'string', 'max:100'],
            'referencia' => ['nullable', 'string', 'max:100'],
            'descricao' => ['nullable', 'string', 'max:1000'],
            // M7 — lançamento em moeda estrangeira (js/moedas_lancamentos.js): moeda e câmbio do documento; cada linha em valor_moeda
            // (o valor em Kz é calculado no servidor). Sem câmbio indicado, usa-se a tabela de câmbios na data fiscal.
            'codigo_moeda' => ['nullable', 'string', 'size:3'],
            'taxa_cambio' => ['nullable', 'numeric', 'gt:0'],
            'linhas' => ['required', 'array', 'min:2', 'max:500'],
            'linhas.*.codigo_conta' => ['required', 'string', 'max:20'],
            'linhas.*.tipo_dc' => ['required', Rule::in(['D', 'C'])],
            'linhas.*.valor' => [$this->emMoeda() ? 'nullable' : 'required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999999.99'],
            'linhas.*.valor_moeda' => [$this->emMoeda() ? 'required' : 'prohibited', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999999.99'],
            'linhas.*.descricao' => ['nullable', 'string', 'max:1000'],
            'linhas.*.terceiro_id' => ['nullable', 'integer', $daEmpresa('terceiros')],
            'linhas.*.centro_custo_id' => ['nullable', 'integer', $daEmpresa('centros_custo')],
            'linhas.*.unidade_negocio_id' => ['nullable', 'integer', $daEmpresa('unidades_negocio')],
            'linhas.*.projeto_id' => ['nullable', 'integer', $daEmpresa('projetos')],
            'linhas.*.nota_demonstracao_id' => ['nullable', 'integer', $daEmpresa('notas_demonstracao_resultados')],
            'linhas.*.nota_fluxo_caixa_id' => ['nullable', 'integer', $daEmpresa('notas_fluxo_caixa')],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'diario_id' => 'diário', 'data_documento' => 'data do documento', 'linhas' => 'linhas do lançamento',
            'linhas.*.codigo_conta' => 'conta', 'linhas.*.tipo_dc' => 'débito/crédito', 'linhas.*.valor' => 'valor',
        ];
    }
}
