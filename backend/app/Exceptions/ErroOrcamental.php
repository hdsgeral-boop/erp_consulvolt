<?php

namespace App\Exceptions;

use App\Models\LogAlertaOrcamental;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;

/**
 * Documento travado pelo controlo orçamental (bloqueio ou excesso por aprovar). O registo do alerta é feito pelo
 * manipulador de excepções DEPOIS de a transacção do documento ter sido desfeita — senão desapareceria com ela.
 * A empresa e o utilizador guardam-se no momento do erro: quando o manipulador corre, o contexto do pedido já pode
 * ter sido limpo.
 */
final class ErroOrcamental extends ErroNegocio
{
    private readonly ?int $empresa;

    private readonly ?string $utilizador;

    /** @param  list<array<string, mixed>>  $alertas */
    public function __construct(string $mensagem, string $codigo, array $detalhes, private readonly array $alertas, private readonly string $acao)
    {
        parent::__construct($mensagem, $codigo, 422, $detalhes);
        $this->empresa = app(ContextoEmpresa::class)->id();
        $this->utilizador = Auth::user()?->nome_utilizador;
    }

    public function registar(): void
    {
        if (! $this->empresa) {
            return;
        }
        app(ContextoEmpresa::class)->executarComo($this->empresa, function () {
            foreach ($this->alertas as $a) {
                LogAlertaOrcamental::create(['em' => now(), 'por' => $this->utilizador, 'origem' => $a['origem'], 'documento' => $a['documento'],
                    'rubrica_orcamental_id' => $a['rubrica_orcamental_id'], 'orcamento_anual_id' => $a['orcamento_anual_id'], 'percentagem' => $a['percentagem'],
                    'estado' => $a['estado'], 'acao' => $this->acao, 'valor' => $a['documento']]);
            }
        });
    }
}
