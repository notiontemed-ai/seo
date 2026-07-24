import React, { useState } from 'react';
import { useStore } from '../../store/useStore.js';
import { api } from '../../api/client.js';
import { Button, Spinner, Modal, Notice, Tag } from '../../components/ui.jsx';

import { buildDraftPayload } from '../../lib/publishPayload.js';
import { checkState } from '../../lib/checkFreshness.js';

const CHECK_LABELS = {
  cannibalization: 'Каннибализация',
  textru: 'TEXT.RU',
  linking: 'Перелинковка',
};

// Предупреждения о проверках, которые устарели или не запускались (этап 8.4).
function checkWarnings(checkHashes, article) {
  const warnings = [];
  for (const [kind, label] of Object.entries(CHECK_LABELS)) {
    const state = checkState(checkHashes[kind], article);
    if (state === 'missing') warnings.push(label + ': проверка не запускалась');
    if (state === 'stale') warnings.push(label + ': результат устарел, текст изменился');
  }
  return warnings;
}

export default function PublishStep() {
  const { article, structures, checkHashes } = useStore();
  const [confirm, setConfirm] = useState(false);
  const [loading, setLoading] = useState(false);
  const [result, setResult] = useState(null);
  const [error, setError] = useState('');

  const canPublish = article.name && article.code && article.section_id && article.blocks.length;
  const staleWarnings = checkWarnings(checkHashes, article);

  const publish = async () => {
    setLoading(true);
    setError('');
    try {
      const res = await api.createDraft(buildDraftPayload(article, structures));
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
      {staleWarnings.length > 0 && (
        <Notice notice={{ type: 'warn', text: 'Проверки: ' + staleWarnings.join('; ') + '.' }} />
      )}
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
        {staleWarnings.length > 0 && (
          <ul className="warn-list">
            {staleWarnings.map((w, i) => (
              <li key={i}>{w}</li>
            ))}
          </ul>
        )}
      </Modal>
    </div>
  );
}
