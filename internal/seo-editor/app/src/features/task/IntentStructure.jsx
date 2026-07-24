import React from 'react';
import { useStore } from '../../store/useStore.js';
import { INTENTS, INTENT_RU, structureBlocks, formatRepeat, structureVersion } from './structureMeta.js';

// Интент → структура (ТЗ 4.2): три карточки интента фильтруют сетку структур,
// выбор структуры открывает панель деталей. Пишет search_intent / structure_id.
export default function IntentStructure({ highlight = false }) {
  const { article, patchArticle, structures } = useStore();
  const intent = article.search_intent || '';
  const list = structures.filter((s) => s.intent === intent);
  const selected = structures.find((s) => s.id === article.structure_id) || null;

  const chooseIntent = (key) => {
    // Смена интента сбрасывает выбранную структуру.
    if (key === intent) return;
    patchArticle({ search_intent: key, structure_id: '' });
  };

  const chooseStructure = (id) => {
    patchArticle({ structure_id: id });
  };

  return (
    <div className={'intent-structure' + (highlight ? ' highlight' : '')}>
      <div className="intents">
        {INTENTS.map((it) => (
          <button
            type="button"
            key={it.key}
            className={'intent-card' + (it.key === intent ? ' on' : '')}
            onClick={() => chooseIntent(it.key)}
          >
            <div className="in">{it.title}</div>
            <div className="id">{it.desc}</div>
          </button>
        ))}
      </div>

      <div className="structs">
        {!intent && <div className="struct-empty">Выберите интент, чтобы увидеть доступные структуры</div>}
        {intent && list.length === 0 && <div className="struct-empty">Для этого интента нет структур в конфиге.</div>}
        {list.map((s) => (
          <button
            type="button"
            key={s.id}
            className={'struct-card' + (s.id === article.structure_id ? ' on' : '')}
            onClick={() => chooseStructure(s.id)}
          >
            <div className="sn">{s.name}</div>
            <div className="sid">{s.id} · v{structureVersion(s)}</div>
            <div className="sw">{s.when_to_use || ''}</div>
            {s.primary_metric && <div className="sm">Метрика теста: {s.primary_metric}</div>}
          </button>
        ))}
      </div>

      {selected && <StructureDetail config={selected} />}
    </div>
  );
}

function StructureDetail({ config }) {
  const blocks = structureBlocks(config);
  const forbidden = Array.isArray(config.forbidden) ? config.forbidden : [];
  return (
    <div className="struct-detail on">
      <b className="struct-detail-title">{config.name}</b>{' '}
      <span className="mono struct-detail-meta">
        · {config.id} · v{structureVersion(config)} · {INTENT_RU[config.intent] || config.intent}
      </span>
      <div className="blocks">
        {blocks.map((b, i) => {
          const rep = formatRepeat(b.repeat);
          return (
            <span key={i} className={'blk' + (b.required ? ' req' : '')}>
              {b.block}
              {!b.required && ' (опц.)'}
              {rep && <span className="blk-rep"> {rep}</span>}
            </span>
          );
        })}
      </div>
      {forbidden.length > 0 && <div className="fb">Запрещено: {forbidden.join('; ')}</div>}
    </div>
  );
}
