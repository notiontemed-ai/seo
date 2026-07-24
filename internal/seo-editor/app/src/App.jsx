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
import TranscribePanel from './features/transcribe/TranscribePanel.jsx';

const STEP_COMPONENTS = {
  1: TaskStep,
  2: GenerationStep,
  3: BlocksEditor,
  4: ChecksStep,
  5: LinkingStep,
  6: PublishStep,
};

const PANELS = {
  assistant: AssistantPanel,
  drafts: DraftsPanel,
  transcribe: TranscribePanel,
};

export default function App() {
  const store = useStore();
  const { ready, bootError, step, panel, debug, notice, dirty } = store;

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
  }, [store.article, step]);

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
  const PanelComponent = panel ? PANELS[panel] : null;

  return (
    <div className={'app-shell' + (panel ? ' with-panel' : '')}>
      <header className="app-header">
        <div className="app-brand">TEMED SEO Editor</div>
        <div className="app-tools">
          <Button variant={panel === 'assistant' ? 'primary' : 'ghost'} onClick={() => store.setPanel('assistant')}>Ассистент</Button>
          <Button variant={panel === 'drafts' ? 'primary' : 'ghost'} onClick={() => store.setPanel('drafts')}>Черновики</Button>
          <Button variant={panel === 'transcribe' ? 'primary' : 'ghost'} onClick={() => store.setPanel('transcribe')}>Транскрибация</Button>
          <label className="debug-toggle">
            <input type="checkbox" checked={debug} onChange={(e) => store.setDebug(e.target.checked)} /> Отладка
          </label>
        </div>
      </header>

      <Wizard />

      {notice && (
        <div className="notice-bar">
          <Notice notice={notice} onClose={() => store.setNotice(null)} />
        </div>
      )}

      <div className="app-main">
        <main className="content-col">
          <StepComponent />
          <StepNav />
        </main>
        {PanelComponent && (
          <aside className="side-panel">
            <PanelComponent />
          </aside>
        )}
      </div>
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
