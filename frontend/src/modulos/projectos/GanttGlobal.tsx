import { Card, Checkbox, Flex, Input, Space } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { contemTexto } from '@/modulos/compras/comum/lista';
import { Gantt } from './comum/Gantt';
import { linhasGanttGlobal } from './comum/regras';
import type { GanttGlobal as DadosGantt } from './comum/tipos';

/** Projectos › Gantt global (ecrã projectos_gantt): calendário dos projectos activos e das suas tarefas. */
export default function GanttGlobal() {
  const navegar = useNavigate();
  const { menu } = useSessao();
  const [tarefas, setTarefas] = useState(true);
  const [texto, setTexto] = useState('');
  const q = useQuery({ queryKey: ['projectos', 'gantt'], queryFn: () => obter<DadosGantt>('/projetos/gantt') });
  const podeCarteira = menu.some((m) => m.ecras.some((e) => e.id === 'projectos_carteira'));

  const filtrado = useMemo<DadosGantt | undefined>(() => {
    if (!q.data) return undefined;
    return { ...q.data, segmentos: q.data.segmentos.map((s) => ({ ...s, projetos: s.projetos.filter((p) => contemTexto(texto, p.codigo, p.nome)) })).filter((s) => s.projetos.length) };
  }, [q.data, texto]);
  const linhas = useMemo(() => linhasGanttGlobal(filtrado, tarefas), [filtrado, tarefas]);

  return (
    <>
      <CabecalhoPagina titulo="Gantt global" subtitulo="Projectos activos e respectivas tarefas; barras tracejadas = sem data de fim" />
      <Card loading={q.isLoading}>
        <Flex gap={12} wrap justify="space-between" style={{ marginBottom: 12 }}>
          <Space>
            <Input.Search placeholder="Código ou nome do projecto" allowClear onSearch={setTexto} style={{ width: 280 }} />
            <Checkbox checked={tarefas} onChange={(e) => setTarefas(e.target.checked)}>Mostrar tarefas</Checkbox>
          </Space>
        </Flex>
        <Gantt
          linhas={linhas}
          inicio={q.data?.inicio}
          fim={q.data?.fim}
          aoClicar={podeCarteira ? (l) => l.tipo === 'projecto' && navegar(`/m/projectos/projectos_carteira/${l.id}?sep=planeamento`) : undefined}
        />
      </Card>
    </>
  );
}
