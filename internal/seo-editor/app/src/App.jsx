import React, { useEffect } from 'react';
import { useStore } from './store/useStore.js';
import { api } from './api/client.js';
import Wizard from './components/Wizard.jsx';
import { Button, Spinner, Notice } from './components/ui.jsx';
import TaskStep from './features/task/TaskStep.jsx';
import GenerationStep from './features/generation/GenerationStep.jsx';
import BlocksEditor from './features/blocks/BlocksEditor.jsx';
import ChecksStep from './features/checks/ChecksStep.jsx';
import LinkingStep from './features/linking/LinkingStep.jsx';
import PublishStep from './features/publish/PublishStep.jsx';
import AssistantPanel from './features/assistant/AssistantPanel.jsx';
import DraftsPanel from './features/drafts/DraftsPanel.jsx';

const STEP_COMPONENTS = {
  1: TaskStep,
  2: GenerationStep,
  3: BlocksEditor,
  4: ChecksStep,
  5: LinkingStep,
  6: PublishStep,
};

const SECTIONS = [
  { key: 'content', label: 'Генерация контента' },
  { key: 'analytics', label: 'Аналитика' },
  { key: 'admin', label: 'Администрирование' },
];

export default function App() {
  const store = useStore();
  const { ready, bootError, step, section, contentView, panel, debug, notice, dirty } = store;

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const [boot, structures] = await Promise.all([api.bootstrap(), api.structures()]);
        if (cancelled) return;
        store.setBootstrap(boot.data || boot);
        store.setStructures((structures.data || structures).configs || []);
        store.restore();
      } catch (e) {
        if (!cancelled) store.setBootError(e.message);
      }
    })();
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    store.persist();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [store.article, step, section]);

  useEffect(() => {
    const handler = (e) => {
      if (dirty) {
        e.preventDefault();
        e.returnValue = '';
      }
    };
    window.addEventListener('beforeunload', handler);
    return () => window.removeEventListener('beforeunload', handler);
  }, [dirty]);

  if (bootError) {
    return (
      <div className="boot-error">
        <h1>TEMED SEO Editor</h1>
        <Notice notice={{ type: 'error', text: 'Не удалось загрузить справочники: ' + bootError }} />
      </div>
    );
  }

  if (!ready) {
    return (
      <div className="boot-loading">
        <Spinner label="Загрузка справочников…" />
      </div>
    );
  }

  const StepComponent = STEP_COMPONENTS[step];
  const isContent = section === 'content';
  const isWizard = isContent && contentView === 'wizard';

  return (
    <div className={'app-shell' + (panel ? ' with-panel' : '')}>
      <header className="app-header">
        <div className="app-brand">TEMED SEO Editor</div>
        <nav className="app-nav" aria-label="Разделы">
          {SECTIONS.map((sc) => (
            <button
              key={sc.key}
              className={'nav-tab' + (section === sc.key ? ' on' : '')}
              onClick={() => store.setSection(sc.key)}
            >
              {sc.label}
            </button>
          ))}
        </nav>
        <div className="app-tools">
          <Button variant={panel === 'assistant' ? 'primary' : 'ghost'} onClick={() => store.setPanel('assistant')}>Ассистент</Button>
          <label className="debug-toggle">
            <input type="checkbox" checked={debug} onChange={(e) => store.setDebug(e.target.checked)} /> Отладка
          </label>
        </div>
      </header>

      {isContent && (
        <div className="section-bar">
          <div className="section-tabs">
            <button className={'section-tab' + (contentView === 'wizard' ? ' on' : '')} onClick={() => store.setContentView('wizard')}>Мастер</button>
            <button className={'section-tab' + (contentView === 'drafts' ? ' on' : '')} onClick={() => store.setContentView('drafts')}>Черновики</button>
          </div>
          <SaveIndicator />
        </div>
      )}

      {isWizard && <Wizard />}

      {notice && (
        <div className="notice-bar">
          <Notice notice={notice} onClose={() => store.setNotice(null)} />
        </div>
      )}

      <div className="app-main">
        <main className="content-col">
          {isWizard && (
            <>
              <StepComponent />
              <StepNav />
            </>
          )}
          {isContent && contentView === 'drafts' && <DraftsPanel />}
          {section === 'analytics' && <EmptySection title="Аналитика" />}
          {section === 'admin' && <EmptySection title="Администрирование" />}
        </main>
        {panel === 'assistant' && (
          <aside className="side-panel">
            <AssistantPanel />
          </aside>
        )}
      </div>
    </div>
  );
}

function SaveIndicator() {
  const { localSavedAt } = useStore();
  if (!localSavedAt) return <span className="save-indicator muted">черновик не сохранён</span>;
  const t = new Date(localSavedAt);
  const hh = String(t.getHours()).padStart(2, '0');
  const mm = String(t.getMinutes()).padStart(2, '0');
  return <span className="save-indicator muted">сохранено локально · {hh}:{mm}</span>;
}

function EmptySection({ title }) {
  return (
    <div className="empty-section step-body">
      <h2 className="step-title">{title}</h2>
      <p className="muted">Раздел появится позже. Сейчас доступна «Генерация контента».</p>
    </div>
  );
}

function StepNav() {
  const { step, setStep } = useStore();
  return (
    <div className="step-nav">
      <Button onClick={() => setStep(Math.max(1, step - 1))} disabled={step === 1}>← Назад</Button>
      <Button variant="primary" onClick={() => setStep(Math.min(6, step + 1))} disabled={step === 6}>Далее →</Button>
    </div>
  );
}
