<?php

namespace App\Services\Migracao;

use App\Exceptions\ErroNegocio;
use Generator;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;

/**
 * Leitura por streaming de um export Dexie ({formatName:"dexie", data:{data:[{tableName, rows:[...]}]}}).
 * Nunca carrega o ficheiro inteiro em memória: 1.ª passagem lê só os nomes das tabelas, 2.ª passagem
 * emite as linhas uma a uma.
 */
final class LeitorBackupDexie
{
    public function __construct(private readonly string $caminho)
    {
        if (! is_file($caminho) || ! is_readable($caminho)) {
            throw new ErroNegocio("Ficheiro de backup não encontrado ou ilegível: {$caminho}", 'BACKUP_INEXISTENTE');
        }
    }

    public function validarFormato(): void
    {
        foreach (Items::fromFile($this->caminho, ['pointer' => '/formatName']) as $formato) {
            if ($formato !== 'dexie') {
                throw new ErroNegocio("Formato de backup inesperado: '{$formato}' (esperado 'dexie').", 'BACKUP_FORMATO');
            }

            return;
        }
        throw new ErroNegocio('O ficheiro não tem formatName: não é um export Dexie.', 'BACKUP_FORMATO');
    }

    /** @return array<int, string> índice da tabela no ficheiro => nome da tabela */
    public function tabelas(): array
    {
        $nomes = [];
        $itens = Items::fromFile($this->caminho, ['pointer' => '/data/data/-/tableName']);
        foreach ($itens as $nome) {
            $nomes[$this->indice($itens->getCurrentJsonPointer())] = $nome;
        }

        return $nomes;
    }

    /**
     * @param  array<int, string>  $tabelas
     * @return Generator<int, array{0: string, 1: array<string, mixed>}>
     */
    public function linhas(array $tabelas): Generator
    {
        $itens = Items::fromFile($this->caminho, ['pointer' => '/data/data/-/rows', 'decoder' => new ExtJsonDecoder(true)]);
        foreach ($itens as $linha) {
            yield [$tabelas[$this->indice($itens->getCurrentJsonPointer())], $linha];
        }
    }

    private function indice(string $ponteiro): int
    {
        // "/data/data/17/rows/42" ou "/data/data/17/tableName"
        return (int) explode('/', $ponteiro)[3];
    }
}
