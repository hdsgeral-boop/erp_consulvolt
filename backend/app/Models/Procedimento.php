<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * procedimentos — edição de uma narrativa de fábrica (origem FABRICA: `personalizado` = texto da empresa; sem texto = só
 * a bandeira «oculto») ou processo próprio da empresa (origem PROPRIO). As etapas e os cruzamentos são tabelas filhas.
 */
class Procedimento extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    public const ORIGEM_FABRICA = 'FABRICA';

    public const ORIGEM_PROPRIO = 'PROPRIO';

    protected $table = 'procedimentos';

    protected string $moduloAuditoria = 'Manuais';

    protected $fillable = ['empresa_id', 'chave', 'origem', 'personalizado', 'oculto', 'titulo', 'objetivo', 'intervenientes', 'ordem', 'fluxo_id', 'modulo', 'base_hash', 'criado_por', 'atualizado_por'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'personalizado' => 'boolean', 'oculto' => 'boolean', 'intervenientes' => 'array', 'ordem' => 'integer'];
    }

    public function etapas(): HasMany
    {
        return $this->hasMany(EtapaProcedimento::class, 'procedimento_id')->orderBy('ordem');
    }

    public function cruzamentos(): HasMany
    {
        return $this->hasMany(CruzamentoProcedimento::class, 'procedimento_id')->orderBy('ordem');
    }
}
