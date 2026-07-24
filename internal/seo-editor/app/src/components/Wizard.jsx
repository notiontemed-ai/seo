import React from 'react';
import { useStore } from '../store/useStore.js';

const STEPS = [
  { n: 1, label: 'Задача' },
  { n: 2, label: 'Генерация' },
  { n: 3, label: 'Текст' },
  { n: 4, label: 'Проверки' },
  { n: 5, label: 'Перелинковка' },
  { n: 6, label: 'Публикация' },
];

// Валидность данных шага → галочка выполненности (ТЗ 9.7). Переход по клику
// сохраняется; отметка не зависит от текущего положения в визарде.
function stepValidity(s) {
  const a = s.article || {};
  const blocks = (a.blocks || []).length;
  return {
    1: !!(a.search_intent && a.structure_id && (a.primary_query || '').trim() && a.section_id && a.author_id),
    2: blocks > 0,
    3: blocks > 0,
    4: !!s.textru || !!s.cannibalization,
    5: (s.linkingSelected || []).length > 0,
    6: false,
  };
}

export default function Wizard() {
  const store = useStore();
  const { step, setStep } = store;
  const valid = stepValidity(store);
  return (
    <nav className="wizard" aria-label="Шаги">
      {STEPS.map((s) => (
        <button
          key={s.n}
          className={'wizard-step' + (s.n === step ? ' active' : '') + (valid[s.n] ? ' done' : '')}
          onClick={() => setStep(s.n)}
        >
          <span className="wizard-num">{valid[s.n] && s.n !== step ? '✓' : s.n}</span>
          <span className="wizard-label">{s.label}</span>
        </button>
      ))}
    </nav>
  );
}
