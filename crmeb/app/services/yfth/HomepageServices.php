<?php

namespace app\services\yfth;

use app\services\yfth\AuditEventServices;
use think\facade\Db;

/**
 * Keeps the fixed YFTH customer-home layout configurable without coupling it
 * to CRMEB's product, order, payment, or generic DIY records.
 */
class HomepageServices
{
    private const CONFIG_ID = 1;

    public function publicConfig(): array
    {
        $config = $this->config();
        $categories = $this->categories();
        $fallbackCategoryId = $this->fallbackCategoryId($categories);

        foreach ($config['quick_entries'] as &$entry) {
            $entry['target'] = $this->target($entry, $fallbackCategoryId);
            $entry['icon_url'] = $this->fileUrl($entry['icon_url'] ?? '');
            unset($entry['product_ids'], $entry['package_id'], $entry['category_id']);
        }
        unset($entry);

        foreach ($config['sections'] as &$section) {
            $categoryId = (int)$section['category_id'] ?: $fallbackCategoryId;
            $section['target'] = $this->target($section, $categoryId);
            $section['items'] = $section['content_type'] === 'package'
                ? $this->packages((int)$section['package_id'], (int)$section['display_limit'])
                : $this->products($categoryId, $section['product_ids'], (int)$section['display_limit']);
            $section['image_url'] = $this->fileUrl($section['image_url'] ?? '');
            unset($section['product_ids'], $section['package_id'], $section['category_id'], $section['content_type']);
        }
        unset($section);

        return [
            'enabled' => (bool)$config['enabled'],
            'version' => $config['version'],
            'header' => $config['header'],
            'quick_entries' => array_values(array_filter($config['quick_entries'], static function ($entry) {
                return !empty($entry['visible']);
            })),
            'sections' => array_values(array_filter($config['sections'], static function ($section) {
                return !empty($section['visible']);
            })),
        ];
    }

    public function adminConfig(): array
    {
        return [
            'config' => $this->config(),
            'options' => $this->options(),
        ];
    }

    public function options(): array
    {
        return [
            'categories' => $this->categories(),
            'products' => Db::name('store_product')
                ->where('is_show', 1)
                ->where('is_del', 0)
                ->field('id,store_name,image,cate_id,price,stock')
                ->order('sort desc,id desc')
                ->limit(100)
                ->select()
                ->toArray(),
            'packages' => Db::name('yfth_package_template')
                ->where('status', 'published')
                ->field('id,package_name,package_title,package_code,sort')
                ->order('sort desc,id desc')
                ->select()
                ->toArray(),
        ];
    }

    public function save(array $payload, int $adminId): array
    {
        $before = $this->config();
        $config = $this->normalize($payload);
        $row = [
            'id' => self::CONFIG_ID,
            'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE),
            'version' => (int)$before['version'] + 1,
            'update_time' => time(),
        ];

        if (Db::name('yfth_homepage_config')->where('id', self::CONFIG_ID)->find()) {
            Db::name('yfth_homepage_config')->where('id', self::CONFIG_ID)->update($row);
        } else {
            $row['add_time'] = time();
            Db::name('yfth_homepage_config')->insert($row);
        }

        $config['version'] = $row['version'];
        app()->make(AuditEventServices::class)->recordSafely(
            'yfth_homepage',
            'homepage_config',
            (string)self::CONFIG_ID,
            'save',
            $this->auditSummary($before),
            $this->auditSummary($config),
            $adminId,
            'headquarters_admin'
        );

        return $config;
    }

    private function config(): array
    {
        $defaults = $this->defaults();
        $row = Db::name('yfth_homepage_config')->where('id', self::CONFIG_ID)->find();
        if (!$row || empty($row['config_json'])) {
            return $defaults;
        }
        $saved = json_decode((string)$row['config_json'], true);
        if (!is_array($saved)) {
            return $defaults;
        }
        $saved['version'] = (int)$row['version'];
        return $this->normalize($saved, $defaults);
    }

    private function defaults(): array
    {
        $quickTitles = ['调养项目', '套餐', '同源产品区', '中药日化', '化产品区', '食硒厨房', '化产品区', '富硒厨房', '产品区', '食疗药膳产品区', '营养医学', '学产品区'];
        $quickEntries = [];
        foreach ($quickTitles as $sort => $title) {
            $quickEntries[] = [
                'title' => $title,
                'icon_url' => '',
                'target_type' => $title === '套餐' ? 'package_list' : 'category',
                'target_path' => '',
                'category_id' => 0,
                'product_ids' => [],
                'package_id' => 0,
                'visible' => 1,
                'sort' => $sort + 1,
            ];
        }

        $sectionTitles = ['调养项目套餐', '食药同源产品区', '中药日化产品区', '富硒厨房产品区', '食疗药膳产品区', '营养医学产品区'];
        $sections = [];
        foreach ($sectionTitles as $sort => $title) {
            $sections[] = [
                'title' => $title,
                'image_url' => '',
                'content_type' => $sort === 0 ? 'package' : 'product',
                'target_type' => $sort === 0 ? 'package_list' : 'category',
                'target_path' => '',
                'category_id' => 0,
                'product_ids' => [],
                'package_id' => 0,
                'display_limit' => $sort === 0 ? 2 : 6,
                'visible' => 1,
                'sort' => $sort + 1,
            ];
        }

        return [
            'enabled' => 1,
            'version' => 0,
            'header' => ['title' => '御方通和', 'search_placeholder' => '搜索调养好物'],
            'quick_entries' => $quickEntries,
            'sections' => $sections,
        ];
    }

    private function normalize(array $payload, ?array $defaults = null): array
    {
        $defaults = $defaults ?: $this->defaults();
        $header = is_array($payload['header'] ?? null) ? $payload['header'] : [];
        $config = [
            'enabled' => empty($payload['enabled']) ? 0 : 1,
            'version' => (int)($payload['version'] ?? $defaults['version']),
            'header' => [
                'title' => $this->text($header['title'] ?? $defaults['header']['title'], 32),
                'search_placeholder' => $this->text($header['search_placeholder'] ?? $defaults['header']['search_placeholder'], 40),
            ],
            'quick_entries' => $this->normalizeEntries($payload['quick_entries'] ?? $defaults['quick_entries'], false),
            'sections' => $this->normalizeEntries($payload['sections'] ?? $defaults['sections'], true),
        ];
        return $config;
    }

    private function normalizeEntries($entries, bool $section): array
    {
        if (!is_array($entries)) {
            return [];
        }
        $result = [];
        foreach (array_slice($entries, 0, $section ? 12 : 16) as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $targetType = (string)($entry['target_type'] ?? 'category');
            if (!in_array($targetType, ['category', 'product', 'package_list', 'package_detail', 'path'], true)) {
                $targetType = 'category';
            }
            $item = [
                'title' => $this->text($entry['title'] ?? '', 32),
                'target_type' => $targetType,
                'target_path' => $this->text($entry['target_path'] ?? '', 255),
                'category_id' => max(0, (int)($entry['category_id'] ?? 0)),
                'product_ids' => $this->ids($entry['product_ids'] ?? []),
                'package_id' => max(0, (int)($entry['package_id'] ?? 0)),
                'visible' => empty($entry['visible']) ? 0 : 1,
                'sort' => max(0, (int)($entry['sort'] ?? $index + 1)),
            ];
            if ($section) {
                $item['image_url'] = $this->text($entry['image_url'] ?? '', 500);
                $item['content_type'] = ($entry['content_type'] ?? 'product') === 'package' ? 'package' : 'product';
                $item['display_limit'] = min(8, max(1, (int)($entry['display_limit'] ?? 6)));
            } else {
                $item['icon_url'] = $this->text($entry['icon_url'] ?? '', 500);
            }
            $result[] = $item;
        }
        usort($result, static function ($left, $right) {
            return $left['sort'] <=> $right['sort'];
        });
        return $result;
    }

    private function categories(): array
    {
        return Db::name('store_category')
            ->where('is_show', 1)
            ->where('is_del', 0)
            ->field('id,pid,cate_name,pic,big_pic,sort')
            ->order('sort desc,id asc')
            ->select()
            ->toArray();
    }

    private function fallbackCategoryId(array $categories): int
    {
        foreach ($categories as $category) {
            if (strpos((string)$category['cate_name'], '御方通和') !== false) {
                return (int)$category['id'];
            }
        }
        return isset($categories[0]) ? (int)$categories[0]['id'] : 0;
    }

    private function products(int $categoryId, array $productIds, int $limit): array
    {
        $query = Db::name('store_product')->where('is_show', 1)->where('is_del', 0);
        if ($productIds) {
            $query->whereIn('id', $productIds);
        } elseif ($categoryId) {
            $query->whereRaw('FIND_IN_SET(' . (int)$categoryId . ', cate_id)');
        }
        $items = $query->field('id,store_name,image,price,ot_price,stock,cate_id')
            ->order('sort desc,id desc')
            ->limit($limit)
            ->select()
            ->toArray();
        foreach ($items as &$item) {
            $item['image'] = $this->fileUrl($item['image'] ?? '');
        }
        unset($item);
        return $items;
    }

    private function packages(int $packageId, int $limit): array
    {
        $query = Db::name('yfth_package_template')->where('status', 'published');
        if ($packageId) {
            $query->where('id', $packageId);
        }
        return $query->field('id,package_name,package_title,package_code,sort')
            ->order('sort desc,id desc')
            ->limit($limit)
            ->select()
            ->toArray();
    }

    private function target(array $entry, int $fallbackCategoryId): array
    {
        $type = (string)($entry['target_type'] ?? 'category');
        if ($type === 'category') {
            return ['type' => 'category', 'id' => (int)($entry['category_id'] ?? 0) ?: $fallbackCategoryId];
        }
        if ($type === 'product') {
            $productIds = $entry['product_ids'] ?? [];
            return ['type' => 'product', 'id' => (int)($productIds[0] ?? 0)];
        }
        if ($type === 'package_detail') {
            return ['type' => 'package_detail', 'id' => (int)($entry['package_id'] ?? 0)];
        }
        if ($type === 'path') {
            return ['type' => 'path', 'path' => (string)($entry['target_path'] ?? '')];
        }
        return ['type' => 'package_list'];
    }

    private function ids($value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', $value))));
    }

    private function text($value, int $limit): string
    {
        return mb_substr(trim((string)$value), 0, $limit);
    }

    private function auditSummary(array $config): array
    {
        return [
            'enabled' => (int)($config['enabled'] ?? 0),
            'version' => (int)($config['version'] ?? 0),
            'quick_entry_count' => count($config['quick_entries'] ?? []),
            'section_count' => count($config['sections'] ?? []),
        ];
    }

    private function fileUrl(string $value): string
    {
        return $value === '' ? '' : set_file_url($value);
    }
}
