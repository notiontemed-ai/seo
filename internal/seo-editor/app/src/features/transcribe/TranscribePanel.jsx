import React, { useState } from 'react';
import { useStore } from '../../store/useStore.js';
import { api } from '../../api/client.js';
import { Button, Spinner, Notice } from '../../components/ui.jsx';

function fileToBase64(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result).split(',')[1] || '');
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });
}

// Транскрибация: transcribe_case → черновик блока case_study.
export default function TranscribePanel() {
  const { addBlock, setBlocks, article, setNotice } = useStore();
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [transcript, setTranscript] = useState('');

  const onFile = async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setLoading(true);
    setError('');
    try {
      const audio_base64 = await fileToBase64(file);
      const res = await api.transcribeCase({ audio_base64, filename: file.name, mime_type: file.type });
      const data = res.data || res;
      setTranscript(data.transcript || data.text || '');
      const cs = data.case_study || data.case || null;
      const block = cs
        ? { type: 'case_study', patient_context: cs.patient_context || '', situation: cs.situation || '', actions: cs.actions || '', outcome: cs.outcome || '' }
        : { type: 'case_study', patient_context: '', situation: data.transcript || data.text || '', actions: '', outcome: '' };
      setBlocks([...article.blocks, block]);
      setNotice({ type: 'success', text: 'Добавлен черновик блока «Случай из практики»' });
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="panel">
      <h3 className="panel-title">Транскрибация</h3>
      <p className="muted">Загрузите аудио — из расшифровки соберётся черновик блока «Случай из практики».</p>
      <input type="file" accept="audio/*" onChange={onFile} disabled={loading} />
      {loading && <Spinner label="Распознавание…" />}
      {error && <Notice notice={{ type: 'error', text: error }} />}
      {transcript && (
        <div className="transcript">
          <h4>Расшифровка</h4>
          <p>{transcript}</p>
        </div>
      )}
    </div>
  );
}
