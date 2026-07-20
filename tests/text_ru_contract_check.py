#!/usr/bin/env python3
"""Static regression checks for TEMED TEXT.RU uniqueness integration."""
import json
import re
from pathlib import Path

root = Path(__file__).resolve().parents[1]
editor = (root / 'internal/seo-editor/assets/js/editor.js').read_text(encoding='utf-8')
workflow_path = root / 'n8n/TEMED SEO Editor.json'
workflow = json.loads(workflow_path.read_text(encoding='utf-8'))
html = (root / 'internal/seo-editor/editor.html').read_text(encoding='utf-8')

assert "return payload.data||{}" in editor, 'callN8n must keep returning response data only'
assert "callN8n('start_external_uniqueness',{text,content_hash},button);const data=payload.data||{}" not in editor
assert "callN8n('get_external_uniqueness',{text_uid:state.text_uid,content_hash:state.content_hash},null);const data=payload.data||{}" not in editor
assert "TEXT.RU не вернул идентификатор проверки text_uid" in editor
assert "hasExternalUid" in editor and "isActiveExternalStatus" in editor
assert "sessionStorage.setItem('temed_external_uniqueness'" in editor
assert "start_external_uniqueness',{text,content_hash}" in editor
assert "get_external_uniqueness',{text_uid:state.text_uid,content_hash:state.content_hash}" in editor
assert re.search(r"editor\.js\?v=1\.0\.1", html), 'editor.js cache buster must be incremented'

nodes = {node['name']: node for node in workflow['nodes']}
submit = nodes['TEXT.RU — Submit text']['parameters']['jsonBody']
result = nodes['TEXT.RU — Get result']['parameters']['jsonBody']
assert "$json.text" in submit and "exceptdomain" in submit and "copying" in submit
assert "$json.text_uid" in result and "jsonvisible" in result
assert "uid:" in result and "text:" not in result
assert "userkey" not in workflow_path.read_text(encoding='utf-8').lower()
assert '={ $json' not in workflow_path.read_text(encoding='utf-8')
assert nodes['IF TEXT.RU submit prepared']['parameters']['conditions']['conditions'][0]['leftValue'] == '={{ $json.text }}'
assert nodes['IF TEXT.RU result prepared']['parameters']['conditions']['conditions'][0]['leftValue'] == '={{ $json.text_uid }}'

print('TEXT.RU contract checks passed')
