import { Button, Flex, Form, Input, Modal, Select, Space, Typography, message } from 'antd';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { enviar } from '@/api/cliente';
import { IconeFa } from '@/componentes/fluxos';
import { notificarErro } from '@/utilitarios/erros';
import { CHAVE_USO } from './PlanoContasParidade';

interface ContaRetida {
  codigo: string;
  descricao: string | null;
  movimentos: number;
}

/** «N conta(s) seleccionada(s)» como o legado (_accUpdateBulkBar, js/app_v2.js:8902-8911). */
export function rotuloSeleccao(n: number): string {
  return `${n} conta${n > 1 ? 's' : ''} seleccionada${n > 1 ? 's' : ''}`;
}

/** Lista «código – descrição (N mov.)» das contas que o servidor não alterou/eliminou. */
export function listaRetidas(contas: ContaRetida[], sufixo: string): string[] {
  return contas.map((c) => `${c.codigo} – ${c.descricao ?? ''} (${c.movimentos} ${sufixo})`);
}

/**
 * Barra de acções em massa do Plano de Contas (legado: caixas na 1.ª coluna e barra «acc-bulk-bar», js/app_v2.js:8828-9037):
 * «Modificar em Massa» (contab_plano_gerir) e «Eliminar Seleccionadas» (contab_tabelas_del). O servidor volta a verificar
 * as permissões, limita-se às contas da empresa activa e recusa converter/eliminar contas com lançamentos.
 */
export function BarraAccoesMassa({ ids, podeModificar, podeEliminar, aoLimpar }: { ids: number[]; podeModificar: boolean; podeEliminar: boolean; aoLimpar: () => void }) {
  const cliente = useQueryClient();
  const [modificar, setModificar] = useState(false);
  const [form] = Form.useForm<{ tipo?: 'M' | 'T'; descricao?: string; sufixo?: string }>();
  const invalidar = () => {
    void cliente.invalidateQueries({ queryKey: ['contab', 'plano-contas'] });
    void cliente.invalidateQueries({ queryKey: CHAVE_USO });
  };

  const gravar = useMutation({
    mutationFn: (v: { tipo?: string; descricao?: string; sufixo?: string }) =>
      enviar<{ actualizadas: number; sem_tipo: ContaRetida[] }>('post', '/contabilidade/plano-contas/massa/modificar', { ids, ...v }),
    onSuccess: ({ dados, mensagem }) => {
      invalidar();
      setModificar(false);
      aoLimpar();
      if (dados.sem_tipo.length)
        Modal.warning({
          title: 'Modificação em Massa',
          content: (
            <>
              <p>{mensagem}</p>
              <p>NÃO convertidas para Totalizadora (têm movimentos):</p>
              <ul>{listaRetidas(dados.sem_tipo, 'mov.').map((t) => <li key={t}>{t}</li>)}</ul>
            </>
          ),
        });
      else message.success(mensagem);
    },
    onError: (e) => notificarErro(e, 'Erro ao modificar contas'),
  });

  const eliminar = useMutation({
    mutationFn: (simular: boolean) =>
      enviar<{ eliminadas: string[]; mantidas: ContaRetida[]; simulacao: boolean }>('post', '/contabilidade/plano-contas/massa/eliminar', { ids, simular }),
    onError: (e) => notificarErro(e, 'Erro ao eliminar contas'),
  });

  const aplicarModificacao = (v: { tipo?: 'M' | 'T'; descricao?: string; sufixo?: string }) => {
    const descricao = v.descricao?.trim();
    const sufixo = v.sufixo?.trim();
    if (!v.tipo && !descricao && !sufixo) {
      message.warning('Nenhuma alteração definida. Preencha pelo menos um campo.');
      return;
    }
    const dados = { tipo: v.tipo, descricao: descricao || undefined, sufixo: sufixo || undefined };
    if (descricao)
      Modal.confirm({
        title: `Vai substituir a Descrição de ${ids.length} conta(s) por "${descricao}". Confirma?`,
        okText: 'Aplicar',
        cancelText: 'Cancelar',
        onOk: () => gravar.mutateAsync(dados),
      });
    else gravar.mutate(dados);
  };

  const pedirEliminacao = () =>
    Modal.confirm({
      title: `Deseja ELIMINAR ${ids.length} conta(s) seleccionada(s)?`,
      content: 'Esta acção é irreversível. Contas com lançamentos não serão eliminadas.',
      okText: 'Continuar',
      okButtonProps: { danger: true },
      cancelText: 'Cancelar',
      onOk: async () => {
        const { dados: sim } = await eliminar.mutateAsync(true);
        const mantidas = listaRetidas(sim.mantidas, 'lançamento(s)');
        if (!sim.eliminadas.length) {
          Modal.info({ title: 'Nenhuma conta foi eliminada', content: <><p>Contas com lançamentos:</p><ul>{mantidas.map((t) => <li key={t}>{t}</li>)}</ul></> });
          return;
        }
        Modal.confirm({
          title: `Serão eliminadas ${sim.eliminadas.length} conta(s). Confirma?`,
          content: mantidas.length ? <><p>NÃO eliminadas (têm lançamentos):</p><ul>{mantidas.map((t) => <li key={t}>{t}</li>)}</ul></> : null,
          okText: 'Eliminar',
          okButtonProps: { danger: true },
          cancelText: 'Cancelar',
          onOk: async () => {
            const { dados, mensagem } = await eliminar.mutateAsync(false);
            invalidar();
            aoLimpar();
            message.success(`${mensagem}${dados.mantidas.length ? ` ${dados.mantidas.length} conta(s) mantida(s) por terem lançamentos.` : ''}`);
          },
        });
      },
    });

  if (!ids.length) return null;
  return (
    <>
      {/* legado: barra fixa azul com «N contas seleccionadas», «Modificar em Massa», «Eliminar Seleccionadas» e × */}
      <Flex
        role="toolbar"
        aria-label="Acções em massa"
        align="center"
        gap={12}
        wrap
        style={{ position: 'sticky', top: 60, zIndex: 100, background: 'linear-gradient(135deg,#1e40af,#4f46e5)', color: '#fff', padding: '10px 16px', borderRadius: 12, marginBottom: 12, boxShadow: '0 4px 20px rgba(79,70,229,0.4)' }}
      >
        <IconeFa nome="check-square" style={{ fontSize: 18 }} />
        <strong style={{ fontSize: 15 }}>{rotuloSeleccao(ids.length)}</strong>
        <Space wrap style={{ marginLeft: 'auto' }}>
          {podeModificar && (
            <Button ghost icon={<IconeFa nome="edit" />} onClick={() => { form.resetFields(); setModificar(true); }}>
              Modificar em Massa
            </Button>
          )}
          {podeEliminar && (
            <Button type="primary" danger icon={<IconeFa nome="trash" />} loading={eliminar.isPending} onClick={pedirEliminacao}>
              Eliminar Seleccionadas
            </Button>
          )}
          <Button ghost icon={<IconeFa nome="times" />} aria-label="Limpar a selecção" title="Limpar a selecção" onClick={aoLimpar} />
        </Space>
      </Flex>
      <Modal
        open={modificar}
        title={<><Typography.Text type="secondary" style={{ fontSize: 11, textTransform: 'uppercase', display: 'block' }}>Operação em Massa</Typography.Text>Modificar {ids.length} Conta{ids.length > 1 ? 's' : ''}</>}
        onCancel={() => setModificar(false)}
        okText="Aplicar"
        cancelText="Cancelar"
        confirmLoading={gravar.isPending}
        onOk={() => form.submit()}
        destroyOnHidden
      >
        <Typography.Paragraph type="secondary">Os campos preenchidos serão aplicados a todas as contas seleccionadas. Deixe em branco para não alterar.</Typography.Paragraph>
        <Form form={form} layout="vertical" onFinish={aplicarModificacao}>
          <Form.Item name="tipo" label="Alterar Tipo para">
            <Select allowClear placeholder="- Não alterar -" options={[{ value: 'M', label: 'Movimento (M)' }, { value: 'T', label: 'Totalizadora (T)' }]} />
          </Form.Item>
          <Form.Item name="descricao" label="Substituir Descrição por" extra="Substituirá a descrição de TODAS as contas seleccionadas">
            <Input maxLength={255} placeholder="Deixe vazio para não alterar" />
          </Form.Item>
          <Form.Item name="sufixo" label="Adicionar Sufixo à Descrição">
            <Input maxLength={100} placeholder="Ex: (Inactiva)" />
          </Form.Item>
        </Form>
      </Modal>
    </>
  );
}
