import { Button, DatePicker, Flex, Form, Input, InputNumber, Modal, Radio, Select, Space, Tooltip } from 'antd';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { colunasParaImpressao, type ColunaApi } from '@/componentes/TabelaApi';
import { BotoesExportar, prepararTexto, tabelaHtml } from '@/componentes/impressao';
import { BarraFiltros, larguraModal, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import { SeletorConta } from '@/modulos/compras/comum/Seletores';
import { EstadoPOS, opcoesEstadoPOS, rotuloEstadoPOS } from '../comum/estados';
import { accoesReclamacao } from '../comum/regras';
import type { Reclamacao } from './tipos';

import { TabelaComModos } from '@/componentes/vistas';
/**
 * Danos e reclamações: AGUARDA_COMPROVATIVO → COMPROVADO → APROVADA ou RECUSADA → PAGA. A indemnização exige comprovativo
 * e valor; quem decide não paga (segregação por reclamação); o pagamento é um PAGAMENTO de tesouraria.
 */
export function Reclamacoes() {
  const { pode, utilizador } = useSessao();
  const [estado, setEstado] = useState<string>();
  const [decidir, setDecidir] = useState<{ r: Reclamacao; modo: 'COMPROVAR' | 'DECIDIR' } | null>(null);
  const [pagar, setPagar] = useState<Reclamacao | null>(null);
  const consulta = useQuery({ queryKey: ['pos', 'lavandaria', 'reclamacoes', estado], queryFn: () => obter<Reclamacao[]>('/pos/lavandaria/reclamacoes', { estado }) });
  useEffect(() => {
    if (consulta.error) notificarErro(consulta.error, 'Erro ao carregar as reclamações');
  }, [consulta.error]);
  const accao = useAccao({
    invalidar: [['pos'], ['tesouraria']],
    aoSucesso: () => {
      setDecidir(null);
      setPagar(null);
    },
  });

  const pequeno = useEcraPequeno();
  const colunas: ColunaApi<Reclamacao>[] = [
          { title: 'Data', dataIndex: 'criado_em', render: (v) => formatarDataHora(v), responsive: ['md'] },
          { title: 'Ordem', dataIndex: 'numero_encomenda' },
          { title: 'Peça', render: (_, r) => r.nome_item ?? r.descricao_peca ?? '—' },
          { title: 'Descrição', dataIndex: 'descricao', ellipsis: true },
          { title: 'Declarado', dataIndex: 'valor_declarado', align: 'right', render: (v) => formatarKz(v), responsive: ['md'] },
          { title: 'Comprovado', dataIndex: 'valor_comprovativo', align: 'right', render: (v) => formatarKz(v) },
          { title: 'Estado', dataIndex: 'estado', render: (v) => <EstadoPOS estado={v} /> },
          { title: 'Decisão', responsive: ['lg'], render: (_, r) => (r.decidido_por ? `${r.decidido_por}${r.nota_decisao ? ` · ${r.nota_decisao}` : ''}` : '—') },
          {
            title: '',
            key: 'a',
            fixed: 'right',
            exportar: false,
            render: (_, r) => {
              const a = accoesReclamacao(pode, r, utilizador?.nome_utilizador);
              return (
                <Space size={4} wrap>
                  {a.comprovar && (
                    <Button size="small" onClick={() => setDecidir({ r, modo: 'COMPROVAR' })}>
                      Comprovativo
                    </Button>
                  )}
                  {a.decidir && (
                    <Button size="small" type="primary" onClick={() => setDecidir({ r, modo: 'DECIDIR' })}>
                      Decidir
                    </Button>
                  )}
                  {a.pagar.visivel && (
                    <Tooltip title={a.pagar.bloqueio}>
                      <Button size="small" disabled={!!a.pagar.bloqueio} onClick={() => setPagar(r)}>
                        Pagar
                      </Button>
                    </Tooltip>
                  )}
                </Space>
              );
            },
          },
        ];

  return (
    <>
      <BarraFiltros
        accoes={
          <BotoesExportar
            tamanho="small"
            desactivado={!consulta.data?.length}
            obterPedido={async () => {
              await prepararTexto();
              const linhas = consulta.data ?? [];
              return { titulo: 'Danos e reclamações da lavandaria', filtros: [`Estado: ${estado ? rotuloEstadoPOS(estado) : 'Todos'}`], conteudo: tabelaHtml({ colunas: colunasParaImpressao(colunas, linhas), linhas }) };
            }}
          />
        }
      >
        <Select allowClear placeholder="Estado" style={{ width: 220 }} value={estado} onChange={setEstado} options={opcoesEstadoPOS(['AGUARDA_COMPROVATIVO', 'COMPROVADO', 'APROVADA', 'RECUSADA', 'PAGA'])} />
      </BarraFiltros>
      <TabelaComModos<Reclamacao>
        rowKey="id"
        size={pequeno ? 'small' : 'middle'}
        loading={consulta.isFetching}
        dataSource={consulta.data}
        scroll={scrollTabela()}
        columns={colunas}
      />
      <ModalDecidir alvo={decidir} carregando={accao.isPending} aoFechar={() => setDecidir(null)} aoConfirmar={(dados) => decidir && accao.mutate({ url: `/pos/lavandaria/reclamacoes/${decidir.r.id}/decidir`, dados })} />
      <ModalPagar alvo={pagar} carregando={accao.isPending} aoFechar={() => setPagar(null)} aoConfirmar={(dados) => pagar && accao.mutate({ url: `/pos/lavandaria/reclamacoes/${pagar.id}/pagar`, dados })} />
    </>
  );
}

interface FormDecisao {
  decisao: 'COMPROVADO' | 'APROVADA' | 'RECUSADA';
  referencia_comprovativo?: string;
  data_comprovativo?: Dayjs | null;
  valor_comprovativo?: number | null;
  nota_decisao?: string;
}

function ModalDecidir({ alvo, carregando, aoFechar, aoConfirmar }: { alvo: { r: Reclamacao; modo: 'COMPROVAR' | 'DECIDIR' } | null; carregando: boolean; aoFechar: () => void; aoConfirmar: (d: Record<string, unknown>) => void }) {
  const [form] = Form.useForm<FormDecisao>();
  const decisao = Form.useWatch('decisao', form);
  useEffect(() => {
    if (alvo)
      form.setFieldsValue({
        decisao: alvo.modo === 'COMPROVAR' ? 'COMPROVADO' : 'APROVADA',
        referencia_comprovativo: alvo.r.referencia_comprovativo ?? undefined,
        valor_comprovativo: alvo.r.valor_comprovativo ? Number(alvo.r.valor_comprovativo) : null,
        data_comprovativo: null,
        nota_decisao: undefined,
      });
  }, [alvo, form]);
  const recusa = decisao === 'RECUSADA';
  return (
    <Modal open={!!alvo} title="Decisão da reclamação" okText="Registar" cancelText="Cancelar" confirmLoading={carregando} onCancel={aoFechar} onOk={() => form.submit()} width={larguraModal(560)} destroyOnHidden>
      <Form
        form={form}
        layout="vertical"
        onFinish={(v) =>
          aoConfirmar({
            decisao: v.decisao,
            referencia_comprovativo: v.referencia_comprovativo?.trim() || undefined,
            data_comprovativo: dataApi(v.data_comprovativo),
            valor_comprovativo: v.valor_comprovativo ?? undefined,
            nota_decisao: v.nota_decisao?.trim() || undefined,
          })
        }
      >
        <Form.Item name="decisao" label="Decisão">
          <Radio.Group>
            <Radio value="COMPROVADO">Comprovado (aguarda decisão)</Radio>
            {alvo?.modo === 'DECIDIR' && <Radio value="APROVADA">Aprovar indemnização</Radio>}
            {alvo?.modo === 'DECIDIR' && <Radio value="RECUSADA">Recusar</Radio>}
          </Radio.Group>
        </Form.Item>
        {!recusa && (
          <Flex gap={12} wrap>
            <Form.Item name="referencia_comprovativo" label="Comprovativo de custo" rules={[{ required: true, message: 'Indique o comprovativo.' }]}>
              <Input maxLength={255} style={{ width: 220 }} />
            </Form.Item>
            <Form.Item name="data_comprovativo" label="Data">
              <DatePicker format="DD/MM/YYYY" />
            </Form.Item>
            <Form.Item name="valor_comprovativo" label="Valor comprovado" rules={[{ required: true, message: 'Indique o valor.' }]}>
              <InputNumber<number> min={0.01} precision={2} decimalSeparator="," suffix="Kz" style={{ width: 180 }} />
            </Form.Item>
          </Flex>
        )}
        <Form.Item name="nota_decisao" label={recusa ? 'Fundamentação (obrigatória)' : 'Nota'} rules={recusa ? [{ required: true, min: 5, message: 'Fundamente a recusa (mínimo 5 caracteres).' }] : []}>
          <Input.TextArea rows={2} maxLength={255} showCount />
        </Form.Item>
      </Form>
    </Modal>
  );
}

function ModalPagar({ alvo, carregando, aoFechar, aoConfirmar }: { alvo: Reclamacao | null; carregando: boolean; aoFechar: () => void; aoConfirmar: (d: { data: string; conta_financeira: string }) => void }) {
  const [form] = Form.useForm<{ data: Dayjs; conta_financeira: string }>();
  useEffect(() => {
    if (alvo) form.setFieldsValue({ data: dayjs(), conta_financeira: undefined });
  }, [alvo, form]);
  return (
    <Modal open={!!alvo} title={`Pagar indemnização · ${formatarKz(alvo?.valor_compensacao ?? alvo?.valor_comprovativo)} Kz`} okText="Criar pagamento" cancelText="Cancelar" confirmLoading={carregando} onCancel={aoFechar} onOk={() => form.submit()} width={larguraModal(560)} destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={(v) => aoConfirmar({ data: dataApi(v.data)!, conta_financeira: v.conta_financeira })}>
        <Form.Item name="data" label="Data" rules={[{ required: true }]}>
          <DatePicker format="DD/MM/YYYY" />
        </Form.Item>
        <Form.Item name="conta_financeira" label="Conta financeira (caixa ou banco)" rules={[{ required: true, message: 'Indique a conta.' }]}>
          <SeletorConta prefixo="4" />
        </Form.Item>
      </Form>
    </Modal>
  );
}
