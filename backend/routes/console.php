<?php

use Illuminate\Support\Facades\Schedule;

// Partições anuais da auditoria preparadas com antecedência (1 de Dezembro).
Schedule::command('erp:auditoria:particoes --anos=2')->yearlyOn(12, 1, '02:00');
