<?php

namespace App\Models\Concerns;

use App\Services\Sistema\ServicoAuditoria;
use Illuminate\Database\Eloquent\Model;

/**
 * Auditoria automática de criação, alteração e eliminação (paridade com os hooks do Dexie,
 * js/db_v2.js:317-337, que registavam "Criou/Atualizou/Eliminou: <tabela>" em audit_logs).
 * Regista também os valores anteriores e novos, que o legado não guardava.
 *
 * Atributos sensíveis (hidden do model) nunca são gravados no log.
 */
trait Auditavel
{
    public static function bootAuditavel(): void
    {
        static::created(fn (Model $m) => app(ServicoAuditoria::class)->registarModelo($m, 'Criou', null, self::atributosAuditaveis($m, $m->getAttributes())));

        static::updated(function (Model $m) {
            $alterados = array_diff_key($m->getChanges(), array_flip([$m->getUpdatedAtColumn()]));
            if ($alterados === []) {
                return;
            }
            $anteriores = array_intersect_key($m->getOriginal(), $alterados);
            app(ServicoAuditoria::class)->registarModelo($m, 'Atualizou', self::atributosAuditaveis($m, $anteriores), self::atributosAuditaveis($m, $alterados));
        });

        static::deleted(fn (Model $m) => app(ServicoAuditoria::class)->registarModelo($m, 'Eliminou', self::atributosAuditaveis($m, $m->getOriginal()), null));
    }

    /** Módulo funcional mostrado nos logs (sobreponível no model). */
    public function moduloAuditoria(): string
    {
        return property_exists($this, 'moduloAuditoria') ? $this->moduloAuditoria : 'Sistema';
    }

    private static function atributosAuditaveis(Model $m, array $atributos): array
    {
        $ocultos = array_merge($m->getHidden(), ['logotipo']);

        return array_diff_key($atributos, array_flip($ocultos));
    }
}
