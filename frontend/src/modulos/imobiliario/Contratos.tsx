import { PlusOutlined } from '@ant-design/icons';
import { Alert, Button, Card, Col, Input, Row, Select, Space, Statistic, Tag, message } from 'antd';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { CabecalhoModulo } from '@/componentes/CabecalhoModulo';
import { TabelaComModos } from '@/componentes/vistas';
import { notificarErro } from '@/utilitarios/erros';
import { useSessao } from '@/sessao/SessaoContexto';
import {
  CHAVES_CT, eliminarContrato, listarContratos, obterAvisos, obterContrato, obterListasContratos, obterResumoContratos, type Contrato, type FichaContrato as Ficha,
} from './apiContratos';
import { FichaContrato } from './FichaContrato';
import { useRefrescar } from './hooks';
import { formatarKz } from './logica';
import { accoesDoContrato, COR_CONTRATO } from './logicaContratos';
import { ModalContrato } from './ModalContrato';
import './imobiliario.css';

const rot = (l: { valor: string; rotulo: string }[] | undefined, v: string) => l?.find((o) => o.valor === v)?.rotulo ?? v;

/**
 * Gestão Imobiliária › Contratos de arrendamento (ecrã imo_contratos): lista com indicadores e avisos, ficha (condições, conta corrente com mora,
 * caução, histórico) e as acções do contrato. Ver: imo_contratos_view · criar e editar: imo_contrato_editar · activar e actualizar a renda:
 * imo_contrato_activar · suspender: imo_contrato_suspender · terminar e eliminar: imo_contrato_terminar · renovar: imo_contrato_renovar ·
 * gerar rendas: imo_renda_gerar · caução: imo_caucao_movimentar (também recusadas no servidor).
 */
export default function Contratos() {
  const { pode } = useSessao();
  const refrescar = useRefrescar();
  const [texto, setTexto] = useState('');
  const [estado, setEstado] = useState<string | undefined>();
  const [lado, setLado] = useState<string | undefined>();
  const [aberto, setAberto] = useState<number | null>(null);
  const [editar, setEditar] = useState<Ficha | null | undefined>(undefined);
  const listas = useQuery({ queryKey: CHAVES_CT.listas, queryFn: obterListasContratos, staleTime: 5 * 60_000 });
  const resumo = useQuery({ queryKey: CHAVES_CT.resumo, queryFn: obterResumoContratos });
  const avisos = useQuery({ queryKey: CHAVES_CT.avisos, queryFn: obterAvisos });
  const lista = useQuery({ queryKey: CHAVES_CT.lista, queryFn: listarContratos });
  useEffect(() => { if (lista.error) notificarErro(lista.error, 'Não foi possível carregar os contratos'); }, [lista.error]);
  useEffect(() => { if (resumo.error) notificarErro(resumo.error, 'Não foi possível carregar os indicadores'); }, [resumo.error]);
  useEffect(() => { if (listas.error) notificarErro(listas.error, 'Não foi possível carregar as listas'); }, [listas.error]);
  const p = {
    editar: pode('imo_contrato_editar'), activar: pode('imo_contrato_activar'), suspender: pode('imo_contrato_suspender'), terminar: pode('imo_contrato_terminar'),
    renovar: pode('imo_contrato_renovar'), gerarRendas: pode('imo_renda_gerar'), caucao: pode('imo_caucao_movimentar'),
  };
  const eliminar = useMutation({
    mutationFn: (c: Contrato) => eliminarContrato(c.id), onError: (e) => notificarErro(e, 'Não foi possível eliminar o contrato'),
    onSuccess: async () => { message.success('Contrato eliminado.'); await refrescar(); },
  });
  const abrirEdicao = useMutation({
    mutationFn: (c: Contrato) => obterContrato(c.id), onError: (e) => notificarErro(e, 'Não foi possível abrir o contrato'), onSuccess: (f) => setEditar(f),
  });

  const filtrados = useMemo(() => {
    const t = texto.trim().toLowerCase();

    return (lista.data ?? []).filter((c) => (!estado || c.estado === estado) && (!lado || c.posicao === lado)
      && (t === '' || [c.numero, c.terceiro_nome, c.imovel_nome, c.imovel_codigo, ...c.fracoes].some((x) => (x ?? '').toLowerCase().includes(t))));
  }, [lista.data, texto, estado, lado]);
  const r = resumo.data;
  const av = avisos.data ?? [];

  return (
    <>
      <CabecalhoModulo titulo="Contratos de arrendamento" icone="file-contract" subtitulo="O contrato prende-se sempre a uma fracção. Daqui saem as rendas, os documentos e a conta corrente."
        accoes={p.editar ? <Button type="primary" icon={<PlusOutlined />} onClick={() => setEditar(null)}>Novo contrato</Button> : undefined} />
      <Row gutter={[12, 12]} className="imo-kpis">
        <Col xs={12} md={6}><Card size="small"><Statistic title={`Contratos activos (de ${r?.contratos ?? 0})`} value={r?.activos ?? 0} loading={resumo.isLoading} /></Card></Col>
        <Col xs={12} md={6}><Card size="small"><Statistic title="Renda mensal a receber" value={formatarKz(r?.renda_mensal)} loading={resumo.isLoading} /></Card></Col>
        {(r?.renda_mensal_paga ?? 0) > 0 && <Col xs={12} md={6}><Card size="small"><Statistic title="Renda mensal a pagar" value={formatarKz(r?.renda_mensal_paga)} /></Card></Col>}
        {(r?.renda_mensal_paga ?? 0) > 0 && <Col xs={12} md={6}><Card size="small"><Statistic title="Margem mensal" value={formatarKz(r?.margem_mensal)} /></Card></Col>}
        <Col xs={12} md={6}><Card size="small"><Statistic title="Cauções em poder da empresa" value={formatarKz(r?.caucoes)} loading={resumo.isLoading} /></Card></Col>
        <Col xs={12} md={6}><Card size="small"><Statistic title="Rendas vencidas por facturar" value={r?.por_facturar ?? 0} loading={resumo.isLoading} /></Card></Col>
        <Col xs={12} md={6}><Card size="small"><Statistic title="Em dívida (rendas em atraso)" value={formatarKz(r?.em_divida)} loading={resumo.isLoading} /></Card></Col>
        <Col xs={12} md={6}><Card size="small"><Statistic title="Contratos a terminar em breve" value={r?.a_expirar ?? 0} loading={resumo.isLoading} /></Card></Col>
      </Row>
      {av.length > 0 && (
        <Alert type="warning" showIcon style={{ marginTop: 12 }} title={`${av.length} ponto(s) a tratar`}
          description={<ul className="imo-lista">{av.slice(0, 8).map((a) => <li key={a.tipo + a.contrato_id + a.texto}>{a.texto}</li>)}{av.length > 8 && <li>e mais {av.length - 8}.</li>}</ul>} />
      )}
      <Card style={{ marginTop: 12 }}>
        <Space wrap style={{ marginBottom: 12 }}>
          <Input.Search allowClear placeholder="número, inquilino, fracção ou imóvel" aria-label="Procurar contratos" value={texto} onChange={(e) => setTexto(e.target.value)} style={{ width: 280 }} />
          <Select allowClear placeholder="Estado" aria-label="Filtrar por estado" value={estado} onChange={setEstado} style={{ width: 160 }} options={(listas.data?.estados_contrato ?? []).map((o) => ({ value: o.valor, label: o.rotulo }))} />
          <Select allowClear placeholder="Lado" aria-label="Filtrar por lado" value={lado} onChange={setLado} style={{ width: 190 }} options={(listas.data?.lados ?? []).map((o) => ({ value: o.valor, label: o.rotulo }))} />
        </Space>
        <TabelaComModos<Contrato>
          idVista="imobiliario_contratos" rowKey="id" size="middle" loading={lista.isFetching} dataSource={filtrados} scroll={{ x: 'max-content' }} impressao={{ titulo: 'Contratos de arrendamento' }}
          columns={[
            { title: 'Número', dataIndex: 'numero', ocultavel: false, principal: true, render: (v: string, c: Contrato) => <a role="button" tabIndex={0} onClick={() => setAberto(c.id)} onKeyDown={(e) => { if (e.key === 'Enter') setAberto(c.id); }}><strong>{v}</strong>{c.contrato_pai_numero ? <small> · sob {c.contrato_pai_numero}</small> : null}</a>, valorImpressao: (c: Contrato) => c.numero },
            { title: 'Tipo', dataIndex: 'tipo', render: (v: string) => rot(listas.data?.tipos, v).replace(/ \(.*/, ''), valorImpressao: (c: Contrato) => rot(listas.data?.tipos, c.tipo).replace(/ \(.*/, '') },
            { title: 'Lado', dataIndex: 'posicao', render: (v: string) => rot(listas.data?.lados, v), valorImpressao: (c: Contrato) => rot(listas.data?.lados, c.posicao) },
            { title: 'Outra parte', dataIndex: 'terceiro_nome', render: (v: string | null, c: Contrato) => `${v ?? '—'} (${c.posicao === 'SENHORIO' ? 'inquilino' : 'senhorio'})`, valorImpressao: (c: Contrato) => c.terceiro_nome ?? '—' },
            { title: 'Imóvel / fracção', dataIndex: 'imovel_nome', render: (v: string | null, c: Contrato) => `${v ?? '—'} — ${c.fracoes.length > 1 ? `${c.fracoes.length} fracções` : (c.fracoes[0] ?? '—')}`, valorImpressao: (c: Contrato) => `${c.imovel_nome ?? ''} — ${c.fracoes.join(', ')}` },
            { title: 'Vigência', dataIndex: 'inicio', render: (v: string, c: Contrato) => `${v} a ${c.fim_real ?? c.fim ?? 'sem termo'}`, valorImpressao: (c: Contrato) => `${c.inicio} a ${c.fim_real ?? c.fim ?? 'sem termo'}` },
            { title: 'Renda', dataIndex: 'renda', align: 'right', render: (v: number) => formatarKz(v), valorImpressao: (c: Contrato) => formatarKz(c.renda) },
            { title: 'Periodicidade', dataIndex: 'periodicidade', ocultaPorOmissao: true, render: (v: string) => rot(listas.data?.periodicidades, v), valorImpressao: (c: Contrato) => rot(listas.data?.periodicidades, c.periodicidade) },
            { title: 'Caução', dataIndex: 'caucao_recebida', align: 'right', render: (v: number, c: Contrato) => `${formatarKz(v)}${c.caucao > v ? ` de ${formatarKz(c.caucao)}` : ''}`, valorImpressao: (c: Contrato) => formatarKz(c.caucao_recebida) },
            { title: 'Estado', dataIndex: 'estado', etiqueta: true, render: (v: Contrato['estado']) => <Tag color={COR_CONTRATO[v]}>{rot(listas.data?.estados_contrato, v)}</Tag>, valorImpressao: (c: Contrato) => rot(listas.data?.estados_contrato, c.estado) },
          ]}
          accoes={(c) => [
            { icone: 'eye', titulo: 'Abrir a ficha', onClick: () => setAberto(c.id) },
            ...(p.editar && accoesDoContrato(c.estado, !!c.fim).editar ? [{ icone: 'edit', titulo: 'Editar', onClick: () => abrirEdicao.mutate(c) }] : []),
            ...(p.terminar && accoesDoContrato(c.estado, !!c.fim).eliminar ? [{ icone: 'trash', titulo: 'Eliminar', variante: 'perigo' as const,
              confirmar: { titulo: `Eliminar o contrato ${c.numero} e as suas rendas não facturadas?`, textoOk: 'Eliminar', perigo: true }, onClick: () => eliminar.mutate(c) }] : []),
          ]}
          vazio={{ titulo: 'Sem contratos', texto: 'O primeiro passo é ter a fracção registada em Imóveis.' }}
        />
      </Card>
      <ModalContrato contrato={editar} listas={listas.data} aoFechar={(g) => { setEditar(undefined); if (g) setAberto(g.id); }} />
      <FichaContrato id={aberto} listas={listas.data} pode={p} aoEditar={(c) => setEditar(c)} aoFechar={() => setAberto(null)} />
    </>
  );
}
