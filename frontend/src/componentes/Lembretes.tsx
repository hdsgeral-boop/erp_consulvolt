import { DeleteOutlined, PlusOutlined, PushpinFilled, PushpinOutlined } from '@ant-design/icons';
import { Button, Checkbox, Input, Popconfirm, Segmented, Tooltip } from 'antd';
import { useState } from 'react';
import { IconeFa } from './fluxos/IconeFa';
import { useLembretes } from './preferencias/lembretes';

type Aba = 'por_fazer' | 'feitos';

/** Caixa «Lembretes» do Início: notas pessoais do utilizador, guardadas no servidor (substitui a lista «Módulos» — a navegação directa aos módulos fica no menu superior). */
export function Lembretes() {
  const { lista, porFazer, adicionar, alternarFeito, alternarFixado, remover } = useLembretes();
  const [aba, setAba] = useState<Aba>('por_fazer');
  const [novo, setNovo] = useState('');
  const [aAdicionar, setAAdicionar] = useState(false);

  const visiveis = lista.filter((l) => (aba === 'por_fazer' ? !l.feito : l.feito));

  const submeter = () => {
    if (novo.trim()) adicionar(novo);
    setNovo('');
    setAAdicionar(false);
  };

  return (
    <nav className="erp-lembretes" aria-label="Lembretes">
      <div className="erp-lembretes-cabeca">
        <h2 className="erp-lembretes-titulo">
          <IconeFa nome="clipboard-check" style={{ color: '#b45309' }} /> Lembretes
          <span className="erp-lembretes-contagem">{porFazer} por fazer</span>
        </h2>
        <div className="erp-lembretes-acoes">
          <Segmented
            size="small"
            value={aba}
            onChange={(v) => setAba(v as Aba)}
            options={[
              { label: 'Por fazer', value: 'por_fazer' },
              { label: 'Feitos', value: 'feitos' },
            ]}
          />
          <Button size="small" icon={<PlusOutlined />} onClick={() => setAAdicionar(true)}>
            Novo
          </Button>
        </div>
      </div>

      {aAdicionar && (
        <Input.Search
          autoFocus
          placeholder="Escreva o lembrete e prima Enter…"
          value={novo}
          onChange={(e) => setNovo(e.target.value)}
          onSearch={submeter}
          onBlur={() => !novo.trim() && setAAdicionar(false)}
          enterButton="Adicionar"
          style={{ marginBottom: 12 }}
          maxLength={300}
        />
      )}

      {visiveis.length ? (
        <ul className="erp-lembretes-lista" style={{ listStyle: 'none', margin: 0, padding: 0 }}>
          {visiveis.map((l) => (
            <li key={l.id} className={`erp-lembrete${l.feito ? ' feito' : ''}`}>
              <Checkbox checked={l.feito} onChange={() => alternarFeito(l.id)} aria-label={l.feito ? 'Marcar por fazer' : 'Marcar como feito'} />
              <span className="erp-lembrete-texto">{l.texto}</span>
              <Tooltip title={l.fixado ? 'Desafixar' : 'Fixar no topo'}>
                <Button
                  type="text"
                  size="small"
                  icon={l.fixado ? <PushpinFilled style={{ color: '#b45309' }} /> : <PushpinOutlined />}
                  onClick={() => alternarFixado(l.id)}
                  aria-label={l.fixado ? 'Desafixar' : 'Fixar no topo'}
                />
              </Tooltip>
              <Popconfirm title="Eliminar este lembrete?" okText="Eliminar" cancelText="Cancelar" onConfirm={() => remover(l.id)}>
                <Button type="text" size="small" danger icon={<DeleteOutlined />} aria-label="Eliminar lembrete" />
              </Popconfirm>
            </li>
          ))}
        </ul>
      ) : (
        <p className="erp-lembretes-vazio" style={{ margin: 0 }}>
          {aba === 'por_fazer' ? 'Sem lembretes por fazer — clique em «Novo» para criar um.' : 'Ainda não marcou nenhum lembrete como feito.'}
        </p>
      )}
    </nav>
  );
}
