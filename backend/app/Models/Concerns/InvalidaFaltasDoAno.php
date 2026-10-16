<?php

namespace App\Models\Concerns;

use App\Services\RH\CacheFaltasDoAno;

/**
 * Modelos que entram no apuramento da efectividade (ServicoAssiduidade::resumoMes): gravar, eliminar ou restaurar um registo
 * invalida o acumulado anual de faltas da empresa em cache (CacheFaltasDoAno), agora e depois do commit.
 * Um modelo pode limitar as colunas que contam com camposFaltasDoAno() (ex.: no colaborador, só o estado e as datas).
 */
trait InvalidaFaltasDoAno
{
    public static function bootInvalidaFaltasDoAno(): void
    {
        $invalidar = fn ($m) => CacheFaltasDoAno::invalidar((int) $m->empresa_id);
        static::saved(function ($m) use ($invalidar) {
            $campos = $m->camposFaltasDoAno();
            if ($campos === null || $m->wasRecentlyCreated || $m->wasChanged($campos)) {
                $invalidar($m);
            }
        });
        static::deleted($invalidar);
        if (method_exists(static::class, 'restored')) {
            static::restored($invalidar);
        }
    }

    /** @return list<string>|null colunas que mudam o apuramento (null = todas) */
    public function camposFaltasDoAno(): ?array
    {
        return null;
    }
}
