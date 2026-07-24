import React, { useEffect } from 'react';

export function Button({ variant = 'default', className = '', ...props }) {
  return <button className={'btn btn-' + variant + ' ' + className} {...props} />;
}

export function Spinner({ label }) {
  return (
    <span className="spinner" role="status">
      <span className="spinner-dot" /> {label || 'Загрузка…'}
    </span>
  );
}

export function Tag({ tone = 'neutral', children }) {
  return <span className={'tag tag-' + tone}>{children}</span>;
}

export function Field({ label, hint, children }) {
  return (
    <label className="field">
      {label && <span className="field-label">{label}</span>}
      {children}
      {hint && <span className="field-hint">{hint}</span>}
    </label>
  );
}

export function TextInput(props) {
  return <input className="input" {...props} />;
}

export function TextArea(props) {
  return <textarea className="textarea" rows={props.rows || 3} {...props} />;
}

export function Select({ options, value, onChange, placeholder }) {
  return (
    <select className="input" value={value ?? ''} onChange={(e) => onChange(e.target.value)}>
      {placeholder != null && <option value="">{placeholder}</option>}
      {options.map((o) => (
        <option key={o.value} value={o.value}>
          {o.label}
        </option>
      ))}
    </select>
  );
}

export function Modal({ open, title, onClose, children, footer }) {
  useEffect(() => {
    if (!open) return undefined;
    const onKey = (e) => e.key === 'Escape' && onClose && onClose();
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open, onClose]);
  if (!open) return null;
  return (
    <div className="modal-overlay" onClick={onClose}>
      <div className="modal" role="dialog" aria-modal="true" onClick={(e) => e.stopPropagation()}>
        <div className="modal-head">
          <h3>{title}</h3>
          <button className="modal-close" onClick={onClose} aria-label="Закрыть">
            ×
          </button>
        </div>
        <div className="modal-body">{children}</div>
        {footer && <div className="modal-foot">{footer}</div>}
      </div>
    </div>
  );
}

export function Collapsible({ title, children, defaultOpen = false }) {
  return (
    <details className="collapsible" open={defaultOpen}>
      <summary>{title}</summary>
      <div className="collapsible-body">{children}</div>
    </details>
  );
}

export function Notice({ notice, onClose }) {
  if (!notice) return null;
  return (
    <div className={'notice notice-' + (notice.type || 'info')}>
      <span>{notice.text}</span>
      {onClose && (
        <button className="notice-close" onClick={onClose} aria-label="Закрыть">
          ×
        </button>
      )}
    </div>
  );
}
