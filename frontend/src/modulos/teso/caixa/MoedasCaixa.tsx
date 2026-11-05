import { Alert, Card, Checkbox, Modal, Skeleton, Table, Tag, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { useAccao } from '@/componentes/Accoes';
import { larguraModal } from '@/componentes/responsivo';
import { formatarKz } from '@/utilitarios/formatacao';
import { useSessao } from '@/sessao/SessaoContexto';
import type { EstadoMoedasCaixa, SaldoCaixaMoeda } from '../api';
import { simboloMoeda } from '../regras';

/** Moedas estrangeiras escolhidas por quem tem `teso_caixa_multimoeda_gerir` (por omissão nenhuma: a caixa só liquida em Kz). */
export function escolhaValida(moedas: string[], disponiveis: string[]): string[] {
  return [...new Set(moedas)].filter((m) => disponiveis.includes(m)).sort();
}

export function ModalMoedasCaixa({ aberto, aoFechar }: { aberto: boolean; aoFechar: () => void }) {
  const { pode } = useSessao();
  const consulta = useQuery({ queryKey: ['teso', 'caixa', 'moedas'], queryFn: () => obter<EstadoMoedasCaixa>('/tesouraria/caixa/moedas'), enabled: aberto });
  const gravar = useAccao<EstadoMoedasCaixa>({ invalidar: [['teso', 'caixa']], aoSucesso: aoFechar });
  const [escolha, setEscolha] = useState<string[]>([]);
  useEffect(() => {
    if (consulta.data) setEscolha(consulta.data.habilitadas);
  }, [consulta.data]);
  const disponiveis = (consulta.data?.moedas ?? []).map((m) => m.codigo);
  const podeGerir = pode('teso_caixa_multimoeda_gerir');

  return (
    <Modal
      title="Moedas da caixa" open={aberto} onCancel={aoFechar} width={larguraModal(520)} okText="Gravar" cancelText="Fechar"
      okButtonProps={{ style: podeGerir ? undefined : { display: 'none' } }} confirmLoading={gravar.isPending}
      onOk={() => gravar.mutate({ metodo: 'put', url: '/tesouraria/caixa/moedas', dados: { moedas: escolhaValida(escolha, disponiveis) } })}
    >
      <Alert type="info" showIcon style={{ marginBottom: 16 }}
        title="Escolha as moedas estrangeiras que esta empresa passa a liquidar pela caixa. O Kz é sempre a moeda funcional. Cada caixa em moeda é uma conta 45 com a moeda definida no plano de contas." />
      {consulta.isLoading ? (
        <Skeleton active />
      ) : disponiveis.length === 0 ? (
        <Typography.Text type="secondary">Não há moedas estrangeiras activas no sistema (Sistema › Moedas e Câmbios).</Typography.Text>
      ) : (
        <Checkbox.Group disabled={!podeGerir} value={escolha} onChange={(v) => setEscolha(v as string[])} style={{ display: 'grid', gap: 8 }}
          options={(consulta.data?.moedas ?? []).map((m) => ({ value: m.codigo, label: `${m.codigo}${m.nome ? ` — ${m.nome}` : ''}` }))} />
      )}
      {consulta.data && <Typography.Paragraph type="secondary" style={{ marginTop: 16, marginBottom: 0 }}>Tolerância do câmbio manual: {consulta.data.tolerancia_pct} % (Sistema › Moedas e Câmbios).</Typography.Paragraph>}
    </Modal>
  );
}

/** Saldo das caixas por moeda; só aparece quando há caixas em moeda estrangeira visíveis para o utilizador. */
export function SaldosPorMoeda() {
  const q = useQuery({ queryKey: ['teso', 'caixa', 'saldos'], queryFn: () => obter<SaldoCaixaMoeda[]>('/tesouraria/caixa/saldos'), retry: false });
  const estrangeiras = (q.data ?? []).filter((c) => c.moeda !== 'AOA');
  if (!estrangeiras.length) return null;
  return (
    <Card size="small" title="Saldo das caixas por moeda" style={{ marginBottom: 16 }}>
      <Table<SaldoCaixaMoeda> rowKey="conta" size="small" pagination={false} dataSource={q.data}
        columns={[
          { title: 'Conta', dataIndex: 'conta', render: (v: string, c) => <><strong>{v}</strong>{c.descricao && <Typography.Text type="secondary"> {c.descricao}</Typography.Text>}</> },
          { title: 'Moeda', dataIndex: 'moeda', render: (v: string) => <Tag>{v}</Tag> },
          { title: 'Saldo na moeda', dataIndex: 'saldo_moeda', align: 'right', render: (v: string | null, c) => (v === null ? '—' : <span style={{ whiteSpace: 'nowrap' }}>{formatarKz(v)} {simboloMoeda(c.moeda)}</span>) },
          { title: 'Contravalor (Kz)', dataIndex: 'saldo_kz', align: 'right', render: (v: string) => <span style={{ whiteSpace: 'nowrap' }}>{formatarKz(v)} Kz</span> },
        ]} />
    </Card>
  );
}
