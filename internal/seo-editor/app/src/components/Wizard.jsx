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

export default function Wizard() {
  const { step, setStep } = useStore();
  return (
    <nav className="wizard" aria-label="Шаги">
      {STEPS.map((s) => (
        <button
          key={s.n}
          className={'wizard-step' + (s.n === step ? ' active' : '') + (s.n < step ? ' done' : '')}
          onClick={() => setStep(s.n)}
        >
          <span className="wizard-num">{s.n}</span>
          <span className="wizard-label">{s.label}</span>
        </button>
      ))}
    </nav>
  );
}
