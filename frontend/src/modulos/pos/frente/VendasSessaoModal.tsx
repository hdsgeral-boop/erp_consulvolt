import { Button, Input, Modal, Table, Tag, Tooltip } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useNavigate } from 'react-router';
import { obter } from '@/api/cliente';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { obterTalaoFiscal } from '../comum/dados';
import { htmlTalaoVenda, lerPreferencias, reimprimir, useCabecalhoTalao } from '../comum/impressao';
import type { Terminal, VendaEmitida } from '../comum/tipos';

/** Venda da sessão na consulta (GET /pos/sessoes/{id}/vendas-consulta, ServicoConsultaVendasPOS). */
export interface VendaConsulta {
  id: number;
  numero_documento: string;
  tipo_documento: string;
  estado: string;
  criado_em: string | null;
  cliente_id: number | null;
  cliente_nome: string;
  total_bruto: string;
  pos_pagamentos: { nome?: string; tipo: string; valor: string }[];
  pos_operador: string | null;
  notas_credito: { id: number; numero_documento: string; total_bruto: string }[];
  saldo_creditavel: string;
  nc_permitida: boolean;
  nc_motivo_bloqueio: string | null;
}

/** Rota da facturação de Vendas, onde está a nota de crédito (a janela «Converter» abre sozinha). */
export const ROTA_FACTURACAO = '/m/vendas/vendas_faturacao';

/**
 * Consulta e reimpressão das vendas da sessão (F1b; o legado não tinha) com o atalho «Emitir NC»: leva ao documento em Vendas com a
 * janela de conversão em nota de crédito aberta. O atalho só está activo se o servidor o permitir (sessão ainda não integrada, saldo
 * por creditar) e o utilizador puder emitir documentos.
 */
export function VendasSessaoModal({ sessaoId, terminal, aberto, aoFechar }: { sessaoId: number; terminal: Terminal; aberto: boolean; aoFechar: () => void }) {
  const { pode, empresa } = useSessao();
  const navegar = useNavigate();
  const cabecalho = useCabecalhoTalao();
  const [q, setQ] = useState('');
  const [aImprimir, setAImprimir] = useState<number | null>(null);
  const lista = useQuery({
    queryKey: ['pos', 'vendas-sessao', sessaoId, q],
    queryFn: () => obter<VendaConsulta[]>(`/pos/sessoes/${sessaoId}/vendas-consulta`, q ? { q } : undefined),
    enabled: aberto,
    retry: false,
  });

  const reimprimirVenda = async (v: VendaConsulta) => {
    setAImprimir(v.id);
    try {
      const venda = await obter<VendaEmitida>(`/pos/sessoes/${sessaoId}/vendas-consulta/${v.id}`);
      const fiscal = await obterTalaoFiscal(v.id);
      const p = lerPreferencias(empresa?.id);
      reimprimir(htmlTalaoVenda(venda, cabecalho({ terminal: `${terminal.codigo} — ${terminal.nome}` }), p, new Map(), fiscal), p);
    } catch (e) {
      notificarErro(e, 'Não foi possível reimprimir o talão');
    } finally {
      setAImprimir(null);
    }
  };

  const emitirNc = (v: VendaConsulta) =>
    Modal.confirm({
      title: `Emitir nota de crédito de ${v.numero_documento}?`,
      content: 'Vai sair do ecrã do POS para o módulo de Vendas (a caixa continua aberta). A nota de crédito credita a conta do cliente.',
      okText: 'Ir para Vendas',
      cancelText: 'Cancelar',
      onOk: () => {
        aoFechar();
        navegar(`${ROTA_FACTURACAO}/${v.id}`, { state: { abrir: 'converter' } });
      },
    });

  return (
    <Modal open={aberto} onCancel={aoFechar} width={larguraModal(980)} title="Vendas da sessão" footer={<Button onClick={aoFechar}>Fechar</Button>} destroyOnHidden>
      <Input.Search allowClear placeholder="Procurar pelo n.º do documento" aria-label="Procurar vendas da sessão" style={{ marginBottom: 12, maxWidth: 360 }} onSearch={setQ} maxLength={60} />
      <Table<VendaConsulta>
        size="small"
        rowKey="id"
        loading={lista.isLoading}
        scroll={scrollTabela()}
        pagination={{ pageSize: 10, hideOnSinglePage: true }}
        dataSource={lista.data ?? []}
        locale={{ emptyText: lista.isError ? 'Não foi possível carregar as vendas.' : 'Sem vendas nesta sessão.' }}
        columns={[
          { title: 'Documento', dataIndex: 'numero_documento', render: (n: string, v) => (<>{n} {v.estado === 'ANULADO' && <Tag color="red">Anulado</Tag>}{v.notas_credito.length > 0 && <Tag color="orange">NC {v.notas_credito.map((x) => x.numero_documento).join(', ')}</Tag>}</>) },
          { title: 'Hora', dataIndex: 'criado_em', render: (d: string | null) => (d ? formatarDataHora(d) : '—') },
          { title: 'Cliente', dataIndex: 'cliente_nome' },
          { title: 'Meios', render: (_: unknown, v) => v.pos_pagamentos.map((p) => p.nome ?? p.tipo).join(' + ') || '—' },
          { title: 'Operador', dataIndex: 'pos_operador', render: (o: string | null) => o ?? '—' },
          { title: 'Total', dataIndex: 'total_bruto', align: 'right', render: (t: string) => `${formatarKz(t)} Kz` },
          {
            title: 'Acções',
            render: (_: unknown, v) => (
              <span style={{ display: 'inline-flex', gap: 6, flexWrap: 'wrap' }}>
                <Button size="small" loading={aImprimir === v.id} onClick={() => void reimprimirVenda(v)}>
                  Reimprimir
                </Button>
                <Tooltip title={v.nc_permitida ? 'Emitir nota de crédito em Vendas' : (v.nc_motivo_bloqueio ?? 'Sem permissão')}>
                  <span>
                    <Button size="small" danger disabled={!v.nc_permitida || !pode('vendas_fat_emitir')} onClick={() => emitirNc(v)}>
                      Emitir NC
                    </Button>
                  </span>
                </Tooltip>
              </span>
            ),
          },
        ]}
      />
    </Modal>
  );
}
