<?php

namespace App\Services\Integracoes\Preferencias;

use App\Exceptions\ErroNegocio;
use Illuminate\Support\Facades\DB;

/**
 * Preferências do utilizador no servidor (M-19): cada utilizador só lê e escreve as suas. Tipos fechados (lista branca),
 * nomes curtos, valores JSON até 64 KB e no máximo 200 entradas por tipo — não é um armazenamento genérico.
 */
final class ServicoPreferencias
{
    public const TIPOS = ['favoritos', 'ordem_modulos', 'visoes_cubo', 'interface'];

    public const MAX_BYTES = 65536;

    public const MAX_POR_TIPO = 200;

    /** @return list<array{nome: string, valor: mixed, atualizado_em: ?string}> */
    public function listar(int $utilizador, string $tipo): array
    {
        $this->exigirTipo($tipo);

        return DB::table('preferencias_utilizador')->where('utilizador_id', $utilizador)->where('tipo', $tipo)->orderBy('nome')
            ->get(['nome', 'valor', 'atualizado_em'])->map(fn ($p) => ['nome' => $p->nome, 'valor' => json_decode((string) $p->valor, true), 'atualizado_em' => $p->atualizado_em])->all();
    }

    public function gravar(int $utilizador, string $tipo, string $nome, mixed $valor): array
    {
        $this->exigirTipo($tipo);
        $json = json_encode($valor, JSON_UNESCAPED_UNICODE);
        if ($json === false || strlen($json) > self::MAX_BYTES) {
            throw new ErroNegocio('Preferência demasiado grande (máximo 64 KB).', 'PREFERENCIA_INVALIDA', 422);
        }
        $existe = DB::table('preferencias_utilizador')->where('utilizador_id', $utilizador)->where('tipo', $tipo)->where('nome', $nome)->exists();
        if (! $existe && DB::table('preferencias_utilizador')->where('utilizador_id', $utilizador)->where('tipo', $tipo)->count() >= self::MAX_POR_TIPO) {
            throw new ErroNegocio('Atingiu o número máximo de preferências deste tipo ('.self::MAX_POR_TIPO.').', 'PREFERENCIA_LIMITE', 422);
        }
        DB::table('preferencias_utilizador')->upsert([['utilizador_id' => $utilizador, 'tipo' => $tipo, 'nome' => $nome, 'valor' => $json, 'criado_em' => now(), 'atualizado_em' => now()]],
            ['utilizador_id', 'tipo', 'nome'], ['valor', 'atualizado_em']);

        return ['nome' => $nome, 'valor' => $valor];
    }

    public function eliminar(int $utilizador, string $tipo, string $nome): bool
    {
        $this->exigirTipo($tipo);

        return DB::table('preferencias_utilizador')->where('utilizador_id', $utilizador)->where('tipo', $tipo)->where('nome', $nome)->delete() > 0;
    }

    private function exigirTipo(string $tipo): void
    {
        if (! in_array($tipo, self::TIPOS, true)) {
            throw new ErroNegocio('Tipo de preferência desconhecido.', 'PREFERENCIA_TIPO_INVALIDO', 404);
        }
    }
}
