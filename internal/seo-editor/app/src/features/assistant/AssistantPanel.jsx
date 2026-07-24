import React, { useEffect, useRef, useState } from 'react';
import { useStore } from '../../store/useStore.js';
import { api } from '../../api/client.js';
import { Button, Spinner, TextArea, Notice } from '../../components/ui.jsx';
import { filterSuggestions } from '../../lib/suggestions.js';

const SESSION_KEY = 'temed_seo_assistant_history_v2';
const HISTORY_LIMIT = 20;

// Поля статьи, которые ассистент может предлагать изменить.
const ALLOWED_FIELDS = ['name', 'code', 'preview_text', 'primary_query', 'secondary_queries', 'search_intent'];

function loadHistory() {
  try {
    return JSON.parse(sessionStorage.getItem(SESSION_KEY) || '[]');
  } catch (_) {
    return [];
  }
}

export default function AssistantPanel() {
  const { article, patchArticle, debug, setNotice } = useStore();
  const [messages, setMessages] = useState(loadHistory);
  const [input, setInput] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const bottomRef = useRef(null);

  useEffect(() => {
    sessionStorage.setItem(SESSION_KEY, JSON.stringify(messages.slice(-HISTORY_LIMIT)));
    bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages]);

  const send = async () => {
    const text = input.trim();
    if (!text) return;
    setInput('');
    setError('');
    const next = [...messages, { role: 'user', text }].slice(-HISTORY_LIMIT);
    setMessages(next);
    setLoading(true);
    try {
      const res = await api.assistantChat({
        mode: 'article',
        message: text,
        allowed_field_ids: ALLOWED_FIELDS,
        article: { name: article.name, code: article.code, primary_query: article.primary_query },
        history: next.slice(-10),
      });
      const data = res.data || res;
      // Рендерим только валидные suggestions: обязательны field_id и value.
      const { kept: suggestions, dropped } = filterSuggestions(data.suggestions, ALLOWED_FIELDS);
      if (dropped.length > 0 && debug) console.warn('Отброшено невалидных suggestions:', dropped.length);
      setMessages((m) => [...m, { role: 'assistant', text: data.answer || '', suggestions, warnings: data.warnings || [] }].slice(-HISTORY_LIMIT));
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  };

  const applySuggestion = (s) => {
    const value = s.field_id === 'secondary_queries' && typeof s.value === 'string'
      ? s.value.split('\n').map((x) => x.trim()).filter(Boolean)
      : s.value;
    patchArticle({ [s.field_id]: value });
    setNotice({ type: 'success', text: 'Применено: ' + (s.label || s.field_id) });
  };

  return (
    <div className="panel">
      <h3 className="panel-title">Ассистент</h3>
      <div className="chat">
        {messages.map((m, i) => (
          <div key={i} className={'chat-msg chat-' + m.role}>
            {m.text && <div className="chat-text">{m.text}</div>}
            {m.suggestions?.map((s, j) => (
              <div className="suggestion" key={j}>
                <div className="suggestion-body">
                  <strong>{s.label || s.field_id}</strong>
                  <div className="muted">{s.reason || ''}</div>
                  <div className="suggestion-value">{Array.isArray(s.value) ? s.value.join(', ') : s.value}</div>
                </div>
                <Button variant="primary" onClick={() => applySuggestion(s)}>Применить</Button>
              </div>
            ))}
          </div>
        ))}
        <div ref={bottomRef} />
      </div>
      {error && <Notice notice={{ type: 'error', text: error }} />}
      <div className="chat-input">
        <TextArea rows={2} value={input} onChange={(e) => setInput(e.target.value)} placeholder="Вопрос ассистенту…" />
        <Button variant="primary" onClick={send} disabled={loading}>
          {loading ? <Spinner label="…" /> : 'Отправить'}
        </Button>
      </div>
    </div>
  );
}
