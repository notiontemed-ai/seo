<?php

declare(strict_types=1);

require_once __DIR__ . '/../../internal/seo-editor/lib/BitrixArticleXmlExporter.php';

$payload = json_decode((string)file_get_contents(__DIR__ . '/../fixtures/bitrix-article-payload.json'), true);
$exporter = new BitrixArticleXmlExporter();
$xml = $exporter->export($payload)->saveXML();
if (!str_contains($xml, '<active><![CDATA[N]]></active>')) { fwrite(STDERR, "ACTIVE=N missing\n"); exit(1); }
if (!str_contains($xml, 'Короткий анонс & проверка UTF-8.')) { fwrite(STDERR, "UTF-8 text missing\n"); exit(1); }
if (!str_contains($xml, ']]&gt;')) { fwrite(STDERR, "CDATA terminator handling missing\n"); exit(1); }
echo "exporter ok\n";
