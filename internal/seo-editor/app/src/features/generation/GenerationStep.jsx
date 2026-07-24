import React, { useState } from 'react';
import { useStore } from '../../store/useStore.js';
import { api } from '../../api/client.js';
import { Button, Spinner, Field, TextArea, Collapsible, Notice } from '../../components/ui.jsx';
import { BLOCK_LIBRARY, validateArticleContent } from '../../lib/articleContent.js';

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

// Пакет задания для внешнего ассистента (этап 8.2, без хеш-механики):
// бриф + структура + разрешённые блоки + требование article_content v2.
export function buildExternalTask(article, structures) {
  const brief = buildBrief(article, structures);
  const recommended = brief.structure_config?.recommended_blocks || [];
  const allowed_blocks = recommended.length
    ? [...new Set(['h2', 'h3', 'p', 'list', 'table', 'sources', ...recommended])].filter((t) => BLOCK_LIBRARY[t])
    : Object.keys(BLOCK_LIBRARY);
  return {
    task: 'write_article',
    output_contract: {
      format: 'article_content_v2',
      schema_version: '2.0',
      instructions:
        'Ответ — строго JSON вида {"article_content":{"schema_version":"2.0","blocks":[…]}} '
        + 'без markdown-обёртки и пояснений вокруг. Разрешены только типы блоков из allowed_blocks. '
        + 'Обязателен хотя бы один блок h2 и один блок p. В полях text допускается только ограниченный '
        + 'inline-markdown: **жирный**, *курсив*, [ссылка](url). HTML внутри блоков запрещён.',
      allowed_blocks,
    },
    brief,
  };
}

export default function GenerationStep() {
  const { article, structures, setBlocks, patchArticle, medQuestions, setMedQuestions, doctorList, setNotice } = useStore();
  const [mode, setMode] = useState('builtin'); // 'builtin' | 'external'
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [warnings, setWarnings] = useState([]);
  const [answers, setAnswers] = useState({});
  const [applying, setApplying] = useState(false);
  const [externalTask, setExternalTask] = useState('');
  const [externalResult, setExternalResult] = useState('');

  const applyResult = (data) => {
    const content = data.article_content || (data.article && data.article.article_content);
    const extraWarnings = [];
    if (content && Array.isArray(content.blocks)) {
      const checked = validateArticleContent(content, { doctorIds: doctorList.map((d) => d.id) });
      if (!checked.valid) {
        setError('Результат не прошёл валидацию: ' + checked.errors.join('; '));
        setWarnings([...(data.warnings || []), ...checked.warnings]);
        return false;
      }
      setBlocks(checked.blocks);
      extraWarnings.push(...checked.warnings);
    }
    if (data.name) patchArticle({ name: data.name });
    if (data.code) patchArticle({ code: data.code });
    if (data.preview_text) patchArticle({ preview_text: data.preview_text });
    if (data.short_answer && !article.short_answer) patchArticle({ short_answer: data.short_answer });
    setWarnings([...(data.warnings || []), ...extraWarnings]);
    setMedQuestions(data.medical_review_questions || data.med_questions || []);
    return true;
  };

  const generate = async () => {
    setLoading(true);
    setError('');
    try {
      const res = await api.generateArticle(buildBrief(article, structures));
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

  const makeExternalTask = async () => {
    const text = JSON.stringify(buildExternalTask(article, structures), null, 2);
    setExternalTask(text);
    try {
      await navigator.clipboard.writeText(text);
      setNotice({ type: 'success', text: 'Задание скопировано в буфер обмена' });
    } catch (_) {
      setNotice({ type: 'warn', text: 'Скопируйте задание из поля вручную' });
    }
  };

  const applyExternalResult = () => {
    setError('');
    let data;
    try {
      data = JSON.parse(externalResult);
    } catch (e) {
      setError('Некорректный JSON: ' + e.message);
      return;
    }
    const ok = applyResult(data.data || data);
    if (ok) setNotice({ type: 'success', text: 'Результат внешнего ассистента загружен в редактор' });
  };

  return (
    <div className="step-body">
      <h2 className="step-title">2. Генерация</h2>
      <p className="muted">ИИ вернёт структурированные блоки (article_content v2). HTML собирается автоматически на этапе публикации.</p>

      <div className="mode-switch" role="tablist">
        <Button variant={mode === 'builtin' ? 'primary' : 'ghost'} onClick={() => setMode('builtin')}>
          Встроенная генерация
        </Button>
        <Button variant={mode === 'external' ? 'primary' : 'ghost'} onClick={() => setMode('external')}>
          Внешний ассистент
        </Button>
      </div>

      {mode === 'builtin' && (
        <div className="row-actions">
          <Button variant="primary" onClick={generate} disabled={loading || !article.name}>
            {loading ? <Spinner label="Генерация…" /> : 'Сгенерировать черновик'}
          </Button>
          {article.blocks.length > 0 && <span className="muted">Блоков: {article.blocks.length}</span>}
        </div>
      )}

      {mode === 'external' && (
        <div className="external-assistant">
          <p className="muted">
            Сформируйте задание, передайте его внешнему ассистенту, затем вставьте его ответ
            (строго article_content v2 JSON) в поле результата.
          </p>
          <Button variant="primary" onClick={makeExternalTask} disabled={!article.name}>
            Сформировать задание и скопировать
          </Button>
          {externalTask && (
            <Field label="Задание для внешнего ассистента">
              <TextArea rows={8} readOnly value={externalTask} />
            </Field>
          )}
          <Field label="Результат внешнего ассистента (article_content v2 JSON)">
            <TextArea rows={6} value={externalResult} onChange={(e) => setExternalResult(e.target.value)} />
          </Field>
          <Button onClick={applyExternalResult} disabled={!externalResult.trim()}>
            Проверить и применить результат
          </Button>
        </div>
      )}

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
    </div>
  );
}
