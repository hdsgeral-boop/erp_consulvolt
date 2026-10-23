<?php

namespace App\Http\Controllers\Api\Plataforma;

use App\Http\Controllers\Controller;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\DefinicoesPlataforma;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Definições globais da plataforma (`definicoes_plataforma`). Só as chaves conhecidas podem ser alteradas. */
final class DefinicoesController extends Controller
{
    private const CHAVES = [
        'nome_plataforma' => ['required', 'string', 'max:80'],
        'email_suporte' => ['nullable', 'email', 'max:150'],
        'mensagem_manutencao' => ['nullable', 'string', 'max:500'],
    ];

    public function index(): JsonResponse
    {
        return RespostaApi::sucesso($this->valores(), 'Definições obtidas com sucesso.');
    }

    public function atualizar(Request $r): JsonResponse
    {
        $regras = [];
        foreach (self::CHAVES as $chave => $regra) {
            $regras[$chave] = array_merge(['sometimes'], $regra);
        }
        $d = $r->validate($regras);

        foreach ($d as $chave => $valor) {
            DB::table('definicoes_plataforma')->updateOrInsert(['chave' => $chave], ['valor' => $valor, 'atualizado_em' => now()]);
        }
        DefinicoesPlataforma::invalidar();

        return RespostaApi::sucesso($this->valores(), 'Definições guardadas.');
    }

    /** @return array<string, string|null> */
    private function valores(): array
    {
        $guardados = DB::table('definicoes_plataforma')->pluck('valor', 'chave');

        return collect(array_keys(self::CHAVES))->mapWithKeys(fn (string $c) => [$c => $guardados[$c] ?? null])->all();
    }
}