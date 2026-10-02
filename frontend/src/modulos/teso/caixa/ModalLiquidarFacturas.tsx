import { Alert, Col, DatePicker, Form, Input, InputNumber, Modal, Row, Space, Table, Tooltip, Typography } from 'antd';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { BotoesExportar, useMensagem } from '@/componentes/impressao';
import { larguraModal, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { ValorKz } from '../../contab/comum/Componentes';
import { SeletorAux, SeletorTerceiro, SeletorUnidade } from '../../contab/comum/Seletores';
import type { Pendente, SessaoCaixa } from '../api';
import { pedidoPendentes, ROTULO_NATUREZA } from '../impressao';
import { CONFIG_LIQUIDACAO, chavePendente, motivoNaoLiquidavel, movimentoDeLiquidacao, totalLiquidacao, validarValor, type ModoLiquidacao } from '../caixaLiquidacao';

interface Props {
  sessao: SessaoCaixa;
  modo: ModoLiquidacao;
  aoFechar: () => void;
}

interface Notas {
  nota_demonstracao_id?: number;
  nota_fluxo_caixa_id?: number;
  unidade_negocio_id?: number;
  centro_custo_id?: number;
}

/**
 * Folha de caixa › Pagar / Receber facturas (A-11): escolhe documentos em aberto (GET /tesouraria/pendentes), o valor de
 * cada um (por omissão o saldo) e as notas, e regista um movimento por documento, ligado à factura.
 */
export function ModalLiquidarFacturas({ sessao, modo, aoFechar }: Props) {
  const cfg = CONFIG_LIQUIDACAO[modo];
  const cliente = useQueryClient();
  const message = useMensagem();
  const pequeno = useEcraPequeno();
  const [terceiro, setTerceiro] = useState<number>();
  const [pesquisa, setPesquisa] = useState('');
  const [seleccao, setSeleccao] = useState<string[]>([]);
  const [valores, setValores] = useState<Record<string, number | null>>({});
  const abertura = dayjs(sessao.data_abertura);
  const [data, setData] = useState<Dayjs>(dayjs().isBefore(abertura, 'day') ? abertura : dayjs());
  const [notas, setNotas] = useState<Notas>({});
  const [aGravar, setAGravar] = useState(false);
  const consulta = useQuery({
    queryKey: ['teso', 'pendentes', 'caixa', cfg.natureza, terceiro, pesquisa],
    queryFn: () => obter<Pendente[]>('/tesouraria/pendentes', { natureza: cfg.natureza, terceiro_id: terceiro, pesquisa }),
  });
  const pendentes = consulta.data ?? [];
  const escolhidos = pendentes.filter((p) => seleccao.includes(chavePendente(p)));
  const valorDe = (p: Pendente) => (chavePendente(p) in valores ? valores[chavePendente(p)] : Number(p.saldo));
  const erros = escolhidos.map((p) => validarValor(valorDe(p), p.saldo)).filter(Boolean);
  const total = totalLiquidacao(escolhidos.map(valorDe));

  const confirmar = async () => {
    if (!escolhidos.length || erros.length) return;
    setAGravar(true);
    const falhas: string[] = [];
    const chavesFalhadas: string[] = [];
    let feitos = 0;
    for (const p of escolhidos) {
      try {
        await enviar('post', `/tesouraria/caixa/sessoes/${sessao.id}/movimentos`, movimentoDeLiquidacao(p, valorDe(p) as number, modo, { data: dataApi(data) ?? '', ...notas }));
        feitos++;
      } catch (e) {
        falhas.push(`${p.numero_documento}: ${e instanceof Error ? e.message : String(e)}`);
        chavesFalhadas.push(chavePendente(p));
      }
    }
    setAGravar(false);
    void cliente.invalidateQueries({ queryKey: ['teso'] });
    if (feitos) message.success(`${feitos} ${modo === 'PAGAR' ? 'pagamento(s)' : 'recebimento(s)'} registado(s) na folha de caixa.`);
    if (falhas.length) {
      Modal.error({ title: `${falhas.length} documento(s) não foram registados`, width: larguraModal(640), content: <ul style={{ paddingLeft: 18 }}>{falhas.map((f) => <li key={f}>{f}</li>)}</ul> });
      setSeleccao(chavesFalhadas); // ficam escolhidos só os que falharam, para corrigir e repetir
    } else {
      aoFechar();
    }
  };

  return (
    <Modal
      open
      title={cfg.titulo}
      width={larguraModal(1100)}
      onCancel={aoFechar}
      okText={escolhidos.length ? `Registar ${escolhidos.length} movimento(s) — ${formatarKz(total)} Kz` : 'Registar'}
      cancelText="Cancelar"
      okButtonProps={{ disabled: !escolhidos.length || erros.length > 0 }}
      confirmLoading={aGravar}
      onOk={() => void confirmar()}
      destroyOnHidden
    >
      <Space wrap style={{ marginBottom: 12 }}>
        <SeletorTerceiro value={terceiro} onChange={setTerceiro} />
        <Input.Search placeholder="N.º do documento" allowClear onSearch={setPesquisa} style={{ width: 200, maxWidth: '100%' }} />
        <BotoesExportar tamanho="small" desactivado={!pendentes.length} obterPedido={() => pedidoPendentes(pendentes, [`Natureza: ${ROTULO_NATUREZA[cfg.natureza]}`, pesquisa && `Pesquisa: ${pesquisa}`])} />
      </Space>
      <Table<Pendente>
        rowKey={chavePendente}
        size="small"
        loading={consulta.isFetching}
        dataSource={pendentes}
        pagination={{ pageSize: 10, showSizeChanger: false }}
        scroll={scrollTabela()}
        rowSelection={{
          selectedRowKeys: seleccao,
          onChange: (k) => setSeleccao(k as string[]),
          getCheckboxProps: (p) => ({ disabled: motivoNaoLiquidavel(p, modo) !== null }),
          renderCell: (_, p, __, no) => {
            const motivo = motivoNaoLiquidavel(p, modo);
            return motivo ? <Tooltip title={motivo}>{no}</Tooltip> : no;
          },
        }}
        locale={{ emptyText: `Sem documentos ${modo === 'PAGAR' ? 'a pagar' : 'a receber'} em aberto.` }}
        columns={[
          { title: 'Terceiro', dataIndex: 'terceiro', ellipsis: true, render: (v: string | null) => v?.trim() ?? '—' },
          { title: 'Conta', dataIndex: 'codigo_conta', responsive: ['lg'] },
          { title: 'Documento', dataIndex: 'numero_documento' },
          { title: 'Data', dataIndex: 'data_documento', responsive: ['md'], render: formatarData },
          { title: 'Saldo', dataIndex: 'saldo', align: 'right', render: (v: string, p) => <><ValorKz valor={v} forte />{p.codigo_moeda && p.codigo_moeda !== 'AOA' ? <div style={{ fontSize: 12 }}>{p.saldo_moeda} {p.codigo_moeda}</div> : null}</> },
          {
            title: 'A liquidar (Kz)',
            key: 'valor',
            width: 170,
            render: (_, p) => {
              const k = chavePendente(p);
              if (!seleccao.includes(k)) return null;
              const erro = validarValor(valorDe(p), p.saldo);
              return (
                <Tooltip title={erro} open={erro ? undefined : false}>
                  <InputNumber
                    aria-label={`Valor a liquidar ${p.numero_documento}`}
                    size="small"
                    min={0.01}
                    precision={2}
                    status={erro ? 'error' : undefined}
                    value={valorDe(p)}
                    onChange={(v) => setValores((s) => ({ ...s, [k]: v === null ? null : Number(v) }))}
                    style={{ width: '100%' }}
                  />
                </Tooltip>
              );
            },
          },
        ]}
      />
      <Form layout="vertical" style={{ marginTop: 12 }}>
        <Row gutter={[12, 0]}>
          <Col xs={24} sm={12} md={6}>
            <Form.Item label="Data dos movimentos" required>
              <DatePicker format="DD/MM/YYYY" value={data} allowClear={false} disabledDate={(d) => d.isBefore(abertura, 'day')} onChange={(d) => d && setData(d)} style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col xs={24} sm={12} md={6}>
            <Form.Item label="Nota às demonstrações">
              <SeletorAux tabela="notas-demonstracao" value={notas.nota_demonstracao_id} onChange={(v) => setNotas((n) => ({ ...n, nota_demonstracao_id: v }))} placeholder="Sem nota" style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col xs={24} sm={12} md={6}>
            <Form.Item label="Nota de fluxo de caixa">
              <SeletorAux tabela="notas-fluxo-caixa" value={notas.nota_fluxo_caixa_id} onChange={(v) => setNotas((n) => ({ ...n, nota_fluxo_caixa_id: v }))} placeholder="Sem nota" style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col xs={24} sm={12} md={6}>
            <Form.Item label="Unidade de negócio">
              <SeletorUnidade value={notas.unidade_negocio_id} onChange={(v) => setNotas((n) => ({ ...n, unidade_negocio_id: v }))} style={{ width: '100%' }} />
            </Form.Item>
          </Col>
        </Row>
      </Form>
      {erros.length > 0 && <Alert type="warning" showIcon message="Corrija os valores assinalados (positivos e até ao saldo em aberto)." />}
      <Typography.Paragraph type="secondary" style={{ marginTop: 8, marginBottom: 0, fontSize: pequeno ? 12 : undefined }}>
        Cada documento dá um movimento {modo === 'PAGAR' ? 'de saída' : 'de entrada'} na caixa {sessao.codigo_conta}, ligado à factura. A factura só fica liquidada quando a sessão for
        contabilizada; até lá, o valor conta como «em liquidação».
      </Typography.Paragraph>
    </Modal>
  );
}
