import { Button, Card, Drawer, Space, Table, Tag, Typography } from 'antd';
import { CheckCircleTwoTone } from '@ant-design/icons';
import { useQueries, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { Route, Routes, useNavigate } from 'react-router-dom';
import { obter, obterPagina } from '@/api/cliente';
import { DiagramaFluxo, IconeFa, RaizFluxo } from '@/componentes/fluxos';
import { larguraGaveta, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { pendente } from '@/modulos/compras/comum/calculos';
import { ModalRececao } from '@/modulos/compras/comum/ModaisEncomenda';
import { DetalheRececao } from '@/modulos/compras/comum/Rececoes';
import { numeroOuId, type EncomendaCompra } from '@/modulos/compras/comum/tipos';
import { useSessao } from '@/sessao/SessaoContexto';
import { somar } from '@/utilitarios/decimal';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { ETAPAS_FLUXO_RECECAO, FILTRO_SITUACAO, SITUACOES_RECECAO, estadosFluxoRececao, referenciaEncomenda, type RececaoArmazem, type SituacaoRececao } from './comum/paridade';

/**
 * Armazém › Validar Entradas (Compras) (ecrã armazem_rececoes), como o «histórico e Validação de Entradas» do legado
 * (renderRececoesPendentes, js/ui_warehouse.js:262): todas as recepções por omissão, com o n.º da encomenda, o fornecedor,
 * o armazém de destino e o estado Pendente/Validado; o detalhe (Validar, Reverter, Anular) é o das Compras.
 */
export default function ValidarEntradas() {
  return (
    <Routes>
      <Route index element={<ListaEntradas />} />
      <Route path=":id" element={<DetalheRececao />} />
    </Routes>
  );
}

function ListaEntradas() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const pequeno = useEcraPequeno();
  const situacao: SituacaoRececao | 'TODAS' = 'TODAS';
  const pesquisa = '';
  const periodo = null as [Dayjs | null, Dayjs | null] | null;
  const [fluxo, setFluxo] = useState<RececaoArmazem | null>(null);
  const filtroSituacao = situacao === 'TODAS' ? undefined : situacao;

  const colunas: ColunaApi<RececaoArmazem>[] = [
    { title: 'Data Guia', dataIndex: 'data', render: formatarData },
    { title: 'Nº Guia Fornecedor', dataIndex: 'numero_entrega', render: (v: string | null, r) => <strong>{v || r.numero_rececao || `#${r.id}`}</strong> },
    { title: 'Encomenda Ref.', key: 'encomenda', valorImpressao: (r) => referenciaEncomenda(r), render: (_, r) => referenciaEncomenda(r) },
    { title: 'Fornecedor', dataIndex: 'fornecedor', render: (v: string | null) => v?.trim() || 'N/D' },
    {
      title: 'Armazém Destino',
      key: 'armazem',
      valorImpressao: (r) => (r.situacao === 'VALIDADO' ? r.armazem ?? 'N/D' : 'Pendente'),
      render: (_, r) => (r.situacao === 'VALIDADO' ? <Tag bordered>{r.armazem ?? 'N/D'}</Tag> : <Typography.Text type="secondary">Pendente</Typography.Text>),
    },
    {
      title: 'Status',
      dataIndex: 'situacao',
      valorImpressao: (r) => SITUACOES_RECECAO[r.situacao]?.rotulo ?? r.situacao,
      // legado: etiqueta em maiúsculas (VALIDADO / PENDENTE)
      render: (s: SituacaoRececao) => <Tag color={SITUACOES_RECECAO[s]?.cor}>{(SITUACOES_RECECAO[s]?.rotulo ?? s).toUpperCase()}</Tag>,
    },
    {
      title: 'Valor (Kz)',
      dataIndex: 'valor_total_kz',
      align: 'right',
      ocultaPorOmissao: true,
      render: (v: string | null) => (v ? formatarKz(v) : '—'),
      totalImpressao: (ls) => formatarKz(somar(ls.map((l) => (l.situacao === 'ANULADO' ? 0 : l.valor_total_kz)))),
    },
    { title: 'Contab.', dataIndex: 'contabilizado', align: 'center', ocultaPorOmissao: true, valorImpressao: (r) => (r.contabilizado ? 'Sim' : 'Não'), render: (c: boolean | null) => (c ? <CheckCircleTwoTone twoToneColor="#52c41a" /> : null) },
  ];

  return (
    <>
      <EncomendasPorReceber />
      <Card
        title="histórico e Validação de Entradas"
        extra={!pequeno && <Typography.Text type="secondary">Gestão de receções de fornecedores e validação física de stock.</Typography.Text>}
      >
        <TabelaApi<RececaoArmazem>
          url="/logistica/paridade/rececoes"
          chaveConsulta={['logistica', 'paridade', 'rececoes']}
          filtros={{ situacao: filtroSituacao, pesquisa: pesquisa || undefined, data_inicio: dataApi(periodo?.[0]), data_fim: dataApi(periodo?.[1]) }}
          columns={colunas}
          size={pequeno ? 'small' : 'middle'}
          vazio={{ icone: 'truck-loading', titulo: 'Nenhuma receção registada.' }}
          accoes={(r) => [
            { icone: 'project-diagram', titulo: 'Fluxo do Processo', onClick: () => setFluxo(r) },
            { icone: 'eye', titulo: 'Ver Detalhes', onClick: () => navegar(String(r.id)) },
            r.situacao === 'PENDENTE' && pode('armazem_validar') && { icone: 'check', titulo: 'Validar Entrada', variante: 'sucesso', cheio: true, onClick: () => navegar(String(r.id)) },
            // legado (ui_warehouse.js:328,335): cancelar a receção pendente / reverter a validação — confirmados no detalhe
            r.situacao === 'PENDENTE' && pode('armazem_validar') && { icone: 'times', titulo: 'Cancelar Receção', variante: 'perigo', cheio: true, onClick: () => navegar(String(r.id)) },
            r.situacao === 'VALIDADO' && pode('armazem_validar') && { icone: 'undo', titulo: 'Eliminar/Reverter Validação', variante: 'perigo', cheio: true, onClick: () => navegar(String(r.id)) },
          ]}
          impressao={{
            titulo: 'histórico e Validação de Entradas',
            periodo: periodo?.[0] && periodo?.[1] ? `${periodo[0].format('DD/MM/YYYY')} a ${periodo[1].format('DD/MM/YYYY')}` : undefined,
            filtros: [filtroSituacao && `Status: ${FILTRO_SITUACAO.find((e) => e.value === situacao)?.label}`, pesquisa && `Pesquisa: ${pesquisa}`],
            rotuloTotal: 'Total (sem anuladas)',
          }}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
        />
      </Card>
      <FluxoRececao rececao={fluxo} aoFechar={() => setFluxo(null)} />
    </>
  );
}

/** Texto «Por receber» do legado: «Produto: quantidade» das linhas com quantidade por receber, separados por « · ». */
export function textoPorReceber(linhas: EncomendaCompra['linhas']): string {
  return (linhas ?? [])
    .map((l) => ({ l, q: pendente(l.quantidade, l.quantidade_recebida) }))
    .filter((x) => x.q > 0)
    .map((x) => `${x.l.produto?.nome ?? x.l.descricao ?? 'Item'}: ${x.q.toLocaleString('pt-PT')}`)
    .join(' · ');
}

/**
 * «Encomendas por Receber» do legado (armazemRenderEncomendasPorReceber, js/ui_warehouse.js:353-396): ordens de encomenda
 * enviadas pelas Compras com quantidades por receber, por cima do histórico; «Receber» regista a guia do fornecedor
 * (a validação da entrada faz-se depois na lista). Sem encomendas pendentes o cartão não aparece.
 */
function EncomendasPorReceber() {
  const { pode } = useSessao();
  const qc = useQueryClient();
  const [aberta, setAberta] = useState<EncomendaCompra | null>(null);
  const lista = useQuery({
    queryKey: ['compras', 'encomendas', 'por-receber'],
    queryFn: () => obterPagina<EncomendaCompra>('/compras/encomendas', { por_pagina: 500 }),
  });
  const candidatas = (lista.data?.itens ?? []).filter((e) => e.estado !== 'ANULADA' && e.estado !== 'RECEBIDO' && (e.percentagem_entregue ?? 0) < 100);
  const detalhes = useQueries({
    queries: candidatas.map((e) => ({ queryKey: ['compras', 'encomenda', String(e.id)], queryFn: () => obter<EncomendaCompra>(`/compras/encomendas/${e.id}`) })),
  });
  const linhas = candidatas
    .map((e, i) => ({ ...e, ...(detalhes[i]?.data ? { linhas: detalhes[i].data!.linhas } : {}) }))
    .filter((e) => !e.linhas || textoPorReceber(e.linhas) !== '');
  if (!linhas.length) return null;

  return (
    <Card
      style={{ marginBottom: 24, borderTop: '4px solid var(--ant-color-success, #059669)' }}
      title={<><IconeFa nome="truck-loading" /> Encomendas por Receber</>}
      extra={<Typography.Text type="secondary">Ordens de encomenda enviadas pelas Compras. Registe a guia do fornecedor e valide a entrada em stock.</Typography.Text>}
    >
      <Table<EncomendaCompra>
        rowKey="id"
        size="small"
        pagination={false}
        scroll={scrollTabela()}
        dataSource={linhas}
        columns={[
          { title: 'Encomenda', key: 'n', render: (_, e) => <><strong>{numeroOuId(e.numero_encomenda, e.id)}</strong>{e.codigo_moeda && e.codigo_moeda !== 'AOA' && <Tag style={{ marginLeft: 4 }}>{e.codigo_moeda}</Tag>}</> },
          { title: 'Data', dataIndex: 'data', render: (v: string | null) => (v ? formatarData(v) : '-') },
          { title: 'Fornecedor', key: 'f', render: (_, e) => e.fornecedor?.nome?.trim() || 'N/D' },
          {
            title: 'Por receber',
            key: 'p',
            render: (_, e) => (e.linhas ? <Typography.Text type="secondary" style={{ fontSize: '0.8rem', whiteSpace: 'normal' }}>{textoPorReceber(e.linhas)}</Typography.Text> : '…'),
          },
          {
            title: 'Ações',
            key: 'a',
            align: 'right',
            render: (_, e) =>
              pode('compras_rec_registar') && (
                <Button size="small" className="erp-accao-linha erp-accao-cheio erp-accao-sucesso" title="Registar a guia do fornecedor" disabled={!e.linhas} onClick={() => setAberta(e)}>
                  <IconeFa nome="truck-loading" /> Receber
                </Button>
              ),
          },
        ]}
      />
      {aberta && (
        <ModalRececao
          encomenda={aberta}
          aberto
          aoFechar={() => setAberta(null)}
          aoRegistar={() => qc.invalidateQueries({ queryKey: ['logistica'] })}
        />
      )}
    </Card>
  );
}

/** «Fluxo do Processo» da recepção (no legado o botão só mostrava «em desenvolvimento»). */
function FluxoRececao({ rececao, aoFechar }: { rececao: RececaoArmazem | null; aoFechar: () => void }) {
  const f = rececao ? estadosFluxoRececao(rececao) : null;
  return (
    <Drawer open={!!rececao} onClose={aoFechar} width={larguraGaveta(820)} title={rececao ? `Fluxo do Processo — ${rececao.numero_entrega ?? `#${rececao.id}`}` : 'Fluxo do Processo'} destroyOnHidden>
      {rececao && f && (
        <RaizFluxo>
          <Space direction="vertical" size={12} style={{ width: '100%' }}>
            <Typography.Text type="secondary">
              Encomenda {referenciaEncomenda(rececao)} · {rececao.fornecedor?.trim() || 'N/D'} · {formatarData(rececao.data)}
            </Typography.Text>
            <DiagramaFluxo titulo="Receção de compra" etapas={[...ETAPAS_FLUXO_RECECAO]} estados={f.estados} estadoGlobal={f.global} concluidas={f.concluidas} />
          </Space>
        </RaizFluxo>
      )}
    </Drawer>
  );
}
