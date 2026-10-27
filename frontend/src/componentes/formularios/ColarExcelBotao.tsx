import { SnippetsOutlined } from '@ant-design/icons';
import { Button, Input, Modal, Typography } from 'antd';
import { useState } from 'react';

/**
 * Botão + modal «Colar do Excel» para grades fora do `PainelLinhas` (mesma aparência e texto de ajuda). O ecrã dá a
 * lista de rótulos das colunas pasteáveis (pela ordem em que as lê sem cabeçalho) e trata o texto colado como quiser
 * (normalmente com `colagemParaLinhas`).
 */
export function ColarExcelBotao({ colunas, aoColar, disabled, tamanho = 'middle' }: { colunas: string[]; aoColar: (texto: string) => void; disabled?: boolean; tamanho?: 'small' | 'middle' }) {
  const [aberto, setAberto] = useState(false);
  const [texto, setTexto] = useState('');
  const confirmar = () => {
    setAberto(false);
    const t = texto;
    setTexto('');
    if (t.trim()) aoColar(t);
  };
  return (
    <>
      <Button size={tamanho} icon={<SnippetsOutlined />} disabled={disabled} title="Colar linhas copiadas do Excel (colunas pela ordem da tabela)" onClick={() => setAberto(true)}>
        Colar do Excel
      </Button>
      <Modal title="Colar linhas do Excel" open={aberto} onCancel={() => setAberto(false)} onOk={confirmar} okText="Acrescentar linhas" cancelText="Cancelar" width="min(720px, calc(100vw - 32px))" destroyOnHidden>
        <Typography.Paragraph style={{ fontSize: 13 }}>
          Copie as linhas no Excel (colunas separadas por tabulação, «|» ou «;»). Sem cabeçalho, as colunas são lidas por esta ordem:
          <br />
          <strong>{colunas.join(' | ')}</strong>
          <br />
          Com a linha de cabeçalho incluída, as colunas são reconhecidas pelo nome e a ordem não importa. Datas DD/MM/AAAA e valores como 70 004,54 são aceites; nas listas pode usar o código ou o nome.
        </Typography.Paragraph>
        <Input.TextArea aria-label="Linhas a colar" autoFocus rows={8} style={{ fontFamily: 'monospace', fontSize: 12 }} placeholder="Cole aqui (Ctrl+V)" value={texto} onChange={(e) => setTexto(e.target.value)} />
      </Modal>
    </>
  );
}
