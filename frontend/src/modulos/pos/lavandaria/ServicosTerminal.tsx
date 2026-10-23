import { Alert, Button, Checkbox, Divider, Flex, Modal, Skeleton, Typography } from 'antd';
import { useEffect, useState } from 'react';
import { larguraModal } from '@/componentes/responsivo';
import { useAccao } from '@/componentes/Accoes';
import type { Terminal } from '../comum/tipos';
import { GRUPOS_LAV, useServicosLav, useServicosLavTerminal } from './dados';

/**
 * Configuração obrigatória do POS de Lavandaria logo a seguir a criá-lo: escolhe, do catálogo de serviços da
 * empresa, quais este terminal usa no seu dia-a-dia (a Recepção só oferece estes). Sem nenhum escolhido o terminal
 * fica sem serviço nenhum na Recepção — por isso «Gravar» só liga com pelo menos um marcado.
 */
export function ModalServicosTerminal({ terminal, obrigatorio, aoFechar }: { terminal: Terminal | null; obrigatorio?: boolean; aoFechar: () => void }) {
  const catalogo = useServicosLav(false);
  const actual = useServicosLavTerminal(terminal?.id);
  const [escolhidos, setEscolhidos] = useState<number[]>([]);
  const gravar = useAccao<{ servico_ids: number[] | null }>({ invalidar: [['pos']], aoSucesso: aoFechar, tituloErro: 'Não foi possível gravar os serviços do terminal' });

  useEffect(() => {
    if (terminal && actual.data) setEscolhidos(actual.data.servico_ids ?? (catalogo.data ?? []).map((s) => s.id));
  }, [terminal, actual.data, catalogo.data]);

  const alternar = (id: number, marcado: boolean) => setEscolhidos((e) => (marcado ? [...e, id] : e.filter((x) => x !== id)));
  const grupos = Object.entries(GRUPOS_LAV) as [keyof typeof GRUPOS_LAV, string][];
  const aCarregar = catalogo.isLoading || (!!terminal && actual.isLoading);

  return (
    <Modal
      open={!!terminal}
      title={<span>Configurar serviços — {terminal?.codigo} · {terminal?.nome}</span>}
      width={larguraModal(620)}
      maskClosable={false}
      destroyOnHidden
      onCancel={aoFechar}
      footer={
        <Flex justify="end" gap={8}>
          <Button onClick={aoFechar}>{obrigatorio ? 'Configurar mais tarde' : 'Cancelar'}</Button>
          <Button type="primary" disabled={escolhidos.length === 0} loading={gravar.isPending} onClick={() => terminal && gravar.mutate({ metodo: 'put', url: `/pos/terminais/${terminal.id}/servicos-lav`, dados: { servico_ids: escolhidos } })}>
            Gravar serviços
          </Button>
        </Flex>
      }
    >
      {obrigatorio && (
        <Alert
          type="warning"
          showIcon
          style={{ marginBottom: 12 }}
          message="Este POS ainda não tem serviços configurados"
          description="Escolha pelo menos um serviço para a Recepção poder registar ordens neste terminal. Pode alterar esta escolha mais tarde, a qualquer momento, em «Configurar»."
        />
      )}
      {aCarregar ? (
        <Skeleton active />
      ) : !catalogo.data?.length ? (
        <Alert type="info" showIcon message="Ainda não há serviços na tabela da lavandaria. Crie-os primeiro em POS › Lavandaria › Tabelas." />
      ) : (
        <>
          <Flex justify="space-between" align="center" style={{ marginBottom: 4 }}>
            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
              {escolhidos.length} de {catalogo.data.length} serviço(s) escolhido(s).
            </Typography.Text>
            <Flex gap={8}>
              <Button size="small" onClick={() => setEscolhidos((catalogo.data ?? []).map((s) => s.id))}>Marcar todos</Button>
              <Button size="small" onClick={() => setEscolhidos([])}>Desmarcar todos</Button>
            </Flex>
          </Flex>
          {grupos.map(([grupo, nomeGrupo]) => {
            const lista = (catalogo.data ?? []).filter((s) => s.lavandaria_grupo === grupo);
            if (!lista.length) return null;
            return (
              <div key={grupo} style={{ marginTop: 8 }}>
                <Divider orientation="left" plain style={{ margin: '8px 0 6px', fontSize: 12 }}>{nomeGrupo}</Divider>
                <Flex vertical gap={4}>
                  {lista.map((s) => (
                    <Checkbox key={s.id} checked={escolhidos.includes(s.id)} onChange={(e) => alternar(s.id, e.target.checked)}>
                      {s.nome} {s.codigo && <Typography.Text type="secondary" style={{ fontSize: 12 }}>({s.codigo})</Typography.Text>}
                    </Checkbox>
                  ))}
                </Flex>
              </div>
            );
          })}
        </>
      )}
    </Modal>
  );
}
