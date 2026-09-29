// Verifica no backup se as chaves únicas candidatas são respeitadas pelos dados reais.
// Uso: node verificar_unicidade.mjs <backup.json>
import fs from 'node:fs';
const b = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const EMP = new Set([10, 18]);
const real = (t, l) => !(l?.is_master_data == 1 && !(t === 'companies' && EMP.has(l.id)));
const T = Object.fromEntries(b.data.data.map((t) => [t.tableName, (t.rows ?? []).filter((l) => real(t.tableName, l))]));
const norm = (v) => (v === null || v === undefined ? '' : String(v).trim().toUpperCase());
export const CANDIDATAS = [
  ['companies', ['nif']], ['users', ['username']], ['user_profiles', ['name']],
  ['chart_of_accounts', ['company_id', 'code']], ['journals', ['company_id', 'code']],
  ['third_parties', ['company_id', 'nif', 'type']], ['third_parties', ['company_id', 'account_code']],
  ['products', ['company_id', 'code']], ['product_categories', ['company_id', 'name']], ['warehouses', ['company_id', 'code']],
  ['warehouse_stock', ['warehouse_id', 'product_id']], ['employees', ['company_id', 'nif']],
  ['roles', ['company_id', 'name']], ['infotypes', ['company_id', 'name']], ['org_types', ['company_id', 'name']],
  ['payroll_periods', ['company_id', 'month_year']], ['contracts', ['company_id', 'employee_id']],
  ['sales', ['company_id', 'doc_type', 'doc_number']], ['sales', ['company_id', 'doc_number']],
  ['receipts', ['company_id', 'receipt_number']], ['purchase_orders', ['company_id', 'order_number']],
  ['purchase_invoices', ['company_id', 'supplier_id', 'invoice_number']], ['delivery_notes', ['company_id', 'doc_number']],
  ['treasury_documents', ['company_id', 'doc_number']], ['journal_lines', ['company_id', 'journal_id', 'lan_number', 'account_code', 'type_dc']],
  ['cost_centers', ['company_id', 'code']], ['business_units', ['company_id', 'code']], ['banks', ['company_id', 'code']],
  ['payment_methods', ['company_id', 'name']], ['currencies', ['code']], ['exchange_rates', ['scope_company_id', 'currency', 'rate_date']],
  ['fixed_assets', ['company_id', 'code']], ['asset_categories', ['company_id', 'code']], ['projects', ['company_id', 'code']],
  ['pos_terminals', ['pos_company_id', 'code']], ['demo_notes', ['company_id', 'code']], ['cashflow_notes', ['company_id', 'code']],
  ['org_units', ['org_company_id', 'codigo']], ['orc_rubrics', ['orc_company_id', 'codigo']], ['employee_bank_details', ['company_id', 'employee_id', 'iban']],
  ['accounting_mapos', ['company_id', 'infotype_id', 'org_type_id']], ['system_accounting_mapos', ['company_id', 'key', 'org_type_id']],
  ['sales_accounting_config', ['company_id']], ['pos_settings', ['pos_company_id']], ['pa_settings', ['pa_company_id']], ['fe_config', ['fe_company_id']],
];
const res = {};
for (const [t, cols] of CANDIDATAS) {
  const rows = T[t] ?? [];
  const seen = new Map(); let dup = 0, vazios = 0; const ex = [];
  for (const r of rows) {
    if (cols.some((c) => r[c] === undefined || r[c] === null || r[c] === '')) { vazios++; continue; }
    const k = cols.map((c) => norm(r[c])).join('|');
    if (seen.has(k)) { dup++; if (ex.length < 3) ex.push(`${k} (ids ${seen.get(k)},${r.id})`); } else seen.set(k, r.id);
  }
  const chave = `${t}(${cols.join(',')})`;
  res[chave] = { linhas: rows.length, duplicados: dup, com_vazios: vazios, exemplos: ex };
  console.log(`${dup ? 'DUP ' : 'OK  '} ${chave.padEnd(62)} linhas=${rows.length} dup=${dup} vazios=${vazios} ${ex.join(' ; ')}`);
}
// Polimórficos
const idsDN = new Set(T.delivery_notes.map((r) => String(r.id))), idsPD = new Set(T.purchase_deliveries.map((r) => String(r.id)));
const di = T.delivery_items.map((r) => String(r.delivery_id));
console.log('\ndelivery_items.delivery_id:', { total: di.length, so_delivery_notes: di.filter((x) => idsDN.has(x) && !idsPD.has(x)).length, so_purchase_deliveries: di.filter((x) => !idsDN.has(x) && idsPD.has(x)).length, ambos: di.filter((x) => idsDN.has(x) && idsPD.has(x)).length, nenhum: di.filter((x) => !idsDN.has(x) && !idsPD.has(x)).length });
console.log('delivery_items colunas:', [...new Set(T.delivery_items.flatMap(Object.keys))].join(','));
const mapaTipo = { REQUEST: 'purchase_requests', QUOTE: 'purchase_quotes', ORDER: 'purchase_orders', INVOICE: 'purchase_invoices' };
const pt = {}; for (const r of T.purchase_items) { const alvo = mapaTipo[String(r.parent_type).toUpperCase()]; const ok = alvo && T[alvo].some((x) => String(x.id) === String(r.parent_id)); const k = `${r.parent_type}:${ok ? 'ok' : 'orfao'}`; pt[k] = (pt[k] || 0) + 1; }
console.log('purchase_items parent_type/parent_id:', JSON.stringify(pt));
fs.writeFileSync(new URL('./unicidade_resultado.json', import.meta.url), JSON.stringify(res, null, 2));
