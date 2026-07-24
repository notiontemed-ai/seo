// Кейсовый вход (этап 8.3): выбор темы из транскрибированного кейса
// предзаполняет шаг «Задача». Здесь — транслитерация названия в символьный код.

const TRANSLIT = {
  а: 'a', б: 'b', в: 'v', г: 'g', д: 'd', е: 'e', ё: 'e', ж: 'zh', з: 'z',
  и: 'i', й: 'y', к: 'k', л: 'l', м: 'm', н: 'n', о: 'o', п: 'p', р: 'r',
  с: 's', т: 't', у: 'u', ф: 'f', х: 'h', ц: 'ts', ч: 'ch', ш: 'sh',
  щ: 'sch', ъ: '', ы: 'y', ь: '', э: 'e', ю: 'yu', я: 'ya',
};

// Черновик символьного кода из названия: транслитерация + kebab-case.
export function translitCode(name) {
  const lower = String(name || '').toLowerCase();
  let out = '';
  for (const ch of lower) {
    if (TRANSLIT[ch] !== undefined) out += TRANSLIT[ch];
    else if (/[a-z0-9]/.test(ch)) out += ch;
    else out += '-';
  }
  return out
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '')
    .slice(0, 100);
}

// Патч статьи при выборе темы кейса. Тип статьи удалён (ТЗ 7); тема приносит
// primary и secondary запросы (ТЗ 8).
export function topicToArticlePatch(topic, caseData) {
  const secondary = Array.isArray(topic.secondary_queries)
    ? topic.secondary_queries.map((s) => String(s).trim()).filter(Boolean)
    : [];
  return {
    name: topic.title || '',
    primary_query: topic.primary_query || '',
    secondary_queries: secondary,
    code: translitCode(topic.title || topic.primary_query || ''),
    case_transcript: caseData.transcript || '',
    case_summary: caseData.summary || '',
  };
}

// Блок case_study из расшифровки кейса.
export function caseStudyBlock(caseData) {
  return {
    type: 'case_study',
    patient_context: '',
    situation: caseData.transcript || '',
    actions: '',
    outcome: '',
  };
}
