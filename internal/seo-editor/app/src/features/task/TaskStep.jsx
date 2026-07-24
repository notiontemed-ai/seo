import React, { useMemo, useRef, useState } from 'react';
import { useStore } from '../../store/useStore.js';
import { api } from '../../api/client.js';
import { Field, TextInput, Button, Collapsible } from '../../components/ui.jsx';
import { Combobox } from '../../components/Combobox.jsx';
import { TagInput } from '../../components/TagInput.jsx';
import { MultiPicker, makeSearch } from '../../components/MultiPicker.jsx';
import { translitCode } from '../../lib/caseEntry.js';
import IntentStructure from './IntentStructure.jsx';
import TranscribePanel from '../transcribe/TranscribePanel.jsx';

function enumOptions(list) {
  return (list || []).map((e) => ({ value: e.xml_id, label: e.value }));
}

// Поисковые функции пикеров связей — существующие read-действия API.
const searchLegacyArticles = makeSearch(async (q) => (await api.articles({ q, source: 'legacy', limit: 20 })).data);
const searchNewArticles = makeSearch(async (q) => (await api.articles({ q, source: 'new', limit: 20 })).data);
const searchServices = makeSearch(async (q) => (await api.services({ q, limit: 20 })).data);
const searchClinics = makeSearch(async (q) => (await api.clinics({ q, limit: 20 })).data);

export default function TaskStep() {
  const { article, patchArticle, dictionaries, doctorList, articleList, serviceList, clinicList, setStep } = useStore();
  const dict = dictionaries || {};

  const caseRef = useRef(null);
  const intentRef = useRef(null);
  const queriesRef = useRef(null);
  const attrsRef = useRef(null);
  const codeTouched = useRef(false);

  const [flash, setFlash] = useState({});
  const [highlightIntent, setHighlightIntent] = useState(false);

  const sections = (dict.article_sections?.new || []).map((s) => ({
    value: String(s.id),
    label: '—'.repeat(Math.max(0, s.depth_level - 1)) + ' ' + s.name,
  }));
  const doctors = doctorList.map((d) => ({ value: String(d.id), label: d.name }));
  const legacyArticles = articleList.filter((a) => a.source === 'legacy');
  const newArticles = articleList.filter((a) => a.source === 'new');

  // Бриф пуст → кейсовый вход раскрыт по умолчанию (ТЗ 3.1).
  const briefEmpty = useMemo(
    () => !article.name && !article.primary_query && !(article.blocks || []).length && !article.case_summary,
    // eslint-disable-next-line react-hooks/exhaustive-deps
    []
  );

  const flashFields = (keys) => {
    const on = {};
    keys.forEach((k) => { on[k] = true; });
    setFlash(on);
    setTimeout(() => setFlash({}), 2500);
  };

  const onName = (name) => {
    const patch = { name };
    if (!codeTouched.current) patch.code = translitCode(name);
    patchArticle(patch);
  };
  const onCode = (code) => {
    codeTouched.current = true;
    patchArticle({ code });
  };

  const REFS = { intent: intentRef, structure: intentRef, primary: queriesRef, section: attrsRef, author: attrsRef };
  const scrollTo = (key) => {
    REFS[key]?.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    if ((key === 'intent' || key === 'structure')) {
      setHighlightIntent(true);
      setTimeout(() => setHighlightIntent(false), 1500);
    }
  };

  const checklist = [
    { key: 'intent', label: 'Интент', ok: !!article.search_intent },
    { key: 'structure', label: 'Структура', ok: !!article.structure_id },
    { key: 'primary', label: 'Основной запрос', ok: !!(article.primary_query || '').trim() },
    { key: 'section', label: 'Раздел', ok: !!article.section_id },
    { key: 'author', label: 'Автор', ok: !!article.author_id },
  ];

  const onGenerate = () => {
    if (!article.search_intent || !article.structure_id) {
      scrollTo('intent');
      return;
    }
    setStep(2);
  };

  const fx = (key) => (flash[key] ? ' flash' : '');

  return (
    <div className="step-body">
      <div className="task-layout">
        <div className="task-main">
          <h2 className="step-title">1. Задача</h2>

          {/* 1. Кейсовый вход (аудио) */}
          <section ref={caseRef} className="task-section">
            <Collapsible title="Кейсовый вход (аудио)" defaultOpen={briefEmpty}>
              <TranscribePanel embedded onApplied={flashFields} />
            </Collapsible>
          </section>

          {/* 2. Интент и структура */}
          <section ref={intentRef} className="task-section">
            <h3 className="subsection-title">Интент и структура</h3>
            <IntentStructure highlight={highlightIntent} />
          </section>

          {/* 3. Запросы */}
          <section ref={queriesRef} className="task-section">
            <h3 className="subsection-title">Запросы</h3>
            <Field label="Основной запрос (primary)">
              <TextInput className={'input' + fx('primary_query')} value={article.primary_query} onChange={(e) => patchArticle({ primary_query: e.target.value })} />
            </Field>
            <Field label="Дополнительные запросы (secondary)" hint="Enter добавляет запрос; вставка списком — построчно или через запятую">
              <div className={'tag-input-wrap' + fx('secondary_queries')}>
                <TagInput value={article.secondary_queries || []} onChange={(v) => patchArticle({ secondary_queries: v })} placeholder="Дополнительный запрос и Enter…" />
              </div>
            </Field>
          </section>

          {/* 4. Атрибуты */}
          <section ref={attrsRef} className="task-section">
            <h3 className="subsection-title">Атрибуты</h3>
            <div className="grid-2">
              <Field label="Название статьи">
                <TextInput className={'input' + fx('name')} value={article.name} onChange={(e) => onName(e.target.value)} />
              </Field>
              <Field label="Символьный код" hint="автотранслит из названия, можно править">
                <TextInput className={'input' + fx('code')} value={article.code} onChange={(e) => onCode(e.target.value)} />
              </Field>
            </div>

            <div className="grid-3">
              <Field label="Раздел (инфоблок 81)">
                <Combobox options={sections} value={article.section_id ? String(article.section_id) : ''} placeholder="— выберите раздел —" onChange={(v) => patchArticle({ section_id: Number(v) || 0 })} />
              </Field>
              <Field label="Регион">
                <Combobox options={enumOptions(dict.regions)} value={article.region} placeholder="— регион —" onChange={(v) => patchArticle({ region: v })} />
              </Field>
              <Field label="ALT главного изображения (871)">
                <TextInput value={article.featured_image_alt} onChange={(e) => patchArticle({ featured_image_alt: e.target.value })} />
              </Field>
            </div>

            <div className="grid-2">
              <Field label="Автор (инфоблок 65)">
                <Combobox options={doctors} value={article.author_id ? String(article.author_id) : ''} placeholder="— автор —" onChange={(v) => patchArticle({ author_id: Number(v) || 0 })} />
              </Field>
              <Field label="Медицинский редактор (инфоблок 65)">
                <Combobox options={doctors} value={article.medical_reviewer_id ? String(article.medical_reviewer_id) : ''} placeholder="— медредактор —" onChange={(v) => patchArticle({ medical_reviewer_id: Number(v) || 0 })} />
              </Field>
            </div>

            <div className="grid-2">
              <Field label="Дата мед. проверки (855)">
                <TextInput type="date" value={article.medical_reviewed_at} onChange={(e) => patchArticle({ medical_reviewed_at: e.target.value })} />
              </Field>
              <Field label="Дата обновления контента (856)">
                <TextInput type="date" value={article.content_updated_at} onChange={(e) => patchArticle({ content_updated_at: e.target.value })} />
              </Field>
            </div>

            <h4 className="subsection-title">Связанные материалы</h4>
            <div className="grid-2">
              <MultiPicker label="Статьи v2 (RELATED_ARTICLES_V2, инфоблок 81)" value={article.related_articles_v2} onChange={(v) => patchArticle({ related_articles_v2: v })} search={searchNewArticles} known={newArticles} />
              <MultiPicker label="Legacy-статьи (RELATED_ARTICLES, инфоблок 68)" value={article.related_articles} onChange={(v) => patchArticle({ related_articles: v })} search={searchLegacyArticles} known={legacyArticles} />
              <MultiPicker label="Услуги (RELATED_SERVICES, инфоблок 70)" value={article.related_services} onChange={(v) => patchArticle({ related_services: v })} search={searchServices} known={serviceList} />
              <MultiPicker label="Клиники (RELATED_CLINICS, инфоблок 10)" value={article.related_clinics} onChange={(v) => patchArticle({ related_clinics: v })} search={searchClinics} known={clinicList} />
            </div>
          </section>
        </div>

        <aside className="task-aside">
          <BriefSummary items={checklist} onScrollTo={scrollTo} onGenerate={onGenerate} />
        </aside>
      </div>
    </div>
  );
}

// Сводка брифа (ТЗ 9.1): закреплённый чек-лист готовности; клик по пункту
// скроллит к секции, «К генерации» активна при полном брифе.
function BriefSummary({ items, onScrollTo, onGenerate }) {
  const ready = items.every((i) => i.ok);
  return (
    <div className="brief-summary">
      <div className="brief-summary-title">Готовность к генерации</div>
      <ul className="brief-checklist">
        {items.map((i) => (
          <li key={i.key} className={i.ok ? 'ok' : 'todo'}>
            <button type="button" onClick={() => onScrollTo(i.key)}>
              <span className="brief-mark">{i.ok ? '✓' : '○'}</span> {i.label}
            </button>
          </li>
        ))}
      </ul>
      <Button variant="primary" disabled={!ready} onClick={onGenerate}>К генерации →</Button>
      {!ready && <div className="brief-hint muted">Заполните отмеченные пункты</div>}
    </div>
  );
}
