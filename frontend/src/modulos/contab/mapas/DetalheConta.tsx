import { Alert, Button, Drawer, Flex, Skeleton, Space, Statistic, Table, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { BotoesExportar, pares } from '@/componentes/impressao';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import type { Extrato, MovimentoExtrato } from '../api';
import { ValorKz } from '../comum/Componentes';
import { tabelaDeColunas } from '../comum/impressao';
import { useAbrirLancamento } from '../comum/useMapa';

/** Conta de movimento a abrir no detalhe (extracto/razão no período do mapa, com os mesmos filtros). */
export interface PedidoDetalheConta {
  conta: string;
  descricao?: string | null;
  terceiroId?: number | null;
  terceiroNome?: string | null;
  inicio?: string | null;
  fim?: string | null;
  /** Filtros do mapa que também se aplicam ao extracto (diário, UN, CC, classe 9, apuramento). */
  filtros?: Record<string, unknown> | null;
}

const FILTROS_EXTRATO = ['diario_id', 'unidade_negocio_id', 'centro_custo_id', 'incluir_classe_9', 'incluir_apuramento'] as const;

/** Parâmetros do extracto da conta (GET /contabilidade/relatorios/extrato) a partir do pedido do mapa. */
export function parametrosDetalhe(p: PedidoDetalheConta): Record<string, unknown> {
  const extra: Record<string, unknown> = {};
  for (const k of FILTROS_EXTRATO) {
    const v = p.filtros?.[k];
    if (v !== undefined && v !== null && v !== '' && v !== false) extra[k] = v === true ? 1 : v;
  }
  return { filtro_contas: p.conta, terceiro_id: p.terceiroId ?? undefined, data_inicio: p.inicio ?? undefined, data_fim: p.fim ?? undefined, tipo: 'todos', ...extra };
}

/**
 * Detalhe de uma conta de movimento aberto a partir dos mapas (Balancete, Balancete do Razão, Evolução, composição das
 * demonstrações): saldo inicial, débitos, créditos e saldo final do período, e os movimentos com o saldo acumulado — cada um
 * liga ao lançamento. Usa o extracto de conta corrente (os mesmos critérios dos mapas: classe 9 e apuramento excluídos por
 * omissão, filtros de diário/UN/CC).
 */
export function DetalheConta({ pedido, aoFechar }: { pedido: PedidoDetalheConta | null; aoFechar: () => void }) {
  const abrir = useAbrirLancamento();
  const navegar = useNavigate();
  const parametros = pedido ? parametrosDetalhe(pedido) : null;
  const consulta = useQuery({
    queryKey: ['contab', 'mapa', 'detalhe-conta', parametros],
    queryFn: () => obter<Extrato>('/contabilidade/relatorios/extrato', parametros ?? undefined),
    enabled: !!pedido,
    retry: false,
  });
  const d = consulta.data;
  const titulo = pedido ? `Conta ${pedido.conta}${pedido.descricao ? ` — ${pedido.descricao}` : ''}${pedido.terceiroNome ? ` · ${pedido.terceiroNome.trim()}` : ''}` : '';
  const periodo = pedido?.inicio || pedido?.fim ? `${formatarData(pedido?.inicio ?? null)} a ${formatarData(pedido?.fim ?? null)}` : undefined;

  const colunas: ColunaApi<MovimentoExtrato>[] = [
    { title: 'Data', dataIndex: 'data_documento', render: formatarData, width: 100 },
    { title: 'Diário', dataIndex: 'diario', responsive: ['md'] },
    {
      title: 'Nº Lanç.',
      dataIndex: 'numero_lan',
      render: (v: string, r) =>
        abrir ? (
          <Typography.Link title="Abrir o lançamento" onClick={() => abrir(r.id)}>
            {v || r.numero_documento || r.id}
          </Typography.Link>
        ) : (
          v
        ),
      valorImpressao: (r) => r.numero_lan ?? '',
    },
    { title: 'Nº Doc', dataIndex: 'numero_documento', responsive: ['md'], render: (v: string | null) => v ?? '' },
    { title: 'Terceiro', dataIndex: 'terceiro', ellipsis: true, responsive: ['lg'], render: (v: string | null) => v?.trim() ?? '', valorImpressao: (r) => r.terceiro?.trim() ?? '' },
    { title: 'Contrapartida', dataIndex: 'contrapartidas', ellipsis: true, responsive: ['lg'], render: (v: string | null) => v ?? '' },
    { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 240, render: (v: string | null) => <span title={v ?? ''}>{v ?? ''}</span>, valorImpressao: (r) => r.descricao ?? '' },
    { title: 'Débito', key: 'd', align: 'right', render: (_, r) => (r.tipo_dc === 'D' ? <ValorKz valor={r.valor} /> : null), valorImpressao: (r) => (r.tipo_dc === 'D' ? formatarKz(r.valor) : '') },
    { title: 'Crédito', key: 'c', align: 'right', render: (_, r) => (r.tipo_dc === 'C' ? <ValorKz valor={r.valor} /> : null), valorImpressao: (r) => (r.tipo_dc === 'C' ? formatarKz(r.valor) : '') },
    { title: 'Saldo', dataIndex: 'saldo', align: 'right', render: (v: string) => <ValorKz valor={v} forte /> },
  ];

  return (
    <Drawer open={!!pedido} onClose={aoFechar} width={larguraModal(1100)} title={titulo} destroyOnClose>
      {consulta.error ? (
        <Alert type="error" showIcon message="Não foi possível obter os movimentos da conta." description="Verifique se tem acesso ao Extrato de Conta Corrente." />
      ) : !d ? (
        <Skeleton active />
      ) : (
        <>
          {periodo && (
            <Typography.Paragraph type="secondary" style={{ marginTop: 0 }}>
              Período: {periodo}
            </Typography.Paragraph>
          )}
          <Space size={32} wrap style={{ marginBottom: 12 }}>
            <Statistic title="Saldo inicial" value={formatarKz(d.saldo_inicial)} />
            <Statistic title="Débitos" value={formatarKz(d.debito)} />
            <Statistic title="Créditos" value={formatarKz(d.credito)} />
            <Statistic title="Saldo final" value={formatarKz(d.saldo_final)} />
          </Space>
          <Flex wrap gap={8} style={{ marginBottom: 12 }}>
            <BotoesExportar
              tamanho="small"
              obterPedido={async () => ({
                titulo: `Extracto da conta ${pedido?.conta ?? ''}`,
                subtitulo: pedido?.descricao ?? undefined,
                periodo,
                filtros: pedido?.terceiroNome ? [`Terceiro: ${pedido.terceiroNome.trim()}`] : [],
                conteudo:
                  pares([
                    ['Saldo inicial', formatarKz(d.saldo_inicial)],
                    ['Débitos', formatarKz(d.debito)],
                    ['Créditos', formatarKz(d.credito)],
                    ['Saldo final', formatarKz(d.saldo_final)],
                  ], 4) + (await tabelaDeColunas(colunas, d.movimentos)),
              })}
            />
            <Button
              size="small"
              icon={<IconeFa nome="file-invoice-dollar" />}
              onClick={() => navegar('/m/contab/contab_mapa_extrato', { state: { extrato: { conta: pedido?.conta, terceiroId: pedido?.terceiroId, inicio: pedido?.inicio, fim: pedido?.fim } } })}
            >
              Abrir no Extrato
            </Button>
          </Flex>
          <Table<MovimentoExtrato>
            rowKey="id"
            size="small"
            dataSource={d.movimentos}
            columns={colunas}
            scroll={scrollTabela()}
            pagination={{ pageSize: 25, showTotal: (n) => `${n} movimento(s)` }}
            locale={{ emptyText: 'Sem movimentos no período.' }}
          />
        </>
      )}
    </Drawer>
  );
}

/** Botão pequeno «Ver movimentos» ao lado do código de uma conta de movimento. */
export function BotaoDetalheConta({ aoClicar, titulo = 'Ver os movimentos da conta no período' }: { aoClicar: () => void; titulo?: string }) {
  return (
    <Button
      type="text"
      size="small"
      className="imp-nao-imprimir"
      icon={<IconeFa nome="search-plus" />}
      title={titulo}
      aria-label={titulo}
      onClick={(e) => {
        e.stopPropagation();
        aoClicar();
      }}
      style={{ marginLeft: 2, height: 20, width: 20, minWidth: 20, padding: 0 }}
    />
  );
}

/** «Expandir tudo» / «Recolher tudo» das tabelas em árvore dos mapas. */
export function BotoesArvore({ aoExpandir, aoRecolher, desactivado }: { aoExpandir: () => void; aoRecolher: () => void; desactivado?: boolean }) {
  return (
    <Space.Compact className="imp-nao-imprimir">
      <Button size="small" icon={<IconeFa nome="expand-alt" />} onClick={aoExpandir} disabled={desactivado}>
        Expandir tudo
      </Button>
      <Button size="small" icon={<IconeFa nome="compress-alt" />} onClick={aoRecolher} disabled={desactivado}>
        Recolher tudo
      </Button>
    </Space.Compact>
  );
}
