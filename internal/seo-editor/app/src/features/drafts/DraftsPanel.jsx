import React, { useEffect, useState } from 'react';
import { useStore } from '../../store/useStore.js';
import { api } from '../../api/client.js';
import { Button, Spinner, Notice } from '../../components/ui.jsx';

// Снапшот черновика на базе article_content v2 (blocks вместо HTML — снапшоты
// меньше). Хранение — Google Sheets через n8n.
function snapshot(article) {
  return {
    name: article.name,
    code: article.code,
    section_id: article.section_id,
    primary_query: article.primary_query,
    secondary_queries: article.secondary_queries,
    search_intent: article.search_intent,
    structure_id: article.structure_id,
    preview_text: article.preview_text,
    article_content: { schema_version: '2.0', blocks: article.blocks },
  };
}

export default function DraftsPanel() {
  const { article, patchArticle, setBlocks, setNotice } = useStore();
  const [list, setList] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const load = async () => {
    setLoading(true);
    setError('');
    try {
      const res = await api.draftList({});
      setList((res.data || res).items || (res.data || res).drafts || []);
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    load();
  }, []);

  const save = async () => {
    setLoading(true);
    setError('');
    try {
      await api.draftSaveVersion({ snapshot: snapshot(article), code: article.code, name: article.name });
      setNotice({ type: 'success', text: 'Черновик сохранён' });
      load();
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  };

  const restore = async (draft) => {
    setLoading(true);
    try {
      const res = await api.draftGet({ id: draft.id || draft.draft_id });
      const data = (res.data || res).snapshot || res.data || res;
      const content = data.article_content;
      if (content?.blocks) setBlocks(content.blocks);
      patchArticle({
        name: data.name || article.name,
        code: data.code || article.code,
        preview_text: data.preview_text || '',
        primary_query: data.primary_query || '',
        secondary_queries: data.secondary_queries || [],
        structure_id: data.structure_id || '',
      });
      setNotice({ type: 'success', text: 'Черновик восстановлен' });
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="panel">
      <h3 className="panel-title">Черновики</h3>
      <div className="row-actions">
        <Button variant="primary" onClick={save} disabled={loading}>Сохранить текущий</Button>
        <Button variant="ghost" onClick={load} disabled={loading}>Обновить</Button>
      </div>
      {loading && <Spinner />}
      {error && <Notice notice={{ type: 'error', text: error }} />}
      <div className="draft-list">
        {list.map((d) => (
          <div className="draft-item" key={d.id || d.draft_id}>
            <div>
              <strong>{d.name || d.code || d.id}</strong>
              <div className="muted">{d.updated_at || d.created_at || ''}</div>
            </div>
            <Button variant="ghost" onClick={() => restore(d)}>Восстановить</Button>
          </div>
        ))}
        {!list.length && !loading && <p className="muted">Черновиков нет.</p>}
      </div>
    </div>
  );
}
