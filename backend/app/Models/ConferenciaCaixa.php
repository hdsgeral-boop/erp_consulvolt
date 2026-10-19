<?php

namespace App\Models;

use App\Models\Base\ConferenciaCaixaBase;

/**
 * conferencias_caixa — /api/tesouraria/conferencias-caixa.
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em ConferenciaCaixaBase (gerado).
 * registado_por (migração 2026_10_16_220000; no esquema gerado desde a ronda 4): utilizador que registou a conferência —
 * o operador (nome_operador) é texto livre.
 */
class ConferenciaCaixa extends ConferenciaCaixaBase {}
