import React from 'react';
import { Field, TextInput, TextArea, Select, Button } from './ui.jsx';
import { emptyItem } from '../lib/articleContent.js';
import { useStore } from '../store/useStore.js';

// Универсальный редактор одного поля блока по его kind.
export function BlockField({ field, value, onChange }) {
  const label = field.label;
  switch (field.kind) {
    case 'text':
      return <Field label={label}><TextInput value={value ?? ''} onChange={(e) => onChange(e.target.value)} /></Field>;
    case 'textarea':
      return <Field label={label}><TextArea value={value ?? ''} onChange={(e) => onChange(e.target.value)} /></Field>;
    case 'number':
      return <Field label={label}><TextInput type="number" value={value ?? 0} onChange={(e) => onChange(Number(e.target.value))} /></Field>;
    case 'checkbox':
      return (
        <label className="field-checkbox">
          <input type="checkbox" checked={!!value} onChange={(e) => onChange(e.target.checked)} /> {label}
        </label>
      );
    case 'stringlist':
      return <StringListField label={label} value={value || []} onChange={onChange} />;
    case 'items':
      return <ItemsField label={label} itemFields={field.item} value={value || []} onChange={onChange} />;
    case 'table':
      return <TableField label={label} value={value || []} onChange={onChange} />;
    case 'doctor':
      return <ReferenceField label={label} kind="doctor" value={value} onChange={onChange} />;
    case 'service':
      return <ReferenceField label={label} kind="service" value={value} onChange={onChange} />;
    default:
      return null;
  }
}

export function StringListField({ label, value, onChange }) {
  const items = value.length ? value : [''];
  const set = (i, v) => onChange(items.map((x, idx) => (idx === i ? v : x)));
  return (
    <Field label={label}>
      <div className="stringlist">
        {items.map((item, i) => (
          <div className="stringlist-row" key={i}>
            <TextInput value={item} onChange={(e) => set(i, e.target.value)} />
            <Button variant="ghost" onClick={() => onChange(items.filter((_, idx) => idx !== i))} aria-label="Удалить">
              −
            </Button>
          </div>
        ))}
        <Button variant="ghost" onClick={() => onChange([...items, ''])}>
          + пункт
        </Button>
      </div>
    </Field>
  );
}

function ItemsField({ label, itemFields, value, onChange }) {
  const items = value.length ? value : [emptyItem(itemFields)];
  const setItem = (i, patch) => onChange(items.map((it, idx) => (idx === i ? { ...it, ...patch } : it)));
  return (
    <Field label={label}>
      <div className="items-editor">
        {items.map((item, i) => (
          <div className="item-row" key={i}>
            <div className="item-fields">
              {itemFields.map((f) => (
                <BlockField key={f.key} field={f} value={item[f.key]} onChange={(v) => setItem(i, { [f.key]: v })} />
              ))}
            </div>
            <Button variant="ghost" onClick={() => onChange(items.filter((_, idx) => idx !== i))} aria-label="Удалить">
              Удалить
            </Button>
          </div>
        ))}
        <Button variant="ghost" onClick={() => onChange([...items, emptyItem(itemFields)])}>
          + элемент
        </Button>
      </div>
    </Field>
  );
}

function TableField({ label, value, onChange }) {
  const rows = value.length ? value : [['', '']];
  const cols = Math.max(1, ...rows.map((r) => r.length));
  const setCell = (r, c, v) => onChange(rows.map((row, ri) => (ri === r ? row.map((cell, ci) => (ci === c ? v : cell)) : row)));
  return (
    <Field label={label}>
      <div className="table-editor">
        {rows.map((row, r) => (
          <div className="table-row" key={r}>
            {Array.from({ length: cols }).map((_, c) => (
              <TextInput key={c} value={row[c] ?? ''} onChange={(e) => setCell(r, c, e.target.value)} />
            ))}
            <Button variant="ghost" onClick={() => onChange(rows.filter((_, ri) => ri !== r))} aria-label="Удалить строку">
              −
            </Button>
          </div>
        ))}
        <div className="table-actions">
          <Button variant="ghost" onClick={() => onChange([...rows, Array.from({ length: cols }).map(() => '')])}>
            + строка
          </Button>
          <Button variant="ghost" onClick={() => onChange(rows.map((row) => [...row, '']))}>
            + столбец
          </Button>
        </div>
      </div>
    </Field>
  );
}

function ReferenceField({ label, kind, value, onChange }) {
  const list = useStore((s) => (kind === 'doctor' ? s.doctorList : s.serviceList));
  const options = list.map((x) => ({ value: String(x.id), label: x.name + ' (#' + x.id + ')' }));
  return (
    <Field label={label}>
      <Select options={options} value={value ? String(value) : ''} placeholder="— не выбрано —" onChange={(v) => onChange(v ? Number(v) : 0)} />
    </Field>
  );
}
