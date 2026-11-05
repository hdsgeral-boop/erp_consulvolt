<?php

namespace App\Models;

use App\Models\Base\DocumentoProjetoBase;

/**
 * documentos_projeto — /api/projetos/{id}/documentos. Cada linha é UMA versão de um documento; as versões do mesmo
 * documento partilham `grupo_uid`. A eliminação é lógica (`eliminado_em`). O ficheiro vive no disco de objectos.
 */
class DocumentoProjeto extends DocumentoProjetoBase
{
    protected $fillable = [
        'empresa_id', 'projeto_id', 'tipo_documento_id', 'nome_ficheiro', 'data_carregamento',
        'tipo_projeto_id', 'titulo', 'versao', 'grupo_uid', 'data_documento', 'autor', 'observacoes', 'caminho', 'mime', 'tamanho_bytes', 'hash_sha256',
        'eliminado_em', 'eliminado_por',
    ];

    /** O caminho no disco e o hash não saem para o cliente (a descarga é sempre pelo endpoint autenticado). */
    protected $hidden = ['caminho', 'hash_sha256'];

    protected function casts(): array
    {
        return parent::casts() + ['tipo_projeto_id' => 'integer', 'versao' => 'integer', 'tamanho_bytes' => 'integer', 'data_documento' => 'date', 'eliminado_em' => 'datetime'];
    }
}
