<?php

namespace App\Models;

use App\Models\Base\ConferenciaCaixaBase;

/**
 * conferencias_caixa — /api/tesouraria/conferencias-caixa.
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em ConferenciaCaixaBase (gerado).
 * registado_por (migração 2026_10_16_220000, fora do contrato gerado): utilizador que registou a conferência — o operador
 * (nome_operador) é texto livre. Grava-se com forceFill em ServicoConferenciaCaixa (não entra no $fillable do contrato).
 */
class ConferenciaCaixa extends ConferenciaCaixaBase {}
