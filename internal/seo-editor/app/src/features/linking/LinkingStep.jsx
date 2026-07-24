import React, { useState } from 'react';
import { useStore } from '../../store/useStore.js';
import { api } from '../../api/client.js';
import { Button, Spinner, Tag, Notice } from '../../components/ui.jsx';
import { contentHash, checkState } from '../../lib/checkFreshness.js';
import { StaleTag } from '../checks/ChecksStep.jsx';

const LOAD_TONE = { green: 'ok', yellow: 'warn', red: 'danger' };

function buildBrief(article, linking, selectedIds) {
  const chosen = (linking.candidates || []).filter((c) => selectedIds.includes(c.element_id));
  const lines = ['# ТЗ на перелинковку', '', 'Целевая статья: **' + (article.name || '') + '**', ''];
  chosen.forEach((c, i) => {
    const anchor = (c.insertion?.anchor_options || [])[0] || article.primary_query || 'ссылка';
    const before = c.insertion?.paragraph_quote || '';
    const href = article.code ? '/' + article.code : '#';
    const after = before + ' <a href="' + href + '">' + anchor + '</a>';
    lines.push('## ' + (i + 1) + '. ' + c.name + ' (#' + c.element_id + ')');
    lines.push('- Донор в админке: ' + c.admin_url);
    lines.push('- URL: ' + (c.absolute_url || c.url));
    lines.push('- Нагрузка ссылками: ' + c.link_load + ' (' + c.links_in_text + ' в тексте)' + (c.already_linked ? ' — УЖЕ ссылается на цель' : ''));
    lines.push('- Позиция: ' + (c.insertion?.position_hint || '—'));
    lines.push('- Анкор: **' + anchor + '**');
    lines.push('');
    lines.push('Было:');
    lines.push('> ' + before);
    lines.push('');
    lines.push('Стало:');
    lines.push('> ' + after);
    lines.push('');
  });
  return lines.join('\n');
}

export default function LinkingStep() {
  const { article, linking, setLinking, linkingSelected, toggleLinkingSelected, setNotice, checkHashes, setCheckHash } = useStore();
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const linkingState = checkState(checkHashes.linking, article);

  const run = async () => {
    setLoading(true);
    setError('');
    try {
      const hash = contentHash(article);
      const res = await api.linking({
        article: {
          element_id: article.element_id || undefined,
          code: article.code || undefined,
          name: article.name,
          primary_query: article.primary_query,
          secondary_queries: article.secondary_queries,
          article_content: { schema_version: '2.0', blocks: article.blocks },
        },
        limit: 20,
      });
      setLinking(res.data || res);
      setCheckHash('linking', hash);
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  };

  const brief = linking ? buildBrief(article, linking, linkingSelected) : '';

  const copyBrief = async () => {
    try {
      await navigator.clipboard.writeText(brief);
      setNotice({ type: 'success', text: 'ТЗ скопировано в буфер обмена' });
    } catch (_) {
      setNotice({ type: 'error', text: 'Не удалось скопировать' });
    }
  };

  const downloadBrief = () => {
    const blob = new Blob([brief], { type: 'text/markdown;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'linking-task-' + (article.code || 'article') + '.md';
    a.click();
    URL.revokeObjectURL(url);
  };

  return (
    <div className="step-body">
      <div className="step-head">
        <h2 className="step-title">5. Перелинковка <StaleTag state={linkingState} /></h2>
        <Button variant="primary" onClick={run} disabled={loading}>
          {loading ? <Spinner label="Поиск доноров…" /> : 'Найти доноров'}
        </Button>
      </div>
      {error && <Notice notice={{ type: 'error', text: error }} />}

      {linking && (
        <>
          <p className="muted">Отметьте доноров и сформируйте ТЗ. Автоматическая правка доноров не выполняется.</p>
          <div className="candidate-list">
            {(linking.candidates || []).map((c) => (
              <label className="candidate selectable" key={c.element_id}>
                <input type="checkbox" checked={linkingSelected.includes(c.element_id)} onChange={() => toggleLinkingSelected(c.element_id)} />
                <div className="candidate-body">
                  <div className="candidate-head">
                    <a href={c.absolute_url || c.url} target="_blank" rel="noreferrer">{c.name}</a>
                    <span className="muted">#{c.element_id}</span>
                    <Tag tone={LOAD_TONE[c.link_load]}>{c.link_load} · {c.links_in_text}</Tag>
                    {c.already_linked && <Tag tone="neutral">уже ссылается</Tag>}
                    <span className="muted">релевантность {(c.relevance * 100).toFixed(0)}%</span>
                  </div>
                  {c.insertion?.paragraph_quote && <div className="muted insertion-quote">«{c.insertion.paragraph_quote}»</div>}
                  {c.found_links?.length > 0 && (
                    <div className="muted">Ссылки в тексте: {c.found_links.map((l) => l.anchor).filter(Boolean).join(', ') || c.found_links.length}</div>
                  )}
                </div>
              </label>
            ))}
            {(linking.candidates || []).length === 0 && <p className="muted">Доноров не найдено.</p>}
          </div>

          {linkingSelected.length > 0 && (
            <div className="brief-block">
              <div className="row-actions">
                <Button variant="primary" onClick={copyBrief}>Скопировать ТЗ</Button>
                <Button onClick={downloadBrief}>Скачать .md</Button>
              </div>
              <pre className="brief-preview">{brief}</pre>
            </div>
          )}
        </>
      )}
    </div>
  );
}
