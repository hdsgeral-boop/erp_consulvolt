import { Button, Dropdown, Modal, Result, Space, Spin, Tabs } from 'antd';
import { ArrowLeftOutlined, DownOutlined, EditOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { useAccao } from '@/componentes/Accoes';
import { BarraExecucao, EtiquetaProjectos } from './comum/componentes';
import { accoesProjecto } from './comum/regras';
import type { FichaProjecto } from './comum/tipos';
import { ModalProjecto } from './ModalProjecto';
import { SeparadorResumo } from './separadores/Resumo';
import { SeparadorPlaneamento } from './separadores/Planeamento';
import { SeparadorKanban } from './separadores/Kanban';
import { SeparadorEquipa } from './separadores/Equipa';
import { SeparadorOrganigrama } from './separadores/Organigrama';
import { SeparadorOrcamento } from './separadores/Orcamento';
import { SeparadorHoras } from './separadores/Horas';
import { SeparadorRequisicoes } from './separadores/Requisicoes';
import { SeparadorRevisoes } from './separadores/Revisoes';

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
  const [editar, setEditar] = useState(false);
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
          </Space>
        }
        subtitulo={
          <Space size="large" wrap>
            {p.cliente && <span>Cliente: {p.cliente.nome}</span>}
            {p.encomenda && <span>Encomenda: {p.encomenda.numero_documento}</span>}
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
          { key: 'resumo', label: 'Resumo', children: <SeparadorResumo {...props} aoIr={(s) => setParams({ sep: s }, { replace: true })} /> },
          { key: 'planeamento', label: 'Planeamento (WBS)', children: <SeparadorPlaneamento {...props} /> },
          { key: 'kanban', label: 'Kanban', children: <SeparadorKanban {...props} /> },
          { key: 'equipa', label: 'Equipa', children: <SeparadorEquipa {...props} /> },
          { key: 'organigrama', label: 'Organigrama', children: <SeparadorOrganigrama {...props} /> },
          { key: 'orcamento', label: 'Orçamento e aditamentos', children: <SeparadorOrcamento {...props} /> },
          { key: 'horas', label: 'Horas e equipamentos', children: <SeparadorHoras {...props} /> },
          { key: 'requisicoes', label: 'Requisições', children: <SeparadorRequisicoes {...props} /> },
          { key: 'revisoes', label: 'Autos e facturação', children: <SeparadorRevisoes {...props} /> },
        ]}
      />
      <ModalProjecto aberto={editar} projecto={p} aoFechar={() => setEditar(false)} />
    </>
  );
}
