import { Button, Card, Flex, Input, Select, Table, Typography } from 'antd';
import { PlusOutlined, RollbackOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { BotaoCsv, ValorKz } from '@/modulos/contab/comum/Componentes';
import { somar } from '@/modulos/contab/comum/decimal';
import { ModalMotivo, useAccao } from '@/modulos/compras/comum/accoes';
import { contemTexto } from '@/modulos/compras/comum/lista';
import { formatarData } from '@/utilitarios/formatacao';
import { EtiquetaActivos } from './comum/componentes';
import type { Abate } from './comum/tipos';
import { ModalAbate } from './ModalAbate';

/** Activos › Abates e vendas (ecrã activos_abates): simular, abater/vender e anular (por estorno do lançamento). */
export default function Abates() {
  const { pode } = useSessao();
  const [novo, setNovo] = useState(false);
  const [anular, setAnular] = useState<Abate | null>(null);
  const [tipo, setTipo] = useState<string>();
  const [texto, setTexto] = useState('');
  const q = useQuery({ queryKey: ['activos', 'abates'], queryFn: () => obter<Abate[]>('/ativos/abates') });
  const accao = useAccao({ invalidar: [['activos']], aoSucesso: () => setAnular(null) });
  const linhas = (q.data ?? []).filter((a) => (!tipo || a.tipo === tipo) && contemTexto(texto, a.ativo_imobilizado?.codigo, a.ativo_imobilizado?.descricao, a.terceiro?.nome, a.numero_documento));

  return (
    <>
      <CabecalhoPagina
        titulo="Abates e vendas"
        subtitulo="Saída do imobilizado com lançamento D 18 / C 11-12 e mais-valia (6) ou menos-valia (7)"
        accoes={pode('activos_abater') && <Button type="primary" danger icon={<PlusOutlined />} onClick={() => setNovo(true)}>Novo abate / venda</Button>}
      />
      <Card>
        <Flex gap={8} wrap justify="space-between" style={{ marginBottom: 16 }}>
          <Flex gap={8} wrap>
            <Input.Search placeholder="Activo, terceiro ou documento" allowClear onSearch={setTexto} style={{ width: 280 }} />
            <Select placeholder="Tipo" allowClear value={tipo} onChange={setTipo} style={{ width: 160 }}
              options={[{ value: 'FIM_VIDA', label: 'Fim de vida' }, { value: 'VENDA', label: 'Venda' }, { value: 'SINISTRO', label: 'Sinistro' }]} />
          </Flex>
          <BotaoCsv nome="abates" linhas={linhas} colunas={[
            { titulo: 'Data', valor: (l) => formatarData(l.data) }, { titulo: 'Documento', valor: (l) => l.numero_documento },
            { titulo: 'Activo', valor: (l) => l.ativo_imobilizado?.codigo }, { titulo: 'Descrição', valor: (l) => l.ativo_imobilizado?.descricao },
            { titulo: 'Tipo', valor: (l) => l.tipo }, { titulo: 'Valor', valor: (l) => l.valor, numerico: true }, { titulo: 'Terceiro', valor: (l) => l.terceiro?.nome },
          ]} />
        </Flex>
        <Table<Abate>
          rowKey="id"
          size="middle"
          loading={q.isFetching}
          dataSource={linhas}
          scroll={{ x: 'max-content' }}
          columns={[
            { title: 'Data', dataIndex: 'data', render: formatarData },
            { title: 'Documento', dataIndex: 'numero_documento', render: (v) => v ?? '—' },
            { title: 'Activo', key: 'a', render: (_, r) => (r.ativo_imobilizado ? `${r.ativo_imobilizado.codigo} — ${r.ativo_imobilizado.descricao}` : r.ativo_imobilizado_id) },
            { title: 'Aquisição', key: 'aq', align: 'right', render: (_, r) => <ValorKz valor={r.ativo_imobilizado?.valor_aquisicao} /> },
            { title: 'Tipo', dataIndex: 'tipo', render: (v) => <EtiquetaActivos valor={v} /> },
            { title: 'Valor (Kz)', dataIndex: 'valor', align: 'right', render: (v) => <ValorKz valor={v} discretoSeZero /> },
            { title: 'Terceiro', key: 't', render: (_, r) => r.terceiro?.nome ?? '—' },
            { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 220, render: (v) => v ?? '—' },
            {
              title: '', key: 'acc', align: 'right', fixed: 'right',
              render: (_, r) => pode('activos_abater') && <Button size="small" danger icon={<RollbackOutlined />} onClick={() => setAnular(r)}>Anular</Button>,
            },
          ]}
          summary={() => linhas.length > 0 && (
            <Table.Summary.Row>
              <Table.Summary.Cell index={0} colSpan={5}><Typography.Text strong>Total</Typography.Text></Table.Summary.Cell>
              <Table.Summary.Cell index={5} align="right"><ValorKz valor={somar(linhas.map((l) => l.valor))} forte /></Table.Summary.Cell>
              <Table.Summary.Cell index={6} colSpan={3} />
            </Table.Summary.Row>
          )}
        />
      </Card>
      <ModalAbate aberto={novo} aoFechar={() => setNovo(false)} />
      <ModalMotivo
        aberto={!!anular}
        titulo={`Anular o abate de ${anular?.ativo_imobilizado?.codigo ?? ''}`}
        textoOk="Anular abate"
        aviso="O lançamento é estornado e o activo volta ao estado «Activo»."
        carregando={accao.isPending}
        aoFechar={() => setAnular(null)}
        aoConfirmar={(motivo) => anular && accao.mutate({ url: `/ativos/abates/${anular.id}/anular`, dados: { motivo } })}
      />
    </>
  );
}
