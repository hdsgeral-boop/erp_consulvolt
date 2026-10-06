import { Alert, Button, Checkbox, List, Modal, Space, Typography, Upload } from 'antd';
import { DownloadOutlined, UploadOutlined } from '@ant-design/icons';
import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { descarregar } from '@/api/cliente';
import { larguraModal } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { enviarFicheiro } from '@/modulos/contab/comum/ficheiros';

export type EntidadeImportacao = 'produtos' | 'categorias';

interface Resultado { simulacao: boolean; linhas_lidas: number; criados: number; actualizados: number; erros: { linha: number; mensagem: string }[]; avisos: string[] }

/**
 * M-07: importação de produtos (12 colunas do legado) ou categorias por Excel (openImportModal do legado), com validação
 * prévia (simulação — nada é gravado) e erros por linha. O stock inicial não é importado (regista-se em Armazém › Ajustes).
 */
export function ModalImportarProdutos({ entidade, aoFechar }: { entidade: EntidadeImportacao | null; aoFechar: () => void }) {
  const cliente = useQueryClient();
  const [ficheiro, setFicheiro] = useState<File | null>(null);
  const [actualizar, setActualizar] = useState(false);
  const [aEnviar, setAEnviar] = useState(false);
  const [resultado, setResultado] = useState<Resultado | null>(null);
  const nome = entidade === 'categorias' ? 'categorias' : 'produtos';

  const fechar = () => { setFicheiro(null); setResultado(null); setActualizar(false); aoFechar(); };
  const executar = async (simular: boolean) => {
    if (!ficheiro || !entidade) return;
    setAEnviar(true);
    try {
      const r = await enviarFicheiro<Resultado>(`/logistica/importacao/${entidade}`, ficheiro, { simular, actualizar_existentes: actualizar });
      setResultado(r.dados);
      if (!simular) {
        void cliente.invalidateQueries({ queryKey: ['logistica'] });
        void cliente.invalidateQueries({ queryKey: ['vendas'] });
      }
    } catch (e) {
      notificarErro(e, simular ? 'O ficheiro não é válido' : 'Não foi possível importar');
    } finally {
      setAEnviar(false);
    }
  };

  return (
    <Modal
      open={!!entidade}
      title={`Importar ${nome} (Excel)`}
      width={larguraModal(680)}
      onCancel={fechar}
      destroyOnHidden
      footer={
        <Space wrap>
          <Button icon={<DownloadOutlined />} onClick={() => entidade && void descarregar(`/logistica/importacao/${entidade}/modelo`, undefined, entidade === 'categorias' ? 'Template_Categorias.xlsx' : 'Template_Produtos.xlsx').catch((e) => notificarErro(e))}>
            Baixar modelo
          </Button>
          <Button onClick={fechar}>{resultado && !resultado.simulacao ? 'Fechar' : 'Cancelar'}</Button>
          <Button disabled={!ficheiro} loading={aEnviar} onClick={() => void executar(true)}>Validar (simulação)</Button>
          <Button type="primary" disabled={!resultado?.simulacao || (resultado.criados + resultado.actualizados === 0)} loading={aEnviar} onClick={() => void executar(false)}>Importar</Button>
        </Space>
      }
    >
      <Upload
        accept=".xlsx,.xls,.csv"
        maxCount={1}
        beforeUpload={(f) => { setFicheiro(f); setResultado(null); return false; }}
        onRemove={() => { setFicheiro(null); setResultado(null); }}
        fileList={ficheiro ? [{ uid: '1', name: ficheiro.name, status: 'done' }] : []}
      >
        <Button icon={<UploadOutlined />}>Escolher ficheiro</Button>
      </Upload>
      {entidade === 'produtos' && (
        <Checkbox style={{ marginTop: 12 }} checked={actualizar} onChange={(e) => { setActualizar(e.target.checked); setResultado(null); }}>
          Actualizar os produtos que já existem (pelo código)
        </Checkbox>
      )}
      {resultado && (
        <>
          <Alert
            style={{ marginTop: 12 }}
            showIcon
            type={resultado.erros.length ? 'warning' : 'success'}
            message={`${resultado.simulacao ? 'Simulação: ' : ''}${resultado.linhas_lidas} linha(s) — ${resultado.criados} a criar/criado(s), ${resultado.actualizados} actualizado(s), ${resultado.erros.length} erro(s).${resultado.simulacao ? ' Nada foi gravado.' : ''}`}
          />
          {[...resultado.erros.map((e) => ({ t: 'danger' as const, m: e.mensagem })), ...resultado.avisos.map((a) => ({ t: 'warning' as const, m: a }))].length > 0 && (
            <List
              size="small"
              style={{ marginTop: 8, maxHeight: 240, overflow: 'auto' }}
              dataSource={[...resultado.erros.map((e) => ({ t: 'danger' as const, m: e.mensagem })), ...resultado.avisos.map((a) => ({ t: 'warning' as const, m: a }))]}
              renderItem={(x) => <List.Item><Typography.Text type={x.t}>{x.m}</Typography.Text></List.Item>}
            />
          )}
        </>
      )}
    </Modal>
  );
}
