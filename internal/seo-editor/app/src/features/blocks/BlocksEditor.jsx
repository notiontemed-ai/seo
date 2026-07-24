import React, { useMemo, useState } from 'react';
import { useStore } from '../../store/useStore.js';
import { BLOCK_LIBRARY, CATEGORIES, blockMeta } from '../../lib/articleContent.js';
import { renderArticle } from '../../lib/htmlRenderer.js';
import { BlockField } from '../../components/fields.jsx';
import { Button, Tag, Select, Modal, Collapsible } from '../../components/ui.jsx';

export default function BlocksEditor() {
  const { article, addBlock, debug } = useStore();
  const [showAdd, setShowAdd] = useState(false);
  const [showPreview, setShowPreview] = useState(false);

  return (
    <div className="step-body">
      <div className="step-head">
        <h2 className="step-title">3. Текст (поблочно)</h2>
        <div className="row-actions">
          <Button onClick={() => setShowPreview(true)} disabled={!article.blocks.length}>
            Предпросмотр
          </Button>
          <Button variant="primary" onClick={() => setShowAdd(true)}>
            + Добавить блок
          </Button>
        </div>
      </div>

      {!article.blocks.length && <p className="muted">Блоков пока нет. Сгенерируйте черновик на шаге 2 или добавьте блоки вручную.</p>}

      <div className="blocks-list">
        {article.blocks.map((block, i) => (
          <BlockCard key={i} index={i} block={block} />
        ))}
      </div>

      <Modal open={showAdd} title="Добавить блок" onClose={() => setShowAdd(false)}>
        <AddBlockMenu
          onPick={(type) => {
            addBlock(type);
            setShowAdd(false);
          }}
        />
      </Modal>

      <Modal open={showPreview} title="Предпросмотр — как статья" onClose={() => setShowPreview(false)}>
        <ArticlePreview />
        {debug && (
          <Collapsible title="article_content v2 (JSON)">
            <pre className="debug-json">{JSON.stringify({ schema_version: '2.0', blocks: article.blocks }, null, 2)}</pre>
          </Collapsible>
        )}
      </Modal>
    </div>
  );
}

function BlockCard({ index, block }) {
  const { updateBlock, removeBlock, moveBlock, changeBlockType, article } = useStore();
  const meta = blockMeta(block.type);
  const typeOptions = Object.entries(BLOCK_LIBRARY).map(([k, v]) => ({ value: k, label: v.label }));

  return (
    <div className="block-card">
      <div className="block-head">
        <div className="block-title">
          <span className="block-index">{index + 1}</span>
          <strong>{meta.label}</strong>
          {meta.medical && <Tag tone="medical">мед</Tag>}
        </div>
        <div className="block-controls">
          <Select options={typeOptions} value={block.type} onChange={(v) => changeBlockType(index, v)} />
          <Button variant="ghost" onClick={() => moveBlock(index, -1)} disabled={index === 0} aria-label="Вверх">↑</Button>
          <Button variant="ghost" onClick={() => moveBlock(index, 1)} disabled={index === article.blocks.length - 1} aria-label="Вниз">↓</Button>
          <Button variant="ghost" onClick={() => removeBlock(index)} aria-label="Удалить">✕</Button>
        </div>
      </div>
      <div className="block-fields">
        {meta.fields.length === 0 && <p className="muted">Блок без полей (форма записи).</p>}
        {meta.fields.map((field) => (
          <BlockField key={field.key} field={field} value={block[field.key]} onChange={(v) => updateBlock(index, { [field.key]: v })} />
        ))}
      </div>
    </div>
  );
}

function AddBlockMenu({ onPick }) {
  return (
    <div className="add-block-menu">
      {CATEGORIES.map((cat) => {
        const entries = Object.entries(BLOCK_LIBRARY).filter(([, v]) => v.category === cat.key);
        if (!entries.length) return null;
        return (
          <div key={cat.key} className="add-block-cat">
            <h4>{cat.label}</h4>
            <div className="add-block-grid">
              {entries.map(([type, meta]) => (
                <button key={type} className="add-block-item" onClick={() => onPick(type)}>
                  <span className="add-block-label">{meta.label}</span>
                  {meta.medical && <Tag tone="medical">мед</Tag>}
                </button>
              ))}
            </div>
          </div>
        );
      })}
    </div>
  );
}

function ArticlePreview() {
  const { article, doctorMap, serviceMap } = useStore();
  const html = useMemo(
    () =>
      renderArticle(article.blocks, {
        resolve: { doctors: doctorMap, services: serviceMap },
        form: { show_form: article.show_form, form_id: article.form_id, button_text: article.form_button_text },
      }),
    [article.blocks, article.show_form, article.form_id, article.form_button_text, doctorMap, serviceMap]
  );
  return <div className="article-preview" dangerouslySetInnerHTML={{ __html: html }} />;
}
