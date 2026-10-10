import { Card, Drawer, Space, Tag, Typography } from 'antd';
import { CheckCircleTwoTone } from '@ant-design/icons';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { Route, Routes, useNavigate } from 'react-router-dom';
import { DiagramaFluxo, RaizFluxo } from '@/componentes/fluxos';
import { larguraGaveta, useEcraPequeno } from '@/componentes/responsivo';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { DetalheRececao } from '@/modulos/compras/comum/Rececoes';
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
