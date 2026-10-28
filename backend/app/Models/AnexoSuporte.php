<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Anexo de uma mensagem da conversa de um ticket de suporte (ficheiro guardado em disco privado). */
class AnexoSuporte extends ModeloBase
{
    public const CREATED_AT = 'criado_em';

    public const UPDATED_AT = null;

    protected $table = 'anexos_suporte';

    protected $fillable = ['mensagem_id', 'nome_original', 'caminho', 'tamanho_bytes', 'tipo_mime'];

    public function mensagem(): BelongsTo
    {
        return $this->belongsTo(MensagemSuporte::class, 'mensagem_id');
    }
}
