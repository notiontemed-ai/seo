import React from 'react';
import { useStore } from '../../store/useStore.js';
import { Field, TextInput, TextArea, Select } from '../../components/ui.jsx';

function enumOptions(list) {
  return (list || []).map((e) => ({ value: e.xml_id, label: e.value }));
}

export default function TaskStep() {
  const { article, patchArticle, dictionaries, structures, doctorList } = useStore();
  const dict = dictionaries || {};
  const sections = (dict.article_sections?.new || []).map((s) => ({
    value: String(s.id),
    label: '—'.repeat(Math.max(0, s.depth_level - 1)) + ' ' + s.name,
  }));
  const doctors = doctorList.map((d) => ({ value: String(d.id), label: d.name }));

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
    </div>
  );
}
