<?php

namespace App\Services\Imobiliario;

/**
 * Enumerações e regras puras do cadastro imobiliário (porte de imobiliario_dados.js: PAPEIS, TIPOS_IMOVEL, NATUREZAS,
 * ESTADOS_UNIDADE, TIPOLOGIAS, FINALIDADES, nomePiso, designacaoGerada) e os dados de fábrica lidos de
 * backend/resources/imobiliario/fabrica.json (gerado por ferramentas/paridade/extrair_modelos_imobiliario.mjs).
 * Sem acesso à base de dados: tudo aqui é testável com números à mão.
 */
final class CatalogoImobiliario
{
    public const PAPEIS = [
        'PROPRIO' => ['rot' => 'Próprio', 'exige_proprietario' => false],
        'ARRENDADO' => ['rot' => 'Arrendado a terceiros', 'exige_proprietario' => true],
        'MEDIACAO' => ['rot' => 'Em mediação', 'exige_proprietario' => true],
    ];

    /** rot, pisos (pede n.º de pisos), habitacoes (pede n.º de habitações) */
    public const TIPOS_IMOVEL = [
        'PREDIO' => ['rot' => 'Prédio / edifício', 'pisos' => true, 'habitacoes' => false],
        'CONDOMINIO' => ['rot' => 'Condomínio', 'pisos' => true, 'habitacoes' => true],
        'MORADIA' => ['rot' => 'Moradia', 'pisos' => true, 'habitacoes' => false],
        'APARTAMENTO' => ['rot' => 'Apartamento isolado', 'pisos' => false, 'habitacoes' => false],
        'LOJA' => ['rot' => 'Loja', 'pisos' => false, 'habitacoes' => false],
        'ESCRITORIO' => ['rot' => 'Escritório', 'pisos' => false, 'habitacoes' => false],
        'ARMAZEM' => ['rot' => 'Armazém / galpão', 'pisos' => false, 'habitacoes' => false],
        'TERRENO' => ['rot' => 'Terreno', 'pisos' => false, 'habitacoes' => false],
        'OUTRO' => ['rot' => 'Outro', 'pisos' => false, 'habitacoes' => false],
    ];

    public const NATUREZAS = [
        'HABITACIONAL' => ['rot' => 'Fracção', 'conta' => true],
        'ESTACIONAMENTO' => ['rot' => 'Vaga de estacionamento', 'conta' => false],
        'ARRECADACAO' => ['rot' => 'Arrecadação', 'conta' => false],
    ];

    /** Naturezas que esta onda sabe gravar (a vaga de estacionamento é do Parqueamento, onda seguinte). */
    public const NATUREZAS_ACTIVAS = ['HABITACIONAL', 'ARRECADACAO'];

    /** `ocupa`: conta para a taxa de ocupação (ARRENDADA e VENDIDA). */
    public const ESTADOS_FRACAO = [
        'DISPONIVEL' => ['rot' => 'Disponível', 'ocupa' => false],
        'RESERVADA' => ['rot' => 'Reservada', 'ocupa' => false],
        'ARRENDADA' => ['rot' => 'Arrendada', 'ocupa' => true],
        'VENDIDA' => ['rot' => 'Vendida', 'ocupa' => true],
        'EM_OBRA' => ['rot' => 'Em obra', 'ocupa' => false],
        'INDISPONIVEL' => ['rot' => 'Indisponível', 'ocupa' => false],
    ];

    /**
     * Mudanças de estado permitidas. O legado deixava passar qualquer estado para qualquer outro; aqui só se recusa o que não
     * faz sentido: uma fracção VENDIDA não volta a ser reservada, arrendada nem posta em obra (reverte-se primeiro para
     * Disponível ou Indisponível, com motivo).
     */
    public const TRANSICOES = [
        'DISPONIVEL' => ['RESERVADA', 'ARRENDADA', 'VENDIDA', 'EM_OBRA', 'INDISPONIVEL'],
        'RESERVADA' => ['DISPONIVEL', 'ARRENDADA', 'VENDIDA', 'EM_OBRA', 'INDISPONIVEL'],
        'ARRENDADA' => ['DISPONIVEL', 'RESERVADA', 'VENDIDA', 'EM_OBRA', 'INDISPONIVEL'],
        'VENDIDA' => ['DISPONIVEL', 'INDISPONIVEL'],
        'EM_OBRA' => ['DISPONIVEL', 'RESERVADA', 'ARRENDADA', 'VENDIDA', 'INDISPONIVEL'],
        'INDISPONIVEL' => ['DISPONIVEL', 'RESERVADA', 'ARRENDADA', 'VENDIDA', 'EM_OBRA'],
    ];

    /** Estados de partida ou de chegada que exigem motivo escrito (sair de arrendada/vendida; ficar indisponível). */
    public const EXIGEM_MOTIVO_AO_SAIR = ['ARRENDADA', 'VENDIDA'];

    public const EXIGEM_MOTIVO_AO_CHEGAR = ['INDISPONIVEL'];

    public const TIPOLOGIAS = ['T0' => 'T0 (estúdio)', 'T1' => 'T1', 'T2' => 'T2', 'T3' => 'T3', 'T4' => 'T4', 'T5' => 'T5 ou maior', 'LOJA' => 'Loja',
        'ESCRITORIO' => 'Escritório', 'ARMAZEM' => 'Armazém', 'GARAGEM' => 'Garagem / estacionamento', 'ARRECADACAO' => 'Arrecadação', 'TERRENO' => 'Terreno', 'OUTRA' => 'Outra'];

    public const FINALIDADES = ['HABITACAO' => 'Habitação', 'COMERCIO' => 'Comércio', 'SERVICOS' => 'Serviços / escritórios', 'INDUSTRIA' => 'Indústria',
        'ARMAZENAGEM' => 'Armazenagem', 'ESTACIONAMENTO' => 'Estacionamento', 'OUTRA' => 'Outra'];

    public const MOBILIA = ['NAO' => 'Não mobilada', 'PARCIAL' => 'Parcialmente mobilada', 'SIM' => 'Mobilada'];

    public const ESTADOS_IMOVEL = ['ACTIVO' => 'Activo', 'INACTIVO' => 'Inactivo', 'ALIENADO' => 'Alienado'];

    public const ISENCOES_DETENCAO = ['NAO' => 'Sem isenção', 'METADE' => 'Redução de 50%', 'TOTAL' => 'Isento'];

    public const ESQUEMAS = ['PORTA' => 'Número de porta (101, 102, 201…)', 'LETRA' => 'Letra por piso (A, B, C…)', 'NUMERO' => 'Número por piso (1, 2, 3…)',
        'ESQ_DTO' => 'Esquerdo / Direito'];

    public const TIPOS_DOCUMENTO = ['MATRIZ' => 'Caderneta predial / matriz', 'REGISTO' => 'Certidão do registo predial', 'LICENCA' => 'Licença de utilização',
        'PLANTA' => 'Planta', 'ESCRITURA' => 'Escritura', 'CONTRATO' => 'Contrato', 'AVALIACAO' => 'Avaliação', 'FOTO' => 'Fotografias', 'OUTRO' => 'Outro'];

    public const PROVINCIAS = ['Bengo', 'Benguela', 'Bié', 'Cabinda', 'Cuando', 'Cubango', 'Cuanza Norte', 'Cuanza Sul', 'Cunene', 'Huambo', 'Huíla', 'Icolo e Bengo',
        'Luanda', 'Lunda Norte', 'Lunda Sul', 'Malanje', 'Moxico', 'Moxico Leste', 'Namibe', 'Uíge', 'Zaire'];

    /** Limite do legado (500), agora por imóvel e contando os blocos (o legado não os multiplicava). */
    public const MAXIMO_GERAR = 500;

    /** Nome do piso: <-1 «N.ª cave», -1 «Cave», 0 «R/C», senão «N.º andar» (nomePiso do legado). */
    public static function nomePiso(int $piso): string
    {
        return match (true) {
            $piso < -1 => abs($piso).'.ª cave',
            $piso === -1 => 'Cave',
            $piso === 0 => 'R/C',
            default => $piso.'.º andar',
        };
    }

    /** Designação gerada para a fracção `$ordem` do piso `$piso` (designacaoGerada do legado; ESQ_DTO já trata pisos negativos). */
    public static function designacaoGerada(string $esquema, int $piso, int $ordem, string $bloco = ''): string
    {
        $pref = $bloco !== '' ? $bloco.' · ' : '';
        $dois = str_pad((string) $ordem, 2, '0', STR_PAD_LEFT);

        return match ($esquema) {
            'PORTA' => $piso < 0 ? $pref.'C'.abs($piso).$dois : ($piso === 0 ? $pref.$dois : $pref.($piso * 100 + $ordem)),
            'LETRA' => $pref.self::nomePiso($piso).' · '.chr(65 + (($ordem - 1) % 26)),
            'ESQ_DTO' => $pref.($piso === 0 ? 'R/C' : ($piso < 0 ? self::nomePiso($piso) : $piso.'.º')).' '.['Esq.', 'Dto.', 'Frt.', 'Tras.'][($ordem - 1) % 4],
            default => $pref.self::nomePiso($piso).' · '.$ordem,
        };
    }

    /** O número da fracção gerada (PORTA: piso×100+ordem para pisos não negativos). */
    public static function numeroGerado(string $esquema, int $piso, int $ordem): string
    {
        return (string) ($esquema === 'PORTA' && $piso >= 0 ? $piso * 100 + $ordem : $ordem);
    }

    /** Uma fracção «principal» é a que não é vaga nem arrecadação (natureza em falta conta como principal). */
    public static function principal(?string $natureza): bool
    {
        return $natureza === null || $natureza === 'HABITACIONAL';
    }

    /** @return array<string, mixed> dados de fábrica (tipos, variáveis, modelos, taxas_base) */
    public static function fabrica(): array
    {
        static $cache = null;

        return $cache ??= (array) json_decode((string) file_get_contents(resource_path('imobiliario/fabrica.json')), true);
    }

    /** @return array<string, float> valores de arranque das taxas (a validar pelo contabilista/jurista) */
    public static function taxasBase(): array
    {
        return array_map('floatval', (array) (self::fabrica()['taxas_base'] ?? []));
    }

    /** Rótulos para o frontend: um único pedido com todas as enumerações do ecrã. */
    public static function listas(): array
    {
        $rot = fn (array $m) => array_map(fn ($v, $k) => ['valor' => $k, 'rotulo' => is_array($v) ? $v['rot'] : $v] + (is_array($v) ? array_diff_key($v, ['rot' => 1]) : []), $m, array_keys($m));

        return [
            'papeis' => $rot(self::PAPEIS), 'tipos_imovel' => $rot(self::TIPOS_IMOVEL), 'naturezas' => $rot(self::NATUREZAS), 'estados_fracao' => $rot(self::ESTADOS_FRACAO),
            'tipologias' => $rot(self::TIPOLOGIAS), 'finalidades' => $rot(self::FINALIDADES), 'mobilia' => $rot(self::MOBILIA), 'estados_imovel' => $rot(self::ESTADOS_IMOVEL),
            'isencoes_detencao' => $rot(self::ISENCOES_DETENCAO), 'esquemas' => $rot(self::ESQUEMAS), 'tipos_documento' => $rot(self::TIPOS_DOCUMENTO),
            'provincias' => self::PROVINCIAS, 'transicoes' => self::TRANSICOES, 'naturezas_activas' => self::NATUREZAS_ACTIVAS,
        ];
    }
}
