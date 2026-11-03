import { Alert, Button, Card, Space, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { IconeFa } from '@/componentes/fluxos';
import { CHAVE_PORTAL_DELEGACOES, criarDelegacaoPortal, minhasDelegacoes, revogarDelegacaoPortal } from '@/modulos/estrutura/faseK/api';
import { ModalNovaDelegacao, TabelaDelegacoes } from '@/modulos/estrutura/faseK/DelegacoesComum';
import { normalizarMinhasDelegacoes, situacaoDelegacao } from '@/modulos/estrutura/faseK/regras';
import type { DadosDelegacao, DelegacaoChefia } from '@/modulos/estrutura/faseK/tipos';
import { useMutacao } from '@/modulos/estrutura/tarefas/comum';
import { useAvisarErro } from '../comum/consultas';

/** Invalida o portal (resumo, aprovações) além das delegações: o que o delegado vê muda com a delegação. */
const INVALIDAR = [CHAVE_PORTAL_DELEGACOES, ['rh', 'estrutura', 'delegacoes'], ['rh', 'portal', 'resumo'], ['rh', 'portal', 'aprovacoes']];

/**
 * Portal › Delegações (K5): dar delegação da minha chefia a um colaborador (datas, âmbito, motivo), ver as que dei e as
 * que recebi e revogar. Sem permissão especial: cada um só mexe nas suas. Em nome de outro, só quem tem `est_delegar`
 * (Estrutura orgânica › Delegações).
 */
export function PortalDelegacoes() {
  const q = useQuery({ queryKey: CHAVE_PORTAL_DELEGACOES, queryFn: minhasDelegacoes, retry: false });
  useAvisarErro(q.error, 'Não foi possível carregar as delegações');
  const { dadas, recebidas } = normalizarMinhasDelegacoes(q.data);
  const [nova, setNova] = useState(false);
  const criar = useMutacao((d: DadosDelegacao) => criarDelegacaoPortal(d), { invalidar: INVALIDAR, aoSucesso: () => setNova(false) });
  const revogar = useMutacao((d: DelegacaoChefia) => revogarDelegacaoPortal(d.id), { invalidar: INVALIDAR });
  const emVigor = recebidas.filter((d) => situacaoDelegacao(d) === 'vigente');
  return (
    <Space orientation="vertical" size="middle" style={{ width: '100%' }}>
      {emVigor.length > 0 && (
        <Alert type="info" showIcon title={`Está a decidir em nome de ${emVigor.map((d) => d.delegante?.nome ?? d.delegante_nome ?? `#${d.delegante_colaborador_id}`).join(', ')}.`}
          description="Os pedidos, assuntos e entregas dessas chefias aparecem em «Aprovações» com a etiqueta «em nome de»." />
      )}
      <Card title={<Space><IconeFa nome="user-shield" />As delegações que dei</Space>} loading={q.isLoading}
        extra={<Button type="primary" icon={<IconeFa nome="plus" />} onClick={() => setNova(true)}>Dar delegação</Button>}>
        <Typography.Paragraph type="secondary">
          Enquanto estiver fora, o colaborador escolhido decide em seu nome o que a si caberia decidir, só no período e nas áreas indicadas. Pode revogar quando quiser.
        </Typography.Paragraph>
        <TabelaDelegacoes dados={dadas} mostrar="delegado" aoRevogar={(d) => revogar.mutate(d)} revogando={revogar.isPending} />
      </Card>
      <Card title={<Space><IconeFa nome="inbox" />As delegações que recebi</Space>} loading={q.isLoading}>
        <TabelaDelegacoes dados={recebidas} mostrar="delegante" />
      </Card>
      <ModalNovaDelegacao aberto={nova} aCarregar={criar.isPending} aoFechar={() => setNova(false)} aoGravar={(d) => criar.mutate(d)} />
    </Space>
  );
}
