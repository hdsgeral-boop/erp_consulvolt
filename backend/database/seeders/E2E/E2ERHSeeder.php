<?php

namespace Database\Seeders\E2E;

use App\Models\Colaborador;
use App\Models\ContratoTrabalho;
use App\Models\InfotipoSalarial;
use App\Models\MapeamentoContabilRH;
use App\Models\MapeamentoContabilSistemaRH;
use App\Models\TipoOrganizacaoRH;

/**
 * Recursos Humanos: tipo de organização, rubricas salariais, três colaboradores activos com contrato e o mapeamento
 * contabilístico completo (rubricas e contas de sistema), suficientes para calcular, validar e contabilizar um período.
 * Corre dentro de ContextoEmpresa::executarComo (empresa activa).
 */
final class E2ERHSeeder
{
    public function executar(): void
    {
        $org = TipoOrganizacaoRH::create(['nome' => 'Colaboradores']);
        $base = InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Salário Base', 'sujeito_inss' => true, 'irt' => 'true']);
        $alim = InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Subsídio de alimentação', 'sujeito_inss' => false, 'irt' => 'conditional_30k']);
        $transp = InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Subsídio de transporte', 'sujeito_inss' => false, 'irt' => 'conditional_30k']);
        $adiant = InfotipoSalarial::create(['tipo' => 'DESCONTO', 'nome' => 'Adiantamento', 'sujeito_inss' => false, 'irt' => 'false']);

        $inicio = now()->subYear()->startOfMonth()->toDateString();
        $pessoas = [
            ['Colaboradora Demo Ana Fictícia', '5999100001', 'F', 300000],
            ['Colaborador Demo Bruno Fictício', '5999100002', 'M', 180000],
            ['Colaboradora Demo Carla Fictícia', '5999100003', 'F', 95000],
        ];
        foreach ($pessoas as $i => [$nome, $nif, $sexo, $salario]) {
            $c = Colaborador::create(['nome_completo' => $nome, 'nif' => $nif, 'numero_inss' => '9'.str_pad((string) ($i + 1), 8, '0', STR_PAD_LEFT),
                'estado' => 'ACTIVO', 'tipo_organizacao_id' => $org->id, 'dias_uteis_mes' => 22, 'sexo' => $sexo, 'data_admissao' => $inicio,
                'email' => 'colaborador'.($i + 1).'@exemplo.invalid']);
            ContratoTrabalho::create(['colaborador_id' => $c->id, 'estado' => 'ACTIVO', 'dias_contrato_mes' => 22, 'horas_por_dia' => 8, 'data_inicio' => $inicio,
                'codigo_moeda' => 'AOA', 'remuneracoes' => [
                    ['infotype_id' => $base->id, 'value_month' => $salario],
                    ['infotype_id' => $alim->id, 'value_month' => 40000],
                    ['infotype_id' => $transp->id, 'value_month' => 25000],
                ]]);
        }

        foreach ([[$base, '721'], [$alim, '722'], [$transp, '722'], [$adiant, '3612']] as [$rubrica, $conta]) {
            MapeamentoContabilRH::create(['infotipo_salarial_id' => $rubrica->id, 'tipo_organizacao_id' => $org->id, 'avencado' => false, 'numero_conta' => $conta]);
        }
        foreach (['NET_PAY_CREDIT' => '3611', 'IRT_CREDIT' => '3421', 'INSS_FUNC_CREDIT' => '3431', 'INSS_EMP_DEBIT' => '725', 'INSS_EMP_CREDIT' => '3431'] as $codigo => $conta) {
            MapeamentoContabilSistemaRH::create(['codigo' => $codigo, 'tipo_organizacao_id' => $org->id, 'avencado' => false, 'numero_conta' => $conta]);
        }
    }
}
