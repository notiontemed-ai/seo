import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useStore } from '../../store/useStore.js';
import { BLOCK_LIBRARY, CATEGORIES, blockMeta, defaultBlock } from '../../lib/articleContent.js';
import { structureBlocks, formatRepeat } from '../task/structureMeta.js';
import { renderArticle } from '../../lib/htmlRenderer.js';
import { BlockField } from '../../components/fields.jsx';
import { Button, Tag, Select, Modal, Collapsible } from '../../components/ui.jsx';

export default function BlocksEditor() {
  const { article, addBlock, setBlocks, structures, debug } = useStore();
  const [showAdd, setShowAdd] = useState(false);
  const [showPreview, setShowPreview] = useState(false);

  const structure = structures.find((s) => s.id === article.structure_id) || null;
  const skeleton = structure ? structureBlocks(structure) : [];
  const prefilled = useRef(false);

  // Скелет из структуры (ТЗ 4.3): обязательные блоки добавляются только в пустую
  // статью, уже существующий контент не перетирается.
  useEffect(() => {
    if (!structure) return;
    if (article.blocks.length > 0) {
      prefilled.current = true;
      return;
    }
    if (prefilled.current) return;
    prefilled.current = true;
    const required = skeleton.filter((b) => b.required).map((b) => defaultBlock(b.block));
    if (required.length) setBlocks(required);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [structure?.id]);

  const present = new Set(article.blocks.map((b) => b.type));
  const requiredBlocks = skeleton.filter((b) => b.required);
  const optionalBlocks = skeleton.filter((b) => !b.required);

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

      {structure && (
        <div className="skeleton-panel">
          <div className="skeleton-title">
            Структура: <strong>{structure.name}</strong> <span className="muted">({requiredBlocks.filter((b) => present.has(b.block)).length}/{requiredBlocks.length} обязательных)</span>
          </div>
          <ul className="skeleton-checklist">
            {requiredBlocks.map((b, i) => {
              const rep = formatRepeat(b.repeat);
              const ok = present.has(b.block);
              return (
                <li key={i} className={ok ? 'ok' : 'todo'}>
                  <span className="brief-mark">{ok ? '✓' : '○'}</span> {blockMeta(b.block).label}{rep && <span className="muted"> {rep}</span>}
                </li>
              );
            })}
          </ul>
          {optionalBlocks.length > 0 && (
            <div className="skeleton-optional muted">
              Опционально: {optionalBlocks.map((b, i) => (
                <button key={i} type="button" className="skeleton-add" onClick={() => addBlock(b.block)}>
                  + {blockMeta(b.block).label}
                </button>
              ))}
            </div>
          )}
        </div>
      )}

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
