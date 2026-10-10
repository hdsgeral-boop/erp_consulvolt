import { Button, Input, Modal, Popover, Radio, Select, Space, Typography } from 'antd';
import { useEffect, useState } from 'react';
import { IconeFa } from '../fluxos/IconeFa';
import {
  MAX_VALOR_FILTRO,
  OPERADORES_CURTOS,
  OPERADORES_FILTRO,
  type CondicaoFiltro,
  type CondicaoPersonalizada,
  type FiltroPersonalizado,
  type OperadorFiltro,
} from './filtrosLista';
import './padroesLegado.css';

const OPCOES_OPERADOR = (Object.keys(OPERADORES_FILTRO) as OperadorFiltro[]).map((o) => ({ value: o, label: OPERADORES_FILTRO[o] }));

/**
 * Caixa de filtro de uma coluna (linha por baixo dos cabeçalhos, como no antigo): escrever filtra por «contém»; o funil
 * abre o «Filtro personalizado» da coluna (condição + valor, Limpar/Aplicar). Com condição do funil activa, a caixa
 * mostra «Igual a: X» e o funil fica azul; escrever por cima volta ao «contém».
 */
export function FiltroColuna({ titulo, valor, aoMudar }: { titulo: string; valor?: CondicaoFiltro; aoMudar: (c: CondicaoFiltro | undefined) => void }) {
  const [aberto, setAberto] = useState(false);
  const comFunil = !!valor && valor.operador !== 'contains' && valor.valor !== '';
  const texto = !valor ? '' : comFunil ? `${OPERADORES_CURTOS[valor.operador]}: ${valor.valor}` : valor.valor;
  const botao = (
    <button type="button" className={`erp-filtro-funil${comFunil ? ' erp-activo' : ''}`} title="Filtro personalizado" aria-label={`Filtro personalizado de ${titulo}`} aria-expanded={aberto} onClick={() => setAberto(true)}>
      <IconeFa nome="filter" />
    </button>
  );
  return (
    <div className="erp-filtro-coluna" onClick={(e) => e.stopPropagation()}>
      {/* caixa nativa, como a .column-filter do antigo (e leve: uma por coluna) */}
      <input
        type="text"
        className="erp-input-filtro"
        // size 1: a caixa não impõe a sua largura natural (~20 caracteres) à coluna nas tabelas «max-content»; a coluna
        // fica com a largura do conteúdo, como no antigo, e a caixa ocupa-a toda (width: 100%)
        size={1}
        value={texto}
        maxLength={MAX_VALOR_FILTRO + 20}
        placeholder={`${titulo}...`}
        aria-label={`Filtrar ${titulo}`}
        onChange={(e) => {
          const t = e.target.value;
          aoMudar(t ? { operador: 'contains', valor: t.slice(0, MAX_VALOR_FILTRO) } : undefined);
        }}
        onKeyDown={(e) => e.stopPropagation()}
      />
      {/* o Popover só é montado quando aberto (uma lista tem dezenas de caixas: render leve) */}
      {aberto ? (
        <Popover
          open
          onOpenChange={(v) => !v && setAberto(false)}
          trigger="click"
          placement="bottomRight"
          destroyOnHidden
          content={<ConteudoFunil titulo={titulo} valor={valor} aoAplicar={(c) => { aoMudar(c); setAberto(false); }} aoFechar={() => setAberto(false)} />}
        >
          {botao}
        </Popover>
      ) : (
        botao
      )}
    </div>
  );
}

function ConteudoFunil({ titulo, valor, aoAplicar, aoFechar }: { titulo: string; valor?: CondicaoFiltro; aoAplicar: (c: CondicaoFiltro | undefined) => void; aoFechar: () => void }) {
  const [operador, setOperador] = useState<OperadorFiltro>(valor?.operador ?? 'contains');
  const [texto, setTexto] = useState(valor?.valor ?? '');
  const aplicar = () => aoAplicar(texto.trim() ? { operador, valor: texto.trim() } : undefined);
  return (
    <div className="erp-popover-filtro" role="dialog" aria-label={`Filtro: ${titulo}`}>
      <div className="erp-popover-filtro-titulo">
        <IconeFa nome="filter" />
        <span style={{ flex: 1 }}>Filtro: {titulo}</span>
        <Button type="text" size="small" aria-label="Fechar" icon={<IconeFa nome="times" />} onClick={aoFechar} />
      </div>
      <div style={{ marginBottom: 10 }}>
        <label>Condição:</label>
        <Select<OperadorFiltro> size="small" style={{ width: '100%' }} value={operador} onChange={setOperador} options={OPCOES_OPERADOR} aria-label="Condição" />
      </div>
      <div style={{ marginBottom: 12 }}>
        <label>Valor:</label>
        <Input size="small" value={texto} maxLength={MAX_VALOR_FILTRO} placeholder="Valor a filtrar..." aria-label="Valor" onChange={(e) => setTexto(e.target.value)} onPressEnter={aplicar} />
      </div>
      <Space style={{ width: '100%', justifyContent: 'flex-end' }}>
        <Button size="small" onClick={() => aoAplicar(undefined)}>
          Limpar
        </Button>
        <Button size="small" type="primary" onClick={aplicar}>
          Aplicar
        </Button>
      </Space>
    </div>
  );
}

export interface ColunaDisponivel {
  chave: string;
  titulo: string;
}

/**
 * Botão «Filtro personalizado» da barra da lista: construtor de condições (coluna, operador, valor) combinadas por
 * «todas» (E) ou «qualquer» (OU), aplicado no cliente. O número de condições activas aparece no botão.
 */
export function BotaoFiltroPersonalizado({ colunas, valor, aoMudar, tamanho = 'small' }: { colunas: ColunaDisponivel[]; valor: FiltroPersonalizado; aoMudar: (f: FiltroPersonalizado) => void; tamanho?: 'small' | 'middle' }) {
  const [aberto, setAberto] = useState(false);
  const activas = valor.condicoes.filter((c) => c.valor.trim() !== '').length;
  return (
    <>
      <Button size={tamanho} icon={<IconeFa nome="filter" />} onClick={() => setAberto(true)} disabled={!colunas.length} type={activas ? 'primary' : 'default'} aria-label={activas ? `Filtro personalizado (${activas} condições activas)` : 'Filtro personalizado'}>
        Filtro personalizado{activas ? ` (${activas})` : ''}
      </Button>
      <ModalFiltroPersonalizado aberto={aberto} colunas={colunas} valor={valor} aoFechar={() => setAberto(false)} aoAplicar={(f) => { aoMudar(f); setAberto(false); }} />
    </>
  );
}

export function ModalFiltroPersonalizado({ aberto, colunas, valor, aoAplicar, aoFechar }: { aberto: boolean; colunas: ColunaDisponivel[]; valor: FiltroPersonalizado; aoAplicar: (f: FiltroPersonalizado) => void; aoFechar: () => void }) {
  const nova = (): CondicaoPersonalizada => ({ coluna: colunas[0]?.chave ?? '', operador: 'contains', valor: '' });
  const [rascunho, setRascunho] = useState<FiltroPersonalizado>(valor);
  useEffect(() => {
    if (aberto) setRascunho(valor.condicoes.length ? valor : { ...valor, condicoes: [nova()] });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [aberto]);
  const mudar = (i: number, p: Partial<CondicaoPersonalizada>) => setRascunho((r) => ({ ...r, condicoes: r.condicoes.map((c, k) => (k === i ? { ...c, ...p } : c)) }));
  const remover = (i: number) => setRascunho((r) => ({ ...r, condicoes: r.condicoes.filter((_, k) => k !== i) }));
  const opcoesColunas = colunas.map((c) => ({ value: c.chave, label: c.titulo }));
  return (
    <Modal
      open={aberto}
      title={
        <Space>
          <IconeFa nome="filter" style={{ color: '#2563eb' }} /> Filtro personalizado
        </Space>
      }
      onCancel={aoFechar}
      width={640}
      destroyOnHidden
      footer={[
        <Button key="limpar" onClick={() => aoAplicar({ juncao: rascunho.juncao, condicoes: [] })}>
          Limpar
        </Button>,
        <Button key="cancelar" onClick={aoFechar}>
          Cancelar
        </Button>,
        <Button key="aplicar" type="primary" onClick={() => aoAplicar({ ...rascunho, condicoes: rascunho.condicoes.filter((c) => c.coluna && c.valor.trim() !== '').map((c) => ({ ...c, valor: c.valor.trim() })) })}>
          Aplicar
        </Button>,
      ]}
    >
      <Space direction="vertical" style={{ width: '100%' }} size={10}>
        <Space wrap>
          <Typography.Text>Mostrar os registos que cumprem</Typography.Text>
          <Radio.Group size="small" value={rascunho.juncao} onChange={(e) => setRascunho((r) => ({ ...r, juncao: e.target.value }))} optionType="button">
            <Radio.Button value="todas">todas as condições</Radio.Button>
            <Radio.Button value="qualquer">qualquer condição</Radio.Button>
          </Radio.Group>
        </Space>
        {rascunho.condicoes.map((c, i) => (
          <div key={i} style={{ display: 'flex', flexWrap: 'wrap', gap: 6, alignItems: 'center' }} role="group" aria-label={`Condição ${i + 1}`}>
            <Select size="small" style={{ flex: '1 1 160px', minWidth: 140 }} value={c.coluna || undefined} options={opcoesColunas} onChange={(v: string) => mudar(i, { coluna: v })} aria-label="Coluna" placeholder="Coluna" showSearch optionFilterProp="label" />
            <Select<OperadorFiltro> size="small" style={{ flex: '1 1 140px', minWidth: 130 }} value={c.operador} options={OPCOES_OPERADOR} onChange={(v) => mudar(i, { operador: v })} aria-label="Operador" />
            <Input size="small" style={{ flex: '2 1 160px', minWidth: 140 }} value={c.valor} maxLength={MAX_VALOR_FILTRO} placeholder="Valor a filtrar..." aria-label="Valor" onChange={(e) => mudar(i, { valor: e.target.value })} />
            <Button size="small" type="text" danger aria-label={`Remover condição ${i + 1}`} icon={<IconeFa nome="times" />} onClick={() => remover(i)} />
          </div>
        ))}
        <Button size="small" type="dashed" icon={<IconeFa nome="plus" />} onClick={() => setRascunho((r) => ({ ...r, condicoes: [...r.condicoes, nova()] }))} disabled={rascunho.condicoes.length >= 20}>
          Acrescentar condição
        </Button>
      </Space>
    </Modal>
  );
}
