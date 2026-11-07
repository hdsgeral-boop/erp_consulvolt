import { Select } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { CHAVES_OB, procurarArtigos } from './apiObras';

/**
 * Escolha de um artigo por pesquisa no servidor (código ou nome). O artigo já escolhido aparece com o nome que o servidor mandou
 * (`nomeConhecido`), mesmo que a pesquisa actual não o devolva. «De armazém» indica se há stock; o resto são serviços.
 */
export function SeletorArtigo({ value, onChange, nomeConhecido, id, placeholder = '— sem artigo —', largura = 280 }: {
  value?: number | null; onChange?: (v: number | null) => void; nomeConhecido?: string | null; id?: string; placeholder?: string; largura?: number | string;
}) {
  const [texto, setTexto] = useState('');
  const q = useQuery({ queryKey: CHAVES_OB.artigos(texto), queryFn: () => procurarArtigos(texto), staleTime: 60_000 });
  const opcoes = useMemo(() => {
    const o = (q.data ?? []).map((a) => ({ value: a.id, label: `${a.codigo ? `${a.codigo} — ` : ''}${a.nome}${a.de_armazem ? '' : ' (serviço)'}` }));
    if (value && nomeConhecido && !o.some((x) => x.value === value)) o.unshift({ value, label: nomeConhecido });
    return o;
  }, [q.data, value, nomeConhecido]);

  return (
    <Select id={id} showSearch allowClear filterOption={false} style={{ width: largura }} placeholder={placeholder} loading={q.isFetching} value={value ?? undefined} options={opcoes}
      onSearch={setTexto} onChange={(v) => onChange?.(v ?? null)} notFoundContent={q.isFetching ? 'A procurar…' : 'Nenhum artigo encontrado.'} />
  );
}
