import { Space, Typography } from 'antd';
import { ApartmentOutlined } from '@ant-design/icons';
import { Link } from 'react-router-dom';
import { useSessao } from '@/sessao/SessaoContexto';
import type { FichaProjecto } from './tipos';

/** Quem pode abrir o registo de assuntos do Método de Trabalho. */
export const PERMISSOES_ASSUNTOS = ['mt_assuntos_view', 'mt_criar', 'mt_aprovar'];

/** Destino da ligação para o assunto de origem (o ecrã de assuntos abre-o pelo parâmetro `assunto`). */
export function ligacaoAssunto(id: number): string {
  return `/m/metodo/mt_assuntos?assunto=${id}`;
}

/** Texto da origem: «Método de Trabalho — assunto MT-2026/0007» (projectos_dashboard.js:555-557). */
export function textoOrigem(p: Pick<FichaProjecto, 'origem' | 'assunto_metodo_id' | 'assunto_metodo'>): string | null {
  if (!p.assunto_metodo_id && p.origem !== 'METODO_TRABALHO') return null;
  const n = p.assunto_metodo?.numero ?? (p.assunto_metodo_id ? `#${p.assunto_metodo_id}` : null);
  return `Método de Trabalho${n ? ` — assunto ${n}` : ''}`;
}

/**
 * #25b: origem «Método de Trabalho» do projecto, com ligação para o assunto só para quem tem acesso aos assuntos
 * (mt_assuntos_view, mt_criar ou mt_aprovar); sem acesso mostra-se apenas o texto.
 */
export function OrigemProjecto({ projecto }: { projecto: Pick<FichaProjecto, 'origem' | 'assunto_metodo_id' | 'assunto_metodo'> }) {
  const { pode } = useSessao();
  const texto = textoOrigem(projecto);
  if (!texto) return null;
  const id = projecto.assunto_metodo?.id ?? projecto.assunto_metodo_id;
  return (
    <Space size={4} wrap>
      <ApartmentOutlined style={{ color: '#4338ca' }} />
      <span>{texto}</span>
      {projecto.assunto_metodo?.titulo && <Typography.Text type="secondary">({projecto.assunto_metodo.titulo})</Typography.Text>}
      {id && pode(...PERMISSOES_ASSUNTOS) && <Link className="imp-nao-imprimir" to={ligacaoAssunto(id)}>abrir o assunto</Link>}
    </Space>
  );
}
