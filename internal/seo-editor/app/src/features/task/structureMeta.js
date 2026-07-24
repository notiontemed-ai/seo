// Метаданные интентов и хелперы отображения структур (ТЗ 4.2).
// Ключи интентов совпадают с полем intent в article_structures.json.

export const INTENTS = [
  {
    key: 'informational',
    title: 'Информационный',
    desc: 'Человек хочет разобраться в теме, симптоме, диагнозе или методе',
  },
  {
    key: 'commercial_informational',
    title: 'Коммерческо-информационный',
    desc: 'Изучает тему и одновременно выбирает лечение, врача или клинику',
  },
  {
    key: 'comparative',
    title: 'Сравнительный',
    desc: 'Сравнивает методы, варианты лечения, процедуры или подходы',
  },
];

export const INTENT_RU = Object.fromEntries(INTENTS.map((i) => [i.key, i.title]));

// Список блоков структуры в едином виде: [{ block, required, repeat }].
export function structureBlocks(config) {
  const raw = Array.isArray(config?.structure) ? config.structure : [];
  return raw
    .map((item) => {
      if (typeof item === 'string') return { block: item, required: false, repeat: false };
      if (!item || typeof item !== 'object') return null;
      return {
        block: String(item.block || ''),
        required: !!item.required,
        repeat: item.repeat ?? false,
      };
    })
    .filter((b) => b && b.block);
}

// «2-6» → «×2–6»; false/пусто → ''.
export function formatRepeat(repeat) {
  if (!repeat || repeat === false) return '';
  return '×' + String(repeat).replace(/-/g, '–');
}

export function structureVersion(config) {
  return String(config?.version || config?.v || '');
}
