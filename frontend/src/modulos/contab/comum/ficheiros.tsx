import { Alert, Button, Modal, Space, Upload, message } from 'antd';
import { UploadOutlined } from '@ant-design/icons';
import { useState, type ReactNode } from 'react';
import { enviar } from '@/api/cliente';
import { notificarErro } from '@/utilitarios/erros';

/** POST multipart (ficheiro + campos). Booleanos seguem como 1/0, que o Laravel aceita na regra «boolean». */
export function enviarFicheiro<T>(url: string, ficheiro: File, campos: Record<string, string | number | boolean | undefined> = {}) {
  const dados = new FormData();
  dados.append('ficheiro', ficheiro);
  for (const [k, v] of Object.entries(campos)) {
    if (v === undefined) continue;
    dados.append(k, typeof v === 'boolean' ? (v ? '1' : '0') : String(v));
  }
  return enviar<T>('post', url, dados);
}

interface PropsModal<T> {
  aberto: boolean;
  titulo: string;
  url: string;
  ajuda?: ReactNode;
  /** Campos adicionais do pedido (ex.: codigo_conta). */
  campos?: Record<string, string | number | boolean | undefined>;
  /** Conteúdo extra do formulário (ex.: caixas de opção controladas pelo pai). */
  extra?: ReactNode;
  /** Botão de simulação: envia `simular=1` e mostra o resumo sem gravar. */
  comSimulacao?: boolean;
  aceitar?: string;
  aoFechar: () => void;
  aoConcluir?: (dados: T, mensagem: string) => void;
}

/** Janela de importação de um ficheiro (XLSX/XLS/CSV) para um endpoint multipart da API. */
export function ModalImportar<T>({ aberto, titulo, url, ajuda, campos, extra, comSimulacao, aceitar = '.xlsx,.xls,.csv,.txt', aoFechar, aoConcluir }: PropsModal<T>) {
  const [ficheiro, setFicheiro] = useState<File | null>(null);
  const [aEnviar, setAEnviar] = useState(false);
  const [resumo, setResumo] = useState<string | null>(null);

  const executar = async (simular: boolean) => {
    if (!ficheiro) return;
    setAEnviar(true);
    try {
      const r = await enviarFicheiro<T>(url, ficheiro, { ...campos, ...(comSimulacao ? { simular } : {}) });
      if (simular) setResumo(r.mensagem);
      else {
        message.success(r.mensagem);
        aoConcluir?.(r.dados, r.mensagem);
        fechar();
      }
    } catch (e) {
      notificarErro(e, simular ? 'O ficheiro não é válido' : 'Não foi possível importar');
    } finally {
      setAEnviar(false);
    }
  };

  const fechar = () => {
    setFicheiro(null);
    setResumo(null);
    aoFechar();
  };

  return (
    <Modal
      title={titulo}
      open={aberto}
      onCancel={fechar}
      destroyOnClose
      footer={
        <Space>
          <Button onClick={fechar}>Cancelar</Button>
          {comSimulacao && (
            <Button disabled={!ficheiro} loading={aEnviar} onClick={() => void executar(true)}>
              Validar (simulação)
            </Button>
          )}
          <Button type="primary" disabled={!ficheiro} loading={aEnviar} onClick={() => void executar(false)}>
            Importar
          </Button>
        </Space>
      }
    >
      {ajuda && <div style={{ marginBottom: 12 }}>{ajuda}</div>}
      <Upload
        accept={aceitar}
        maxCount={1}
        beforeUpload={(f) => {
          setFicheiro(f);
          setResumo(null);
          return false;
        }}
        onRemove={() => setFicheiro(null)}
        fileList={ficheiro ? [{ uid: '1', name: ficheiro.name, status: 'done' }] : []}
      >
        <Button icon={<UploadOutlined />}>Escolher ficheiro</Button>
      </Upload>
      {extra && <div style={{ marginTop: 12 }}>{extra}</div>}
      {resumo && <Alert style={{ marginTop: 12 }} type="success" showIcon message={resumo} />}
    </Modal>
  );
}
