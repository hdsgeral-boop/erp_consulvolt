import { Card, Input, Select, Tag, Typography } from 'antd';
import { BarraFiltros, useEcraPequeno } from '@/componentes/responsivo';
import { useEffect, useState } from 'react';
import { Route, Routes, useNavigate, useSearchParams } from 'react-router-dom';
import { ModalMotivo, useAccao } from '@/componentes/Accoes';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import type { AccaoLinha } from '@/componentes/vistas';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { NomeTerceiro } from '../comum/referencias';
import { accoesContrato, type Pode } from '../comum/regras';
import { SeletorTerceiro } from '../comum/Seletores';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { numeroOuId, type ContratoCompra } from '../comum/tipos';
import { DetalheContrato } from './DetalheContrato';
import { ModalContrato } from './ModalContrato';

/** Compras › Contratos de compra (ecrã compras_contratos): contratos com fornecedores, encomendas associadas e marcos. */
export default function Contratos() {
  return (
    <Routes>
      <Route index element={<ListaContratos />} />
      <Route path=":id" element={<DetalheContrato />} />
    </Routes>
  );
}

/** Acções por linha do legado (js/ui_compras_v2.js:818-819): Editar e, nos contratos activos, Cancelar Contrato. */
export function accoesLinhaContrato(c: ContratoCompra, pode: Pode, f: { editar: () => void; cancelar: () => void }): AccaoLinha[] {
  const a = accoesContrato(c, pode);
  return [
    { icone: 'edit', titulo: 'Editar', variante: 'primario', desactivado: !a.editar && (c.estado === 'CANCELADO' ? 'Um contrato cancelado não se altera' : 'Sem permissão para gerir contratos'), onClick: f.editar },
    { icone: 'ban', titulo: 'Cancelar Contrato', variante: 'perigo', oculto: c.estado !== 'ATIVO', desactivado: !a.cancelar && 'Sem permissão para cancelar contratos', onClick: f.cancelar },
  ];
}

/** Estados dos contratos com os rótulos e as cores do legado (js/ui_compras_v2.js:791-795). */
const ESTADOS_CONTRATO: Record<string, { rotulo: string; cor: string }> = {
  ATIVO: { rotulo: 'Ativo', cor: 'green' },
  CANCELADO: { rotulo: 'Cancelado', cor: 'red' },
  EXPIRADO: { rotulo: 'Expirado', cor: 'orange' },
};

export const rotuloEstadoContrato = (e: string | null | undefined) => (e ? ESTADOS_CONTRATO[e]?.rotulo ?? e : '—');

export function EstadoContratoTag({ estado }: { estado: string | null | undefined }) {
  return <Tag color={estado ? ESTADOS_CONTRATO[estado]?.cor : undefined}>{rotuloEstadoContrato(estado)}</Tag>;
}

/** «Total encomendado» com «N% do previsto» (vermelho acima de 100 %). */
export function TotalEncomendado({ c }: { c: ContratoCompra }) {
  const p = c.percentagem_encomendada;
  return (
    <>
      {formatarKz(c.total_encomendado ?? 0)}
      {p !== null && p !== undefined && <Typography.Text style={{ display: 'block', fontSize: 12, color: p > 100 ? '#b91c1c' : '#64748b' }}>{p}% do previsto</Typography.Text>}
    </>
  );
}

function ListaContratos() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [estado, setEstado] = useState<string>();
  const [fornecedor, setFornecedor] = useState<number>();
  const [procura, setProcura] = useSearchParams();
  const [novo, setNovo] = useState(procura.get('novo') === '1');   // acção rápida «Novo Contrato» da barra do módulo
  const [edicao, setEdicao] = useState<ContratoCompra | null>(null);
  const [cancelar, setCancelar] = useState<ContratoCompra | null>(null);
  const [pesquisa, setPesquisa] = useState('');
  const [nomeFornecedor, setNomeFornecedor] = useState<string>();
  const pequeno = useEcraPequeno();
  const accao = useAccao<ContratoCompra>({ invalidar: [['compras']], aoSucesso: () => setCancelar(null) });

  // a barra do módulo pode pedir «Novo Contrato» com o ecrã já aberto
  useEffect(() => {
    if (procura.get('novo') === '1') {
      setNovo(true);
      setProcura({}, { replace: true });
    }
  }, [procura, setProcura]);

  const colunas: ColunaApi<ContratoCompra>[] = [
    { title: 'Referência', dataIndex: 'referencia', fixed: 'left', render: (v: string) => <strong>{v || 'N/D'}</strong> },
    { title: 'Fornecedor', key: 'fornecedor', valorImpressao: (r) => r.fornecedor?.nome?.trim() ?? `#${r.fornecedor_id}`, render: (_, r) => <NomeTerceiro id={r.fornecedor_id} terceiro={r.fornecedor} /> },
    { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, render: (v) => v || '' },
    {
      title: 'Encomendas associadas', key: 'encomendas',
      valorImpressao: (r) => (r.encomendas ?? []).map((e) => numeroOuId(e.numero_encomenda, e.id)).join(', ') || 'Sem encomendas',
      valorFiltro: (r) => (r.encomendas ?? []).map((e) => e.numero_encomenda ?? '').join(' '),
      render: (_, r) =>
        r.encomendas?.length ? (
          r.encomendas.map((e) => (
            <Tag key={e.id} style={{ margin: 1, cursor: pode('compras_encomendas_view') ? 'pointer' : undefined }}
              onClick={(ev) => { ev.stopPropagation(); if (pode('compras_encomendas_view')) navegar(`/m/compras/compras_encomendas/${e.id}`); }}>
              <IconeFa nome="link" /> {numeroOuId(e.numero_encomenda, e.id)}
            </Tag>
          ))
        ) : (
          <Typography.Text type="secondary" style={{ fontSize: 12 }}>Sem encomendas</Typography.Text>
        ),
    },
    { title: 'Início', dataIndex: 'data_inicio', render: (v: string | null) => (v ? formatarData(v) : '-') },
    { title: 'Fim', dataIndex: 'data_fim', render: (v: string | null) => (v ? formatarData(v) : '-') },
    { title: 'Valor Global Previsto', dataIndex: 'valor_total', align: 'right', render: (v: string) => <strong>{formatarKz(v)}</strong> },
    {
      title: 'Total encomendado', key: 'total_encomendado', align: 'right',
      valorImpressao: (r) => `${formatarKz(r.total_encomendado ?? 0)}${r.percentagem_encomendada != null ? ` (${r.percentagem_encomendada}%)` : ''}`,
      render: (_, r) => <TotalEncomendado c={r} />,
    },
    { title: 'Estado', dataIndex: 'estado', valorImpressao: (r) => rotuloEstadoContrato(r.estado), render: (e: string) => <EstadoContratoTag estado={e} /> },
  ];

  return (
    <>
      {/* o legado mostra só a tabela no cartão do módulo, sem título (js/ui_compras_v2.js:828-848); os filtros são do sistema novo */}
      <Card className="compras-cartao-lista">
        <BarraFiltros>
          <Select placeholder="Estado" allowClear style={{ width: 180 }} value={estado} onChange={setEstado} options={['ATIVO', 'EXPIRADO', 'CANCELADO'].map((e) => ({ value: e, label: rotuloEstadoContrato(e) }))} />
          <SeletorTerceiro papel="FORNECEDOR" style={{ width: 320, maxWidth: '100%' }} value={fornecedor} onChange={(v, o) => { setFornecedor(v); setNomeFornecedor(o && !Array.isArray(o) && o.label ? String(o.label) : undefined); }} />
          <Input.Search placeholder="Referência" allowClear style={{ width: 200, maxWidth: '100%' }} onSearch={(v) => setPesquisa(v.trim())} />
        </BarraFiltros>
        <TabelaApi<ContratoCompra>
          url="/compras/contratos"
          filtros={{ estado, fornecedor_id: fornecedor, pesquisa: pesquisa || undefined }}
          chaveConsulta={['compras', 'contratos']}
          columns={colunas}
          size={pequeno ? 'small' : 'middle'}
          vazio={{ icone: 'file-contract', titulo: 'Nenhum contrato de fornecimento registado.' }}
          impressao={{
            titulo: 'Contratos de Fornecimento',
            filtros: [estado && `Estado: ${rotuloEstadoContrato(estado)}`, !!fornecedor && `Fornecedor: ${nomeFornecedor ?? `#${fornecedor}`}`, pesquisa && `Pesquisa: ${pesquisa}`],
          }}
          accoes={(r) => accoesLinhaContrato(r, pode, { editar: () => setEdicao(r), cancelar: () => setCancelar(r) })}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
        />
      </Card>
      <ModalContrato aberto={novo} aoFechar={() => setNovo(false)} aoGravar={(c) => navegar(String(c.id))} />
      <ModalContrato aberto={!!edicao} contrato={edicao ?? undefined} aoFechar={() => setEdicao(null)} />
      <ModalMotivo
        aberto={!!cancelar}
        titulo={cancelar ? `Cancelar o contrato ${cancelar.referencia}` : ''}
        textoOk="Cancelar contrato"
        aviso="O contrato deixa de aceitar encomendas e marcos (fica o rasto do motivo)."
        carregando={accao.isPending}
        aoFechar={() => setCancelar(null)}
        aoConfirmar={(motivo) => cancelar && accao.mutate({ url: `/compras/contratos/${cancelar.id}/cancelar`, dados: { motivo } })}
      />
    </>
  );
}
