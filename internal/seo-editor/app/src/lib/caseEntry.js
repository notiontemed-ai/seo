// Кейсовый вход (этап 8.3): выбор темы из транскрибированного кейса
// предзаполняет шаг «Задача». Здесь — транслитерация названия в символьный
// код и эвристика типа статьи по fit/интенту темы.

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

// Эвристика типа статьи по названию темы и fit-описанию. Возвращает XML_ID
// варианта ARTICLE_TYPE только если он существует в справочнике — иначе ''.
const TYPE_RULES = [
  [/сравн|против|или лучше|что выбрать|vs\b/i, ['comparison']],
  [/диагност|мрт|кт\b|узи|рентген|анализ|обследован/i, ['diagnostics']],
  [/лечени|терапи|операц|реабилитац|восстановлен/i, ['treatment']],
  [/симптом|признак|болит|боль\b/i, ['symptoms', 'informational']],
];

export function guessArticleType(text, articleTypes) {
  const available = new Set((articleTypes || []).map((t) => t.xml_id));
  const haystack = String(text || '');
  for (const [re, candidates] of TYPE_RULES) {
    if (re.test(haystack)) {
      const found = candidates.find((c) => available.has(c));
      if (found) return found;
    }
  }
  return available.has('informational') ? 'informational' : '';
}

// Патч статьи при выборе темы кейса.
export function topicToArticlePatch(topic, caseData, articleTypes) {
  return {
    name: topic.title || '',
    primary_query: topic.primary_query || '',
    code: translitCode(topic.title || topic.primary_query || ''),
    article_type: guessArticleType((topic.title || '') + ' ' + (topic.fit || ''), articleTypes),
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
