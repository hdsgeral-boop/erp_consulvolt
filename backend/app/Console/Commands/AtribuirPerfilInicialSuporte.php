<?php

namespace App\Console\Commands;

use App\Models\PerfilUtilizador;
use App\Models\Utilizador;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ADR-077: antes desta funcionalidade, um agente de suporte sem perfil via TODOS os tickets, de todas as
 * categorias — era o único comportamento possível, porque SuporteController não tinha nenhuma verificação de
 * permissão. Agora, sem perfil, um agente passa a não ver tickets nenhuns (seguro por omissão). Este comando
 * (idempotente) mantém o acesso de quem já estava activo: atribui, a cada AGENTE_SUPORTE sem perfil_utilizador_id,
 * um perfil único com as 3 chaves `suporte_tickets_*_gerir` (todas as categorias) — até alguém o restringir à mão.
 */
final class AtribuirPerfilInicialSuporte extends Command
{
    protected $signature = 'erp:suporte:atribuir-perfil-inicial {--simular : mostra quem seria alterado e desfaz tudo (ROLLBACK)}';

    protected $description = 'Atribui um perfil com todas as categorias aos agentes de suporte que ainda não têm perfil (ADR-077)';

    private const NOME_PERFIL = 'Agente de Suporte (todas as categorias — atribuição inicial)';

    public function handle(): int
    {
        $simular = (bool) $this->option('simular');
        $agentes = Utilizador::query()->where('papel', Utilizador::PAPEL_AGENTE_SUPORTE)->whereNull('perfil_utilizador_id')->get();
        if ($agentes->isEmpty()) {
            $this->info('Nenhum agente de suporte sem perfil. Nada a fazer.');

            return self::SUCCESS;
        }

        DB::beginTransaction();
        try {
            $perfil = PerfilUtilizador::query()->where('nome', self::NOME_PERFIL)->first()
                ?? PerfilUtilizador::create([
                    'nome' => self::NOME_PERFIL,
                    'descricao' => 'Criado automaticamente por erp:suporte:atribuir-perfil-inicial — mantém o acesso que os agentes já tinham antes da especialização (ADR-077).',
                    'permissoes' => ['_v2' => true, 'suporte_tickets_geral_view' => true, 'suporte_tickets_geral_gerir' => true,
                        'suporte_tickets_financeiro_view' => true, 'suporte_tickets_financeiro_gerir' => true,
                        'suporte_tickets_marketing_view' => true, 'suporte_tickets_marketing_gerir' => true],
                    'restrito_plataforma' => true,
                ]);
            Utilizador::query()->whereIn('id', $agentes->pluck('id'))->update(['perfil_utilizador_id' => $perfil->id]);
            $simular ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('NÃO APLICADA: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Utilizador', 'Email'], $agentes->map(fn (Utilizador $u) => [$u->nome_utilizador, $u->email ?? '—'])->all());
        $this->line(($simular ? 'Seriam atribuídos' : 'Atribuídos').' '.$agentes->count().' agente(s) ao perfil «'.self::NOME_PERFIL.'».');
        $this->info($simular ? 'Simulação: nada foi gravado (ROLLBACK).' : 'Gravado.');

        return self::SUCCESS;
    }
}
