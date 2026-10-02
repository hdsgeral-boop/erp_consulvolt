import { Button, Card, DatePicker, Dropdown, Empty, Input, List, Modal, Segmented, Space, Table, Tag, Typography } from 'antd';
import { DownOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { BotoesExportar } from '@/componentes/impressao';
import { BarraFiltros, larguraModal, scrollTabela } from '@/componentes/responsivo';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { tabelaDeColunas } from '@/modulos/contab/comum/impressao';
import { useSessao } from '@/sessao/SessaoContexto';
import { ValorKz } from '@/modulos/contab/comum/Componentes';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { EtiquetaAD } from './comum/componentes';
import type { Candidato, FonteRecolha, ItemAD, TipoAD } from './comum/tipos';
import { ModalRegularizar } from './DetalheItem';
import { ModalItem, type ValoresIniciais } from './ModalItem';

const FONTES: { value: FonteRecolha; label: string }[] = [
  { value: 'COMPRA', label: 'Facturas de fornecedor' },
  { value: 'VENDA', label: 'Facturas de cliente' },
  { value: 'DIARIO', label: 'Diário' },
  { value: 'TESOURARIA', label: 'Tesouraria' },
];

/** Acréscimos e diferimentos › Recolher documentos (ecrã ad_recolher): criar registos a partir de documentos ou regularizar acréscimos. */
export default function Recolher() {
  const { pode } = useSessao();
  const navegar = useNavigate();
  const [fonte, setFonte] = useState<FonteRecolha>('COMPRA');
  const [periodo, setPeriodo] = useState<[dayjs.Dayjs | null, dayjs.Dayjs | null] | null>([dayjs().startOf('year'), dayjs()]);
  const [texto, setTexto] = useState('');
  const [criar, setCriar] = useState<ValoresIniciais | null>(null);
  const [regularizarDe, setRegularizarDe] = useState<Candidato | null>(null);
  const de = dataApi(periodo?.[0]);
  const ate = dataApi(periodo?.[1]);
  const q = useQuery({ queryKey: ['acrescimos', 'recolha', fonte, de, ate, texto], queryFn: () => obter<Candidato[]>('/acrescimos/recolha', { fonte, de, ate, texto }) });
  const editar = pode('ad_editar');

  const novoRegisto = (c: Candidato, tipo: TipoAD) => {
    const principal = [...c.linhas].sort((a, b) => Math.abs(Number(b.valor)) - Math.abs(Number(a.valor)))[0];
    const data = c.data;
    setCriar({
      tipo, natureza: c.natureza, valor: c.total, descricao: `${c.doc}${c.terceiro ? ` — ${c.terceiro.trim()}` : ''}`.slice(0, 1000),
      conta_resultado: principal?.conta, terceiro_id: c.terceiro_id, unidade_negocio_id: c.unidade_negocio_id, centro_custo_id: c.centro_custo_id, projeto_id: c.projeto_id,
      data_inicio: data, data_fim: dayjs(data).add(tipo === 'DIFERIMENTO' ? 12 : 1, 'month').subtract(1, 'day').format('YYYY-MM-DD'),
      data_documento: tipo === 'DIFERIMENTO' ? data : null, reparticao: 'MESES', terceiro: c.terceiro_id ? { id: c.terceiro_id, nome: c.terceiro } : null,
      origem: { fonte: c.fonte, id: c.id, doc: c.doc, data: c.data, conta: principal?.conta ?? null },
    });
  };

  const colunasLinhas: ColunaApi<Candidato['linhas'][number]>[] = [{ title: 'Conta', dataIndex: 'conta' }, { title: 'Valor (Kz)', dataIndex: 'valor', align: 'right', render: (v) => <ValorKz valor={v} /> }];
  const colunas: ColunaApi<Candidato>[] = [
            { title: 'Data', dataIndex: 'data', render: formatarData },
            { title: 'Documento', dataIndex: 'doc', render: (v, c) => <>{v}{c.lan && c.lan !== v && <Typography.Text type="secondary"> · {c.lan}</Typography.Text>}</> },
            { title: 'Terceiro', dataIndex: 'terceiro', ellipsis: true, width: 220, responsive: ['md'], render: (v) => v?.trim() || '—' },
            { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 220, render: (v) => v ?? '—', responsive: ['lg'] },
            { title: 'Natureza', dataIndex: 'natureza', render: (v) => <EtiquetaAD valor={v} /> },
            { title: 'Contas', key: 'c', responsive: ['md'], render: (_, c) => c.linhas.map((l) => l.conta).join(', ') },
            { title: 'Total (Kz)', dataIndex: 'total', align: 'right', render: (v) => <ValorKz valor={v} forte /> },
            { title: 'Situação', key: 's', render: (_, c) => <Space size={4}>{c.ligado && <Tag color="blue">Já ligado</Tag>}{!c.contabilizado && <Tag>Não contabilizado</Tag>}</Space> },
            {
              title: '', key: 'acc', align: 'right', fixed: 'right', exportar: false,
              render: (_, c) => editar && (
                <Dropdown trigger={['click']} menu={{
                  items: [
                    { key: 'DIFERIMENTO', label: 'Criar diferimento' },
                    { key: 'ACRESCIMO', label: 'Criar acréscimo' },
                    { type: 'divider' },
                    { key: 'REG', label: 'Regularizar um acréscimo com este documento' },
                  ],
                  onClick: ({ key }) => (key === 'REG' ? setRegularizarDe(c) : novoRegisto(c, key as TipoAD)),
                }}>
                  <Button size="small">Usar <DownOutlined /></Button>
                </Dropdown>
              ),
            },
          ];
  return (
    <>
      <CabecalhoPagina titulo="Recolher documentos" subtitulo="Documentos com linhas de gastos (7) ou rendimentos (6) que podem originar ou regularizar acréscimos e diferimentos" />
      <Card>
        <BarraFiltros
          accoes={
            <BotoesExportar
              tamanho="small"
              desactivado={!q.data?.length}
              obterPedido={async () => ({
                titulo: 'Documentos a recolher para acréscimos e diferimentos',
                periodo: de && ate ? `${formatarData(de)} a ${formatarData(ate)}` : undefined,
                filtros: [`Fonte: ${FONTES.find((x) => x.value === fonte)?.label ?? fonte}`, texto && `Pesquisa: ${texto}`],
                conteudo: await tabelaDeColunas(colunas, q.data ?? []),
              })}
            />
          }
        >
          <Segmented value={fonte} onChange={(v) => setFonte(v as FonteRecolha)} options={FONTES} style={{ maxWidth: '100%', overflowX: 'auto' }} />
          <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} />
          <Input.Search placeholder="Documento, terceiro ou conta" allowClear onSearch={setTexto} style={{ width: 260 }} />
        </BarraFiltros>
        <Table<Candidato>
          rowKey={(c) => `${c.fonte}-${c.id}`}
          size="small"
          loading={q.isFetching}
          dataSource={q.data}
          scroll={scrollTabela()}
          pagination={{ defaultPageSize: 25, showSizeChanger: true, showTotal: (n) => `${n} documento(s)` }}
          expandable={{
            expandedRowRender: (c) => (
              <Table size="small" rowKey="conta" pagination={false} dataSource={c.linhas} style={{ maxWidth: 420 }} scroll={scrollTabela()}
                columns={colunasLinhas} />
            ),
          }}
          columns={colunas}
        />
      </Card>
      <ModalItem aberto={!!criar} item={criar} aoFechar={() => setCriar(null)} aoGravar={(it) => navegar(`/m/acrescimos/ad_registos/${it.id}`)} />
      <EscolherAcrescimo documento={regularizarDe} aoFechar={() => setRegularizarDe(null)} />
    </>
  );
}

/** Lista os acréscimos em aberto que o documento pode regularizar (mesmo terceiro e mesma conta primeiro). */
function EscolherAcrescimo({ documento, aoFechar }: { documento: Candidato | null; aoFechar: () => void }) {
  const [escolhido, setEscolhido] = useState<ItemAD | null>(null);
  const q = useQuery({
    queryKey: ['acrescimos', 'abertos', documento?.fonte, documento?.id],
    queryFn: () => obter<{ item: ItemAD; afinidade: number }[]>('/acrescimos/recolha/acrescimos-abertos', { natureza: documento?.natureza, terceiro_id: documento?.terceiro_id, contas: documento?.linhas.map((l) => l.conta) }),
    enabled: !!documento,
  });
  return (
    <>
      <Modal title={`Regularizar com ${documento?.doc ?? ''}`} open={!!documento && !escolhido} onCancel={aoFechar} footer={<Button onClick={aoFechar}>Fechar</Button>} width={larguraModal(720)}>
        <Typography.Paragraph type="secondary">Documento: {documento?.doc} · {formatarKz(documento?.total, true)} · {documento?.terceiro?.trim()}</Typography.Paragraph>
        {q.data?.length ? (
          <List
            loading={q.isFetching}
            dataSource={q.data}
            renderItem={({ item, afinidade }) => (
              <List.Item actions={[<Button key="r" type="primary" size="small" onClick={() => setEscolhido(item)}>Regularizar</Button>]}>
                <List.Item.Meta
                  title={<>#{item.id} {item.descricao} {afinidade >= 2 && <Tag color="green">Mesmo terceiro</Tag>}{afinidade % 2 === 1 && <Tag color="blue">Mesma conta</Tag>}</>}
                  description={`${formatarData(item.data_inicio)} → ${formatarData(item.data_fim)} · ${item.conta_resultado} · ${formatarKz(item.valor, true)}`}
                />
              </List.Item>
            )}
          />
        ) : <Empty description={q.isFetching ? 'A procurar…' : 'Não há acréscimos em aberto desta natureza'} />}
      </Modal>
      <ModalRegularizar
        item={escolhido}
        documento={documento && escolhido ? { fonte: documento.fonte, id: documento.id, doc: documento.doc, data: documento.data, valor: documento.total } : undefined}
        aoFechar={() => { setEscolhido(null); aoFechar(); }}
      />
    </>
  );
}
