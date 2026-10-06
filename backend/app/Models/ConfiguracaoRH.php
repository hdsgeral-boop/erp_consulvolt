<?php

namespace App\Models;

use App\Models\Base\ConfiguracaoRHBase;

/**
 * configuracoes_rh — configuração de RH por empresa (chave → valor JSON), lida e gravada por
 * App\Services\RH\ServicoConfiguracaoRH (GET/PUT /api/rh/configuracao). Decisões 5 e 7 do utilizador (ADR-068).
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em ConfiguracaoRHBase (gerado).
 */
class ConfiguracaoRH extends ConfiguracaoRHBase {}
