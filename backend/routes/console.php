<?php

use Illuminate\Support\Facades\Schedule;

// Partições anuais da auditoria preparadas com antecedência (1 de Dezembro).
Schedule::command('erp:auditoria:particoes --anos=2')->yearlyOn(12, 1, '02:00');

// Facturação electrónica: envio automático e consulta de estados na AGT (no legado corria no browser, de 2 em 2 min).
Schedule::command('erp:agt:ciclo')->everyTwoMinutes()->withoutOverlapping();
