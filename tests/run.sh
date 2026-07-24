#!/usr/bin/env bash
# Запуск всех автоматических тестов проекта.
# PHP-тесты не требуют окружения Bitrix (используют фикстуры/заглушки).
set -u
cd "$(dirname "$0")/.."

fail=0

echo "== PHP tests =="
for t in tests/php/*_test.php; do
  printf '  %-45s ' "$(basename "$t")"
  if php "$t" >/tmp/temed_test_out 2>&1; then
    echo "OK"
  else
    echo "FAIL"
    cat /tmp/temed_test_out
    fail=1
  fi
done

echo
echo "== JS tests (Vitest) =="
if [ -d internal/seo-editor/app/node_modules ]; then
  ( cd internal/seo-editor/app && npm test --silent ) || fail=1
else
  echo "  пропущено: нет node_modules (выполните npm install в internal/seo-editor/app)"
fi

echo
if [ "$fail" -eq 0 ]; then
  echo "ВСЕ ТЕСТЫ ПРОЙДЕНЫ"
else
  echo "ЕСТЬ ПАДЕНИЯ"
fi
exit "$fail"
