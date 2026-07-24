import React, { useState } from 'react';
import { useStore } from '../../store/useStore.js';
import { api } from '../../api/client.js';
import { Button, Spinner, Tag, Notice, Collapsible } from '../../components/ui.jsx';

const RISK_TONE = { high: 'danger', medium: 'warn', low: 'neutral' };

export default function ChecksStep() {
  const { article, cannibalization, setCannibalization, textru, setTextru, debug } = useStore();
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [textruLoading, setTextruLoading] = useState(false);

  const articlePayload = {
    name: article.name,
    primary_query: article.primary_query,
    secondary_queries: article.secondary_queries,
    search_intent: article.search_intent,
    article_content: { schema_version: '2.0', blocks: article.blocks },
  };

  const runCannibalization = async () => {
    setLoading(true);
    setError('');
    try {
      const exclude = article.element_id ? { source: article.source, element_id: article.element_id } : undefined;
      const res = await api.cannibalization({ article: articlePayload, exclude, max_matches: 50 });
      setCannibalization(res.data || res);
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  };

  const runTextru = async () => {
    setTextruLoading(true);
    setError('');
    try {
      const res = await api.startExternalUniqueness({ article: articlePayload });
      setTextru(res.data || res);
    } catch (e) {
      setError(e.message);
    } finally {
      setTextruLoading(false);
    }
  };

  const refreshTextru = async () => {
    setTextruLoading(true);
    try {
      const uid = textru?.uid || textru?.text_uid;
      const res = await api.getExternalUniqueness({ uid });
      setTextru(res.data || res);
    } catch (e) {
      setError(e.message);
    } finally {
      setTextruLoading(false);
    }
  };

  return (
    <div className="step-body">
      <h2 className="step-title">4. Проверки</h2>

      <div className="check-block">
        <div className="step-head">
          <h3>Каннибализация</h3>
          <Button variant="primary" onClick={runCannibalization} disabled={loading}>
            {loading ? <Spinner label="Проверка…" /> : 'Проверить'}
          </Button>
        </div>
        {error && <Notice notice={{ type: 'error', text: error }} />}
        {cannibalization && (
          <>
            <div className="risk-summary">
              <Tag tone="danger">high: {cannibalization.risk_summary?.high ?? 0}</Tag>
              <Tag tone="warn">medium: {cannibalization.risk_summary?.medium ?? 0}</Tag>
              <Tag tone="neutral">uniqueness: {cannibalization.uniqueness_percent}%</Tag>
            </div>
            <div className="candidate-list">
              {(cannibalization.candidates || []).map((c) => (
                <div className="candidate" key={c.source + c.element_id}>
                  <div className="candidate-head">
                    <Tag tone={RISK_TONE[c.risk]}>{c.risk}</Tag>
                    <a href={c.absolute_url || c.url} target="_blank" rel="noreferrer">{c.name}</a>
                    <span className="muted">#{c.element_id} · {c.source}</span>
                  </div>
                  <div className="signals">
                    query {(c.signals.query_overlap * 100).toFixed(0)}% · title {(c.signals.title_similarity * 100).toFixed(0)}% · text {c.signals.text_overlap_percent}%
                    {c.signals.intent_match && ' · intent ✓'}
                    {c.signals.primary_primary && ' · primary↔primary'}
                  </div>
                  {c.fragments?.length > 0 && (
                    <Collapsible title={'Фрагменты совпадений (' + c.fragments.length + ')'}>
                      {c.fragments.map((f, i) => (
                        <blockquote key={i} className="fragment">{f.text}</blockquote>
                      ))}
                    </Collapsible>
                  )}
                </div>
              ))}
              {(cannibalization.candidates || []).length === 0 && <p className="muted">Пересечений не найдено.</p>}
            </div>
          </>
        )}
      </div>

      <div className="check-block">
        <div className="step-head">
          <h3>TEXT.RU (внешняя проверка)</h3>
          <div className="row-actions">
            <Button onClick={runTextru} disabled={textruLoading}>Запустить</Button>
            {textru && <Button variant="ghost" onClick={refreshTextru} disabled={textruLoading}>Обновить статус</Button>}
          </div>
        </div>
        {textru && (
          <div className="textru-result">
            {textru.uniqueness_percent != null ? (
              <Tag tone="neutral">уникальность: {textru.uniqueness_percent}%</Tag>
            ) : (
              <span className="muted">Статус: {textru.status || 'в очереди'}. Обновите статус позже.</span>
            )}
            {debug && <pre className="debug-json">{JSON.stringify(textru, null, 2)}</pre>}
          </div>
        )}
      </div>
    </div>
  );
}
