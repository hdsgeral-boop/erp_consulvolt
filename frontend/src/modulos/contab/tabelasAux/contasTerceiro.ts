/**
 * Contas do terceiro por papel — regras puras (porta de window.ContaTerceiro do legado novo,
 * Versão Actual js/shared/contas_terceiro.js; servidor: App\Services\Terceiros\ContaTerceiro).
 *   conta_cliente     classe 31          → integração das vendas, recibos e NC
 *   conta_fornecedor  classe 32 ou 37.0  → integração das facturas de compra e pagamentos
 * O codigo_conta antigo só serve de recurso quando a classe corresponde ao papel. Sem contas por defeito.
 */

export type PapelConta = 'CLIENTE' | 'FORNECEDOR';
export type TipoTerceiro = 'CLIENTE' | 'FORNECEDOR' | 'CLIENTE_FORNECEDOR' | 'COLABORADOR';

export interface TerceiroComContas {
  tipo?: string | null;
  codigo_conta?: string | null;
  conta_cliente?: string | null;
  conta_fornecedor?: string | null;
}

export const CLASSE_CLIENTE = '31';
/** As compras admitem a 32 (fornecedores) e a 37.0 (outros devedores e credores) — contas_terceiro.js:26. */
export const CLASSES_FORNECEDOR = ['32', '370'];

/** Opções do selector de tipo, com a combinação cliente + fornecedor (OPCOES_TIPO, contas_terceiro.js:118-124). */
export const OPCOES_TIPO: { value: TipoTerceiro; label: string }[] = [
  { value: 'CLIENTE', label: 'Cliente' },
  { value: 'FORNECEDOR', label: 'Fornecedor' },
  { value: 'CLIENTE_FORNECEDOR', label: 'Cliente e Fornecedor' },
  { value: 'COLABORADOR', label: 'Colaborador' },
];

const ROTULOS: Record<string, string> = { CLIENTE: 'Cliente', FORNECEDOR: 'Fornecedor', CLIENTE_FORNECEDOR: 'Fornecedor + Cliente', COLABORADOR: 'Colaborador' };

const texto = (v: string | null | undefined) => String(v ?? '').trim();
/** Só os dígitos: '32.1.1', '321001' e '32-1' são todos da classe 32. */
export const digitos = (v: string | null | undefined) => texto(v).replace(/\D/g, '');

export function daClasse(conta: string | null | undefined, classe: string): boolean {
  const d = digitos(conta);
  return d !== '' && d.startsWith(digitos(classe));
}

/** A conta é da classe certa para o papel? Vazio conta como certo (a obrigatoriedade valida-se à parte). */
export function classeCorrecta(codigo: string | null | undefined, papel: PapelConta): boolean {
  const c = texto(codigo);
  if (!c) return true;
  return papel === 'FORNECEDOR' ? CLASSES_FORNECEDOR.some((x) => daClasse(c, x)) : daClasse(c, CLASSE_CLIENTE);
}

/** Papéis do tipo gravado (sem tipo: nenhum — os registos antigos não se escondem de nenhum papel). */
export function papeis(tipo: string | null | undefined): string[] {
  switch (texto(tipo).toUpperCase()) {
    case 'CLIENTE':
      return ['CLIENTE'];
    case 'FORNECEDOR':
      return ['FORNECEDOR'];
    case 'CLIENTE_FORNECEDOR':
      return ['CLIENTE', 'FORNECEDOR'];
    case 'COLABORADOR':
      return ['COLABORADOR'];
    default:
      return [];
  }
}

export const temPapel = (tipo: string | null | undefined, papel: PapelConta) => papeis(tipo).includes(papel);

/** Conta do papel: a própria; senão o codigo_conta antigo se for da classe; senão '' (ContaTerceiro.conta). */
export function contaDoPapel(t: TerceiroComContas | null | undefined, papel: PapelConta): string {
  if (!t) return '';
  const propria = texto(papel === 'FORNECEDOR' ? t.conta_fornecedor : t.conta_cliente);
  if (propria) return propria;
  const legada = texto(t.codigo_conta);
  return legada && classeCorrecta(legada, papel) ? legada : '';
}

export const contaCliente = (t: TerceiroComContas | null | undefined) => contaDoPapel(t, 'CLIENTE');
export const contaFornecedor = (t: TerceiroComContas | null | undefined) => contaDoPapel(t, 'FORNECEDOR');

/** Rótulo da coluna «Tipo» (rotuloTipo: «Fornecedor + Cliente»). */
export const rotuloTipo = (tipo: string | null | undefined) => ROTULOS[texto(tipo).toUpperCase()] ?? (texto(tipo) || '—');

/** Campos de conta que o tipo pede (auxTipoTerceiroMudou, ui_aux.js:675-686). */
export function camposConta(tipo: string | null | undefined): { cliente: boolean; fornecedor: boolean } {
  return { cliente: temPapel(tipo, 'CLIENTE'), fornecedor: temPapel(tipo, 'FORNECEDOR') };
}

/** Prefixos do plano para a caixa de contas do papel. */
export const prefixosConta = (papel: PapelConta) => (papel === 'FORNECEDOR' ? ['32', '37.0', '370'] : ['31']);

/**
 * Validação da ficha antes de gravar (saveThirdParty, ui_aux.js:598-612): conta obrigatória para cada papel do tipo e da
 * classe certa. A existência no plano e «só de movimento» valida-se no servidor. Devolve a mensagem ou null.
 */
export function validarContasTerceiro(tipo: string | null | undefined, contaCli: string | null | undefined, contaForn: string | null | undefined): string | null {
  const { cliente, fornecedor } = camposConta(tipo);
  if (cliente && !texto(contaCli)) return 'Indique a conta de cliente (classe 31).';
  if (fornecedor && !texto(contaForn)) return 'Indique a conta de fornecedor (classe 32 ou 37.0).';
  if (cliente && !classeCorrecta(contaCli, 'CLIENTE')) return `A conta de cliente ${texto(contaCli)} não é da classe 31.`;
  if (fornecedor && !classeCorrecta(contaForn, 'FORNECEDOR')) return `A conta de fornecedor ${texto(contaForn)} não é da classe 32 nem 37.0.`;
  return null;
}

/** Endereço estruturado (third_party_addresses) e o texto que vai para os documentos. */
export interface MoradaTerceiro {
  rua?: string | null;
  numero_porta?: string | null;
  bairro?: string | null;
  municipio?: string | null;
  provincia?: string | null;
  pais?: string | null;
}

export function textoMorada(m: MoradaTerceiro | null | undefined): string {
  if (!m) return '';
  const rua = [texto(m.rua), texto(m.numero_porta) ? `n.º ${texto(m.numero_porta)}` : ''].filter(Boolean).join(', ');
  return [rua, texto(m.bairro), texto(m.municipio), texto(m.provincia), texto(m.pais)].filter(Boolean).join(', ');
}
