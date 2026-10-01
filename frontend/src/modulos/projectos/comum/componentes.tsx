import { Input, Modal, Progress, Select, Tag, Typography, type SelectProps } from 'antd';
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { obter } from '@/api/cliente';
import { useColaboradores } from '@/modulos/rh/comum/consultas';
import { achatarWbs } from './regras';
import type { Membro, Wbs } from './tipos';

/** Componentes e consultas partilhados de Projectos. Chaves de cache: ['projectos', ...]. */

const ESTADOS: Record<string, { rotulo: string; cor: string }> = {
  PREPARACAO: { rotulo: 'Em preparação', cor: 'default' },
  ACTIVO: { rotulo: 'Activo', cor: 'green' },
  EM_CURSO: { rotulo: 'Em curso', cor: 'processing' },
  ENCERRADO: { rotulo: 'Encerrado', cor: 'blue' },
  CANCELADO: { rotulo: 'Cancelado', cor: 'red' },
  PENDENTE: { rotulo: 'Pendente', cor: 'default' },
  CONCLUIDA: { rotulo: 'Concluída', cor: 'green' },
  BLOQUEADA: { rotulo: 'Bloqueada', cor: 'red' },
  APROVADO: { rotulo: 'Aprovado', cor: 'green' },
  REJEITADO: { rotulo: 'Rejeitado', cor: 'red' },
  PROCESSADO: { rotulo: 'Processado', cor: 'blue' },
  REGISTADO: { rotulo: 'Registado', cor: 'default' },
  IMPUTADO: { rotulo: 'Imputado', cor: 'green' },
  COMPRAS: { rotulo: 'Em compras', cor: 'gold' },
  INTERNO: { rotulo: 'Interno', cor: 'purple' },
  EXTERNO: { rotulo: 'Externo', cor: 'cyan' },
  TERCEIRO: { rotulo: 'Terceiro', cor: 'orange' },
  LIVRE: { rotulo: 'Livre', cor: 'default' },
  CUSTO: { rotulo: 'Custo', cor: 'volcano' },
  PROVEITO: { rotulo: 'Proveito', cor: 'green' },
  COMPROMISSO: { rotulo: 'Compromisso', cor: 'gold' },
};

export function rotuloProjectos(v: string | null | undefined): string {
  if (!v) return '—';
  return ESTADOS[v]?.rotulo ?? v.charAt(0) + v.slice(1).toLowerCase().replace(/_/g, ' ');
}

export function EtiquetaProjectos({ valor }: { valor: string | null | undefined }) {
  if (!valor) return <>—</>;
  return <Tag color={ESTADOS[valor]?.cor ?? 'default'}>{rotuloProjectos(valor)}</Tag>;
}

export function BarraExecucao({ valor, largura = 120 }: { valor: number | null | undefined; largura?: number }) {
  return <Progress percent={Math.round(valor ?? 0)} size="small" style={{ width: largura, margin: 0 }} status={(valor ?? 0) >= 100 ? 'success' : 'normal'} />;
}

export function useWbs(projectoId: number | string | undefined) {
  return useQuery({ queryKey: ['projectos', 'wbs', String(projectoId)], queryFn: () => obter<Wbs>(`/projetos/${projectoId}/wbs`), enabled: !!projectoId });
}

export function useEquipa(projectoId: number | string | undefined) {
  return useQuery({
    queryKey: ['projectos', 'equipa', String(projectoId)],
    queryFn: () => obter<{ equipa: { id: number; nome: string } | null; membros: Membro[] }>(`/projetos/${projectoId}/equipa`),
    enabled: !!projectoId,
  });
}

type PropsSelect<V> = Omit<SelectProps<V>, 'options' | 'loading' | 'showSearch'>;

/** Tarefa do projecto (lista plana da WBS, com indentação das subtarefas). */
export function SeletorTarefa({ projectoId, ...props }: PropsSelect<number> & { projectoId: number | string }) {
  const w = useWbs(projectoId);
  return (
    <Select<number>
      showSearch
      optionFilterProp="label"
      loading={w.isLoading}
      placeholder="Tarefa"
      popupMatchSelectWidth={false}
      options={achatarWbs(w.data).map((t) => ({ value: t.id, label: `${'— '.repeat(t.nivel)}${t.codigo ? `${t.codigo} ` : ''}${t.nome}` }))}
      {...props}
    />
  );
}

/** Membro da equipa do projecto; `apenasInternos` mostra só colaboradores (para registo de horas devolve o colaborador_id). */
export function SeletorMembro({ projectoId, apenasInternos, valorColaborador, ...props }: PropsSelect<number> & { projectoId: number | string; apenasInternos?: boolean; valorColaborador?: boolean }) {
  const e = useEquipa(projectoId);
  const membros = (e.data?.membros ?? []).filter((m) => !apenasInternos || m.tipo === 'INTERNO');
  return (
    <Select<number>
      showSearch
      optionFilterProp="label"
      loading={e.isLoading}
      placeholder={apenasInternos ? 'Colaborador da equipa' : 'Membro da equipa'}
      popupMatchSelectWidth={false}
      options={membros.map((m) => ({ value: (valorColaborador ? m.colaborador_id : m.id) ?? m.id, label: `${m.nome}${m.papel ? ` (${m.papel})` : ''}` }))}
      {...props}
    />
  );
}

/** Colaborador da empresa (todas as páginas de /rh/colaboradores, cache de 5 min). */
export function SeletorColaborador(props: PropsSelect<number> & { mode?: 'multiple' }) {
  const c = useColaboradores();
  return (
    <Select<number>
      showSearch
      optionFilterProp="label"
      loading={c.isLoading}
      placeholder="Colaborador"
      popupMatchSelectWidth={false}
      options={c.lista.filter((x) => x.estado !== 'INACTIVO').map((x) => ({ value: x.id, label: `${x.nome_completo}${x.nif ? ` (${x.nif})` : ''}` }))}
      {...props}
    />
  );
}

/**
 * Confirmação forte das eliminações de Projectos: o servidor exige `confirmacao = «ELIMINAR»` no corpo do pedido.
 * Chama `aoConfirmar('ELIMINAR')` só quando o utilizador escreve a palavra.
 */
export function ModalEliminar({ aberto, titulo, aviso, carregando, aoConfirmar, aoFechar }: { aberto: boolean; titulo: string; aviso?: string; carregando?: boolean; aoConfirmar: (confirmacao: string) => void; aoFechar: () => void }) {
  const [texto, setTexto] = useState('');
  const valido = texto.trim().toUpperCase() === 'ELIMINAR';
  return (
    <Modal
      title={titulo}
      open={aberto}
      onCancel={() => { setTexto(''); aoFechar(); }}
      okText="Eliminar"
      cancelText="Cancelar"
      okButtonProps={{ danger: true, disabled: !valido }}
      confirmLoading={carregando}
      onOk={() => { aoConfirmar('ELIMINAR'); setTexto(''); }}
      destroyOnClose
    >
      {aviso && <Typography.Paragraph>{aviso}</Typography.Paragraph>}
      <Typography.Paragraph type="secondary">Escreva <strong>ELIMINAR</strong> para confirmar.</Typography.Paragraph>
      <Input value={texto} onChange={(e) => setTexto(e.target.value)} autoFocus />
    </Modal>
  );
}
