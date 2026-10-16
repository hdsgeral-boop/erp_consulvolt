import { Alert, Button, Card, DatePicker, Flex, List, Modal, Select, Space, Table, Tabs, Tag, Typography } from 'antd';
import { RollbackOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { obter, obterPagina } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { BotoesExportar, tabelaHtml } from '@/componentes/impressao';
import { BarraFiltros, scrollTabela } from '@/componentes/responsivo';
import { TabelaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { BotaoCsv, ValorKz } from '@/modulos/contab/comum/Componentes';
import { ModalMotivo, useAccao } from '@/componentes/Accoes';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { EtiquetaAD, rotuloAD, useDefinicoes } from './comum/componentes';
import { efeitoPorConta, mesApi, mesCurto, totaisSeleccao } from './comum/regras';
import type { LancamentoAD, LinhaProposta, LinhaReconciliacao, Proposta, ResultadoContabilizacao } from './comum/tipos';

/** Acréscimos e diferimentos › Proposta mensal (ecrã ad_propostas): contabilizar as linhas escolhidas, descontabilizar e reconciliar. */
export default function Propostas() {
  return (
    <>
      <CabecalhoPagina
        titulo="Proposta mensal — acréscimos e diferimentos"
        subtitulo="Tudo o que respeita ao mês (e meses anteriores ainda por lançar): lançamentos iniciais dos diferimentos, quotas mensais, regularizações com o documento real, anulações e términos. Reveja, escolha e contabilize no Diário."
      />
      <Tabs
        destroyOnHidden
        items={[
          { key: 'proposta', label: 'Proposta', children: <PropostaMes /> },
          { key: 'lancamentos', label: 'Lançamentos', children: <Lancamentos /> },
          { key: 'reconciliacao', label: 'Reconciliação', children: <Reconciliacao /> },
        ]}
      />
    </>
  );
}

const estiloTitulo = { margin: 0, fontSize: '0.95rem' } as const;

/**
 * Proposta do mês como no legado (desenharProposta de ad_ui.js), numa só página: «Por contabilizar em Mmm/AAAA · N
 * linha(s)» (todas seleccionadas por omissão, efeito por conta) e «Contabilizados com data de Mmm/AAAA (N)» com
 * «Descontabilizar».
 */
function PropostaMes() {
  const { pode } = useSessao();
  const def = useDefinicoes();
  const [mes, setMes] = useState(dayjs());
  const [chaves, setChaves] = useState<string[]>([]);
  const [resultado, setResultado] = useState<ResultadoContabilizacao | null>(null);
  const [descontab, setDescontab] = useState<LancamentoAD | null>(null);
  const m = mesApi(mes);
  const rotulo = mesCurto(m);
  const q = useQuery({ queryKey: ['acrescimos', 'proposta', m], queryFn: () => obter<Proposta>('/acrescimos/proposta', { mes: m }) });
  const feitos = useQuery({
    queryKey: ['acrescimos', 'lancamentos', 'do-mes', m],
    queryFn: () => obterPagina<LancamentoAD>('/acrescimos/lancamentos', { mes_documento: m, estado: 'CONTABILIZADO', por_pagina: 500 }),
  });
  const accao = useAccao<ResultadoContabilizacao>({ invalidar: [['acrescimos']], aoSucesso: (r) => { if (r.erros.length) setResultado(r); } });
  const accaoDesc = useAccao({ invalidar: [['acrescimos']], aoSucesso: () => setDescontab(null) });
  const linhas = q.data?.linhas ?? [];
  // como no legado: ao abrir (ou mudar de mês / depois de contabilizar) ficam todas seleccionadas
  useEffect(() => setChaves((q.data?.linhas ?? []).map((l) => l.chave)), [q.data]);
  const sel = totaisSeleccao(linhas, chaves);
  const efeito = efeitoPorConta(linhas, chaves);
  const podeContab = pode('ad_contabilizar');
  const semDiario = !!def.data && !def.data.diario_id;
  const doMes = feitos.data?.itens ?? [];
  const nLanc = linhas.filter((l) => chaves.includes(l.chave) && Number(l.valor) > 0.004).length;
  const comAtraso = linhas.some((l) => chaves.includes(l.chave) && l.atrasada);

  const contabilizar = () =>
    Modal.confirm({
      title: 'Contabilizar proposta',
      content: `Criar ${nLanc} lançamento(s) no Diário (${formatarKz(sel.total)}) com data de ${rotulo}${comAtraso ? ' — inclui meses anteriores em atraso' : ''}?`,
      okText: 'Contabilizar',
      cancelText: 'Cancelar',
      onOk: () => accao.mutateAsync({ url: '/acrescimos/proposta/contabilizar', dados: { mes: m, chaves } }),
    });

  return (
    <>
      <Flex gap={8} wrap align="flex-end" style={{ marginBottom: 12 }}>
        <div>
          <Typography.Text strong style={{ display: 'block', fontSize: '0.8rem' }}>Mês</Typography.Text>
          <DatePicker picker="month" format="MM/YYYY" value={mes} allowClear={false} onChange={(d) => d && setMes(d)} aria-label="Mês" />
        </div>
        <Button icon={<IconeFa nome="chevron-left" />} aria-label="Mês anterior" onClick={() => setMes((d) => d.subtract(1, 'month'))} />
        <Button icon={<IconeFa nome="chevron-right" />} aria-label="Mês seguinte" onClick={() => setMes((d) => d.add(1, 'month'))} />
      </Flex>
      {semDiario && (
        <Alert type="warning" showIcon icon={<IconeFa nome="cog" />} style={{ marginBottom: 12 }} message="Defina o diário em Acréscimos e Diferimentos › Definições antes de contabilizar." />
      )}
      {(q.data?.alertas.length ?? 0) > 0 && (
        <Alert
          type="error"
          showIcon
          icon={<IconeFa nome="file-invoice" />}
          style={{ marginBottom: 12 }}
          message={
            <>
              <strong>{q.data?.alertas.length} acréscimo(s) sem documento real</strong> depois da data limite:{' '}
              {q.data?.alertas.map((a, i) => (
                <span key={a.id}>
                  {i > 0 && '; '}
                  <Link to={`/m/acrescimos/ad_registos/${a.id}`}>{a.descricao}</Link> (até {formatarData(a.data_limite)})
                </span>
              ))}
              .
            </>
          }
        />
      )}

      <Card size="small" style={{ marginBottom: 12 }}>
        <Flex gap={8} wrap justify="space-between" align="center" style={{ marginBottom: 8 }}>
          <h4 style={estiloTitulo}>Por contabilizar em {rotulo} · {linhas.length} linha(s)</h4>
          <Space wrap>
            <BotoesExportar
              tamanho="small"
              desactivado={!linhas.length}
              obterPedido={() => ({
                titulo: 'Proposta mensal de acréscimos e diferimentos',
                periodo: rotulo,
                filtros: [`${linhas.length} linha(s)`],
                conteudo: tabelaHtml({
                  linhas,
                  totais: true,
                  colunas: [
                    { titulo: 'Registo', valor: (l) => `${rotuloAD(l.item.tipo)} · ${l.item.descricao}`, quebrar: true },
                    { titulo: 'Lançamento', valor: (l) => l.tipo_rotulo },
                    { titulo: 'Período', valor: (l) => `${mesCurto(l.periodo)}${l.atrasada ? ' (em atraso)' : ''}` },
                    { titulo: 'Data', valor: (l) => l.data, formato: 'data' },
                    { titulo: 'Débito', valor: (l) => l.debito },
                    { titulo: 'Crédito', valor: (l) => l.credito },
                    { titulo: 'Valor', valor: (l) => l.valor, formato: 'moeda', somar: true },
                  ],
                }),
              })}
            />
            <BotaoCsv nome={`proposta-ad-${m}`} linhas={linhas} colunas={[
              { titulo: 'Registo', valor: (l) => l.item.id }, { titulo: 'Descrição', valor: (l) => l.item.descricao }, { titulo: 'Lançamento', valor: (l) => l.tipo_rotulo },
              { titulo: 'Período', valor: (l) => l.periodo }, { titulo: 'Data', valor: (l) => l.data }, { titulo: 'Débito', valor: (l) => l.debito }, { titulo: 'Crédito', valor: (l) => l.credito },
              { titulo: 'Valor', valor: (l) => l.valor, numerico: true }, { titulo: 'Em atraso', valor: (l) => l.atrasada },
            ]} />
            {podeContab && linhas.length > 0 && (
              <Button type="primary" icon={<IconeFa nome="book" />} disabled={!sel.n || semDiario} loading={accao.isPending} onClick={contabilizar}>
                Contabilizar {sel.n} seleccionada(s)
              </Button>
            )}
          </Space>
        </Flex>
        {linhas.length || q.isFetching ? (
          <>
            <Table<LinhaProposta>
              rowKey="chave"
              size="small"
              loading={q.isFetching}
              dataSource={linhas}
              pagination={false}
              scroll={scrollTabela()}
              rowSelection={podeContab ? { selectedRowKeys: chaves, onChange: (k) => setChaves(k as string[]) } : undefined}
              columns={[
                { title: 'Registo', key: 'i', render: (_, l) => <><EtiquetaAD valor={l.item.tipo} /> <strong>{l.item.descricao}</strong></> },
                {
                  title: 'Lançamento', dataIndex: 'tipo_rotulo',
                  render: (v, l) => <>{v}{l.diferenca != null && <Typography.Text type="warning" style={{ display: 'block', fontSize: '0.72rem' }}>diferença {formatarKz(l.diferenca)}</Typography.Text>}</>,
                },
                { title: 'Período', dataIndex: 'periodo', render: (p, l) => <>{mesCurto(p)}{l.atrasada && <Tag color="orange" style={{ marginLeft: 6 }} title="Mês anterior ainda por lançar">em atraso</Tag>}</> },
                { title: 'Data', dataIndex: 'data', render: formatarData },
                { title: 'Débito', dataIndex: 'debito', render: (c) => <Tag>{c}</Tag> },
                { title: 'Crédito', dataIndex: 'credito', render: (c) => <Tag>{c}</Tag> },
                { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v) => <ValorKz valor={v} /> },
              ]}
            />
            {efeito.length > 0 && (
              <>
                <h4 style={{ margin: '0.7rem 0 0.3rem', fontSize: '0.88rem' }}>Efeito por conta (seleccionadas)</h4>
                <Table
                  rowKey="conta"
                  size="small"
                  dataSource={efeito}
                  pagination={false}
                  style={{ maxWidth: 640 }}
                  columns={[
                    { title: 'Conta', dataIndex: 'conta', render: (c) => <strong>{c}</strong> },
                    { title: 'Débito', dataIndex: 'debito', align: 'right', render: (v) => <ValorKz valor={v} /> },
                    { title: 'Crédito', dataIndex: 'credito', align: 'right', render: (v) => <ValorKz valor={v} /> },
                    { title: 'Saldo', dataIndex: 'saldo', align: 'right', render: (v) => <ValorKz valor={v} /> },
                  ]}
                />
              </>
            )}
          </>
        ) : (
          <Typography.Paragraph type="secondary" style={{ margin: 0 }}>Nada por contabilizar neste mês.</Typography.Paragraph>
        )}
      </Card>

      <Card size="small">
        <h4 style={{ ...estiloTitulo, marginBottom: 8 }}>Contabilizados com data de {rotulo} ({feitos.data?.paginacao.total ?? doMes.length})</h4>
        {doMes.length || feitos.isFetching ? (
          <Table<LancamentoAD>
            rowKey="id"
            size="small"
            loading={feitos.isFetching}
            dataSource={doMes}
            pagination={false}
            scroll={scrollTabela()}
            columns={[
              { title: 'Data', dataIndex: 'data_documento', render: formatarData },
              {
                title: 'Registo', key: 'r',
                render: (_, l) => <Link to={`/m/acrescimos/ad_registos/${l.item_acrescimo_diferimento_id}`}>{l.item_acrescimo_diferimento?.descricao ?? `#${l.item_acrescimo_diferimento_id}`}</Link>,
              },
              { title: 'Lançamento', dataIndex: 'tipo', render: (t) => def.data?.tipos_linha?.[t] ?? rotuloAD(t) },
              { title: 'Período', dataIndex: 'periodo', render: mesCurto },
              { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v) => <ValorKz valor={v} /> },
              { title: 'Nº', dataIndex: 'numero_lan', render: (v) => v || '—' },
              { title: 'Por', dataIndex: 'por', render: (v) => <span style={{ fontSize: '0.76rem' }}>{v ?? ''}</span> },
              {
                title: '', key: 'acc', align: 'right',
                render: (_, l) => podeContab && (
                  <Button size="small" icon={<IconeFa nome="undo" />} onClick={() => setDescontab(l)}>Descontabilizar</Button>
                ),
              },
            ]}
          />
        ) : (
          <Typography.Paragraph type="secondary" style={{ margin: 0 }}>Nenhum.</Typography.Paragraph>
        )}
      </Card>

      <ModalMotivo
        aberto={!!descontab}
        titulo="Descontabilizar"
        textoOk="Descontabilizar"
        aviso="Anular no Diário este lançamento de acréscimo/diferimento (estorno, sem buracos na numeração)? Volta a aparecer na proposta do mês."
        carregando={accaoDesc.isPending}
        aoFechar={() => setDescontab(null)}
        aoConfirmar={(motivo) => descontab && accaoDesc.mutate({ url: `/acrescimos/lancamentos/${descontab.id}/descontabilizar`, dados: { motivo } })}
      />
      <Modal title="Resultado da contabilização" open={!!resultado} onCancel={() => setResultado(null)} footer={<Button onClick={() => setResultado(null)}>Fechar</Button>}>
        <Typography.Paragraph>{resultado?.ok.length} contabilizado(s), {resultado?.erros.length} com erro:</Typography.Paragraph>
        <List size="small" dataSource={resultado?.erros ?? []} renderItem={(e) => <List.Item><Typography.Text type="danger">{e.mensagem}</Typography.Text></List.Item>} />
      </Modal>
    </>
  );
}

function Lancamentos() {
  const { pode } = useSessao();
  const [periodo, setPeriodo] = useState<dayjs.Dayjs | null>(null);
  const [estado, setEstado] = useState<string>();
  const [descontab, setDescontab] = useState<LancamentoAD | null>(null);
  const accao = useAccao({ invalidar: [['acrescimos']], aoSucesso: () => setDescontab(null) });
  return (
    <Card>
      <BarraFiltros>
        <DatePicker picker="month" format="MM/YYYY" placeholder="Período" value={periodo} onChange={setPeriodo} />
        <Select placeholder="Estado" allowClear value={estado} onChange={setEstado} style={{ width: 170 }} options={[{ value: 'CONTABILIZADO', label: 'Contabilizado' }, { value: 'ANULADO', label: 'Anulado (estornado)' }]} />
      </BarraFiltros>
      <TabelaApi<LancamentoAD>
        url="/acrescimos/lancamentos"
        chaveConsulta={['acrescimos', 'lancamentos']}
        filtros={{ periodo: periodo ? mesApi(periodo) : undefined, estado }}
        impressao={{ titulo: 'Lançamentos de acréscimos e diferimentos', periodo: periodo ? periodo.format('MM/YYYY') : undefined, filtros: [estado && `Estado: ${estado === 'CONTABILIZADO' ? 'Contabilizado' : 'Anulado (estornado)'}`] }}
        columns={[
          { title: 'Registo', dataIndex: 'item_acrescimo_diferimento_id', render: (id) => <Link to={`/m/acrescimos/ad_registos/${id}`}>#{id}</Link> },
          { title: 'Período', dataIndex: 'periodo' },
          { title: 'Tipo', dataIndex: 'tipo', render: (v) => <EtiquetaAD valor={v} /> },
          { title: 'Documento', dataIndex: 'numero_documento', responsive: ['md'] },
          { title: 'N.º lanç.', dataIndex: 'numero_lan' },
          { title: 'Data', dataIndex: 'data_documento', render: formatarData },
          { title: 'Valor (Kz)', dataIndex: 'valor', align: 'right', render: (v) => <ValorKz valor={v} /> },
          { title: 'Estado', dataIndex: 'estado', render: (v) => <EtiquetaAD valor={v} /> },
          {
            title: '', key: 'acc', align: 'right', exportar: false,
            render: (_, l) => pode('ad_contabilizar') && l.estado === 'CONTABILIZADO' && <Button size="small" danger icon={<RollbackOutlined />} onClick={() => setDescontab(l)}>Descontabilizar</Button>,
          },
        ]}
      />
      <ModalMotivo aberto={!!descontab} titulo={`Descontabilizar ${descontab?.numero_documento ?? ''}`} textoOk="Descontabilizar" aviso="O lançamento é estornado (sem buracos na numeração) e a linha volta à proposta."
        carregando={accao.isPending} aoFechar={() => setDescontab(null)} aoConfirmar={(motivo) => descontab && accao.mutate({ url: `/acrescimos/lancamentos/${descontab.id}/descontabilizar`, dados: { motivo } })} />
    </Card>
  );
}

function Reconciliacao() {
  const [data, setData] = useState<dayjs.Dayjs | null>(null);
  const d = dataApi(data);
  const q = useQuery({ queryKey: ['acrescimos', 'reconciliacao', d], queryFn: () => obter<LinhaReconciliacao[]>('/acrescimos/reconciliacao', { data: d }) });
  const divergentes = (q.data ?? []).filter((l) => Number(l.diferenca) !== 0).length;
  return (
    <Card>
      <Flex gap={8} wrap style={{ marginBottom: 16 }} align="center">
        <DatePicker format="DD/MM/YYYY" placeholder="À data (hoje)" value={data} onChange={setData} />
        <Typography.Text type="secondary">Saldo das contas 37 segundo o módulo e segundo o Diário {data ? `em ${data.format('DD/MM/YYYY')}` : 'à data de hoje'}.</Typography.Text>
        <BotoesExportar
          tamanho="small"
          desactivado={!q.data?.length}
          obterPedido={() => ({
            titulo: 'Reconciliação das contas 37 (módulo × Diário)',
            periodo: data ? `Em ${data.format('DD/MM/YYYY')}` : 'À data de hoje',
            filtros: [divergentes ? `${divergentes} conta(s) com diferença` : 'Módulo e Diário conferem'],
            conteudo: tabelaHtml({
              linhas: q.data ?? [],
              totais: true,
              colunas: [
                { titulo: 'Conta', valor: (l) => l.conta },
                { titulo: 'Módulo (Kz)', valor: (l) => l.modulo, formato: 'moeda', somar: true },
                { titulo: 'Diário (Kz)', valor: (l) => l.diario, formato: 'moeda', somar: true },
                { titulo: 'Diferença', valor: (l) => l.diferenca, formato: 'moeda', somar: true },
              ],
            }),
          })}
        />
      </Flex>
      {q.data && <Alert style={{ marginBottom: 12 }} type={divergentes ? 'warning' : 'success'} showIcon message={divergentes ? `${divergentes} conta(s) com diferença` : 'Módulo e Diário conferem'} />}
      <Table<LinhaReconciliacao>
        rowKey="conta"
        size="small"
        loading={q.isFetching}
        dataSource={q.data}
        pagination={false}
        scroll={scrollTabela()}
        columns={[
          { title: 'Conta', dataIndex: 'conta' },
          { title: 'Módulo (Kz)', dataIndex: 'modulo', align: 'right', render: (v) => <ValorKz valor={v} /> },
          { title: 'Diário (Kz)', dataIndex: 'diario', align: 'right', render: (v) => <ValorKz valor={v} /> },
          { title: 'Diferença', dataIndex: 'diferenca', align: 'right', render: (v) => <ValorKz valor={v} forte discretoSeZero /> },
        ]}
      />
    </Card>
  );
}
