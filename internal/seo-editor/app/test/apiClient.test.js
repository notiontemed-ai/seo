import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { api } from '../src/api/client.js';

function okResponse(json = { success: true }) {
  return {
    ok: true,
    status: 200,
    text: () => Promise.resolve(JSON.stringify(json)),
  };
}

describe('api.transcribeCase', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(okResponse())));
  });
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('wraps payload into data (contract {action, data})', async () => {
    const payload = {
      audio_base64: 'QUJD',
      filename: 'case.ogg',
      mime_type: 'audio/ogg',
    };
    await api.transcribeCase(payload);

    expect(fetch).toHaveBeenCalledTimes(1);
    const [, init] = fetch.mock.calls[0];
    expect(JSON.parse(init.body)).toEqual({
      action: 'transcribe_case',
      data: {
        audio_base64: 'QUJD',
        filename: 'case.ogg',
        mime_type: 'audio/ogg',
      },
    });
  });

  it('does not leak audio fields to the top level of the request', async () => {
    await api.transcribeCase({ audio_base64: 'QUJD', filename: 'a.mp3', mime_type: 'audio/mpeg' });
    const body = JSON.parse(fetch.mock.calls[0][1].body);
    expect(body.audio_base64).toBeUndefined();
    expect(body.filename).toBeUndefined();
    expect(body.mime_type).toBeUndefined();
  });
});
