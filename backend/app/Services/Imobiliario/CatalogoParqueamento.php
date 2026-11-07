<?php

namespace App\Services\Imobiliario;

/**
 * Enumerações e regras puras do parqueamento e das áreas comuns (porte de PARK_TIPOS, PARK_PORTES, PARK_AFECTACOES, normalizarMatricula,
 * porteCabe, vagaArrendavel, TIPOS_AREA, REGIMES, UNIDADES_PRECO, ESTADOS_RESERVA e TRANSICOES do legado). Sem acesso à base de dados:
 * tudo aqui é testável com números à mão.
 */
final class CatalogoParqueamento
{
    public const PARK_TIPOS = [
        'DESCOBERTO' => ['rot' => 'Descoberto', 'ajuda' => 'Ao ar livre, sem cobertura.'],
        'COBERTO' => ['rot' => 'Coberto', 'ajuda' => 'Com cobertura mas sem fecho.'],
        'SUBTERRANEO' => ['rot' => 'Subterrâneo', 'ajuda' => 'Em cave. É aqui que a altura máxima importa.'],
        'BOX' => ['rot' => 'Box fechada', 'ajuda' => 'Compartimento próprio com porta. Vale mais e pode guardar outras coisas.'],
    ];

    /** `ordem`: o veículo cabe no lugar se a sua ordem não for maior do que a do lugar. */
    public const PARK_PORTES = [
        'MOTA' => ['rot' => 'Mota', 'ordem' => 1],
        'LIGEIRO' => ['rot' => 'Ligeiro', 'ordem' => 2],
        'SUV' => ['rot' => 'SUV / 4×4', 'ordem' => 3],
        'PESADO' => ['rot' => 'Pesado / carrinha', 'ordem' => 4],
    ];

    /** `pede_fraccao`: a afectação exige a fracção-mãe; `arrendavel`: pode entrar num contrato. */
    public const PARK_AFECTACOES = [
        'PERMANENTE' => ['rot' => 'Afecta a uma fracção', 'ajuda' => 'Acessório da fracção: arrendar a fracção leva a vaga junto.', 'pede_fraccao' => true, 'arrendavel' => true],
        'AUTONOMA' => ['rot' => 'Autónoma', 'ajuda' => 'Arrenda-se sozinha, mesmo a quem não mora no prédio.', 'pede_fraccao' => false, 'arrendavel' => true],
        'VISITA' => ['rot' => 'Visitas', 'ajuda' => 'Uso comum e rotativo. Nunca entra em contratos nem conta para a ocupação.', 'pede_fraccao' => false, 'arrendavel' => false],
        'CARGA_DESCARGA' => ['rot' => 'Cargas e descargas', 'ajuda' => 'Lugar de serviço. Nunca entra em contratos.', 'pede_fraccao' => false, 'arrendavel' => false],
    ];

    public const TIPOS_AREA = [
        'SALAO' => ['rot' => 'Salão de festas', 'capacidade' => true], 'GINASIO' => ['rot' => 'Ginásio', 'capacidade' => true], 'PISCINA' => ['rot' => 'Piscina', 'capacidade' => true],
        'CHURRASQUEIRA' => ['rot' => 'Churrasqueira', 'capacidade' => true], 'REUNIOES' => ['rot' => 'Sala de reuniões', 'capacidade' => true],
        'COWORKING' => ['rot' => 'Espaço de trabalho', 'capacidade' => true], 'TERRACO' => ['rot' => 'Terraço', 'capacidade' => true], 'CAMPO' => ['rot' => 'Campo de jogos', 'capacidade' => false],
        'LAVANDARIA' => ['rot' => 'Lavandaria comum', 'capacidade' => false], 'PARQUE_INFANTIL' => ['rot' => 'Parque infantil', 'capacidade' => false], 'OUTRO' => ['rot' => 'Outro espaço', 'capacidade' => false],
    ];

    public const REGIMES = [
        'GRATUITO' => ['rot' => 'Uso livre', 'ajuda' => 'Entra quem quer, quando quer. Não se marca nem se cobra — fica no cadastro só para constar e para a manutenção.', 'reserva' => false, 'paga' => false],
        'RESERVA_GRATUITA' => ['rot' => 'Reserva obrigatória, sem custo', 'ajuda' => 'Marca-se para não haver duas festas no mesmo sábado, mas não se paga nada.', 'reserva' => true, 'paga' => false],
        'RESERVA_PAGA' => ['rot' => 'Reserva paga', 'ajuda' => 'Marca-se e paga-se. O espaço só fica garantido depois do recebimento.', 'reserva' => true, 'paga' => true],
    ];

    /** `horas`: duração de uma unidade de preço (0 = valor fixo por reserva). */
    public const UNIDADES_PRECO = [
        'HORA' => ['rot' => 'por hora', 'horas' => 1], 'PERIODO' => ['rot' => 'por período de 4 horas', 'horas' => 4], 'DIA' => ['rot' => 'por dia', 'horas' => 24],
        'FIXO' => ['rot' => 'valor fixo por reserva', 'horas' => 0],
    ];

    /** `viva`: a reserva ainda pode mudar; `ocupa`: ocupa o horário do espaço (a realizada continua a contar no historial do dia). */
    public const ESTADOS_RESERVA = [
        'PEDIDA' => ['rot' => 'Pedida', 'ocupa' => true, 'viva' => true],
        'APROVADA' => ['rot' => 'Aprovada', 'ocupa' => true, 'viva' => true],
        'AGUARDA_PAGAMENTO' => ['rot' => 'A aguardar pagamento', 'ocupa' => true, 'viva' => true],
        'CONFIRMADA' => ['rot' => 'Confirmada', 'ocupa' => true, 'viva' => true],
        'REALIZADA' => ['rot' => 'Realizada', 'ocupa' => true, 'viva' => false],
        'FALTOU' => ['rot' => 'Não compareceu', 'ocupa' => false, 'viva' => false],
        'CANCELADA' => ['rot' => 'Cancelada', 'ocupa' => false, 'viva' => false],
        'RECUSADA' => ['rot' => 'Recusada', 'ocupa' => false, 'viva' => false],
    ];

    /**
     * Máquina de estados da reserva. Correcção do legado: aprovar uma reserva paga leva a «a aguardar pagamento» (o legado saltava-o e a
     * reserva ficava «aprovada» sem nunca ser facturada nem confirmada); a transição PEDIDA → AGUARDA_PAGAMENTO existe mas a própria
     * aprovação escolhe o destino conforme o regime do espaço.
     */
    public const TRANSICOES = [
        'PEDIDA' => ['APROVADA', 'AGUARDA_PAGAMENTO', 'RECUSADA', 'CANCELADA'],
        'APROVADA' => ['REALIZADA', 'FALTOU', 'CANCELADA', 'AGUARDA_PAGAMENTO'],
        'AGUARDA_PAGAMENTO' => ['CONFIRMADA', 'CANCELADA'],
        'CONFIRMADA' => ['REALIZADA', 'FALTOU', 'CANCELADA'],
        'REALIZADA' => [], 'FALTOU' => [], 'CANCELADA' => [], 'RECUSADA' => [],
    ];

    public const ESTADOS_CAUCAO = ['NAO_APLICA' => 'Sem caução', 'POR_RECEBER' => 'Por receber', 'EM_PODER' => 'Em poder da empresa', 'DEVOLVIDA' => 'Devolvida',
        'RETIDA_PARCIAL' => 'Devolvida em parte', 'RETIDA_TOTAL' => 'Retida por danos'];

    public const ESTADOS_VISTORIA = ['BOM' => 'Bom — sem danos', 'SUJO' => 'Entregue sujo', 'DANIFICADO' => 'Com danos', 'EM_FALTA' => 'Com coisas em falta'];

    public const DIAS = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];

    /** Limite do legado de vagas geradas de uma vez. */
    public const MAXIMO_VAGAS = 500;

    /** Maiúsculas, sem separadores; com 8 caracteres fica `LD-12-34-AB`. Não recusa matrículas estrangeiras. */
    public static function normalizarMatricula(?string $v): string
    {
        $s = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(trim((string) $v))) ?? '';
        if (strlen($s) === 8) {
            return substr($s, 0, 2).'-'.substr($s, 2, 2).'-'.substr($s, 4, 2).'-'.substr($s, 6);
        }

        return $s;
    }

    /** O veículo cabe no lugar: ordem do veículo (0 se desconhecido) ≤ ordem do lugar (9 se desconhecido). */
    public static function porteCabe(?string $vaga, ?string $veiculo): bool
    {
        return (self::PARK_PORTES[$veiculo ?? '']['ordem'] ?? 0) <= (self::PARK_PORTES[$vaga ?? '']['ordem'] ?? 9);
    }

    public static function ehVaga(?string $natureza): bool
    {
        return $natureza === 'ESTACIONAMENTO';
    }

    /** Arrendável: vaga cuja afectação é PERMANENTE ou AUTONOMA (afectação em falta não é arrendável, como no legado). */
    public static function vagaArrendavel(?string $natureza, ?string $afectacao): bool
    {
        return self::ehVaga($natureza) && (self::PARK_AFECTACOES[$afectacao ?? '']['arrendavel'] ?? false);
    }

    /** Designação da vaga gerada: `G-101` (piso −1, 1.º lugar), `G-01` ao R/C (piso 0). */
    public static function designacaoVaga(string $prefixo, int $piso, int $k): string
    {
        return $prefixo.'-'.($piso === 0 ? str_pad((string) $k, 2, '0', STR_PAD_LEFT) : (string) (abs($piso) * 100 + $k));
    }

    /** Sequência numérica da vaga gerada: |piso|×100 + k. */
    public static function sequenciaVaga(int $piso, int $k): int
    {
        return abs($piso) * 100 + $k;
    }

    /** Hora «HH:MM» (aceita 24:00) em minutos; inválida devolve null. */
    public static function minutos(?string $hora): ?int
    {
        if ($hora === null || preg_match('/^(\d{1,2}):(\d{2})$/', trim($hora), $m) !== 1) {
            return null;
        }
        $h = (int) $m[1];
        $min = (int) $m[2];

        return $h > 24 || $min > 59 || ($h === 24 && $min > 0) ? null : $h * 60 + $min;
    }

    public static function hhmm(int $minutos): string
    {
        return str_pad((string) intdiv($minutos, 60), 2, '0', STR_PAD_LEFT).':'.str_pad((string) ($minutos % 60), 2, '0', STR_PAD_LEFT);
    }

    /** @return array<string, mixed> rótulos para o frontend: um único pedido com todas as enumerações dos ecrãs. */
    public static function listas(): array
    {
        $rot = fn (array $m) => array_map(fn ($v, $k) => ['valor' => $k, 'rotulo' => is_array($v) ? $v['rot'] : $v] + (is_array($v) ? array_diff_key($v, ['rot' => 1]) : []), $m, array_keys($m));

        return [
            'park_tipos' => $rot(self::PARK_TIPOS), 'park_portes' => $rot(self::PARK_PORTES), 'park_afectacoes' => $rot(self::PARK_AFECTACOES), 'tipos_area' => $rot(self::TIPOS_AREA),
            'regimes' => $rot(self::REGIMES), 'unidades_preco' => $rot(self::UNIDADES_PRECO), 'estados_reserva' => $rot(self::ESTADOS_RESERVA), 'transicoes' => self::TRANSICOES,
            'estados_caucao' => $rot(self::ESTADOS_CAUCAO), 'estados_vistoria' => $rot(self::ESTADOS_VISTORIA), 'dias' => self::DIAS,
        ];
    }
}
