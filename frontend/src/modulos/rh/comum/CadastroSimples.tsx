import { Button, Card, Form, Modal, Popconfirm, Space, Table } from 'antd';
import { DeleteOutlined, EditOutlined, PlusOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState, type ReactNode } from 'react';
import { obter } from '@/api/cliente';
import { BotoesExportar } from '@/componentes/impressao';
import { BarraFiltros, larguraModal as limitarLargura, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { useAccaoRh } from './consultas';
import { contem, PesquisaLocal } from './componentes';
import { pedidoTabela } from './impressao';

interface Props<T extends { id: number }> {
  /** Caminho da API (GET lista; POST cria; PUT/DELETE {url}/{id}). */
  url: string;
  chave: unknown[];
  colunas: ColumnsType<T>;
  /** Campos do formulário (Form.Item). */
  campos: ReactNode;
  podeGerir: boolean;
  podeEliminar: boolean;
  nomeItem: string;
  /** Valores do formulário a partir do registo (edição) e por omissão (novo). */
  paraFormulario?: (r: T) => Record<string, unknown>;
  valoresNovos?: Record<string, unknown>;
  /** Transformação dos valores do formulário antes do envio. */
  paraEnvio?: (v: Record<string, unknown>) => Record<string, unknown>;
  pesquisa?: (r: T) => string;
  accoesExtra?: ReactNode;
  larguraModal?: number;
  /** Título do documento impresso (mostra «Imprimir» e «PDF» com as linhas filtradas). */
  tituloImpressao?: string;
}

/** Tabela de suporte com criar/editar num modal e eliminar (cargos, tipos de organização, bancos, rubricas, itens). */
export function CadastroSimples<T extends { id: number }>({
  url, chave, colunas, campos, podeGerir, podeEliminar, nomeItem, paraFormulario, valoresNovos, paraEnvio, pesquisa, accoesExtra, larguraModal = 560, tituloImpressao,
}: Props<T>) {
  const pequeno = useEcraPequeno();
  const [form] = Form.useForm();
  const [aberto, setAberto] = useState<T | 'novo' | null>(null);
  const [termo, setTermo] = useState('');
  const consulta = useQuery({ queryKey: chave, queryFn: () => obter<T[]>(url) });
  useEffect(() => {
    if (consulta.error) notificarErro(consulta.error, 'Erro ao carregar a listagem');
  }, [consulta.error]);
  const accao = useAccaoRh(() => setAberto(null));

  const abrir = (r: T | 'novo') => {
    form.resetFields();
    form.setFieldsValue(r === 'novo' ? valoresNovos ?? {} : paraFormulario ? paraFormulario(r) : r);
    setAberto(r);
  };

  const todas: ColumnsType<T> = [
    ...colunas,
    ...(podeGerir || podeEliminar
      ? [{
          title: '',
          key: 'accoes',
          width: 96,
          align: 'right' as const,
          render: (_: unknown, r: T) => (
            <Space size={4}>
              {podeGerir && <Button size="small" type="text" icon={<EditOutlined />} aria-label="Editar" onClick={() => abrir(r)} />}
              {podeEliminar && (
                <Popconfirm title={`Eliminar ${nomeItem}?`} okText="Eliminar" okButtonProps={{ danger: true }} cancelText="Cancelar"
                  onConfirm={() => accao.mutateAsync({ metodo: 'delete', url: `${url}/${r.id}` })}>
                  <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Eliminar" />
                </Popconfirm>
              )}
            </Space>
          ),
        }]
      : []),
  ];

  const dados = (consulta.data ?? []).filter((r) => !pesquisa || contem(pesquisa(r), termo));

  return (
    <Card>
      <BarraFiltros
        accoes={
          <>
            {accoesExtra}
            {tituloImpressao && (
              <BotoesExportar
                desactivado={!dados.length}
                obterPedido={() => pedidoTabela({ titulo: tituloImpressao, filtros: termo ? [`Pesquisa: ${termo}`] : undefined, colunas, linhas: dados })}
              />
            )}
            {podeGerir && <Button type="primary" icon={<PlusOutlined />} onClick={() => abrir('novo')}>Novo</Button>}
          </>
        }
      >
        {pesquisa ? <PesquisaLocal aoMudar={setTermo} /> : null}
      </BarraFiltros>
      <Table<T> rowKey="id" size={pequeno ? 'small' : 'middle'} loading={consulta.isFetching} columns={todas} dataSource={dados} scroll={scrollTabela()}
        pagination={{ pageSize: 25, showSizeChanger: true, showTotal: (t) => `${t} registo(s)` }} />
      <Modal
        title={aberto === 'novo' ? `Novo — ${nomeItem}` : `Editar — ${nomeItem}`}
        open={aberto !== null}
        width={limitarLargura(larguraModal)}
        onCancel={() => setAberto(null)}
        okText="Gravar"
        cancelText="Cancelar"
        confirmLoading={accao.isPending}
        onOk={() => form.submit()}
        destroyOnHidden
      >
        <Form form={form} layout="vertical" onFinish={(v: Record<string, unknown>) => {
          const dadosEnvio = paraEnvio ? paraEnvio(v) : v;
          if (aberto === 'novo') accao.mutate({ metodo: 'post', url, dados: dadosEnvio });
          else if (aberto) accao.mutate({ metodo: 'put', url: `${url}/${aberto.id}`, dados: dadosEnvio });
        }}>
          {campos}
        </Form>
      </Modal>
    </Card>
  );
}
