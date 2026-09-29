<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Perfil de permissões. Legado: user_profiles (formatos v2 e antigo — ver ServicoPermissoes).
 */
class PerfilUtilizador extends ModeloBase
{
    use Auditavel;

    protected $table = 'perfis_utilizador';

    protected string $moduloAuditoria = 'Sistema/Perfis';

    protected $fillable = ['nome', 'descricao', 'permissoes'];

    protected function casts(): array
    {
        return ['permissoes' => 'array'];
    }

    public function utilizadores(): HasMany
    {
        return $this->hasMany(Utilizador::class, 'perfil_utilizador_id');
    }
}
