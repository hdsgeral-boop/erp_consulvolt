<?php

namespace App\Models;

use App\Models\Base\AlocacaoAquisicaoAtivoBase;

/**
 * alocacoes_aquisicao_ativos — parte de uma linha de aquisição (11/12 a débito) coberta por um activo inventariado em
 * combinação de várias linhas (ServicoAquisicoesAtivos::combinar; legado journal_line_id = "ID1,ID2").
 * Estrutura, $fillable e relações em AlocacaoAquisicaoAtivoBase (gerado — TABELAS_NOVAS, ronda 4).
 */
final class AlocacaoAquisicaoAtivo extends AlocacaoAquisicaoAtivoBase {}
