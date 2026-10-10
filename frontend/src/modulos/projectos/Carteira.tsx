import { Button, Card, Input, Select, Typography } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import { BarraFiltros, useEcraPequeno } from '@/componentes/responsivo';
import { useState } from 'react';
import { Route, Routes, useNavigate } from 'react-router-dom';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { EtiquetaProjectos, rotuloProjectos } from './comum/componentes';
import { accoesLinhaCarteira } from './comum/regras';
import type { Projecto } from './comum/tipos';
import { DetalheProjecto } from './DetalheProjecto';
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

function ListaProjectos() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [pesquisa, setPesquisa] = useState('');
  const [estado, setEstado] = useState<string>();
  const [tipo, setTipo] = useState<string>();
  const [novo, setNovo] = useState(false);
  const pequeno = useEcraPequeno();

  const colunas: ColunaApi<Projecto>[] = [
    { title: 'Código', dataIndex: 'codigo', render: (v) => <Typography.Text strong>{v ?? '—'}</Typography.Text> },
    { title: 'Designação', dataIndex: 'nome', ellipsis: true, width: 320 },
    { title: 'Cliente', key: 'cliente', ellipsis: true, width: 240, responsive: ['md'], ocultaPorOmissao: true, render: (_, p) => p.cliente?.nome ?? (p.cliente_id ? `#${p.cliente_id}` : '—') },
    { title: 'Tipo', dataIndex: 'tipo', responsive: ['lg'], ocultaPorOmissao: true, render: (v) => <EtiquetaProjectos valor={v} /> },
    // como o legado (renderProjectsList, ui_projects.js:403): ACTIVO e EM_CURSO aparecem os dois como «Em Curso»
    { title: 'Estado', dataIndex: 'estado', render: (v) => <EtiquetaProjectos valor={v === 'ACTIVO' || v === 'ATIVO' ? 'EM_CURSO' : v} /> },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo="Carteira de Projectos"
        subtitulo="Lista global de projectos e obras."
        accoes={pode('proj_gerir') && <Button type="primary" icon={<PlusOutlined />} onClick={() => setNovo(true)}>Novo Projecto</Button>}
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
    </>
  );
}
