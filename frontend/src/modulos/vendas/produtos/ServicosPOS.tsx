import { Alert, Button, Checkbox, Radio, Skeleton, Space, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { useAccao } from '@/componentes/Accoes';
import { useSessao } from '@/sessao/SessaoContexto';

/** Resposta de GET /pos/produtos/servicos: serviços do POS e, por produto, a lista marcada (null = todos). */
export interface ServicosPOSResposta {
  servicos: { valor: string; rotulo: string }[];
  produtos: Record<string, string[] | null>;
}

/** Corpo do PUT a partir da escolha no ecrã: «todos» grava null (comportamento por omissão), senão a lista marcada. */
export function corpoServicosPOS(todos: boolean, marcados: string[]): { servicos_pos: string[] | null } {
  return { servicos_pos: todos ? null : marcados };
}

/**
 * Separador «Serviços do POS» da ficha do produto: em que POS (Loja, Restaurante, Hotelaria, Lavandaria) o artigo aparece.
 * O servidor filtra o catálogo de cada terminal por esta marcação; os quartos ficam sempre só na hotelaria e os serviços de
 * lavandaria sempre na lavandaria (regras fixas do servidor).
 */
export function ServicosPOS({ produtoId }: { produtoId: number }) {
  const { pode } = useSessao();
  const consulta = useQuery({ queryKey: ['pos', 'produtos', 'servicos'], queryFn: () => obter<ServicosPOSResposta>('/pos/produtos/servicos') });
  const gravar = useAccao({ invalidar: [['pos'], ['logistica']] });
  const [todos, setTodos] = useState(true);
  const [marcados, setMarcados] = useState<string[]>([]);
  const actual = consulta.data?.produtos[String(produtoId)];

  useEffect(() => {
    if (!consulta.data) return;
    setTodos(actual === null || actual === undefined);
    setMarcados(actual ?? []);
  }, [consulta.data, actual]);

  if (consulta.isLoading) return <Skeleton active paragraph={{ rows: 3 }} />;
  if (!consulta.data) return <Alert type="warning" showIcon message="Não foi possível obter os serviços do POS." />;
  const podeGerir = pode('vendas_produtos_gerir');

  return (
    <Space direction="vertical" style={{ width: '100%' }}>
      <Typography.Text type="secondary">
        Escolha em que pontos de venda este artigo aparece. Os quartos aparecem sempre só na Hotelaria e os serviços de lavandaria sempre na Lavandaria.
      </Typography.Text>
      <Radio.Group value={todos ? 'todos' : 'escolhidos'} disabled={!podeGerir} onChange={(e) => setTodos(e.target.value === 'todos')}>
        <Space direction="vertical">
          <Radio value="todos">Em todos os serviços do POS</Radio>
          <Radio value="escolhidos">Só nos serviços escolhidos</Radio>
        </Space>
      </Radio.Group>
      {!todos && (
        <Checkbox.Group
          disabled={!podeGerir}
          value={marcados}
          onChange={(v) => setMarcados(v as string[])}
          options={consulta.data.servicos.map((s) => ({ value: s.valor, label: s.rotulo }))}
        />
      )}
      {!todos && marcados.length === 0 && <Alert type="info" showIcon message="Sem nenhum serviço escolhido, o artigo não aparece em nenhum POS." />}
      {podeGerir && (
        <Button
          type="primary"
          loading={gravar.isPending}
          onClick={() => gravar.mutate({ metodo: 'put', url: `/pos/produtos/${produtoId}/servicos`, dados: corpoServicosPOS(todos, marcados) })}
        >
          Guardar serviços do POS
        </Button>
      )}
    </Space>
  );
}
