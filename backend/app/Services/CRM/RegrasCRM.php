<?php

namespace App\Services\CRM;

use Carbon\CarbonImmutable;

/**
 * Constantes e funções puras do CRM (crm_dados.js:26-43, 177, 256-273, 339-342).
 */
final class RegrasCRM
{
    public const TIPOS_ATIVIDADE = ['CHAMADA' => 'Telefonar', 'EMAIL' => 'Enviar email', 'REUNIAO' => 'Reunião', 'TAREFA' => 'Tarefa', 'NOTA' => 'Nota'];

    public const TIPOS_ETAPA = ['ABERTA', 'GANHA', 'PERDIDA'];

    public const MOTIVOS_PADRAO = ['Preço', 'Concorrência', 'Falta de produto / stock', 'Prazo de entrega', 'Cliente sem orçamento', 'Sem resposta do cliente',
        'Requisitos não cumpridos', 'Outro'];

    public const ORIGENS_PADRAO = ['Website', 'Recomendação', 'Telefone', 'Feira / evento', 'Redes sociais', 'Email marketing', 'Visita comercial', 'Cliente existente', 'Outro'];

    public const MARCADORES = ['cliente', 'contacto', 'oportunidade', 'valor', 'responsavel', 'empresa', 'data_fecho'];

    /**
     * Código normalizado da origem (domínio da coluna `origem`; o texto do utilizador fica em `origem_original`).
     * Mapa da ETL (normalizacoes.mjs: crm_accounts.origem) alargado às origens padrão; o resto fica sem código.
     */
    private const MAPA_ORIGEM = ['RECOMENDACAO' => 'RECOMENDACAO', 'CLIENTE EXISTENTE' => 'CLIENTE_EXISTENTE', 'WEBSITE' => 'SITE', 'SITE' => 'SITE',
        'EMAIL MARKETING' => 'CAMPANHA', 'REDES SOCIAIS' => 'CAMPANHA', 'CAMPANHA' => 'CAMPANHA', 'OUTRO' => 'OUTRO'];

    /** Tipos de documento de venda que a conversão aceita e os que marcam a oportunidade como ganha (crm_dados.js:322). */
    public const DOCUMENTOS_CONVERSAO = ['OR', 'PF', 'NE', 'FT', 'FR'];

    public const DOCUMENTOS_GANHA = ['FT', 'FR', 'NE'];

    public static function hoje(): string
    {
        return now()->toDateString();
    }

    public static function somarDias(string $data, int $n): string
    {
        return CarbonImmutable::parse(substr($data, 0, 10))->addDays($n)->toDateString();
    }

    public static function diasEntre(?string $a, ?string $b): int
    {
        if (! $a || ! $b) {
            return 0;
        }

        return (int) CarbonImmutable::parse(substr($a, 0, 10))->diffInDays(CarbonImmutable::parse(substr($b, 0, 10)), false);
    }

    public static function dobrar(string $s): string
    {
        $s = preg_replace('/\p{Mn}/u', '', \Normalizer::normalize($s, \Normalizer::FORM_D));

        return mb_strtoupper(trim(preg_replace('/[\s_\-]+/', ' ', (string) $s)));
    }

    /** @return array{origem: ?string, origem_original: ?string} */
    public static function origem(?string $texto): array
    {
        $t = trim((string) $texto);

        return ['origem' => $t === '' ? null : (self::MAPA_ORIGEM[self::dobrar($t)] ?? null), 'origem_original' => $t === '' ? null : mb_substr($t, 0, 100)];
    }

    public static function preencher(?string $texto, array $ctx): string
    {
        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', fn ($m) => isset($ctx[$m[1]]) ? (string) $ctx[$m[1]] : '', (string) $texto);
    }

    public static function emailValido(?string $email): bool
    {
        return $email === null || $email === '' || preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email) === 1;
    }

    /** Identificador curto de etapa (crm_dados.js:38). */
    public static function novoId(): string
    {
        return substr(str_shuffle(str_repeat('abcdefghijklmnopqrstuvwxyz0123456789', 3)), 0, 8);
    }

    /** Probabilidade efectiva: a da oportunidade ou, se vazia, a da etapa (probabilidadeDe, crm_dados.js:177). */
    public static function probabilidade(?string $daOportunidade, ?array $etapa): string
    {
        return $daOportunidade !== null && $daOportunidade !== '' ? bcadd($daOportunidade, '0', 4) : bcadd((string) ($etapa['probabilidade'] ?? 0), '0', 4);
    }

    public static function ponderado(string $valor, string $probabilidade): string
    {
        $v = bcdiv(bcmul($valor, $probabilidade, 8), '100', 8);

        return bcadd($v, '0.005', 2);
    }

    public static function etapa(?array $etapas, ?string $codigo): ?array
    {
        foreach ($etapas ?? [] as $e) {
            if (($e['id'] ?? null) === $codigo) {
                return $e;
            }
        }

        return null;
    }
}
