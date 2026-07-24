import React, { useState } from 'react';
import { useStore } from '../../store/useStore.js';
import { api } from '../../api/client.js';
import { Button, Spinner, Notice, Tag, Collapsible } from '../../components/ui.jsx';
import { topicToArticlePatch, caseStudyBlock } from '../../lib/caseEntry.js';

function fileToBase64(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result).split(',')[1] || '');
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });
}

const RISK_TONE = { high: 'danger', medium: 'warn', low: 'ok' };
const RISK_LABEL = { high: 'конфликт', medium: 'возможен конфликт', low: 'свободно' };

// Кейсовый вход: транскрибация → аннотация + темы → выбор темы предзаполняет
// шаг «Задача», кейс прикрепляется блоком case_study. По темам сразу идёт
// лёгкая проверка каннибализации (только запросы/заголовки).
export default function TranscribePanel() {
  const {
    article, patchArticle, setBlocks, setNotice, setStep,
    caseResult, setCaseResult, setTopicRisk, dictionaries,
  } = useStore();
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  // Лёгкий режим cannibalization_check: без текста, только запрос/заголовок.
  const checkTopics = async (topics) => {
    await Promise.all(topics.map(async (topic, index) => {
      if (!topic.primary_query && !topic.title) return;
      try {
        const res = await api.cannibalization({
          article: { name: topic.title, primary_query: topic.primary_query },
          max_matches: 5,
        });
        const data = res.data || res;
        const summary = data.risk_summary || {};
        const risk = summary.high > 0 ? 'high' : summary.medium > 0 ? 'medium' : 'low';
        setTopicRisk(index, risk);
      } catch (_) {
        setTopicRisk(index, 'unknown');
      }
    }));
  };

  const onFile = async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setLoading(true);
    setError('');
    try {
      const audio_base64 = await fileToBase64(file);
      const res = await api.transcribeCase({ audio_base64, filename: file.name, mime_type: file.type });
      const data = res.data || res;
      const result = {
        transcript: data.transcript || data.text || '',
        summary: data.summary || '',
        topics: (Array.isArray(data.topics) ? data.topics : []).map((t) => ({
          title: t.title || '',
          primary_query: t.primary_query || '',
          fit: t.fit || '',
          risk: null,
        })),
        processing_failed: !!data.processing_failed,
      };
      setCaseResult(result);
      if (result.processing_failed) {
        setNotice({ type: 'warn', text: 'Аудио распознано, но пост-обработка не удалась: доступна только сырая расшифровка.' });
      }
      checkTopics(result.topics);
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  const chooseTopic = (topic) => {
    patchArticle(topicToArticlePatch(topic, caseResult, (dictionaries || {}).article_types));
    setBlocks([...article.blocks, caseStudyBlock(caseResult)]);
    setStep(1);
    setNotice({ type: 'success', text: 'Тема выбрана: шаг «Задача» предзаполнен, кейс добавлен блоком «Случай из практики».' });
  };

  return (
    <div className="panel">
      <h3 className="panel-title">Транскрибация кейса</h3>
      <p className="muted">Загрузите аудио: получите расшифровку, аннотацию и темы статей, где кейс уместен.</p>
      <input type="file" accept="audio/*" onChange={onFile} disabled={loading} />
      {loading && <Spinner label="Распознавание и обработка…" />}
      {error && <Notice notice={{ type: 'error', text: error }} />}

      {caseResult && (
        <div className="case-result">
          {caseResult.summary && (
            <div className="case-summary">
              <h4>Аннотация</h4>
              <p>{caseResult.summary}</p>
            </div>
          )}

          {caseResult.topics.length > 0 && (
            <div className="case-topics">
              <h4>Темы статей ({caseResult.topics.length})</h4>
              <p className="muted">Темы проверены лёгкой каннибализацией (запросы/заголовки по корпусу).</p>
              {caseResult.topics.map((topic, i) => (
                <div className="case-topic" key={i}>
                  <div className="case-topic-head">
                    <strong>{topic.title}</strong>
                    {topic.risk === null && <Spinner label="проверка…" />}
                    {topic.risk && topic.risk !== 'unknown' && (
                      <Tag tone={RISK_TONE[topic.risk]}>{RISK_LABEL[topic.risk]}</Tag>
                    )}
                    {topic.risk === 'unknown' && <Tag tone="neutral">проверка не удалась</Tag>}
                  </div>
                  <div className="muted">Запрос: {topic.primary_query || '—'}</div>
                  {topic.fit && <div className="muted case-topic-fit">{topic.fit}</div>}
                  <Button variant="primary" onClick={() => chooseTopic(topic)}>
                    Выбрать тему
                  </Button>
                </div>
              ))}
            </div>
          )}

          {caseResult.transcript && (
            <Collapsible title="Расшифровка">
              <p className="transcript-text">{caseResult.transcript}</p>
            </Collapsible>
          )}
        </div>
      )}
    </div>
  );
}
