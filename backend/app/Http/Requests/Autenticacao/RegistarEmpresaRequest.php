<?php

namespace App\Http\Requests\Autenticacao;

use App\Models\Utilizador;
use App\Rules\NifAngolano;
use App\Rules\NomeUtilizador;
use App\Rules\TelefoneAngolano;
use App\Support\Angola\NifAngolano as ValidadorNif;
use App\Support\Angola\ProvinciasAngola;
use App\Support\Angola\TelefoneAngolano as ValidadorTelefone;
use App\Support\Seguranca\PoliticaPalavraPasse;
use App\Support\Texto\TextoEntrada;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Registo público de uma empresa nova (POST /api/autenticacao/registar-empresa). O servidor é a autoridade: o
 * formulário do frontend (RegistarEmpresa.tsx) repete as mesmas regras só para dar resposta imediata.
 * Os textos chegam normalizados (espaços, maiúsculas, NIF e telefone em forma compacta) para o que fica gravado
 * ser sempre igual, venha de onde vier.
 */
final class RegistarEmpresaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $limpo = [];
        foreach (['nome_comercial', 'nome_completo', 'plano'] as $c) {
            if (is_string($this->input($c))) {
                $limpo[$c] = TextoEntrada::limpar($this->input($c));
            }
        }
        if (is_string($this->input('nif'))) {
            $limpo['nif'] = ValidadorNif::normalizar($this->input('nif')) ?: null;
        }
        if (is_string($this->input('telefone'))) {
            $t = TextoEntrada::limpar($this->input('telefone'));
            $limpo['telefone'] = $t === null ? null : ValidadorTelefone::normalizar($t);
        }
        if (is_string($this->input('provincia'))) {
            $p = TextoEntrada::limpar($this->input('provincia'));
            $limpo['provincia'] = $p === null ? null : (ProvinciasAngola::canonica($p) ?? $p);
        }
        foreach (['nome_utilizador', 'email'] as $c) {
            if (is_string($this->input($c))) {
                $t = TextoEntrada::limpar($this->input($c));
                $limpo[$c] = $t === null ? null : mb_strtolower($t);
            }
        }
        $this->merge($limpo);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nome_comercial' => ['required', 'string', 'min:2', 'max:255', 'regex:/^[^<>]+$/u', 'regex:/[\p{L}\p{N}]/u'],
            'nif' => ['required', 'string', new NifAngolano, Rule::unique('empresas', 'nif')->whereNull('eliminado_em')],
            'telefone' => ['required', 'string', 'max:30', new TelefoneAngolano],
            'provincia' => ['required', 'string', Rule::in(ProvinciasAngola::LISTA)],
            'nome_completo' => ['required', 'string', 'min:3', 'max:200', "regex:/^\\p{L}[\\p{L}\\p{M}\\s'’.\\-]*\\p{L}\\.?$/u"],
            'nome_utilizador' => ['required', 'string', new NomeUtilizador],
            'email' => ['required', 'string', 'email:rfc', 'max:150'],
            'palavra_passe' => ['required', 'string'],
            'plano' => ['nullable', 'string', 'max:50', Rule::exists('planos_licenca', 'codigo')->where('ativo', true)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nome_comercial.required' => 'O nome comercial da empresa é obrigatório.',
            'nome_comercial.min' => 'O nome comercial deve ter pelo menos 2 caracteres.',
            'nome_comercial.max' => 'O nome comercial não pode ter mais de 255 caracteres.',
            'nome_comercial.regex' => 'O nome comercial tem caracteres que não são permitidos (< e >) ou não tem letras nem algarismos.',
            'nif.required' => 'O NIF da empresa é obrigatório.',
            'nif.unique' => 'Este NIF já se encontra registado numa empresa existente.',
            'telefone.required' => 'O telefone de contacto é obrigatório.',
            'telefone.max' => 'O telefone não pode ter mais de 30 caracteres.',
            'provincia.required' => 'A província é obrigatória.',
            'provincia.in' => 'Seleccione uma das províncias de Angola.',
            'nome_completo.required' => 'O nome completo do administrador é obrigatório.',
            'nome_completo.min' => 'O nome completo deve ter pelo menos 3 caracteres.',
            'nome_completo.max' => 'O nome completo não pode ter mais de 200 caracteres.',
            'nome_completo.regex' => "O nome só pode ter letras, espaços e os sinais . ' -",
            'nome_utilizador.required' => 'O nome de utilizador para login é obrigatório.',
            'email.required' => 'O endereço de e-mail é obrigatório.',
            'email.email' => 'O endereço de e-mail indicado não é válido.',
            'email.max' => 'O e-mail não pode ter mais de 150 caracteres.',
            'palavra_passe.required' => 'A palavra-passe é obrigatória.',
            'plano.exists' => 'O plano selecionado não é válido.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'nome_comercial' => 'nome comercial', 'nif' => 'NIF', 'telefone' => 'telefone', 'provincia' => 'província',
            'nome_completo' => 'nome completo', 'nome_utilizador' => 'nome de utilizador', 'email' => 'e-mail',
            'palavra_passe' => 'palavra-passe', 'plano' => 'plano',
        ];
    }

    /**
     * Regras que dependem de mais do que um campo, ou da base de dados em todos os clientes. O nome de utilizador e o
     * e-mail são únicos em toda a instalação, mas a RLS esconde os outros clientes: procura-se pelas funções SECURITY
     * DEFINER do login (as mesmas que o login usa), que não devolvem dados a quem chama — só se há ou não conta.
     * O NIF repetido (índice global) é apanhado pelo controlador quando a RLS impede esta validação de o ver.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $v): void {
            $e = $v->errors();
            if (! $e->has('nome_utilizador') && Utilizador::fromQuery('SELECT * FROM login_localizar_utilizador(?, NULL)', [(string) $this->input('nome_utilizador')])->isNotEmpty()) {
                $e->add('nome_utilizador', 'Este nome de utilizador já se encontra registado. Escolha outro.');
            }
            if (! $e->has('email') && Utilizador::fromQuery('SELECT * FROM login_localizar_utilizadores_por_email(?)', [(string) $this->input('email')])->isNotEmpty()) {
                $e->add('email', 'Este e-mail já se encontra associado a um utilizador.');
            }
            if (! $e->has('palavra_passe') && is_string($this->input('palavra_passe'))) {
                $problema = PoliticaPalavraPasse::problema($this->input('palavra_passe'), [$this->input('nome_utilizador'), strstr((string) $this->input('email'), '@', true) ?: null], PoliticaPalavraPasse::MINIMO_REGISTO);
                if ($problema !== null) {
                    $e->add('palavra_passe', $problema);
                }
            }
        }];
    }
}
