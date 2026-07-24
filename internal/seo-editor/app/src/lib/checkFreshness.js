// Устаревание проверок (этап 8.4). Хеш контента (blocks + name + запросы)
// фиксируется в момент запуска TEXT.RU / каннибализации / перелинковки;
// при изменении контента результат помечается «устарел, текст изменился».

// FNV-1a: стабильный синхронный хеш, криптостойкость не нужна.
function fnv1a(str) {
  let hash = 0x811c9dc5;
  for (let i = 0; i < str.length; i++) {
    hash ^= str.charCodeAt(i);
    hash = Math.imul(hash, 0x01000193);
  }
  return (hash >>> 0).toString(16).padStart(8, '0');
}

export function contentHash(article) {
  return fnv1a(
    JSON.stringify({
      name: article.name || '',
      primary_query: article.primary_query || '',
      secondary_queries: article.secondary_queries || [],
      blocks: article.blocks || [],
    })
  );
}

// 'missing' — проверка не запускалась; 'fresh' — актуальна; 'stale' — устарела.
export function checkState(recordedHash, article) {
  if (!recordedHash) return 'missing';
  return recordedHash === contentHash(article) ? 'fresh' : 'stale';
}

// ── Персистенция активной проверки TEXT.RU (переживает перезагрузку) ──
const TEXTRU_KEY = 'temed_seo_textru_check_v2';

export function saveTextruSession(entry) {
  try {
    sessionStorage.setItem(TEXTRU_KEY, JSON.stringify(entry));
  } catch (_) {}
}

export function loadTextruSession() {
  try {
    const raw = sessionStorage.getItem(TEXTRU_KEY);
    if (!raw) return null;
    const data = JSON.parse(raw);
    return data && data.text_uid ? data : null;
  } catch (_) {
    return null;
  }
}

export function clearTextruSession() {
  try {
    sessionStorage.removeItem(TEXTRU_KEY);
  } catch (_) {}
}

export function bumpTextruAttempt() {
  const entry = loadTextruSession();
  if (!entry) return null;
  const next = { ...entry, attempt: (entry.attempt || 0) + 1 };
  saveTextruSession(next);
  return next;
}
