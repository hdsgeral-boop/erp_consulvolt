import { Alert, Button, Checkbox, Form, List, Modal, Select, Space, Typography, Upload } from 'antd';
import { DownloadOutlined, UploadOutlined } from '@ant-design/icons';
import { useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { descarregar } from '@/api/cliente';
import { useAccao } from '@/componentes/Accoes';
import { larguraModal } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { enviarFicheiro } from '@/modulos/contab/comum/ficheiros';
import type { FichaTerceiro } from './referencias';
import { SeletorConta } from './Seletores';

type Papel = 'CLIENTE' | 'FORNECEDOR';

/** Moedas por omissão oferecidas no «Editar em massa» do legado (js/mapeamento_massa.js:13). */
export const MOEDAS_MASSA = ['AOA', 'USD', 'EUR', 'ZAR', 'GBP', 'CNY', 'BRL', 'CHF'];

interface ValoresMassa {
  codigo_moeda?: string;
  codigo_conta?: string;
  conta_compra_transitoria?: string;
  retirar_conta_transitoria?: boolean;
}

/** Corpo de POST /terceiros/edicao-massa: só os campos escolhidos («Manter como está» = ausente). */
export function corpoEdicaoMassa(papel: Papel, ids: number[], v: ValoresMassa): Record<string, unknown> {
  return {
    papel,
    ids,
    codigo_moeda: v.codigo_moeda || undefined,
    codigo_conta: v.codigo_conta || undefined,
    conta_compra_transitoria: papel === 'FORNECEDOR' && !v.retirar_conta_transitoria ? v.conta_compra_transitoria || undefined : undefined,
    retirar_conta_transitoria: papel === 'FORNECEDOR' && v.retirar_conta_transitoria ? true : undefined,
  };
}

/** «Editar em massa (N)» de clientes/fornecedores (mapeamento_massa.js): moeda e contas das fichas seleccionadas. */
export function ModalEdicaoMassaTerceiros({ papel, fichas, aoFechar, aoConcluir }: { papel: Papel; fichas: FichaTerceiro[] | null; aoFechar: () => void; aoConcluir?: () => void }) {
  const [form] = Form.useForm<ValoresMassa>();
  const retirar = Form.useWatch('retirar_conta_transitoria', form);
  const gravar = useAccao({ invalidar: [['terceiros']], aoSucesso: () => { aoConcluir?.(); aoFechar(); }, tituloErro: 'Não foi possível alterar as fichas' });
  useEffect(() => {
    if (fichas) form.resetFields();
  }, [fichas, form]);
  const n = fichas?.length ?? 0;
  const comConta = (campo: 'codigo_conta' | 'conta_compra_transitoria') => (fichas ?? []).filter((f) => (f[campo] ?? '').trim()).length;
  const resumo = (campo: 'codigo_conta' | 'conta_compra_transitoria') => (comConta(campo) ? `${comConta(campo)} de ${n} já com conta` : 'Nenhum com conta');
  const nomes = (fichas ?? []).slice(0, 6).map((f) => f.nome.trim()).join(', ') + (n > 6 ? '…' : '');

  return (
    <Modal
      open={!!fichas}
      title="Editar em massa"
      width={larguraModal(760)}
      okText="Aplicar"
      cancelText="Cancelar"
      confirmLoading={gravar.isPending}
      onCancel={aoFechar}
      onOk={() => form.submit()}
      destroyOnHidden
    >
      <Typography.Paragraph type="secondary">
        {n} {papel === 'CLIENTE' ? 'cliente(s)' : 'fornecedor(es)'}: {nomes}. Os campos deixados em «Manter como está» não mudam.
      </Typography.Paragraph>
      <Form<ValoresMassa> form={form} layout="vertical" onFinish={(v) => fichas && gravar.mutate({ url: '/terceiros/edicao-massa', dados: corpoEdicaoMassa(papel, fichas.map((f) => f.id), v) })}>
        <Form.Item name="codigo_moeda" label="Moeda por omissão">
          <Select allowClear placeholder="— Manter como está —" options={MOEDAS_MASSA.map((m) => ({ value: m, label: m }))} />
        </Form.Item>
        <Form.Item name="codigo_conta" label={papel === 'CLIENTE' ? 'Conta contabilística do cliente' : 'Conta de terceiro'} extra={resumo('codigo_conta')}>
          <SeletorConta prefixo={papel === 'CLIENTE' ? '31' : '32'} placeholder="— Manter como está —" />
        </Form.Item>
        {papel === 'FORNECEDOR' && (
          <>
            <Form.Item name="conta_compra_transitoria" label="Conta de compras / trânsito" extra={resumo('conta_compra_transitoria')}>
              <SeletorConta prefixo="328" placeholder="— Manter como está —" disabled={!!retirar} />
            </Form.Item>
            <Form.Item name="retirar_conta_transitoria" valuePropName="checked">
              <Checkbox>— Retirar a conta de compras / trânsito —</Checkbox>
            </Form.Item>
          </>
        )}
      </Form>
    </Modal>
  );
}

interface ResultadoImportacaoClientes {
  simulacao: boolean;
  linhas_lidas: number;
  criados: number;
  actualizados: number;
  ignorados: number;
  repetidos: number;
  erros: { linha: number; mensagem: string }[];
}

/** «Importar» clientes (openImportModal('clientes')): modelo, validação (simulação) e importação. */
export function ModalImportarClientes({ aberto, aoFechar }: { aberto: boolean; aoFechar: () => void }) {
  const cliente = useQueryClient();
  const [ficheiro, setFicheiro] = useState<File | null>(null);
  const [actualizar, setActualizar] = useState(false);
  const [aEnviar, setAEnviar] = useState(false);
  const [resultado, setResultado] = useState<ResultadoImportacaoClientes | null>(null);
  const fechar = () => { setFicheiro(null); setResultado(null); setActualizar(false); aoFechar(); };
  const executar = async (simular: boolean) => {
    if (!ficheiro) return;
    setAEnviar(true);
    try {
      const r = await enviarFicheiro<ResultadoImportacaoClientes>('/terceiros/importacao', ficheiro, { simular, actualizar_existentes: actualizar });
      setResultado(r.dados);
      if (!simular) void cliente.invalidateQueries({ queryKey: ['terceiros'] });
    } catch (e) {
      notificarErro(e, simular ? 'O ficheiro não é válido' : 'Não foi possível importar');
    } finally {
      setAEnviar(false);
    }
  };
  const modelo = () => void descarregar('/terceiros/importacao/modelo', undefined, 'Template_Clientes_Vendas.xlsx').catch((e) => notificarErro(e, 'Não foi possível obter o modelo'));

  return (
    <Modal
      title="Importar clientes (Excel)"
      open={aberto}
      onCancel={fechar}
      width={larguraModal(720)}
      destroyOnHidden
      footer={
        <Space wrap>
          <Button icon={<DownloadOutlined />} onClick={modelo}>Baixar modelo</Button>
          <Button onClick={fechar}>{resultado && !resultado.simulacao ? 'Fechar' : 'Cancelar'}</Button>
          <Button disabled={!ficheiro} loading={aEnviar} onClick={() => void executar(true)}>Validar (simulação)</Button>
          <Button type="primary" disabled={!resultado?.simulacao} loading={aEnviar} onClick={() => void executar(false)}>Importar</Button>
        </Space>
      }
    >
      <Typography.Paragraph type="secondary">
        Colunas do modelo: NIF, Nome, Endereco, Email, Conta_Contábil. As linhas sem conta contabilística não são importadas; um cliente já
        existente (mesmo NIF ou, sem NIF, o mesmo nome) só é actualizado se o pedir.
      </Typography.Paragraph>
      <Space direction="vertical">
        <Upload accept=".xlsx,.xls,.csv" maxCount={1} beforeUpload={(f) => { setFicheiro(f); setResultado(null); return false; }}
          onRemove={() => { setFicheiro(null); setResultado(null); }} fileList={ficheiro ? [{ uid: '1', name: ficheiro.name, status: 'done' }] : []}>
          <Button icon={<UploadOutlined />}>Escolher ficheiro</Button>
        </Upload>
        <Checkbox checked={actualizar} onChange={(e) => { setActualizar(e.target.checked); setResultado(null); }}>Actualizar os clientes já existentes</Checkbox>
      </Space>
      {resultado && (
        <>
          <Alert
            style={{ marginTop: 12 }}
            type={resultado.erros.length ? 'warning' : 'success'}
            showIcon
            message={`${resultado.simulacao ? 'Simulação: ' : ''}${resultado.linhas_lidas} linha(s): ${resultado.criados} novo(s), ${resultado.actualizados} actualizado(s), ${resultado.ignorados} já existente(s), ${resultado.repetidos} repetido(s), ${resultado.erros.length} erro(s).${resultado.simulacao ? ' Nada foi gravado.' : ''}`}
          />
          {resultado.erros.length > 0 && (
            <List size="small" header={<strong>Erros</strong>} dataSource={resultado.erros} style={{ marginTop: 8, maxHeight: 220, overflow: 'auto' }}
              renderItem={(e) => <List.Item><Typography.Text type="danger">{e.mensagem}</Typography.Text></List.Item>} />
          )}
        </>
      )}
    </Modal>
  );
}
