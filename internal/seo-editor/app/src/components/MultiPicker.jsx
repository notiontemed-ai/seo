import React, { useEffect, useRef, useState } from 'react';
import { TextInput } from './ui.jsx';

// Пикер множественных привязок (RELATED_*): поиск по справочнику + чипсы
// выбранных элементов. Данные — из существующих read-действий API.
export function MultiPicker({ label, hint, value = [], onChange, search, known = [] }) {
  const [query, setQuery] = useState('');
  const [results, setResults] = useState([]);
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const [labels, setLabels] = useState({});
  const timer = useRef(null);
  const rootRef = useRef(null);

  const labelOf = (id) => labels[id] || known.find((k) => k.id === id)?.name || '#' + id;

  useEffect(() => {
    const q = query.trim();
    clearTimeout(timer.current);
    if (!q) {
      setResults([]);
      setOpen(false);
      return undefined;
    }
    timer.current = setTimeout(async () => {
      setLoading(true);
      try {
        const items = await search(q);
        setResults(items);
        setLabels((m) => ({ ...m, ...Object.fromEntries(items.map((i) => [i.id, i.name])) }));
        setOpen(true);
      } catch (_) {
        setResults([]);
      } finally {
        setLoading(false);
      }
    }, 300);
    return () => clearTimeout(timer.current);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [query]);

  useEffect(() => {
    const onDocClick = (e) => {
      if (rootRef.current && !rootRef.current.contains(e.target)) setOpen(false);
    };
    document.addEventListener('mousedown', onDocClick);
    return () => document.removeEventListener('mousedown', onDocClick);
  }, []);

  const add = (item) => {
    if (!value.includes(item.id)) onChange([...value, item.id]);
    setLabels((m) => ({ ...m, [item.id]: item.name }));
    setQuery('');
    setResults([]);
    setOpen(false);
  };

  const remove = (id) => onChange(value.filter((x) => x !== id));

  return (
    <div className="field multipicker" ref={rootRef}>
      <span className="field-label">{label}</span>
      {value.length > 0 && (
        <div className="chips">
          {value.map((id) => (
            <span className="chip" key={id}>
              {labelOf(id)}
              <button type="button" className="chip-remove" onClick={() => remove(id)} aria-label="Убрать">
                ×
              </button>
            </span>
          ))}
        </div>
      )}
      <div className="multipicker-input">
        <TextInput
          value={query}
          placeholder="Поиск по названию…"
          onChange={(e) => setQuery(e.target.value)}
          onFocus={() => results.length > 0 && setOpen(true)}
        />
        {open && results.length > 0 && (
          <div className="multipicker-results">
            {results
              .filter((r) => !value.includes(r.id))
              .map((r) => (
                <button type="button" className="multipicker-item" key={r.id} onClick={() => add(r)}>
                  {r.name} <span className="muted">#{r.id}</span>
                </button>
              ))}
          </div>
        )}
      </div>
      {loading && <span className="field-hint">поиск…</span>}
      {hint && <span className="field-hint">{hint}</span>}
    </div>
  );
}

// Хелпер: собрать поисковую функцию по read-действию API.
// fetcher(q) должен вернуть массив {id, name}.
export function makeSearch(fetcher) {
  return async (q) => {
    const items = await fetcher(q);
    return (items || [])
      .filter((x) => x && x.id)
      .map((x) => ({ id: Number(x.id), name: String(x.name || '#' + x.id) }));
  };
}
