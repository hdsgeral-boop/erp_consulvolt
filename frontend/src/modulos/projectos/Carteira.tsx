import { Button, Card, Input, Select, Space, Tag, Typography } from 'antd';
import { PlusOutlined, TagsOutlined } from '@ant-design/icons';
import { BarraFiltros, useEcraPequeno } from '@/componentes/responsivo';
import { useState } from 'react';
import { Route, Routes, useNavigate } from 'react-router-dom';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { EtiquetaProjectos, rotuloProjectos } from './comum/componentes';
import { accoesLinhaCarteira } from './comum/regras';
import type { Projecto } from './comum/tipos';
import { CabecalhoSemaforo, Semaforo } from './comum/Semaforo';
import { DetalheProjecto } from './DetalheProjecto';
import { ModalCatalogoEtiquetasSinalizacoes } from './ModalCatalogoEtiquetasSinalizacoes';
import { ModalProjecto } from './ModalProjecto';

/** Projectos › Carteira (ecrã projectos_carteira): lista dos projectos e detalhe com os separadores de planeamento, equipa, orçamento e execução. */
export default function Carteira() {
  return (
    <Routes>
      <Route index element={<ListaProjectos />} />
      <Route path=":id/*" element={<DetalheProjecto />} />
    </Routes>
  );
}

/** Estado na Carteira, com as cores dos badges do legado (success / warning / secondary / danger). */
const ESTADO_CARTEIRA: Record<string, { rotulo: string; cor: string }> = {
  ACTIVO: { rotulo: 'Em curso', cor: 'success' },
  ATIVO: { rotulo: 'Em curso', cor: 'success' },
  EM_CURSO: { rotulo: 'Em curso', cor: 'success' },
  PREPARACAO: { rotulo: 'Em preparação', cor: 'warning' },
  ENCERRADO: { rotulo: 'Encerrado', cor: 'default' },
  CANCELADO: { rotulo: 'Cancelado', cor: 'error' },
};

function ListaProjectos() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [pesquisa, setPesquisa] = useState('');
  const [estado, setEstado] = useState<string>();
  const [tipo, setTipo] = useState<string>();
  const [novo, setNovo] = useState(false);
  const [catalogo, setCatalogo] = useState(false);
  const pequeno = useEcraPequeno();

  const colunas: ColunaApi<Projecto>[] = [
    // semáforo (legado: ícone verde fixo, ui_projects.js:468) — agora com a regra dos alertas de prazo e orçamento do Resumo (comum/regraSemaforo.ts)
    { title: <CabecalhoSemaforo />, key: 'semaforo', width: 50, align: 'center', filtravel: false, render: (_, p) => <Semaforo estado={p.estado} indicadores={p.semaforo} /> },
    { title: 'Código', dataIndex: 'codigo', width: 150, render: (v) => <Typography.Text strong>{v ?? '—'}</Typography.Text> },
    { title: 'Designação', dataIndex: 'nome', ellipsis: true, width: 320 },
    { title: 'Cliente', key: 'cliente', ellipsis: true, width: 240, responsive: ['md'], ocultaPorOmissao: true, render: (_, p) => p.cliente?.nome ?? (p.cliente_id ? `#${p.cliente_id}` : '—') },
    { title: 'Tipo', dataIndex: 'tipo', responsive: ['lg'], ocultaPorOmissao: true, render: (v) => <EtiquetaProjectos valor={v} /> },
    // como o legado (renderProjectsList, ui_projects.js:456-512): ACTIVO e EM_CURSO aparecem os dois como «Em Curso» (verde); preparação âmbar; encerrado cinzento; cancelado vermelho
    { title: 'Estado', dataIndex: 'estado', width: 150, render: (v: string) => <Tag color={ESTADO_CARTEIRA[v]?.cor ?? 'default'}>{ESTADO_CARTEIRA[v]?.rotulo ?? rotuloProjectos(v)}</Tag> },
    { title: 'Etiquetas e sinalizações', key: 'etiquetas', ellipsis: true, responsive: ['lg'], ocultaPorOmissao: true, filtravel: false, render: (_, p) => (
      <Space size={4} wrap>
        {(p.etiquetas ?? []).map((e) => <Tag key={`e-${e.id}`} color={e.cor}>{e.nome}</Tag>)}
        {(p.sinalizacoes ?? []).map((s) => <Tag key={`s-${s.id}`} color={s.cor}>{s.nome}</Tag>)}
      </Space>
    ) },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo="Carteira de Projectos"
        subtitulo="Lista global de projectos e obras."
        accoes={
          <>
            <Button icon={<TagsOutlined />} onClick={() => setCatalogo(true)}>Etiquetas e sinalizações</Button>
            {pode('proj_gerir') && <Button type="primary" icon={<PlusOutlined />} onClick={() => setNovo(true)}>Novo Projecto</Button>}
          </>
        }
      />
      <Card>
        <BarraFiltros>
          <Input.Search placeholder="Código ou nome" allowClear onSearch={setPesquisa} style={{ width: 260, maxWidth: '100%' }} />
          <Select placeholder="Estado" allowClear value={estado} onChange={setEstado} style={{ width: 170 }}
            options={[{ value: 'PREPARACAO', label: 'Em preparação' }, { value: 'ACTIVO', label: 'Activo / em curso' }, { value: 'ENCERRADO', label: 'Encerrado' }, { value: 'CANCELADO', label: 'Cancelado' }]} />
          <Select placeholder="Tipo" allowClear value={tipo} onChange={setTipo} style={{ width: 150 }}
            options={[{ value: 'INTERNO', label: 'Interno' }, { value: 'EXTERNO', label: 'Externo (obra)' }]} />
        </BarraFiltros>
        <TabelaApi<Projecto>
          url="/projetos"
          chaveConsulta={['projectos', 'lista']}
          filtros={{ pesquisa, estado, tipo }}
          columns={colunas}
          size={pequeno ? 'small' : 'middle'}
          impressao={{ titulo: 'Carteira de projectos', filtros: [estado && `Estado: ${rotuloProjectos(estado)}`, tipo && `Tipo: ${rotuloProjectos(tipo)}`, pesquisa && `Pesquisa: ${pesquisa}`] }}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
          tituloAccoes="Acções"
          accoes={(r) => accoesLinhaCarteira(r, pode).map((a) => ({ icone: a.icone, titulo: a.titulo, onClick: () => navegar(a.destino) }))}
        />
      </Card>
      <ModalProjecto aberto={novo} aoFechar={() => setNovo(false)} aoGravar={(p) => navegar(String(p.id))} />
      <ModalCatalogoEtiquetasSinalizacoes aberto={catalogo} aoFechar={() => setCatalogo(false)} />
    </>
  );
}
