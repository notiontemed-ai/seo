<?php

declare(strict_types=1);

/**
 * Резолвер справочных сущностей для смысловых блоков: врачи (инфоблок 65) и
 * услуги (инфоблок 70). Вынесен в интерфейс, чтобы HtmlRenderer/ArticleContent
 * тестировались без окружения Bitrix.
 */
interface ContentReferenceResolver
{
    /** Существует ли врач с таким ID в справочнике (инфоблок 65). */
    public function doctorExists(int $doctorId): bool;

    /**
     * Данные врача для рендера expert_opinion.
     *
     * @return array{name:string,position:string,photo_url:string,url:string}|null
     */
    public function resolveDoctor(int $doctorId): ?array;

    /**
     * Данные услуги для ссылок в diagnostics/treatment_methods.
     *
     * @return array{name:string,url:string}|null
     */
    public function resolveService(int $serviceId): ?array;
}

/**
 * Реализация на API Bitrix. Классы CIBlock* резолвятся при вызове методов,
 * поэтому файл можно подключать в тестах без окружения Bitrix.
 */
final class BitrixContentReferenceResolver implements ContentReferenceResolver
{
    /** @var array<int,array{name:string,position:string,photo_url:string,url:string}|null> */
    private array $doctorCache = [];
    /** @var array<int,array{name:string,url:string}|null> */
    private array $serviceCache = [];

    public function __construct(private array $config)
    {
    }

    public function doctorExists(int $doctorId): bool
    {
        return $this->resolveDoctor($doctorId) !== null;
    }

    public function resolveDoctor(int $doctorId): ?array
    {
        if ($doctorId <= 0) {
            return null;
        }
        if (array_key_exists($doctorId, $this->doctorCache)) {
            return $this->doctorCache[$doctorId];
        }

        $iblockId = (int)($this->config['iblocks']['doctors'] ?? 0);
        if ($iblockId <= 0) {
            return $this->doctorCache[$doctorId] = null;
        }

        $res = CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, 'ID' => $doctorId, 'ACTIVE' => 'Y'],
            false,
            false,
            ['ID', 'NAME', 'DETAIL_PAGE_URL', 'PREVIEW_PICTURE', 'DETAIL_PICTURE']
        );
        $row = $res->GetNextElement();
        if (!$row) {
            return $this->doctorCache[$doctorId] = null;
        }

        $fields = $row->GetFields();
        $props = $row->GetProperties();
        $position = '';
        if (isset($props['POSITION']['VALUE'])) {
            $value = $props['POSITION']['VALUE'];
            $position = is_array($value) ? (string)reset($value) : (string)$value;
        }

        $pictureId = (int)($fields['DETAIL_PICTURE'] ?? 0) ?: (int)($fields['PREVIEW_PICTURE'] ?? 0);
        $photoUrl = $pictureId > 0 ? (string)CFile::GetPath($pictureId) : '';

        return $this->doctorCache[$doctorId] = [
            'name' => (string)($fields['NAME'] ?? ''),
            'position' => $position,
            'photo_url' => $this->absolute($photoUrl),
            'url' => $this->absolute((string)($fields['DETAIL_PAGE_URL'] ?? '')),
        ];
    }

    public function resolveService(int $serviceId): ?array
    {
        if ($serviceId <= 0) {
            return null;
        }
        if (array_key_exists($serviceId, $this->serviceCache)) {
            return $this->serviceCache[$serviceId];
        }

        $iblockId = (int)($this->config['iblocks']['prices'] ?? 0);
        if ($iblockId <= 0) {
            return $this->serviceCache[$serviceId] = null;
        }

        $res = CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, 'ID' => $serviceId, 'ACTIVE' => 'Y'],
            false,
            false,
            ['ID', 'NAME', 'DETAIL_PAGE_URL']
        );
        $row = $res->GetNext();
        if (!$row) {
            return $this->serviceCache[$serviceId] = null;
        }

        return $this->serviceCache[$serviceId] = [
            'name' => (string)($row['NAME'] ?? ''),
            'url' => $this->absolute((string)($row['DETAIL_PAGE_URL'] ?? '')),
        ];
    }

    private function absolute(string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('~^https?://~i', $url)) {
            return $url;
        }
        $base = rtrim((string)($this->config['base_url'] ?? ''), '/');
        return $base . '/' . ltrim($url, '/');
    }
}
