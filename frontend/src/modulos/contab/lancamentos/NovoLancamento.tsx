import { Alert, Button, Card, Col, DatePicker, Form, Input, Row, Space, message } from 'antd';
import { ArrowLeftOutlined } from '@ant-design/icons';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useLocation, useNavigate } from 'react-router-dom';
import { enviar } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi } from '@/utilitarios/formatacao';
import type { DocumentoLancamento } from '../api';
import { IndicadorEquilibrio } from '../comum/Componentes';
import { equilibrio } from '../comum/decimal';
import { EditorLinhasDC, linhasParaApi, type LinhaEditor } from '../comum/EditorLinhasDC';
import { SeletorDiario } from '../comum/Seletores';

interface ValoresForm {
  diario_id?: number;
  data_documento: Dayjs;
  numero_documento?: string;
  referencia?: string;
  descricao?: string;
  linhas: LinhaEditor[];
}

/** Estado de navegação para «copiar» um lançamento existente. */
export interface EstadoCopia {
  copia?: DocumentoLancamento;
}

/**
 * Lançamento manual (POST /contabilidade/lancamentos). Só se grava equilibrado (Σ D = Σ C); o servidor numera,
 * valida o exercício aberto, as contas de movimento e o controlo orçamental.
 */
export function NovoLancamento() {
  const navegar = useNavigate();
  const local = useLocation();
  const cliente = useQueryClient();
  const [form] = Form.useForm<ValoresForm>();
  const linhas = Form.useWatch('linhas', form) ?? [];
  const e = equilibrio(linhas);
  const copia = (local.state as EstadoCopia | null)?.copia;

  const valoresIniciais: Partial<ValoresForm> = copia
    ? {
        diario_id: copia.diario_id,
        data_documento: dayjs(),
        numero_documento: copia.numero_documento ?? undefined,
        descricao: copia.linhas[0]?.descricao ?? undefined,
        linhas: copia.linhas.map((l) => ({
          codigo_conta: l.codigo_conta,
          tipo_dc: l.tipo_dc,
          valor: Number(l.valor),
          descricao: l.descricao ?? undefined,
          terceiro_id: l.terceiro_id ?? undefined,
          centro_custo_id: l.centro_custo_id ?? undefined,
          unidade_negocio_id: l.unidade_negocio_id ?? undefined,
          nota_demonstracao_id: l.nota_demonstracao_id ?? undefined,
          nota_fluxo_caixa_id: l.nota_fluxo_caixa_id ?? undefined,
          _terceiro: l.terceiro_id ? `Terceiro #${l.terceiro_id}` : undefined,
        })),
      }
    : { data_documento: dayjs(), linhas: [{ tipo_dc: 'D' }, { tipo_dc: 'C' }] };

  const gravar = useMutation({
    mutationFn: (v: ValoresForm) =>
      enviar<DocumentoLancamento>('post', '/contabilidade/lancamentos', {
        diario_id: v.diario_id,
        data_documento: dataApi(v.data_documento),
        numero_documento: v.numero_documento || undefined,
        referencia: v.referencia || undefined,
        descricao: v.descricao || undefined,
        linhas: linhasParaApi(v.linhas.map((l) => ({ ...l, descricao: l.descricao || v.descricao }))),
      }),
    onSuccess: ({ dados, mensagem }) => {
      message.success(mensagem);
      void cliente.invalidateQueries({ queryKey: ['contab'] });
      const primeira = dados.linhas[0];
      navegar(primeira ? `../${primeira.id}` : '..');
    },
    onError: (erro) => notificarErro(erro, 'Não foi possível gravar o lançamento'),
  });

  return (
    <>
      <CabecalhoPagina
        titulo={copia ? `Novo lançamento (cópia de ${copia.numero_lan})` : 'Novo lançamento'}
        accoes={<Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>}
      />
      <Form<ValoresForm> form={form} layout="vertical" initialValues={valoresIniciais} onFinish={(v) => gravar.mutate(v)}>
        <Card title="Cabeçalho" style={{ marginBottom: 16 }}>
          <Row gutter={16}>
            <Col xs={24} md={6}>
              <Form.Item name="diario_id" label="Diário" rules={[{ required: true, message: 'Escolha o diário.' }]}>
                <SeletorDiario style={{ width: '100%' }} />
              </Form.Item>
            </Col>
            <Col xs={24} md={4}>
              <Form.Item name="data_documento" label="Data do documento" rules={[{ required: true }]}>
                <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
              </Form.Item>
            </Col>
            <Col xs={24} md={5}>
              <Form.Item name="numero_documento" label="N.º do documento">
                <Input maxLength={100} />
              </Form.Item>
            </Col>
            <Col xs={24} md={9}>
              <Form.Item name="referencia" label="Referência">
                <Input maxLength={100} />
              </Form.Item>
            </Col>
          </Row>
          <Form.Item name="descricao" label="Descrição (aplica-se às linhas sem descrição)">
            <Input maxLength={1000} />
          </Form.Item>
        </Card>
        <Card title="Linhas" style={{ marginBottom: 16 }}>
          <EditorLinhasDC minimo={2} campos={{ terceiro: true, centroCusto: true, unidade: true, notas: true }} />
          <div style={{ marginTop: 16 }}>
            <IndicadorEquilibrio linhas={linhas} />
          </div>
          {!e.equilibrado && (
            <Alert style={{ marginTop: 12 }} type="warning" showIcon message="O lançamento só pode ser gravado com o total a débito igual ao total a crédito." />
          )}
        </Card>
        <Space>
          <Button type="primary" htmlType="submit" loading={gravar.isPending} disabled={!e.valido}>
            Gravar lançamento
          </Button>
          <Button onClick={() => navegar('..')}>Cancelar</Button>
        </Space>
      </Form>
    </>
  );
}
