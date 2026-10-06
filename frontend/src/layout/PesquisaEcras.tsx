import { SearchOutlined, StarFilled } from '@ant-design/icons';
import { Button, Empty, Input, Modal, Typography, type InputRef } from 'antd';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import type { ModuloMenu } from '@/api/tipos';
import { useFavoritos } from '@/componentes/preferencias/favoritos';
import { iconeModulo } from '@/componentes/icones/iconesModulos';
import { useSessao } from '@/sessao/SessaoContexto';

/**
 * Pesquisa de ecrãs «Ir para» (CTRL+K / ⌘K; legado ui_dashboard.js:950-1008, citado na Dica do dia): procura nos ecrãs do
 * menu do utilizador (nome do ecrã ou do módulo, sem acentos), favoritos primeiro, setas ↑/↓ e Enter para abrir.
 */
export interface ResultadoEcra {
  modulo: string;
  ecra: string;
  nome: string;
  nomeModulo: string;
  rota: string;
  favorito: boolean;
}

const normalizar = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

/** Ecrãs do menu que correspondem a todas as palavras do termo (favoritos primeiro; sem termo, só os favoritos e o resto do menu). */
export function procurarEcras(menu: ModuloMenu[], termo: string, eFavorito: (m: string, e: string) => boolean, limite = 30): ResultadoEcra[] {
  const palavras = normalizar(termo).split(/\s+/).filter(Boolean);
  const todos = menu.flatMap((m) => m.ecras.map((e) => ({ modulo: m.id, ecra: e.id, nome: e.nome, nomeModulo: m.nome, rota: `/m/${m.id}/${e.id}`, favorito: eFavorito(m.id, e.id) })));
  const filtrados = palavras.length ? todos.filter((r) => palavras.every((p) => normalizar(`${r.nome} ${r.nomeModulo} ${r.ecra}`).includes(p))) : todos;
  return filtrados.sort((a, b) => Number(b.favorito) - Number(a.favorito) || (palavras.length ? Number(normalizar(b.nome).startsWith(palavras[0])) - Number(normalizar(a.nome).startsWith(palavras[0])) : 0)).slice(0, limite);
}

export function PesquisaEcras({ aberta, aoFechar }: { aberta: boolean; aoFechar: () => void }) {
  const { menu } = useSessao();
  const { eFavorito } = useFavoritos();
  const navegar = useNavigate();
  const [termo, setTermo] = useState('');
  const [activo, setActivo] = useState(0);
  const entrada = useRef<InputRef>(null);
  const resultados = useMemo(() => procurarEcras(menu, termo, eFavorito), [menu, termo, eFavorito]);
  useEffect(() => setActivo(0), [termo]);
  useEffect(() => {
    if (aberta) {
      setTermo('');
      window.setTimeout(() => entrada.current?.focus(), 50);
    }
  }, [aberta]);
  const abrir = (r?: ResultadoEcra) => {
    if (!r) return;
    aoFechar();
    navegar(r.rota);
  };
  return (
    <Modal open={aberta} onCancel={aoFechar} footer={null} title="Ir para um ecrã" destroyOnHidden width={560} className="erp-pesquisa-ecras">
      <Input
        ref={entrada}
        size="large"
        prefix={<SearchOutlined aria-hidden />}
        placeholder="Escreva o nome do ecrã ou do módulo (ex.: balancete, facturação)"
        aria-label="Procurar ecrã"
        value={termo}
        onChange={(e) => setTermo(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActivo((a) => Math.min(a + 1, resultados.length - 1));
          } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActivo((a) => Math.max(a - 1, 0));
          } else if (e.key === 'Enter') abrir(resultados[activo]);
        }}
      />
      <ul className="erp-pesquisa-resultados" role="listbox" aria-label="Ecrãs encontrados">
        {resultados.map((r, i) => (
          <li key={r.rota} role="option" aria-selected={i === activo}>
            <button type="button" className={i === activo ? 'activo' : undefined} onMouseEnter={() => setActivo(i)} onClick={() => abrir(r)}>
              <span className="erp-pesquisa-icone" aria-hidden>{iconeModulo(r.modulo)}</span>
              <span className="erp-pesquisa-textos">
                <span className="erp-pesquisa-nome">{r.nome}</span>
                <span className="erp-pesquisa-modulo">{r.nomeModulo}</span>
              </span>
              {r.favorito && <StarFilled style={{ color: '#f59e0b' }} aria-label="Favorito" />}
            </button>
          </li>
        ))}
      </ul>
      {!resultados.length && <Empty description="Nenhum ecrã encontrado" image={Empty.PRESENTED_IMAGE_SIMPLE} />}
      <Typography.Text type="secondary" style={{ fontSize: 12 }}>↑ ↓ para escolher · Enter para abrir · Esc para fechar</Typography.Text>
    </Modal>
  );
}

/** Botão da barra superior que abre a pesquisa (o atalho CTRL+K é registado no layout). */
export function BotaoPesquisa({ aoAbrir, compacto }: { aoAbrir: () => void; compacto?: boolean }) {
  return (
    <Button className="erp-botao-pesquisa" icon={<SearchOutlined />} onClick={aoAbrir} aria-label="Procurar ecrã" title="Procurar ecrã (Ctrl+K)">
      {compacto ? null : 'Procurar'}
    </Button>
  );
}
