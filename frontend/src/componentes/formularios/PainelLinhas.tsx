import { CheckOutlined, CloseOutlined, CopyOutlined, PlusOutlined, SettingOutlined, SnippetsOutlined } from '@ant-design/icons';
import { Alert, App, Button, Checkbox, DatePicker, Dropdown, Input, InputNumber, message, Modal, Progress, Select, Space, Typography, theme } from 'antd';
import dayjs from 'dayjs';
import { memo, useEffect, useId, useMemo, useRef, useState, type ClipboardEvent, type KeyboardEvent, type MutableRefObject, type ReactNode } from 'react';
import { estaVazio } from './conversoes';
import type { CampoFormulario, LinhaFormulario, ResumoLinhas } from './tipos';
import { useFormularioLinhas, type EstadoFormularioLinhas, type ResultadoColagem } from './useFormularioLinhas';

/** Texto do legado (formulario_linhas.js, painel da vista em linhas). */
export const NOTA_LINHAS = 'Uma linha por registo. Linhas deixadas em branco são ignoradas. Cada linha é gravada com as mesmas regras do formulário.';

export interface PropsPainelLinhas {
  /** Chave do formulário (preferência das colunas opcionais). */
  chave: string;
  campos: CampoFormulario[];
  gravar: (registo: Record<string, unknown>, contexto: { numero: number }) => Promise<unknown>;
  /** Valores por omissão vindos da grade. */
  omissoes?: Record<string, unknown>;
  linhasIniciais?: number;
  /** Nota própria (legado: `nota`); a frase dos obrigatórios é acrescentada sempre que há campos obrigatórios. */
  nota?: ReactNode;
  /** Nome dos registos na confirmação («Registar tarefas»). */
  nomeRegistos?: string;
  /** Pedir confirmação antes de gravar (legado: sim). */
  confirmar?: boolean;
  /** No fim da gravação (com ou sem falhas). */
  aoConcluir?: (r: ResumoLinhas) => void;
  /** Acesso ao estado (o modal lê a 1.ª linha ao voltar à grade). */
  refEstado?: MutableRefObject<EstadoFormularioLinhas | null>;
}

const CHAVE_COLUNAS = 'erp.formLinhas.colunas.';

function lerColunas(chave: string): string[] | null {
  try {
    const t = window.localStorage.getItem(CHAVE_COLUNAS + chave);
    const v = t ? (JSON.parse(t) as unknown) : null;
    return Array.isArray(v) ? v.filter((x): x is string => typeof x === 'string') : null;
  } catch {
    return null;
  }
}

function guardarColunas(chave: string, nomes: string[]): void {
  try {
    window.localStorage.setItem(CHAVE_COLUNAS + chave, JSON.stringify(nomes));
  } catch {
    /* sem armazenamento: só nesta sessão */
  }
}

/** Uma célula editável (memorizada: escrever numa linha não redesenha as outras). */
const Celula = memo(function Celula({ campo, linha, numero, definir, erroObrigatorio }: { campo: CampoFormulario; linha: LinhaFormulario; numero: number; definir: EstadoFormularioLinhas['definir']; erroObrigatorio: boolean }) {
  const valor = linha.valores[campo.nome];
  const original = linha.invalidos?.[campo.nome];
  const erro = erroObrigatorio || original !== undefined;
  const rotulo = `${campo.rotulo}, linha ${numero}`;
  const mudar = (v: unknown) => definir(linha.id, campo.nome, v);
  const status = erro ? 'error' : undefined;
  const titulo = original !== undefined ? `Valor não reconhecido: "${original}"` : erroObrigatorio ? `${campo.rotulo}: obrigatório` : undefined;
  const comum = { 'aria-label': rotulo, 'aria-invalid': erro || undefined, status, title: titulo, style: { width: '100%' } } as const;
  switch (campo.tipo ?? 'texto') {
    case 'numero':
      return <InputNumber {...comum} size="small" value={valor as number | null} min={campo.min} max={campo.max} precision={campo.precisao} decimalSeparator="," onChange={(v) => mudar(v ?? null)} />;
    case 'data':
      return <DatePicker {...comum} size="small" format="DD/MM/YYYY" value={typeof valor === 'string' && valor ? dayjs(valor) : null} onChange={(d) => mudar(d ? d.format('YYYY-MM-DD') : null)} placeholder="DD/MM/AAAA" />;
    case 'select':
      return (
        <Select {...comum} size="small" allowClear showSearch optionFilterProp="label" popupMatchSelectWidth={false} value={estaVazio(valor) ? undefined : (valor as string | number)}
          options={campo.opcoes} onChange={(v) => mudar(v ?? null)} placeholder={campo.placeholder ?? '--'} />
      );
    case 'personalizado':
      return <div title={titulo}>{campo.celula?.({ valor, aoMudar: mudar, rotulo, erro })}</div>;
    case 'textoLongo':
      return <Input.TextArea {...comum} size="small" autoSize={{ minRows: 1, maxRows: 4 }} maxLength={campo.maxLength} value={(valor as string) ?? ''} placeholder={campo.placeholder} onChange={(e) => mudar(e.target.value)} />;
    default:
      return <Input {...comum} size="small" maxLength={campo.maxLength} value={(valor as string) ?? ''} placeholder={campo.placeholder} onChange={(e) => mudar(e.target.value)} />;
  }
});

/** Foca o primeiro controlo de uma célula (`linha:coluna`). */
function focar(raiz: HTMLElement | null, linha: number, coluna: number): boolean {
  const el = raiz?.querySelector<HTMLElement>(`[data-fl-celula="${linha}:${coluna}"]`);
  const ctrl = el?.querySelector<HTMLElement>('input:not([type=hidden]), textarea, [tabindex]:not([tabindex="-1"])');
  if (!ctrl) return false;
  ctrl.focus();
  return true;
}

/**
 * Vista «Linhas»: tabela de entrada com uma linha por registo (legado: `.fl-painel`), com «+ Adicionar linha»,
 * «Duplicar última», «Colar do Excel», contador, colunas opcionais, atalhos de teclado e gravação linha a linha.
 */
export function PainelLinhas({ chave, campos, gravar, omissoes, linhasIniciais, nota, nomeRegistos, confirmar = true, aoConcluir, refEstado }: PropsPainelLinhas) {
  const { token } = theme.useToken();
  const app = App.useApp();
  // fora de <App> o contexto vem vazio: métodos estáticos
  const msg = typeof app.message?.success === 'function' ? app.message : message;
  const modal = app.modal;
  const estado = useFormularioLinhas({ campos, gravar, omissoes, linhasIniciais });
  if (refEstado) refEstado.current = estado;
  const idNota = useId();
  const raiz = useRef<HTMLDivElement>(null);
  const focoPendente = useRef<{ id: number; coluna: number } | null>(null);
  const opcionais = useMemo(() => estado.campos.filter((c) => c.emLinhas === 'opcional'), [estado.campos]);
  const [mostradas, setMostradas] = useState<string[]>(() => lerColunas(chave) ?? []);
  const visiveis = useMemo(() => estado.campos.filter((c) => c.emLinhas !== 'opcional' || mostradas.includes(c.nome)), [estado.campos, mostradas]);
  const [aviso, setAviso] = useState<{ tipo: 'success' | 'warning' | 'error' | 'info'; texto: ReactNode } | null>(null);
  const [colarAberto, setColarAberto] = useState(false);
  const [textoColar, setTextoColar] = useState('');
  const temObrigatorios = estado.campos.some((c) => c.obrigatorio);
  const temErros = estado.linhas.some((l) => l.erro);

  // foco depois de criar uma linha (Enter na última linha, «Adicionar linha», Ctrl+D)
  useEffect(() => {
    const f = focoPendente.current;
    if (!f) return;
    const i = estado.linhas.findIndex((l) => l.id === f.id);
    if (i >= 0 && focar(raiz.current, i, f.coluna)) focoPendente.current = null;
  }, [estado.linhas]);

  const alternarColuna = (nome: string, ver: boolean) => {
    const novo = ver ? [...mostradas, nome] : mostradas.filter((n) => n !== nome);
    setMostradas(novo);
    guardarColunas(chave, novo);
  };

  const posicao = (alvo: EventTarget): { linha: number; coluna: number } | null => {
    const td = (alvo as HTMLElement).closest?.('[data-fl-celula]') as HTMLElement | null;
    if (!td) return null;
    const [l, c] = (td.dataset.flCelula ?? '').split(':').map(Number);
    return { linha: l, coluna: c };
  };

  const aoTeclar = (e: KeyboardEvent<HTMLTableSectionElement>) => {
    const alvo = e.target as HTMLElement;
    const tr = alvo.closest('tr[data-fl-linha]') as HTMLElement | null;
    if (!tr) return;
    const id = Number(tr.dataset.flLinha);
    const pos = posicao(alvo);
    const ctrl = e.ctrlKey || e.metaKey;
    if (ctrl && (e.key === 'd' || e.key === 'D')) {
      e.preventDefault();
      focoPendente.current = { id: estado.duplicar(id), coluna: pos?.coluna ?? 0 };
      return;
    }
    if ((ctrl && e.key === 'Delete') || (e.key === 'Delete' && alvo.dataset.flNumero !== undefined)) {
      e.preventDefault();
      estado.limpar(id);
      return;
    }
    if (e.key === 'Enter' && !ctrl && !e.shiftKey && !e.altKey && pos && alvo.tagName === 'INPUT' && !alvo.closest('.ant-select, .ant-picker')) {
      e.preventDefault();
      const prox = pos.linha + 1;
      if (prox < estado.linhas.length) focar(raiz.current, prox, pos.coluna);
      else focoPendente.current = { id: estado.adicionar(), coluna: pos.coluna };
    }
  };

  const resumoColagem = (r: ResultadoColagem, linhas: number) => {
    if (r.naoReconhecidos) {
      const det = Object.entries(r.exemplos).map(([k, s]) => `${k}: ${s.join(', ')}`).join('; ');
      setAviso({ tipo: 'warning', texto: `${linhas} linha(s) acrescentada(s)${r.comCabecalho ? ' (colunas pelo cabeçalho)' : ''}. ${r.naoReconhecidos} valor(es) não reconhecido(s), assinalados a vermelho — corrija-os antes de registar: ${det}` });
    } else {
      setAviso(null);
      void msg.success(`${linhas} linha(s) acrescentada(s)${r.comCabecalho ? ' (colunas reconhecidas pelo cabeçalho)' : ''}.`);
    }
  };

  // Colagem directa numa célula: texto com tabulações ou várias linhas preenche a partir dessa célula
  const aoColarCelula = (e: ClipboardEvent<HTMLTableSectionElement>) => {
    const texto = e.clipboardData?.getData('text') ?? '';
    if (!/[\t\n]/.test(texto.replace(/\r?\n$/, ''))) return;
    const tr = (e.target as HTMLElement).closest('tr[data-fl-linha]') as HTMLElement | null;
    const pos = posicao(e.target);
    if (!tr || !pos) return;
    e.preventDefault();
    const r = estado.colar(texto, { colunas: visiveis, inicio: { id: Number(tr.dataset.flLinha), nome: visiveis[pos.coluna].nome } });
    resumoColagem(r, texto.replace(/\r/g, '').split('\n').filter((l) => l.trim()).length);
  };

  const confirmarColagem = () => {
    const linhas = textoColar.replace(/\r/g, '').split('\n').filter((l) => l.trim()).length;
    setColarAberto(false);
    if (!linhas) return;
    const r = estado.colar(textoColar, { colunas: visiveis });
    setTextoColar('');
    resumoColagem(r, r.acrescentadas);
  };

  const executar = async () => {
    const r = await estado.gravarTodas();
    const resumo = `${r.gravadas} de ${r.total} linha(s) gravada(s)${r.falhadas ? `; ${r.falhadas} linha(s) ficaram na tabela para corrigir` : ''}.`;
    const extra = r.avisos.length ? <ul style={{ margin: '4px 0 0', paddingLeft: 18 }}>{r.avisos.slice(0, 10).map((a) => <li key={a}>{a}</li>)}</ul> : null;
    setAviso({ tipo: r.falhadas ? (r.gravadas ? 'warning' : 'error') : r.avisos.length ? 'info' : 'success', texto: <>{resumo}{extra}</> });
    if (r.falhadas) void msg.warning(resumo);
    else void msg.success(resumo);
    aoConcluir?.(r);
  };

  const registar = () => {
    if (!estado.preenchidas.length) {
      setAviso({ tipo: 'warning', texto: 'Preencha pelo menos uma linha.' });
      return;
    }
    const n = estado.validar();
    if (n) {
      setAviso({ tipo: 'error', texto: `${n} linha(s) com campos obrigatórios em falta ou valores por corrigir (assinaladas a vermelho).` });
      return;
    }
    setAviso(null);
    if (!confirmar) {
      void executar();
      return;
    }
    const total = estado.preenchidas.length;
    (modal?.confirm ?? Modal.confirm)({
      title: `Registar ${nomeRegistos ?? 'registos'}`,
      content: `Gravar ${total} linha(s)? Cada linha é validada e gravada como no formulário normal. As que falharem voltam à tabela para corrigir.`,
      okText: 'Registar',
      cancelText: 'Cancelar',
      onOk: () => {
        void executar();
      },
    });
  };

  const corErro = token.colorErrorBg;
  const th = { position: 'sticky' as const, top: 0, zIndex: 1, background: token.colorFillAlter, color: token.colorTextHeading, textAlign: 'left' as const, padding: '6px 6px', borderBottom: `1px solid ${token.colorBorder}`, whiteSpace: 'nowrap' as const, fontWeight: 600 };
  const td = { padding: '3px 4px', borderBottom: `1px solid ${token.colorBorderSecondary}`, verticalAlign: 'middle' as const };

  return (
    <div ref={raiz} className="erp-form-linhas" data-testid="painel-linhas">
      <Typography.Text type="secondary" id={idNota} style={{ display: 'block', fontSize: 12, marginBottom: 6 }}>
        {nota ?? NOTA_LINHAS}{temObrigatorios ? ' Campos com * são obrigatórios.' : ''}
        {' '}Atalhos: Enter passa à linha seguinte, Ctrl+D duplica a linha, Ctrl+Del limpa a linha; pode colar do Excel directamente numa célula.
      </Typography.Text>
      <div style={{ overflow: 'auto', maxHeight: '60vh', border: `1px solid ${token.colorBorderSecondary}`, borderRadius: token.borderRadius }} tabIndex={-1}>
        <table style={{ borderCollapse: 'collapse', fontSize: 13, minWidth: '100%' }} aria-describedby={idNota}>
          <caption className="erp-so-leitor" style={{ position: 'absolute', width: 1, height: 1, overflow: 'hidden', clip: 'rect(0 0 0 0)' }}>Registos a criar, uma linha por registo</caption>
          <thead>
            <tr>
              <th scope="col" style={{ ...th, width: 34, textAlign: 'center' }}>#</th>
              {visiveis.map((c) => (
                <th key={c.nome} scope="col" style={{ ...th, minWidth: c.largura ?? 140 }} title={c.seccao}>
                  {c.rotulo}{c.obrigatorio ? ' *' : ''}
                </th>
              ))}
              <th scope="col" style={th}><span className="erp-so-leitor" style={{ position: 'absolute', width: 1, height: 1, overflow: 'hidden', clip: 'rect(0 0 0 0)' }}>Acções</span></th>
              {temErros && <th scope="col" style={{ ...th, minWidth: 200 }}>Erro</th>}
            </tr>
          </thead>
          <tbody onKeyDown={aoTeclar} onPaste={aoColarCelula}>
            {estado.linhas.map((l, i) => {
              const comErro = !!l.erro;
              return (
                <tr key={l.id} data-fl-linha={l.id} data-testid="linha-formulario" aria-invalid={comErro || undefined} style={comErro ? { background: corErro } : undefined}>
                  <td style={{ ...td, textAlign: 'center', color: token.colorTextSecondary }} tabIndex={0} data-fl-numero="" aria-label={`Linha ${i + 1}`}>{i + 1}</td>
                  {visiveis.map((c, k) => (
                    <td key={c.nome} style={{ ...td, minWidth: c.largura ?? 140 }} data-fl-celula={`${i}:${k}`}>
                      <Celula campo={c} linha={l} numero={i + 1} definir={estado.definir} erroObrigatorio={comErro && !!c.obrigatorio && estaVazio(l.valores[c.nome])} />
                    </td>
                  ))}
                  <td style={{ ...td, whiteSpace: 'nowrap' }}>
                    <Button size="small" icon={<CloseOutlined />} aria-label={`Remover linha ${i + 1}`} title="Remover linha" onClick={() => estado.remover(l.id)} disabled={estado.aGravar} />
                  </td>
                  {temErros && <td style={{ ...td, color: token.colorError, fontSize: 12, whiteSpace: 'normal', minWidth: 200 }} role={comErro ? 'alert' : undefined}>{l.erro}</td>}
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
      {aviso && <Alert style={{ marginTop: 8 }} type={aviso.tipo} showIcon message={aviso.texto} closable onClose={() => setAviso(null)} />}
      {estado.progresso && (
        <div style={{ marginTop: 8, display: 'flex', gap: 8, alignItems: 'center' }}>
          <Progress percent={Math.round((estado.progresso.feitas / Math.max(1, estado.progresso.total)) * 100)} size="small" style={{ flex: 1, margin: 0 }} aria-label="Progresso da gravação" />
          <Typography.Text type="secondary" style={{ fontSize: 12 }}>{estado.progresso.feitas} de {estado.progresso.total}</Typography.Text>
          <Button size="small" onClick={estado.cancelar}>Parar</Button>
        </div>
      )}
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, alignItems: 'center', marginTop: 10 }}>
        <Button size="small" icon={<PlusOutlined />} disabled={estado.aGravar} onClick={() => { focoPendente.current = { id: estado.adicionar(), coluna: 0 }; }}>Adicionar linha</Button>
        <Button size="small" icon={<CopyOutlined />} disabled={estado.aGravar} title="Nova linha igual à última linha preenchida" onClick={() => { focoPendente.current = { id: estado.duplicarUltima(), coluna: 0 }; }}>Duplicar última</Button>
        <Button size="small" icon={<SnippetsOutlined />} disabled={estado.aGravar} title="Colar linhas copiadas do Excel (colunas pela ordem da tabela)" onClick={() => setColarAberto(true)}>Colar do Excel</Button>
        {opcionais.length > 0 && (
          <Dropdown
            trigger={['click']}
            popupRender={() => (
              <div style={{ background: token.colorBgElevated, boxShadow: token.boxShadowSecondary, borderRadius: token.borderRadiusLG, padding: 8, maxHeight: 320, overflow: 'auto' }} role="group" aria-label="Colunas opcionais">
                <Space direction="vertical" size={4}>
                  {opcionais.map((c) => (
                    <Checkbox key={c.nome} checked={mostradas.includes(c.nome)} onChange={(e) => alternarColuna(c.nome, e.target.checked)}>
                      {c.rotulo}{c.seccao ? <Typography.Text type="secondary" style={{ fontSize: 11 }}> · {c.seccao}</Typography.Text> : null}
                    </Checkbox>
                  ))}
                </Space>
              </div>
            )}
          >
            <Button size="small" icon={<SettingOutlined />} aria-label="Mostrar ou esconder colunas">Colunas ({visiveis.length - (estado.campos.length - opcionais.length)}/{opcionais.length})</Button>
          </Dropdown>
        )}
        <Typography.Text type="secondary" style={{ fontSize: 12 }} aria-live="polite" data-testid="contagem-linhas">{estado.preenchidas.length} linha(s) preenchida(s)</Typography.Text>
        <span style={{ flex: 1 }} />
        <Button type="primary" icon={<CheckOutlined />} loading={estado.aGravar} onClick={registar}>Registar linhas</Button>
      </div>
      <Modal title="Colar linhas do Excel" open={colarAberto} onCancel={() => setColarAberto(false)} onOk={confirmarColagem} okText="Acrescentar linhas" cancelText="Cancelar" width="min(720px, calc(100vw - 32px))" destroyOnHidden>
        <Typography.Paragraph style={{ fontSize: 13 }}>
          Copie as linhas no Excel (colunas separadas por tabulação, «|» ou «;»). Sem cabeçalho, as colunas são lidas por esta ordem:
          <br /><strong>{visiveis.map((c) => c.rotulo).join(' | ')}</strong><br />
          Com a linha de cabeçalho incluída, as colunas são reconhecidas pelo nome e a ordem não importa. Datas DD/MM/AAAA e valores como 70 004,54 são aceites; nas listas pode usar o código ou o nome.
        </Typography.Paragraph>
        <Input.TextArea aria-label="Linhas a colar" autoFocus rows={8} style={{ fontFamily: 'monospace', fontSize: 12 }} placeholder="Cole aqui (Ctrl+V)" value={textoColar} onChange={(e) => setTextoColar(e.target.value)} />
      </Modal>
    </div>
  );
}
