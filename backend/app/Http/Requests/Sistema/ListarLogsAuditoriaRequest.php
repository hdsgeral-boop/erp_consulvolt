<?php

namespace App\Http\Requests\Sistema;

use Illuminate\Foundation\Http\FormRequest;

/** Filtros da consulta de auditoria (paridade: config_logs, js/ui_aux.js:1082). */
final class ListarLogsAuditoriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'modulo' => ['nullable', 'string', 'max:100'],
            'acao' => ['nullable', 'string', 'max:100'],
            'nome_utilizador' => ['nullable', 'string', 'max:100'],
            'tabela' => ['nullable', 'string', 'max:100'],
            'registo_id' => ['nullable', 'string', 'max:255'],
            'pesquisa' => ['nullable', 'string', 'max:200'],
            'data_inicio' => ['nullable', 'date'],
            'data_fim' => ['nullable', 'date', 'after_or_equal:data_inicio'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:200'],
            'pagina' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
