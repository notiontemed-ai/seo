// Обёртка над proxy.php. Все запросы идут в одном источнике (same-origin),
// сессия и токены — на сервере, в браузер не попадают.

const PROXY = 'proxy.php';

async function post(payload) {
  const res = await fetch(PROXY, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify(payload),
  });
  const text = await res.text();
  let data;
  try {
    data = text ? JSON.parse(text) : {};
  } catch (_) {
    throw new Error('Сервер вернул некорректный JSON: ' + text.slice(0, 200));
  }
  if (data && data.success === false) {
    const err = new Error(data.error || data.message || 'Ошибка запроса (HTTP ' + res.status + ')');
    err.data = data;
    throw err;
  }
  if (!res.ok) {
    const err = new Error('HTTP ' + res.status);
    err.data = data;
    throw err;
  }
  return data;
}

async function get(action, params = {}) {
  const query = new URLSearchParams({ action, ...params });
  const res = await fetch(PROXY + '?' + query.toString(), { credentials: 'same-origin' });
  const data = await res.json();
  if (data && data.success === false) {
    const err = new Error(data.error || 'Ошибка запроса');
    err.data = data;
    throw err;
  }
  return data;
}

export const api = {
  // ── Справочники (GET) ──
  bootstrap: () => get('bootstrap'),
  dictionaries: () => get('dictionaries'),
  structures: () => get('article_structures'),
  articles: (params) => get('articles', params),
  article: (id, source = 'all') => get('article', { id, source }),
  doctors: (params) => get('doctors', params),
  services: (params) => get('services', params),
  clinics: (params) => get('clinics', params),

  // ── ИИ / n8n (POST, {action, data}) ──
  generateArticle: (data) => post({ action: 'generate_article', data }),
  reviseArticle: (data) => post({ action: 'revise_article', data }),
  extractMedQuestions: (data) => post({ action: 'extract_med_questions', data }),
  applyMedAnswers: (data) => post({ action: 'apply_med_answers', data }),
  suggestAnchor: (data) => post({ action: 'suggest_anchor', data }),
  assistantChat: (data) => post({ action: 'assistant_chat', data }),
  transcribeCase: (payload) => post({ action: 'transcribe_case', data: payload }),
  startExternalUniqueness: (data) => post({ action: 'start_external_uniqueness', data }),
  getExternalUniqueness: (data) => post({ action: 'get_external_uniqueness', data }),

  // ── Черновики / n8n ──
  draftList: (payload = {}) => post({ action: 'draft_list', ...payload }),
  draftGet: (payload) => post({ action: 'draft_get', ...payload }),
  draftCreate: (payload) => post({ action: 'draft_create', ...payload }),
  draftSaveVersion: (payload) => post({ action: 'draft_save_version', ...payload }),
  draftRestoreVersion: (payload) => post({ action: 'draft_restore_version', ...payload }),
  draftArchive: (payload) => post({ action: 'draft_archive', ...payload }),

  // ── Нативные PHP-проверки / запись (POST) ──
  cannibalization: (payload) => post({ action: 'cannibalization_check', ...payload }),
  linking: (payload) => post({ action: 'linking_candidates', ...payload }),
  createDraft: (payload) => post({ action: 'create_or_update_draft', ...payload }),
};
