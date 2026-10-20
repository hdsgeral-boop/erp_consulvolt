<?php

namespace App\Console\Commands;

use App\Models\PlanoLicenca;
use App\Services\Plataforma\ServicoVersoesPlano;
use App\Services\Sistema\ServicoLicenca;
use Illuminate\Console\Command;

/**
 * Cria (se faltar) o plano interno da equipa: sem limites de empresas nem de utilizadores, todos os módulos e sem custo,
 * e publica a sua primeira versão pelo mesmo fluxo da plataforma (rascunho → publicação). Repetível: se o plano já tem
 * uma versão em vigor, não faz nada.
 */
final class CriarPlanoInterno extends Command
{
    public const CODIGO = 'interno';

    protected $signature = 'erp:planos:criar-interno';

    protected $description = 'Cria o plano interno sem limites (todos os módulos, sem custo) e publica a sua versão';

    public function handle(ServicoLicenca $licenca, ServicoVersoesPlano $versoes): int
    {
        $plano = PlanoLicenca::query()->where('codigo', self::CODIGO)->first();

        if ($plano === null) {
            $licenca->gravarPlano(null, [
                'codigo' => self::CODIGO,
                'nome' => 'Interno (sem limites)',
                'descricao' => 'Plano interno para a equipa e para testes: sem limite de empresas nem de utilizadores, todos os módulos, sem custo.',
                'preco_mensal' => 0,
                'moeda' => 'AOA',
                'max_empresas' => null,
                'max_utilizadores' => null,
                'ativo' => true,
                'ordem' => 0,
            ]);
            $plano = PlanoLicenca::query()->where('codigo', self::CODIGO)->firstOrFail();
            $this->info('Plano «interno» criado.');
        }

        if ($versoes->emVigor($plano) !== null) {
            $this->line('O plano «interno» já tem uma versão em vigor: nada a fazer.');

            return self::SUCCESS;
        }

        $rascunho = $versoes->criarRascunho($plano);
        $versoes->atualizarRascunho($rascunho, [
            'preco_mensal' => 0,
            'moeda' => 'AOA',
            'max_empresas' => null,
            'max_utilizadores' => null,
            'modulos' => array_keys((array) config('erp.planos_catalogo.modulos')),
        ]);
        $publicada = $versoes->publicar($rascunho->refresh());
        $this->info("Versão {$publicada->numero} do plano «interno» publicada (sem limites, todos os módulos).");

        return self::SUCCESS;
    }
}
