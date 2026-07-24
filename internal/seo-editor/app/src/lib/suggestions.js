// Фильтрация suggestions ассистента. Карточка допустима только при наличии
// field_id (из allowlist) и непустого value — иначе отбрасывается (в лог).
// «Поле: undefined…» невозможно by design.

export function filterSuggestions(list, allowedFields) {
  const allowed = new Set(allowedFields || []);
  const kept = [];
  const dropped = [];
  for (const s of Array.isArray(list) ? list : []) {
    if (s && s.field_id && allowed.has(s.field_id) && s.value != null && s.value !== '') {
      kept.push(s);
    } else {
      dropped.push(s);
    }
  }
  return { kept, dropped };
}
