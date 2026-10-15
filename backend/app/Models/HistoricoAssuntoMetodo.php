<?php

namespace App\Models;

use App\Models\Base\HistoricoAssuntoMetodoBase;

/**
 * historico_assuntos_metodo — /api/metodo/assuntos/{id}/historico (mt_assunto_log do legado).
 * Só se acrescenta: o histórico de um assunto não se edita nem se apaga.
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em HistoricoAssuntoMetodoBase (gerado).
 */
class HistoricoAssuntoMetodo extends HistoricoAssuntoMetodoBase {}
