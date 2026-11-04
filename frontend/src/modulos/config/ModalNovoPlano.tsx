import { Alert, Form, Input, InputNumber, Modal, Select, Switch, message } from 'antd';
import { useMutation } from '@tanstack/react-query';
import { enviar } from '@/api/cliente';
import { notificarErro } from '@/utilitarios/erros';

interface Props {
  aberto: boolean;
  modulos: Record<string, string>;
  onFechar: () => void;
  onCriado: () => void;
}

interface Valores {
  codigo: string; nome: string; descricao?: string; preco_mensal: number; moeda: string;
  empresas_sem_limite: boolean; max_empresas?: number | null;
  utilizadores_sem_limite: boolean; max_utilizadores?: number | null;
  dias_teste?: number | null;
  modulos?: string[]; ordem?: number;
}

/**
 * Plataforma SaaS — «Novo plano»: cria o plano e a sua versão 1 em rascunho (POST /plataforma/planos); publicar é um passo
 * à parte. Os módulos escolhidos passam a ser os únicos acessíveis às empresas do plano a partir da publicação.
 */
export function ModalNovoPlano({ aberto, modulos, onFechar, onCriado }: Props) {
  const [form] = Form.useForm<Valores>();
  const empresasSemLimite = Form.useWatch('empresas_sem_limite', form);
  const utilizadoresSemLimite = Form.useWatch('utilizadores_sem_limite', form);
  const criar = useMutation({
    mutationFn: (v: Valores) => enviar('post', '/plataforma/planos', {
      codigo: v.codigo, nome: v.nome, descricao: v.descricao || null, preco_mensal: v.preco_mensal, moeda: v.moeda.toUpperCase(),
      max_empresas: v.empresas_sem_limite ? null : v.max_empresas ?? null,
      max_utilizadores: v.utilizadores_sem_limite ? null : v.max_utilizadores ?? null,
      ...(v.dias_teste ? { dias_teste: v.dias_teste } : {}),
      modulos: v.modulos ?? [], ordem: v.ordem ?? 0,
    }),
    onSuccess: (r) => { message.success(r.mensagem || 'Plano criado.'); form.resetFields(); onCriado(); },
    onError: (e) => notificarErro(e),
  });
  return (
    <Modal open={aberto} title="Novo plano" okText="Criar plano" cancelText="Cancelar" confirmLoading={criar.isPending} onCancel={onFechar} destroyOnHidden
      onOk={() => form.validateFields().then((v) => criar.mutate(v)).catch(() => undefined)}>
      <Alert type="info" showIcon style={{ marginBottom: 16 }} title="O plano nasce inactivo, com a versão 1 em rascunho. Publique a versão para o activar." />
      <Form form={form} layout="vertical" initialValues={{ moeda: 'AOA', empresas_sem_limite: false, utilizadores_sem_limite: false, ordem: 0 }}>
        <Form.Item name="codigo" label="Código (não muda depois)" rules={[{ required: true, message: 'Indique o código.' }, { pattern: /^[a-z0-9_-]+$/, message: 'Só minúsculas, números, - e _.' }]}>
          <Input maxLength={30} placeholder="ex.: profissional" />
        </Form.Item>
        <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}><Input maxLength={100} /></Form.Item>
        <Form.Item name="descricao" label="Descrição"><Input.TextArea rows={2} maxLength={2000} /></Form.Item>
        <Form.Item name="preco_mensal" label="Preço mensal" rules={[{ required: true, message: 'Indique o preço.' }]}><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item>
        <Form.Item name="moeda" label="Moeda" rules={[{ required: true }, { pattern: /^[A-Za-z]{3}$/, message: 'Código de 3 letras (ex.: AOA).' }]}><Input maxLength={3} style={{ width: 100 }} /></Form.Item>
        <Form.Item label="Empresas">
          <Form.Item name="empresas_sem_limite" valuePropName="checked" noStyle><Switch checkedChildren="Sem limite" unCheckedChildren="Sem limite" /></Form.Item>
          {!empresasSemLimite && (
            <Form.Item name="max_empresas" noStyle rules={[{ required: true, message: 'Indique o limite.' }]}><InputNumber min={1} placeholder="Máx. empresas" aria-label="Máx. empresas" style={{ marginLeft: 12 }} /></Form.Item>
          )}
        </Form.Item>
        <Form.Item label="Utilizadores">
          <Form.Item name="utilizadores_sem_limite" valuePropName="checked" noStyle><Switch checkedChildren="Sem limite" unCheckedChildren="Sem limite" /></Form.Item>
          {!utilizadoresSemLimite && (
            <Form.Item name="max_utilizadores" noStyle rules={[{ required: true, message: 'Indique o limite.' }]}><InputNumber min={1} placeholder="Máx. utilizadores" aria-label="Máx. utilizadores" style={{ marginLeft: 12 }} /></Form.Item>
          )}
        </Form.Item>
        <Form.Item name="dias_teste" label="Dias de período experimental" extra="Vazio = usa a definição global."><InputNumber min={1} max={90} style={{ width: 120 }} /></Form.Item>
        <Form.Item name="modulos" label="Módulos incluídos" extra="Depois de publicado, o plano só dá acesso a estes módulos (menu e API), mesmo a perfis de acesso total." rules={[{ required: true, type: 'array', min: 1, message: 'Escolha pelo menos um módulo.' }]}>
          <Select mode="multiple" allowClear placeholder="Seleccione os módulos" options={Object.entries(modulos).map(([value, label]) => ({ value, label }))} />
        </Form.Item>
        <Form.Item name="ordem" label="Ordem de apresentação"><InputNumber min={0} style={{ width: 100 }} /></Form.Item>
      </Form>
    </Modal>
  );
}
