<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Base de todos os models de negócio: carimbos temporais em português e datas em ISO-8601.
 */
abstract class ModeloBase extends Model
{
    public const CREATED_AT = 'criado_em';

    public const UPDATED_AT = 'atualizado_em';

    public const DELETED_AT = 'eliminado_em';

    protected $perPage = 25;

    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format(DateTimeInterface::ATOM);
    }
}
