import React, { useState } from 'react';
import { useStore } from '../../store/useStore.js';
import { api } from '../../api/client.js';
import { Button, Spinner, Modal, Notice, Tag } from '../../components/ui.jsx';

function buildProperties(article, structures) {
  const structure = structures.find((s) => s.id === article.structure_id);
  const props = {};
  const setIf = (k, v) => {
    if (v !== '' && v != null && v !== 0 && !(Array.isArray(v) && v.length === 0)) props[k] = v;
  };
  setIf('ARTICLE_TYPE', article.article_type);
  setIf('PRIMARY_QUERY', article.primary_query);
  setIf('SECONDARY_QUERIES', article.secondary_queries);
  setIf('SEARCH_INTENT', article.search_intent);
  setIf('REGION', article.region);
  setIf('AUTHOR', article.author_id);
  setIf('MEDICAL_REVIEWER', article.medical_reviewer_id);
  if (structure) {
    setIf('ARTICLE_STRUCTURE', structure.id);
    setIf('ARTICLE_STRUCTURE_NAME', structure.name);
    setIf('ARTICLE_STRUCTURE_VERSION', structure.version || structure.v || '');
  }
  setIf('SHOW_FORM', article.show_form);
  setIf('FORM_ID', article.form_id);
  setIf('FORM_BUTTON_TEXT', article.form_button_text);
  return props;
}

export default function PublishStep() {
  const { article, structures } = useStore();
  const [confirm, setConfirm] = useState(false);
  const [loading, setLoading] = useState(false);
  const [result, setResult] = useState(null);
  const [error, setError] = useState('');

  const canPublish = article.name && article.code && article.section_id && article.blocks.length;

  const publish = async () => {
    setLoading(true);
    setError('');
    try {
      const res = await api.createDraft({
        code: article.code,
        name: article.name,
        preview_text: article.preview_text,
        section_id: article.section_id,
        article_content: { schema_version: '2.0', blocks: article.blocks },
        properties: buildProperties(article, structures),
      });
      setResult(res.data || res);
      setConfirm(false);
    } catch (e) {
      setError(e.message);
      setConfirm(false);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="step-body">
      <h2 className="step-title">6. Публикация</h2>
      <p className="muted">Запись создаёт/обновляет НЕАКТИВНЫЙ элемент в инфоблоке 81. Активные элементы не изменяются.</p>

      {!canPublish && <Notice notice={{ type: 'warn', text: 'Заполните название, код, раздел и добавьте хотя бы один блок.' }} />}
      {error && <Notice notice={{ type: 'error', text: error }} />}

      <Button variant="primary" onClick={() => setConfirm(true)} disabled={!canPublish || loading}>
        Опубликовать черновик
      </Button>

      {result && (
        <div className="publish-result">
          <Tag tone="ok">{result.created ? 'Создан' : 'Обновлён'}</Tag>
          <span>Элемент #{result.element_id} (ACTIVE=N)</span>
          {result.admin_url && (
            <a href={result.admin_url} target="_blank" rel="noreferrer">Открыть в админке</a>
          )}
          {result.warnings?.length > 0 && (
            <ul className="warn-list">
              {result.warnings.map((w, i) => (
                <li key={i}>{w.message || JSON.stringify(w)}</li>
              ))}
            </ul>
          )}
        </div>
      )}

      <Modal
        open={confirm}
        title="Подтверждение записи в Bitrix"
        onClose={() => setConfirm(false)}
        footer={
          <>
            <Button onClick={() => setConfirm(false)}>Отмена</Button>
            <Button variant="primary" onClick={publish} disabled={loading}>
              {loading ? <Spinner label="Запись…" /> : 'Подтвердить и записать'}
            </Button>
          </>
        }
      >
        <dl className="confirm-list">
          <dt>Инфоблок</dt>
          <dd>[articles_v2] Статьи v2 (ID 81)</dd>
          <dt>Название</dt>
          <dd>{article.name}</dd>
          <dt>Символьный код</dt>
          <dd>{article.code}</dd>
          <dt>Раздел (ID)</dt>
          <dd>{article.section_id}</dd>
          <dt>Режим</dt>
          <dd>создание / обновление неактивного элемента</dd>
          <dt>ACTIVE</dt>
          <dd>N (всегда)</dd>
        </dl>
      </Modal>
    </div>
  );
}
