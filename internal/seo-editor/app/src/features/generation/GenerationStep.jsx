import React, { useState } from 'react';
import { useStore } from '../../store/useStore.js';
import { api } from '../../api/client.js';
import { Button, Spinner, Field, TextArea, Collapsible, Notice } from '../../components/ui.jsx';

function buildBrief(article, structures) {
  const structure = structures.find((s) => s.id === article.structure_id) || null;
  return {
    name: article.name,
    code: article.code,
    primary_query: article.primary_query,
    secondary_queries: article.secondary_queries,
    search_intent: article.search_intent,
    article_type: article.article_type,
    region: article.region,
    preview_text: article.preview_text,
    structure_config: structure
      ? { id: structure.id, name: structure.name, recommended_blocks: structure.recommended_blocks || [], structure: structure.structure || [], forbidden: structure.forbidden || [] }
      : null,
  };
}

export default function GenerationStep() {
  const { article, structures, setBlocks, patchArticle, medQuestions, setMedQuestions } = useStore();
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [warnings, setWarnings] = useState([]);
  const [answers, setAnswers] = useState({});
  const [applying, setApplying] = useState(false);

  const brief = buildBrief(article, structures);

  const applyResult = (data) => {
    const content = data.article_content || (data.article && data.article.article_content);
    if (content && Array.isArray(content.blocks)) setBlocks(content.blocks);
    if (data.name) patchArticle({ name: data.name });
    if (data.code) patchArticle({ code: data.code });
    if (data.preview_text) patchArticle({ preview_text: data.preview_text });
    if (data.short_answer && !article.preview_text) patchArticle({ preview_text: data.short_answer });
    setWarnings(data.warnings || []);
    setMedQuestions(data.medical_review_questions || data.med_questions || []);
  };

  const generate = async () => {
    setLoading(true);
    setError('');
    try {
      const res = await api.generateArticle(brief);
      applyResult(res.data || res);
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  };

  const applyMed = async () => {
    setApplying(true);
    setError('');
    try {
      const res = await api.applyMedAnswers({
        article: { name: article.name, code: article.code, article_content: { schema_version: '2.0', blocks: article.blocks } },
        med_answers: medQuestions.map((q) => ({ id: q.id, answer: answers[q.id] || '' })),
      });
      applyResult(res.data || res);
    } catch (e) {
      setError(e.message);
    } finally {
      setApplying(false);
    }
  };

  return (
    <div className="step-body">
      <h2 className="step-title">2. Генерация</h2>
      <p className="muted">ИИ вернёт структурированные блоки (article_content v2). HTML собирается автоматически на этапе публикации.</p>

      <div className="row-actions">
        <Button variant="primary" onClick={generate} disabled={loading || !article.name}>
          {loading ? <Spinner label="Генерация…" /> : 'Сгенерировать черновик'}
        </Button>
        {article.blocks.length > 0 && <span className="muted">Блоков: {article.blocks.length}</span>}
      </div>

      {error && <Notice notice={{ type: 'error', text: error }} />}
      {warnings.length > 0 && (
        <Collapsible title={'Предупреждения генерации (' + warnings.length + ')'}>
          <ul className="warn-list">
            {warnings.map((w, i) => (
              <li key={i}>{typeof w === 'string' ? w : w.message || JSON.stringify(w)}</li>
            ))}
          </ul>
        </Collapsible>
      )}

      {medQuestions.length > 0 && (
        <div className="med-review">
          <h3>Вопросы медицинскому редактору ({medQuestions.length})</h3>
          {medQuestions.map((q) => (
            <div className="med-question" key={q.id}>
              <div className="med-q">
                <strong>{q.id}.</strong> {q.question}
                {q.fragment && <div className="med-fragment">«{q.fragment}»</div>}
              </div>
              <Field label="Ответ врача">
                <TextArea value={answers[q.id] || ''} onChange={(e) => setAnswers({ ...answers, [q.id]: e.target.value })} />
              </Field>
            </div>
          ))}
          <Button variant="primary" onClick={applyMed} disabled={applying}>
            {applying ? <Spinner label="Применение…" /> : 'Применить ответы врача'}
          </Button>
        </div>
      )}

      <Collapsible title="Задание для внешнего ассистента (написание)">
        <p className="muted">Формат результата — article_content v2. Вставьте JSON ниже и примените.</p>
        <TextArea rows={6} readOnly value={JSON.stringify({ task: 'write_article', brief }, null, 2)} />
        <PasteBack onApply={applyResult} />
      </Collapsible>
    </div>
  );
}

function PasteBack({ onApply }) {
  const [raw, setRaw] = useState('');
  const [err, setErr] = useState('');
  const apply = () => {
    try {
      const data = JSON.parse(raw);
      onApply(data.data || data);
      setErr('');
    } catch (e) {
      setErr('Некорректный JSON: ' + e.message);
    }
  };
  return (
    <div className="paste-back">
      <Field label="Результат внешнего ассистента (article_content v2 JSON)">
        <TextArea rows={5} value={raw} onChange={(e) => setRaw(e.target.value)} />
      </Field>
      {err && <Notice notice={{ type: 'error', text: err }} />}
      <Button onClick={apply} disabled={!raw.trim()}>Применить результат</Button>
    </div>
  );
}
