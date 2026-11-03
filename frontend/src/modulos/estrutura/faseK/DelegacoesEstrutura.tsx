import { Button, Card, Select, Space, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { SeletorColaborador } from '@/modulos/rh/comum/componentes';
import { useAvisarErro } from '@/modulos/rh/comum/consultas';
import { useSessao } from '@/sessao/SessaoContexto';
import { useMutacao } from '../tarefas/comum';
import { CHAVE_DELEGACOES, criarDelegacao, listarDelegacoes, revogarDelegacao } from './api';
import { ModalNovaDelegacao, TabelaDelegacoes } from './DelegacoesComum';
import type { DadosDelegacao, DelegacaoChefia, EstadoDelegacao } from './tipos';

const INVALIDAR = [CHAVE_DELEGACOES, ['rh', 'portal', 'delegacoes'], ['rh', 'portal', 'resumo'], ['rh', 'portal', 'aprovacoes']];

/**
 * Estrutura orgânica › Delegações (K5): todas as delegações da empresa (`est_estrutura_view`); com `est_delegar` criam-se em
 * nome de outro colaborador e revogam-se quaisquer. Quem só vê a estrutura, só consulta.
 */
export function DelegacoesEstrutura() {
  const { pode } = useSessao();
  const delegar = pode('est_delegar');
  const [estado, setEstado] = useState<EstadoDelegacao | undefined>();
  const [colaborador, setColaborador] = useState<number | undefined>();
  const [nova, setNova] = useState(false);
  const q = useQuery({ queryKey: [...CHAVE_DELEGACOES, estado, colaborador], queryFn: () => listarDelegacoes({ estado, colaborador_id: colaborador }), retry: false });
  const criar = useMutacao((d: DadosDelegacao) => criarDelegacao(d), { invalidar: INVALIDAR, aoSucesso: () => setNova(false) });
  const revogar = useMutacao((d: DelegacaoChefia) => revogarDelegacao(d.id), { invalidar: INVALIDAR });
  useAvisarErro(q.error, 'Não foi possível carregar as delegações');
  return (
    <Card title={<Space><IconeFa nome="user-shield" />Delegações da chefia</Space>}
      extra={delegar && <Button type="primary" icon={<IconeFa nome="plus" />} onClick={() => setNova(true)}>Nova delegação</Button>}>
      <Typography.Paragraph type="secondary">
        Um delegado decide em nome da chefia, só no período e âmbito indicados (pedidos do portal, Método de trabalho, entregáveis). Fica registado em nome de quem decidiu.
      </Typography.Paragraph>
      <Space wrap style={{ marginBottom: 12 }}>
        <Select<EstadoDelegacao> allowClear placeholder="Estado" style={{ width: 160 }} value={estado} onChange={setEstado} options={[{ value: 'ATIVA', label: 'Activas' }, { value: 'REVOGADA', label: 'Revogadas' }]} />
        <SeletorColaborador placeholder="Colaborador" style={{ width: 260 }} value={colaborador} onChange={setColaborador} />
      </Space>
      <TabelaDelegacoes dados={Array.isArray(q.data) ? q.data : []} aCarregar={q.isFetching} aoRevogar={delegar ? (d) => revogar.mutate(d) : undefined} revogando={revogar.isPending} />
      <ModalNovaDelegacao aberto={nova} emNomeDeOutro aCarregar={criar.isPending} aoFechar={() => setNova(false)} aoGravar={(d) => criar.mutate(d)} />
    </Card>
  );
}
