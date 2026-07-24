import React from 'react';
import { useStore } from '../../store/useStore.js';
import { api } from '../../api/client.js';
import { Field, TextInput, TextArea, Select } from '../../components/ui.jsx';
import { MultiPicker, makeSearch } from '../../components/MultiPicker.jsx';

function enumOptions(list) {
  return (list || []).map((e) => ({ value: e.xml_id, label: e.value }));
}

// Поисковые функции пикеров связей — существующие read-действия API.
const searchLegacyArticles = makeSearch(async (q) => (await api.articles({ q, source: 'legacy', limit: 20 })).data);
const searchNewArticles = makeSearch(async (q) => (await api.articles({ q, source: 'new', limit: 20 })).data);
const searchServices = makeSearch(async (q) => (await api.services({ q, limit: 20 })).data);
const searchClinics = makeSearch(async (q) => (await api.clinics({ q, limit: 20 })).data);

export default function TaskStep() {
  const { article, patchArticle, dictionaries, structures, doctorList, articleList, serviceList, clinicList } = useStore();
  const dict = dictionaries || {};
  const sections = (dict.article_sections?.new || []).map((s) => ({
    value: String(s.id),
    label: '—'.repeat(Math.max(0, s.depth_level - 1)) + ' ' + s.name,
  }));
  const doctors = doctorList.map((d) => ({ value: String(d.id), label: d.name }));
  const legacyArticles = articleList.filter((a) => a.source === 'legacy');
  const newArticles = articleList.filter((a) => a.source === 'new');

  return (
    <div className="step-body">
      <h2 className="step-title">1. Задача</h2>
      <div className="grid-2">
        <Field label="Название статьи">
          <TextInput value={article.name} onChange={(e) => patchArticle({ name: e.target.value })} />
        </Field>
        <Field label="Символьный код" hint="латиница, цифры, _ и -">
          <TextInput value={article.code} onChange={(e) => patchArticle({ code: e.target.value })} />
        </Field>
      </div>

      <div className="grid-2">
        <Field label="Раздел (инфоблок 81)">
          <Select options={sections} value={article.section_id ? String(article.section_id) : ''} placeholder="— выберите раздел —" onChange={(v) => patchArticle({ section_id: Number(v) })} />
        </Field>
        <Field label="Структура статьи">
          <Select
            options={structures.map((s) => ({ value: s.id, label: s.name }))}
            value={article.structure_id}
            placeholder="— выберите структуру —"
            onChange={(v) => patchArticle({ structure_id: v })}
          />
        </Field>
      </div>

      <Field label="Основной запрос (primary)">
        <TextInput value={article.primary_query} onChange={(e) => patchArticle({ primary_query: e.target.value })} />
      </Field>
      <Field label="Дополнительные запросы (secondary)" hint="по одному в строке">
        <TextArea
          value={(article.secondary_queries || []).join('\n')}
          onChange={(e) => patchArticle({ secondary_queries: e.target.value.split('\n').map((x) => x.trim()).filter(Boolean) })}
        />
      </Field>

      <div className="grid-3">
        <Field label="Поисковый интент">
          <Select options={enumOptions(dict.search_intents)} value={article.search_intent} placeholder="—" onChange={(v) => patchArticle({ search_intent: v })} />
        </Field>
        <Field label="Тип статьи">
          <Select options={enumOptions(dict.article_types)} value={article.article_type} placeholder="—" onChange={(v) => patchArticle({ article_type: v })} />
        </Field>
        <Field label="Регион">
          <Select options={enumOptions(dict.regions)} value={article.region} placeholder="—" onChange={(v) => patchArticle({ region: v })} />
        </Field>
      </div>

      <div className="grid-2">
        <Field label="Автор (инфоблок 65)">
          <Select options={doctors} value={article.author_id ? String(article.author_id) : ''} placeholder="—" onChange={(v) => patchArticle({ author_id: Number(v) })} />
        </Field>
        <Field label="Медицинский редактор (инфоблок 65)">
          <Select options={doctors} value={article.medical_reviewer_id ? String(article.medical_reviewer_id) : ''} placeholder="—" onChange={(v) => patchArticle({ medical_reviewer_id: Number(v) })} />
        </Field>
      </div>

      <Field label="Анонс (preview_text)">
        <TextArea value={article.preview_text} onChange={(e) => patchArticle({ preview_text: e.target.value })} />
      </Field>

      <Field label="Краткий ответ (SHORT_ANSWER)" hint="отдельное свойство 851; выводится плашкой в начале статьи">
        <TextArea value={article.short_answer} onChange={(e) => patchArticle({ short_answer: e.target.value })} />
      </Field>

      <h3 className="subsection-title">SEO-мета</h3>
      <div className="grid-2">
        <Field label="SEO title">
          <TextInput value={article.seo_title} onChange={(e) => patchArticle({ seo_title: e.target.value })} />
        </Field>
        <Field label="Meta description">
          <TextArea rows={2} value={article.meta_description} onChange={(e) => patchArticle({ meta_description: e.target.value })} />
        </Field>
      </div>

      <div className="grid-3">
        <Field label="ALT главного изображения (871)">
          <TextInput value={article.featured_image_alt} onChange={(e) => patchArticle({ featured_image_alt: e.target.value })} />
        </Field>
        <Field label="Дата мед. проверки (855)">
          <TextInput type="date" value={article.medical_reviewed_at} onChange={(e) => patchArticle({ medical_reviewed_at: e.target.value })} />
        </Field>
        <Field label="Дата обновления контента (856)">
          <TextInput type="date" value={article.content_updated_at} onChange={(e) => patchArticle({ content_updated_at: e.target.value })} />
        </Field>
      </div>

      <h3 className="subsection-title">Связанные материалы</h3>
      <div className="grid-2">
        <MultiPicker
          label="Статьи v2 (RELATED_ARTICLES_V2, инфоблок 81)"
          value={article.related_articles_v2}
          onChange={(v) => patchArticle({ related_articles_v2: v })}
          search={searchNewArticles}
          known={newArticles}
        />
        <MultiPicker
          label="Legacy-статьи (RELATED_ARTICLES, инфоблок 68)"
          value={article.related_articles}
          onChange={(v) => patchArticle({ related_articles: v })}
          search={searchLegacyArticles}
          known={legacyArticles}
        />
        <MultiPicker
          label="Услуги (RELATED_SERVICES, инфоблок 70)"
          value={article.related_services}
          onChange={(v) => patchArticle({ related_services: v })}
          search={searchServices}
          known={serviceList}
        />
        <MultiPicker
          label="Клиники (RELATED_CLINICS, инфоблок 10)"
          value={article.related_clinics}
          onChange={(v) => patchArticle({ related_clinics: v })}
          search={searchClinics}
          known={clinicList}
        />
      </div>
    </div>
  );
}
