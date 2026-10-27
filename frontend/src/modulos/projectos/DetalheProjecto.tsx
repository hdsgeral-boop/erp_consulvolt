import { Button, Dropdown, Modal, Result, Space, Spin, Tabs, Tag } from 'antd';
import { ArrowLeftOutlined, DownOutlined, EditOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { useSessao } from '@/sessao/SessaoContexto';
import { useAccao } from '@/componentes/Accoes';
import { BarraExecucao, EtiquetaProjectos } from './comum/componentes';
import { accoesProjecto } from './comum/regras';
import type { FichaProjecto } from './comum/tipos';
import { ModalProjecto } from './ModalProjecto';
import { OrigemProjecto } from './comum/OrigemProjecto';
import { SeparadorResumo } from './separadores/Resumo';
import { SeparadorPlaneamento } from './separadores/Planeamento';
import { SeparadorKanban } from './separadores/Kanban';
import { SeparadorEquipa } from './separadores/Equipa';
import { SeparadorOrganigrama } from './separadores/Organigrama';
import { SeparadorOrcamento } from './separadores/Orcamento';
import { SeparadorBacklog } from './separadores/Backlog';
import { SeparadorHoras } from './separadores/Horas';
import { SeparadorRequisicoes } from './separadores/Requisicoes';
import { SeparadorRevisoes } from './separadores/Revisoes';
import { SeparadorExecucao } from './separadores/Execucao';
import { SeparadorActividade } from './separadores/Actividade';
import { SeparadorConfiguracao } from './separadores/Configuracao';

export interface PropsSeparador {
  projecto: FichaProjecto;
  acc: ReturnType<typeof accoesProjecto>;
}

/** Detalhe do projecto com separadores (o separador activo fica no endereço: ?sep=…). */
export function DetalheProjecto() {
  const { id } = useParams();
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [params, setParams] = useSearchParams();
  // ?editar=1: aberto a partir do ícone «Editar» da Carteira (ui_projects.js:416)
  const [editar, setEditarEstado] = useState(() => params.get('editar') === '1');
  const setEditar = (v: boolean) => {
    setEditarEstado(v);
    if (!v && params.has('editar')) {
      const n = new URLSearchParams(params);
      n.delete('editar');
      setParams(n, { replace: true });
    }
  };
  const q = useQuery({ queryKey: ['projectos', 'ficha', id], queryFn: () => obter<FichaProjecto>(`/projetos/${id}`) });
  const estado = useAccao({ invalidar: [['projectos']] });

  if (q.isLoading) return <Spin style={{ display: 'block', margin: 48 }} />;
  if (q.error || !q.data) return <Result status="404" title="Projecto não encontrado" extra={<Button onClick={() => navegar('..')}>Voltar</Button>} />;
  const p = q.data;
  const acc = accoesProjecto(p, pode);
  const separador = params.get('sep') ?? 'resumo';

  const mudarEstado = (novo: string, texto: string) =>
    Modal.confirm({
      title: `${texto} o projecto?`,
      content: novo === 'ENCERRADO' ? 'Um projecto encerrado não aceita mais imputações.' : undefined,
      okText: texto, cancelText: 'Cancelar', okButtonProps: { danger: novo === 'CANCELADO' },
      onOk: () => estado.mutateAsync({ url: `/projetos/${p.id}/estado`, dados: { estado: novo } }),
    });

  const itensEstado = [
    acc.activar && { key: 'ACTIVO', label: 'Activar' },
    acc.encerrar && { key: 'ENCERRADO', label: 'Encerrar' },
    acc.cancelar && { key: 'CANCELADO', label: 'Cancelar', danger: true },
    acc.reabrir && { key: 'ACTIVO', label: 'Reabrir' },
  ].filter(Boolean) as { key: string; label: string; danger?: boolean }[];

  const props: PropsSeparador = { projecto: p, acc };

  return (
    <>
      <CabecalhoPagina
        titulo={
          <Space wrap>
            <Button type="text" icon={<ArrowLeftOutlined />} onClick={() => navegar('..')} />
            {p.codigo} — {p.nome}
            <EtiquetaProjectos valor={p.tipo} />
            <EtiquetaProjectos valor={p.estado} />
            {(p.tags ?? []).map((t) => <Tag key={t} color="blue">{t}</Tag>)}
          </Space>
        }
        subtitulo={
          <Space size="large" wrap>
            {p.cliente && <span>Cliente: {p.cliente.nome}</span>}
            {p.encomenda && <span>Encomenda: {p.encomenda.numero_documento}</span>}
            <OrigemProjecto projecto={p} />
            <Space wrap>Execução <BarraExecucao valor={p.execucao} largura={160} /></Space>
          </Space>
        }
        accoes={
          <>
            {acc.editar && <Button icon={<EditOutlined />} onClick={() => setEditar(true)}>Editar</Button>}
            {itensEstado.length > 0 && (
              <Dropdown menu={{ items: itensEstado.map((i, n) => ({ ...i, key: `${i.key}-${n}` })), onClick: ({ key }) => { const i = itensEstado[Number(key.split('-')[1])]; mudarEstado(i.key, i.label); } }}>
                <Button loading={estado.isPending}>Estado <DownOutlined /></Button>
              </Dropdown>
            )}
          </>
        }
      />
      <Tabs
        activeKey={separador}
        onChange={(k) => setParams({ sep: k }, { replace: true })}
        destroyOnHidden
        items={[
          // ordem do legado (viewProjectDetails, ui_projects.js:516-557): Resumo, Planeamento (WBS & Kanban), Execução, Equipa,
          // Organigrama, Autos & Revisões, Aditamentos, Requisições, Orçamento base; «Horas e equipamentos» é do sistema novo (fim)
          { key: 'resumo', label: <span><IconeFa nome="info-circle" /> Resumo</span>, children: <SeparadorResumo {...props} aoIr={(s) => setParams({ sep: s }, { replace: true })} /> },
          { key: 'planeamento', label: <span><IconeFa nome="tasks" /> Planeamento (WBS)</span>, children: <SeparadorPlaneamento {...props} /> },
          { key: 'kanban', label: <span><IconeFa nome="columns" /> Kanban</span>, children: <SeparadorKanban {...props} /> },
          // Versão Actual (ui_projects.js:539): horas, quantidades medidas e tarefas concluídas por colaborador e período
          { key: 'execucao', label: <span><IconeFa nome="helmet-safety" /> Execução</span>, children: <SeparadorExecucao {...props} /> },
          { key: 'equipa', label: <span><IconeFa nome="users" /> Equipa</span>, children: <SeparadorEquipa {...props} /> },
          { key: 'organigrama', label: <span><IconeFa nome="sitemap" /> Organigrama</span>, children: <SeparadorOrganigrama {...props} /> },
          { key: 'revisoes', label: <span><IconeFa nome="file-invoice-dollar" /> Autos e facturação</span>, children: <SeparadorRevisoes {...props} /> },
          { key: 'requisicoes', label: <span><IconeFa nome="shopping-cart" /> Requisições</span>, children: <SeparadorRequisicoes {...props} /> },
          { key: 'orcamento', label: <span><IconeFa nome="calculator" /> Orçamento e aditamentos</span>, children: <SeparadorOrcamento {...props} /> },
          { key: 'backlog', label: <span><IconeFa nome="layer-group" /> Backlog</span>, children: <SeparadorBacklog {...props} /> },
          { key: 'horas', label: <span><IconeFa nome="clock" /> Horas e equipamentos</span>, children: <SeparadorHoras {...props} /> },
          // auditoria backend×frontend: registo de actividade (GET /projetos/{id}/atividade) e configuração dos projectos (GET/PUT /projetos/configuracao)
          { key: 'actividade', label: <span><IconeFa nome="history" /> Actividade</span>, children: <SeparadorActividade {...props} /> },
          { key: 'configuracao', label: <span><IconeFa nome="cog" /> Configuração</span>, children: <SeparadorConfiguracao {...props} /> },
        ]}
      />
      <ModalProjecto aberto={editar && acc.editar} projecto={p} aoFechar={() => setEditar(false)} />
    </>
  );
}
