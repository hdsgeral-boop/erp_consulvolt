<?php

namespace App\Services\Imobiliario;

/**
 * Enumerações de contratos, rendas e caução (rótulos exactos do legado, CT:31-109). Os valores e rótulos servem as listas do
 * ecrã e a validação; o servidor tem sempre a última palavra.
 */
final class CatalogoContratos
{
    public const TIPOS = [
        'ARRENDAMENTO' => 'Arrendamento (a empresa é senhoria)',
        'SUBARRENDAMENTO' => 'Subarrendamento (a empresa subarrenda o que arrendou)',
        'TOMADA' => 'Arrendamento tomado (a empresa é inquilina)',
    ];

    public const AMBITOS = ['FRACCAO' => 'Uma fracção', 'IMOVEL' => 'Imóvel inteiro', 'PARCIAL' => 'Várias fracções'];

    public const FINALIDADES_FISCAIS = ['HABITACAO' => 'Habitação', 'COMERCIO' => 'Comércio', 'ESCRITORIO' => 'Escritório', 'ARMAZEM' => 'Armazém / industrial',
        'ALOJAMENTO' => 'Alojamento hoteleiro', 'OUTRO' => 'Outro'];

    public const PERIODICIDADES = ['MENSAL' => 'Mensal', 'BIMESTRAL' => 'Bimestral', 'TRIMESTRAL' => 'Trimestral', 'SEMESTRAL' => 'Semestral', 'ANUAL' => 'Anual'];

    public const ESTADOS_CONTRATO = ['RASCUNHO' => 'Rascunho', 'ACTIVO' => 'Activo', 'SUSPENSO' => 'Suspenso', 'TERMINADO' => 'Terminado', 'DENUNCIADO' => 'Denunciado'];

    public const ESTADOS_RENDA = ['PREVISTA' => 'Prevista', 'APROVADA' => 'Aprovada', 'FACTURADA' => 'Facturada', 'PAGA' => 'Paga', 'ANULADA' => 'Anulada'];

    public const ACTUALIZACOES = ['NAO' => 'Não actualiza', 'PERC' => 'Percentagem fixa', 'VALOR' => 'Valor fixo'];

    public const ISENCOES = ['NAO' => 'Sem isenção', 'METADE' => 'Redução de 50% (OGE)', 'TOTAL' => 'Isento (OGE)'];

    public const SELO_ENCARGO = ['SENHORIO' => 'Encargo do senhorio', 'INQUILINO' => 'Encargo do inquilino', 'ISENTO' => 'Isento'];

    public const RENOVACOES = ['NAO' => 'Não renova', 'AUTO' => 'Automática'];

    public const DOC_TIPOS = ['FT' => 'Factura (recebe-se depois)', 'FR' => 'Factura-Recibo (pago no acto)'];

    public const TIPOS_CAUCAO = ['RECEBIDA' => 'Recebida', 'DEVOLVIDA' => 'Devolvida', 'RETIDA' => 'Retida'];

    public const LADOS = ['SENHORIO' => 'A empresa recebe', 'INQUILINO' => 'A empresa paga'];

    /** Eventos do trilho do contrato. */
    public const EVENTOS = ['CRIADO' => 'Criado', 'EDITADO' => 'Editado', 'ACTIVADO' => 'Activado', 'SUSPENSO' => 'Suspenso', 'REACTIVADO' => 'Reactivado',
        'TERMINADO' => 'Terminado', 'DENUNCIADO' => 'Denunciado', 'RENOVADO' => 'Renovado', 'RENDA_ACTUALIZADA' => 'Renda actualizada'];

    /** Máximo de rendas que a caução pode valer. */
    public const CAUCAO_MAX_RENDAS = 6;

    /** @return array<string, list<array{valor: string, rotulo: string}>> */
    public static function listas(): array
    {
        $opcoes = fn (array $m) => array_map(fn ($v, $r) => ['valor' => (string) $v, 'rotulo' => $r], array_keys($m), array_values($m));

        return ['tipos' => $opcoes(self::TIPOS), 'ambitos' => $opcoes(self::AMBITOS), 'finalidades_fiscais' => $opcoes(self::FINALIDADES_FISCAIS),
            'periodicidades' => $opcoes(self::PERIODICIDADES), 'estados_contrato' => $opcoes(self::ESTADOS_CONTRATO), 'estados_renda' => $opcoes(self::ESTADOS_RENDA),
            'actualizacoes' => $opcoes(self::ACTUALIZACOES), 'isencoes' => $opcoes(self::ISENCOES), 'selo_encargo' => $opcoes(self::SELO_ENCARGO),
            'renovacoes' => $opcoes(self::RENOVACOES), 'doc_tipos' => $opcoes(self::DOC_TIPOS), 'tipos_caucao' => $opcoes(self::TIPOS_CAUCAO), 'lados' => $opcoes(self::LADOS),
            'eventos' => $opcoes(self::EVENTOS)];
    }
}
