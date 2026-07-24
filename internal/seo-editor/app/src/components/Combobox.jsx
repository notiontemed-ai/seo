import React, { useEffect, useMemo, useRef, useState } from 'react';

// Комбобокс с поиском (ТЗ 9.5): единичный выбор из справочника с клиентской
// фильтрацией. Замена длинных <select> для раздела, автора, медредактора.
// options: [{ value, label }]; value — текущее значение (строка).
export function Combobox({ options, value, onChange, placeholder = '— выберите —' }) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const rootRef = useRef(null);

  const selected = options.find((o) => String(o.value) === String(value)) || null;

  useEffect(() => {
    const onDocClick = (e) => {
      if (rootRef.current && !rootRef.current.contains(e.target)) {
        setOpen(false);
        setQuery('');
      }
    };
    document.addEventListener('mousedown', onDocClick);
    return () => document.removeEventListener('mousedown', onDocClick);
  }, []);

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return options;
    return options.filter((o) => o.label.toLowerCase().includes(q));
  }, [options, query]);

  const pick = (v) => {
    onChange(v);
    setOpen(false);
    setQuery('');
  };

  return (
    <div className="combobox" ref={rootRef}>
      {open ? (
        <input
          className="input"
          autoFocus
          value={query}
          placeholder={selected ? selected.label : placeholder}
          onChange={(e) => setQuery(e.target.value)}
        />
      ) : (
        <button type="button" className="input combobox-value" onClick={() => setOpen(true)}>
          <span className={selected ? '' : 'muted'}>{selected ? selected.label : placeholder}</span>
          <span className="combobox-caret">▾</span>
        </button>
      )}
      {open && (
        <div className="combobox-results">
          {value ? (
            <button type="button" className="combobox-item muted" onMouseDown={(e) => e.preventDefault()} onClick={() => pick('')}>
              — очистить —
            </button>
          ) : null}
          {filtered.map((o) => (
            <button
              type="button"
              key={o.value}
              className={'combobox-item' + (String(o.value) === String(value) ? ' on' : '')}
              onMouseDown={(e) => e.preventDefault()}
              onClick={() => pick(o.value)}
            >
              {o.label}
            </button>
          ))}
          {!filtered.length && <div className="combobox-empty">Ничего не найдено</div>}
        </div>
      )}
    </div>
  );
}
