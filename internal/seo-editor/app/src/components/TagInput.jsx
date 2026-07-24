import React, { useState } from 'react';

// Чипы-теги (ТЗ 9.2): ввод по Enter, удаление крестиком, вставка списком из
// буфера построчно или через запятую. value — массив строк.
export function TagInput({ value = [], onChange, placeholder }) {
  const [draft, setDraft] = useState('');

  const addMany = (raw) => {
    const parts = String(raw)
      .split(/[\n,]+/)
      .map((x) => x.trim())
      .filter(Boolean);
    if (!parts.length) return;
    const next = [...value];
    for (const p of parts) {
      if (!next.includes(p)) next.push(p);
    }
    onChange(next);
  };

  const commitDraft = () => {
    if (draft.trim()) addMany(draft);
    setDraft('');
  };

  const onKeyDown = (e) => {
    if (e.key === 'Enter' || e.key === ',') {
      e.preventDefault();
      commitDraft();
    } else if (e.key === 'Backspace' && !draft && value.length) {
      onChange(value.slice(0, -1));
    }
  };

  const onPaste = (e) => {
    const text = e.clipboardData.getData('text');
    if (/[\n,]/.test(text)) {
      e.preventDefault();
      addMany(text);
      setDraft('');
    }
  };

  const remove = (tag) => onChange(value.filter((t) => t !== tag));

  return (
    <div className="tag-input">
      {value.length > 0 && (
        <div className="chips">
          {value.map((tag) => (
            <span className="chip" key={tag}>
              {tag}
              <button type="button" className="chip-remove" onClick={() => remove(tag)} aria-label="Убрать">
                ×
              </button>
            </span>
          ))}
        </div>
      )}
      <input
        className="input"
        value={draft}
        placeholder={placeholder || 'Запрос и Enter…'}
        onChange={(e) => setDraft(e.target.value)}
        onKeyDown={onKeyDown}
        onPaste={onPaste}
        onBlur={commitDraft}
      />
    </div>
  );
}
