/**
 * Contas do terceiro por papel — regras puras (porta de window.ContaTerceiro do legado novo,
 * Versão Actual js/shared/contas_terceiro.js; servidor: App\Services\Terceiros\ContaTerceiro).
 *   conta_cliente     classe 31          → integração das vendas, recibos e NC
 *   conta_fornecedor  classe 32 ou 37.0  → integração das facturas de compra e pagamentos
 * O codigo_conta antigo só serve de recurso quando a classe corresponde ao papel. Sem contas por defeito.
 */

export type PapelConta = 'CLIENTE' | 'FORNECEDOR' | 'CREDOR_DEVEDOR';
export type TipoTerceiro = 'CLIENTE' | 'FORNECEDOR' | 'CLIENTE_FORNECEDOR' | 'COLABORADOR' | 'CREDOR_DEVEDOR';

export interface TerceiroComContas {
  tipo?: string | null;
  codigo_conta?: string | null;
  conta_cliente?: string | null;
  conta_fornecedor?: string | null;
  conta_credor_devedor?: string | null;
}

/** Classes da conta de cliente: 31 e, por pedido do utilizador, também 37.91 e 37.2. */
export const CLASSES_CLIENTE = ['31', '3791', '372'];

/** As compras admitem a 32 (fornecedores), a 37.0 (outros devedores e credores) e, por pedido do utilizador, 37.1 e 37.92. */
export const CLASSES_FORNECEDOR = ['32', '370', '371', '3792'];

/** Conta de Credor/Devedor (papel autónomo, sem cliente/fornecedor): qualquer conta da classe 37. */
export const CLASSE_CREDOR_DEVEDOR = '37';

const CLASSES_PAPEL: Record<PapelConta, string[]> = { CLIENTE: CLASSES_CLIENTE, FORNECEDOR: CLASSES_FORNECEDOR, CREDOR_DEVEDOR: [CLASSE_CREDOR_DEVEDOR] };
const CAMPOS_CONTA: Record<PapelConta, 'conta_cliente' | 'conta_fornecedor' | 'conta_credor_devedor'> = {
  CLIENTE: 'conta_cliente', FORNECEDOR: 'conta_fornecedor', CREDOR_DEVEDOR: 'conta_credor_devedor',
};

/**
 * Opções do selector de tipo da ficha combinada (Tabelas auxiliares › Terceiros, OPCOES_TIPO, contas_terceiro.js:118-124).
 * Sem «Credor/Devedor»: esse papel é autónomo e tem ecrã próprio (Tabelas auxiliares › Credores e Devedores), porque a
 * ficha combinada só sabe pedir a conta de cliente/fornecedor, nunca a de credor/devedor.
 */
export const OPCOES_TIPO: { value: TipoTerceiro; label: string }[] = [
  { value: 'CLIENTE', label: 'Cliente' },
  { value: 'FORNECEDOR', label: 'Fornecedor' },
  { value: 'CLIENTE_FORNECEDOR', label: 'Cliente e Fornecedor' },
  { value: 'COLABORADOR', label: 'Colaborador' },
];

const ROTULOS: Record<string, string> = {
  CLIENTE: 'Cliente', FORNECEDOR: 'Fornecedor', CLIENTE_FORNECEDOR: 'Fornecedor + Cliente', COLABORADOR: 'Colaborador', CREDOR_DEVEDOR: 'Credor/Devedor',
};

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
  return CLASSES_PAPEL[papel].some((x) => daClasse(c, x));
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
    case 'CREDOR_DEVEDOR':
      return ['CREDOR_DEVEDOR'];
    default:
      return [];
  }
}

export const temPapel = (tipo: string | null | undefined, papel: PapelConta) => papeis(tipo).includes(papel);

/** Conta do papel: a própria; senão o codigo_conta antigo se for da classe (não se aplica ao Credor/Devedor, papel novo sem fichas antigas); senão '' (ContaTerceiro.conta). */
export function contaDoPapel(t: TerceiroComContas | null | undefined, papel: PapelConta): string {
  if (!t) return '';
  const propria = texto(t[CAMPOS_CONTA[papel]]);
  if (propria || papel === 'CREDOR_DEVEDOR') return propria;
  const legada = texto(t.codigo_conta);
  return legada && classeCorrecta(legada, papel) ? legada : '';
}

export const contaCliente = (t: TerceiroComContas | null | undefined) => contaDoPapel(t, 'CLIENTE');
export const contaFornecedor = (t: TerceiroComContas | null | undefined) => contaDoPapel(t, 'FORNECEDOR');
export const contaCredorDevedor = (t: TerceiroComContas | null | undefined) => contaDoPapel(t, 'CREDOR_DEVEDOR');

/** Rótulo da coluna «Tipo» (rotuloTipo: «Fornecedor + Cliente»). */
export const rotuloTipo = (tipo: string | null | undefined) => ROTULOS[texto(tipo).toUpperCase()] ?? (texto(tipo) || '—');

/** Campos de conta que o tipo pede (auxTipoTerceiroMudou, ui_aux.js:675-686). */
export function camposConta(tipo: string | null | undefined): { cliente: boolean; fornecedor: boolean; credorDevedor: boolean } {
  return { cliente: temPapel(tipo, 'CLIENTE'), fornecedor: temPapel(tipo, 'FORNECEDOR'), credorDevedor: temPapel(tipo, 'CREDOR_DEVEDOR') };
}

/**
 * Prefixos do plano para a caixa de contas do papel — com e sem pontos, porque `opcoesConta` (SeletorConta) compara o
 * código tal como está gravado (`codigo.startsWith`, sem ignorar pontos) e as empresas podem codificar com ou sem eles.
 */
export const prefixosConta = (papel: PapelConta) => (papel === 'FORNECEDOR' ? ['32', '37.0', '370', '37.1', '371', '37.92', '3792']
  : papel === 'CREDOR_DEVEDOR' ? ['37'] : ['31', '37.91', '3791', '37.2', '372']);

/**
 * Validação da ficha antes de gravar (saveThirdParty, ui_aux.js:598-612): conta obrigatória para cada papel do tipo e da
 * classe certa. A existência no plano e «só de movimento» valida-se no servidor. Devolve a mensagem ou null.
 */
export function validarContasTerceiro(
  tipo: string | null | undefined,
  contaCli: string | null | undefined,
  contaForn: string | null | undefined,
  contaCredDev?: string | null | undefined,
): string | null {
  const { cliente, fornecedor, credorDevedor } = camposConta(tipo);
  if (cliente && !texto(contaCli)) return 'Indique a conta de cliente (classe 31).';
  if (fornecedor && !texto(contaForn)) return 'Indique a conta de fornecedor (classe 32 ou 37.0).';
  if (credorDevedor && !texto(contaCredDev)) return 'Indique a conta de credor/devedor (classe 37).';
  if (cliente && !classeCorrecta(contaCli, 'CLIENTE')) return `A conta de cliente ${texto(contaCli)} não é da classe 31, 37.91 ou 37.2.`;
  if (fornecedor && !classeCorrecta(contaForn, 'FORNECEDOR')) return `A conta de fornecedor ${texto(contaForn)} não é da classe 32, 37.0, 37.1 ou 37.92.`;
  if (credorDevedor && !classeCorrecta(contaCredDev, 'CREDOR_DEVEDOR')) return `A conta de credor/devedor ${texto(contaCredDev)} não é da classe 37.`;
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
