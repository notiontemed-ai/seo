import { create } from 'zustand';
import { defaultBlock } from '../lib/articleContent.js';

const STORAGE_KEY = 'temed_seo_editor_state_v2';

function today() {
  return new Date().toISOString().slice(0, 10);
}

function emptyArticle() {
  return {
    name: '',
    code: '',
    source: 'new',
    element_id: 0,
    section_id: 0,
    preview_text: '',
    primary_query: '',
    secondary_queries: [],
    search_intent: '',
    article_type: '',
    region: '',
    author_id: 0,
    medical_reviewer_id: 0,
    structure_id: '',
    show_form: 'N',
    form_id: '',
    form_button_text: '',
    // SEO-мета элемента (IPROPERTY) и восстановленные свойства (этап 8.1).
    seo_title: '',
    meta_description: '',
    short_answer: '',
    featured_image_alt: '',
    related_articles: [],
    related_articles_v2: [],
    related_services: [],
    related_clinics: [],
    medical_reviewed_at: today(),
    content_updated_at: today(),
    // Кейсовый вход (этап 8.3): расшифровка и аннотация хранятся со статьёй.
    case_transcript: '',
    case_summary: '',
    blocks: [],
  };
}

export const useStore = create((set, get) => ({
  // ── Загрузка справочников ──
  ready: false,
  bootError: '',
  dictionaries: null,
  structures: [],
  doctorMap: {},
  serviceMap: {},
  doctorList: [],
  serviceList: [],
  clinicList: [],
  articleList: [],

  // ── Навигация / UI ──
  step: 1,
  debug: false,
  panel: null, // 'assistant' | 'drafts' | 'transcribe' | null
  notice: null, // {type, text}

  // ── Данные статьи ──
  article: emptyArticle(),
  dirty: false,

  // ── Результаты проверок / генерации ──
  medQuestions: [],
  cannibalization: null,
  textru: null,
  linking: null,
  linkingSelected: [],

  setStep: (step) => set({ step }),
  setDebug: (debug) => set({ debug }),
  setPanel: (panel) => set((s) => ({ panel: s.panel === panel ? null : panel })),
  setNotice: (notice) => set({ notice }),

  patchArticle: (patch) => set((s) => ({ article: { ...s.article, ...patch }, dirty: true })),

  setBlocks: (blocks) => set((s) => ({ article: { ...s.article, blocks }, dirty: true })),
  addBlock: (type, atIndex) =>
    set((s) => {
      const blocks = s.article.blocks.slice();
      const block = defaultBlock(type);
      if (atIndex == null || atIndex < 0) blocks.push(block);
      else blocks.splice(atIndex, 0, block);
      return { article: { ...s.article, blocks }, dirty: true };
    }),
  updateBlock: (index, patch) =>
    set((s) => {
      const blocks = s.article.blocks.slice();
      blocks[index] = { ...blocks[index], ...patch };
      return { article: { ...s.article, blocks }, dirty: true };
    }),
  removeBlock: (index) =>
    set((s) => {
      const blocks = s.article.blocks.slice();
      blocks.splice(index, 1);
      return { article: { ...s.article, blocks }, dirty: true };
    }),
  moveBlock: (index, dir) =>
    set((s) => {
      const blocks = s.article.blocks.slice();
      const to = index + dir;
      if (to < 0 || to >= blocks.length) return {};
      const [b] = blocks.splice(index, 1);
      blocks.splice(to, 0, b);
      return { article: { ...s.article, blocks }, dirty: true };
    }),
  changeBlockType: (index, type) =>
    set((s) => {
      const blocks = s.article.blocks.slice();
      const old = blocks[index];
      const next = defaultBlock(type);
      // Переносим текст, если у обоих есть поле text.
      if (old && typeof old.text === 'string' && 'text' in next) next.text = old.text;
      blocks[index] = next;
      return { article: { ...s.article, blocks }, dirty: true };
    }),

  setMedQuestions: (medQuestions) => set({ medQuestions }),
  setCannibalization: (cannibalization) => set({ cannibalization }),
  setTextru: (textru) => set({ textru }),
  setLinking: (linking) => set({ linking, linkingSelected: [] }),
  toggleLinkingSelected: (id) =>
    set((s) => ({
      linkingSelected: s.linkingSelected.includes(id)
        ? s.linkingSelected.filter((x) => x !== id)
        : [...s.linkingSelected, id],
    })),

  loadArticleContent: (data) =>
    set((s) => {
      const blocks = (data.article_content && Array.isArray(data.article_content.blocks))
        ? data.article_content.blocks
        : [];
      return {
        article: {
          ...s.article,
          name: data.name || s.article.name,
          code: data.code || s.article.code,
          element_id: data.id || s.article.element_id,
          source: data.source || s.article.source,
          preview_text: data.preview_text || '',
          blocks,
        },
        dirty: false,
      };
    }),

  resetArticle: () => set({ article: emptyArticle(), medQuestions: [], cannibalization: null, textru: null, linking: null, dirty: false }),

  // Восстановление снапшота черновика: переносим только известные поля статьи.
  applySnapshot: (data) =>
    set((s) => {
      const base = emptyArticle();
      const patch = {};
      for (const key of Object.keys(base)) {
        if (data[key] !== undefined) patch[key] = data[key];
      }
      const blocks = data.article_content && Array.isArray(data.article_content.blocks)
        ? data.article_content.blocks
        : null;
      return {
        article: { ...s.article, ...patch, ...(blocks ? { blocks } : {}) },
        dirty: false,
      };
    }),

  setBootstrap: (payload) => {
    const dict = payload.dictionaries || {};
    const doctorList = (payload.doctors || []).map((d) => ({
      id: d.id,
      name: d.name,
      position: (((d.properties || {}).POSITION || {}).values || [{}])[0]?.value || '',
      url: d.absolute_url || d.url || '',
      photo_url: d.preview_picture?.absolute_url || '',
    }));
    const serviceList = ((payload.prices || {}).items || payload.prices || []).map((p) => ({
      id: p.id,
      name: p.name,
      url: p.absolute_url || p.url || '',
    }));
    const clinicList = ((payload.clinics || {}).items || payload.clinics || []).map((c) => ({
      id: c.id,
      name: c.name,
    }));
    const articleList = ((payload.articles || {}).items || payload.articles || []).map((a) => ({
      id: a.id,
      name: a.name,
      source: a.source || '',
    }));
    const doctorMap = Object.fromEntries(doctorList.map((d) => [d.id, d]));
    const serviceMap = Object.fromEntries(serviceList.map((p) => [p.id, p]));
    set({
      ready: true,
      dictionaries: dict,
      doctorList,
      serviceList,
      clinicList,
      articleList,
      doctorMap,
      serviceMap,
    });
  },
  setStructures: (structures) => set({ structures }),
  setBootError: (bootError) => set({ bootError }),

  persist: () => {
    try {
      const { article, step } = get();
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ article, step }));
    } catch (_) {}
  },
  restore: () => {
    try {
      const raw = sessionStorage.getItem(STORAGE_KEY);
      if (!raw) return;
      const data = JSON.parse(raw);
      if (data && data.article) set({ article: { ...emptyArticle(), ...data.article }, step: data.step || 1 });
    } catch (_) {}
  },
}));
