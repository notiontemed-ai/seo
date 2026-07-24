// Библиотека блоков article_content v2 для редактора. Зеркалит серверный
// каталог (ArticleContent.php). Каждый блок: label, category, medical и список
// полей с типом редактора. Дополняется динамически из dictionaries.content_blocks.

export const BLOCK_LIBRARY = {
  h2: { label: 'Заголовок H2', category: 'base', medical: false, fields: [{ key: 'text', kind: 'text' }] },
  h3: { label: 'Заголовок H3', category: 'base', medical: false, fields: [{ key: 'text', kind: 'text' }] },
  p: { label: 'Абзац', category: 'base', medical: false, fields: [{ key: 'text', kind: 'textarea' }] },
  list: {
    label: 'Список', category: 'base', medical: false,
    fields: [
      { key: 'ordered', kind: 'checkbox', label: 'Нумерованный' },
      { key: 'items', kind: 'stringlist', label: 'Пункты' },
    ],
  },
  table: {
    label: 'Таблица', category: 'base', medical: false,
    fields: [
      { key: 'header', kind: 'stringlist', label: 'Заголовки столбцов' },
      { key: 'rows', kind: 'table', label: 'Строки' },
    ],
  },
  short_answer: { label: 'Краткий ответ', category: 'semantic', medical: false, fields: [{ key: 'text', kind: 'textarea' }] },
  expert_opinion: {
    label: 'Мнение эксперта', category: 'semantic', medical: true,
    fields: [
      { key: 'doctor_id', kind: 'doctor', label: 'Врач (инфоблок 65)' },
      { key: 'quote', kind: 'textarea', label: 'Цитата (не перефразируется)' },
    ],
  },
  case_study: {
    label: 'Случай из практики', category: 'semantic', medical: true,
    fields: [
      { key: 'patient_context', kind: 'textarea', label: 'Контекст пациента' },
      { key: 'situation', kind: 'textarea', label: 'Ситуация' },
      { key: 'actions', kind: 'textarea', label: 'Что сделали' },
      { key: 'outcome', kind: 'textarea', label: 'Результат' },
    ],
  },
  symptoms: {
    label: 'Симптомы', category: 'semantic', medical: true,
    fields: [{ key: 'items', kind: 'items', label: 'Симптомы', item: [
      { key: 'text', kind: 'text', label: 'Симптом' },
      { key: 'red_flag', kind: 'checkbox', label: 'Тревожный' },
    ] }],
  },
  when_to_see_doctor: { label: 'Когда к врачу', category: 'semantic', medical: false, fields: [{ key: 'items', kind: 'stringlist', label: 'Ситуации' }] },
  causes: { label: 'Причины и факторы', category: 'semantic', medical: false, fields: [{ key: 'items', kind: 'stringlist', label: 'Причины' }] },
  diagnostics: {
    label: 'Диагностика', category: 'semantic', medical: false,
    fields: [{ key: 'items', kind: 'items', label: 'Методы', item: [
      { key: 'method', kind: 'text', label: 'Метод' },
      { key: 'what_shows', kind: 'text', label: 'Что показывает' },
      { key: 'related_service_id', kind: 'service', label: 'Услуга (инфоблок 70)' },
    ] }],
  },
  treatment_methods: {
    label: 'Методы лечения', category: 'semantic', medical: false,
    fields: [{ key: 'items', kind: 'items', label: 'Методы', item: [
      { key: 'method', kind: 'text', label: 'Метод' },
      { key: 'what_shows', kind: 'text', label: 'Описание' },
      { key: 'related_service_id', kind: 'service', label: 'Услуга (инфоблок 70)' },
    ] }],
  },
  faq: {
    label: 'FAQ', category: 'semantic', medical: false,
    fields: [{ key: 'items', kind: 'items', label: 'Вопросы', item: [
      { key: 'q', kind: 'text', label: 'Вопрос' },
      { key: 'a', kind: 'textarea', label: 'Ответ' },
    ] }],
  },
  comparison_table: {
    label: 'Таблица сравнения', category: 'semantic', medical: false,
    fields: [
      { key: 'criteria', kind: 'stringlist', label: 'Критерии (строки)' },
      { key: 'options', kind: 'items', label: 'Варианты (столбцы)', item: [
        { key: 'name', kind: 'text', label: 'Название' },
        { key: 'values', kind: 'stringlist', label: 'Значения по критериям' },
      ] },
    ],
  },
  myth_fact: {
    label: 'Мифы и факты', category: 'semantic', medical: false,
    fields: [{ key: 'items', kind: 'items', label: 'Пары', item: [
      { key: 'myth', kind: 'text', label: 'Миф' },
      { key: 'fact', kind: 'textarea', label: 'Факт' },
    ] }],
  },
  stats_highlight: {
    label: 'Статистика', category: 'semantic', medical: true,
    fields: [
      { key: 'value', kind: 'text', label: 'Значение' },
      { key: 'description', kind: 'textarea', label: 'Описание' },
      { key: 'source_index', kind: 'number', label: 'Индекс источника (обязателен)' },
    ],
  },
  appointment_form: { label: 'Форма записи', category: 'system', medical: false, fields: [] },
  sources: { label: 'Источники', category: 'semantic', medical: false, fields: [{ key: 'items', kind: 'stringlist', label: 'Источники' }] },
};

export function blockMeta(type) {
  return BLOCK_LIBRARY[type] || { label: type, category: 'system', medical: false, fields: [] };
}

export function defaultBlock(type) {
  const meta = blockMeta(type);
  const block = { type };
  for (const field of meta.fields) {
    if (field.kind === 'checkbox') block[field.key] = false;
    else if (field.kind === 'number') block[field.key] = 0;
    else if (field.kind === 'stringlist') block[field.key] = [''];
    else if (field.kind === 'items') block[field.key] = [emptyItem(field.item)];
    else if (field.kind === 'table') block[field.key] = [['', '']];
    else block[field.key] = '';
  }
  return block;
}

export function emptyItem(itemFields) {
  const item = {};
  for (const f of itemFields) {
    if (f.kind === 'checkbox') item[f.key] = false;
    else if (f.kind === 'stringlist') item[f.key] = [''];
    else item[f.key] = '';
  }
  return item;
}

export const CATEGORIES = [
  { key: 'base', label: 'Базовые' },
  { key: 'semantic', label: 'Смысловые' },
  { key: 'system', label: 'Служебные' },
];
