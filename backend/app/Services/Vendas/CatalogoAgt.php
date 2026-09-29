<?php

namespace App\Services\Vendas;

/** Catálogos da facturação electrónica / SAF-T(AO) (js/facturacao_agt.js:40-76 do legado). */
final class CatalogoAgt
{
    /** Códigos de taxa de IVA. */
    public const CODIGOS_TAXA = ['0' => 'ISE', '14' => 'NOR', '7' => 'INT', '5' => 'RED'];

    /** Motivos de isenção de IVA (descrições resumidas; confirmar o enquadramento com o consultor fiscal). */
    public const ISENCOES = [
        'M00' => 'IVA – Regime Simplificado', 'M02' => 'Transmissão de bens e serviço não sujeita', 'M04' => 'IVA – Regime de Exclusão',
        'M10' => 'Art.º 12.º CIVA — bens alimentares (anexo I)', 'M11' => 'Art.º 12.º CIVA — medicamentos',
        'M12' => 'Art.º 12.º CIVA — cadeiras de rodas e artefactos para deficientes', 'M13' => 'Art.º 12.º CIVA — livros, incluindo digitais',
        'M14' => 'Art.º 12.º CIVA — locação de imóveis para habitação', 'M15' => 'Art.º 12.º CIVA — operações sujeitas a SISA',
        'M16' => 'Art.º 12.º CIVA — jogos sujeitos a Imposto Especial', 'M17' => 'Art.º 12.º CIVA — transporte colectivo de passageiros',
        'M18' => 'Art.º 12.º CIVA — intermediação e locação financeira', 'M19' => 'Art.º 12.º CIVA — seguros de saúde e de vida',
        'M20' => 'Art.º 12.º CIVA — produtos petrolíferos (anexo II)', 'M21' => 'Art.º 12.º CIVA — ensino reconhecido',
        'M22' => 'Art.º 12.º CIVA — prestações médico-sanitárias', 'M23' => 'Art.º 12.º CIVA — transporte de doentes em ambulância',
        'M24' => 'Art.º 12.º CIVA — equipamentos médicos', 'M30' => 'Art.º 15.º CIVA — bens expedidos para o estrangeiro',
        'M31' => 'Art.º 15.º CIVA — abastecimento de embarcações internacionais', 'M32' => 'Art.º 15.º CIVA — abastecimento de aeronaves internacionais',
        'M33' => 'Art.º 15.º CIVA — embarcações de salvamento e de guerra', 'M34' => 'Art.º 15.º CIVA — embarcações e aeronaves internacionais',
        'M35' => 'Art.º 15.º CIVA — relações diplomáticas e consulares', 'M36' => 'Art.º 15.º CIVA — organismos internacionais',
        'M37' => 'Art.º 15.º CIVA — tratados internacionais', 'M38' => 'Art.º 15.º CIVA — transporte de pessoas de/para o estrangeiro',
        'M80' => 'Art.º 14.º CIVA — importações com transmissão isenta', 'M81' => 'Art.º 14.º CIVA — ouro e moedas (BNA)',
        'M82' => 'Art.º 14.º CIVA — calamidades', 'M83' => 'Art.º 14.º CIVA — operações petrolíferas e mineiras',
        'M84' => 'Art.º 14.º CIVA — moeda estrangeira', 'M85' => 'Art.º 14.º CIVA — tratados e acordos internacionais',
        'M86' => 'Art.º 14.º CIVA — relações diplomáticas', 'M90' => 'Art.º 16.º CIVA — zonas francas',
        'M91' => 'Art.º 16.º CIVA — zonas e depósitos aduaneiros', 'M92' => 'Art.º 16.º CIVA — regimes aduaneiros especiais',
        'M93' => 'Art.º 16.º CIVA — trânsito e importação temporária', 'M94' => 'Art.º 16.º CIVA — reimportação no mesmo estado',
    ];

    public static function codigoTaxa(string|float $taxa): string
    {
        return self::CODIGOS_TAXA[self::taxaTexto($taxa)] ?? 'OUT';
    }

    /** "14.0000" -> "14"; "7.5" -> "7.5" */
    public static function taxaTexto(string|float $taxa): string
    {
        return rtrim(rtrim(number_format((float) $taxa, 4, '.', ''), '0'), '.');
    }
}
