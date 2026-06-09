<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cầu nối sản phẩm global cho plugin Viettel Invoice.
 *
 * Quy ước: hóa đơn vẫn lấy dòng bán hàng từ local_ledger_item, nhưng catalog
 * sản phẩm phải lấy từ TGS_Global_Product_Source / wp_global_product_name.
 * Các key local_product_* bên dưới chỉ là alias để giữ tương thích payload cũ.
 */
class TGS_Viettel_Invoice_Global_Products
{
    private static function ensure_global_constants()
    {
        global $wpdb;

        if (!defined('TGS_TABLE_GLOBAL_PRODUCT_NAME')) {
            define('TGS_TABLE_GLOBAL_PRODUCT_NAME', $wpdb->base_prefix . 'global_product_name');
        }

        if (!defined('TGS_TABLE_GLOBAL_MILK_UNDER24M')) {
            define('TGS_TABLE_GLOBAL_MILK_UNDER24M', $wpdb->base_prefix . 'global_milk_under24m');
        }
    }

    private static function ensure_source()
    {
        self::ensure_global_constants();

        if (class_exists('TGS_Global_Product_Source')) {
            return true;
        }

        $plugin_root = defined('WP_PLUGIN_DIR')
            ? WP_PLUGIN_DIR
            : dirname(TGS_VIETTEL_INVOICE_PLUGIN_DIR);

        $candidates = [
            trailingslashit($plugin_root) . 'tgs_shop_management/functions/class-tgs-global-product-source.php',
            trailingslashit(dirname(TGS_VIETTEL_INVOICE_PLUGIN_DIR)) . 'tgs_shop_management/functions/class-tgs-global-product-source.php',
        ];

        foreach ($candidates as $file) {
            if (is_readable($file)) {
                require_once $file;
                break;
            }
        }

        return class_exists('TGS_Global_Product_Source');
    }

    public static function is_available()
    {
        return self::ensure_source();
    }

    public static function query_products(array $args = [])
    {
        if (!self::ensure_source()) {
            return [
                'items' => [],
                'total' => 0,
                'page' => 1,
                'per_page' => 0,
                'total_pages' => 0,
            ];
        }

        $args = wp_parse_args($args, [
            'parent_only' => false,
            'with_local_aliases' => true,
            'status_filter' => 'all',
        ]);

        return TGS_Global_Product_Source::query_products($args);
    }

    public static function get_products_by_ids(array $ids, $blog_id = 0)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            return [];
        }

        $args = [
            'ids' => $ids,
            'per_page' => count($ids),
            'parent_only' => false,
            'with_local_aliases' => true,
            'status_filter' => 'all',
        ];

        if ((int) $blog_id > 0) {
            $args['blog_id'] = (int) $blog_id;
        }

        $result = self::query_products($args);
        return is_array($result['items'] ?? null) ? $result['items'] : [];
    }

    public static function get_products_by_skus(array $skus, $blog_id = 0)
    {
        $skus = array_values(array_unique(array_filter(array_map(static function ($sku) {
            return trim((string) $sku);
        }, $skus))));

        if (empty($skus)) {
            return [];
        }

        $args = [
            'skus' => $skus,
            'per_page' => count($skus),
            'parent_only' => false,
            'with_local_aliases' => true,
            'status_filter' => 'all',
        ];

        if ((int) $blog_id > 0) {
            $args['blog_id'] = (int) $blog_id;
        }

        $result = self::query_products($args);
        return is_array($result['items'] ?? null) ? $result['items'] : [];
    }

    public static function enrich_ledger_items(array $rows, $blog_id = 0)
    {
        $ids = [];
        $skus = [];

        foreach ($rows as $row) {
            $row = is_array($row) ? $row : (array) $row;

            $product_id = self::row_product_id($row);
            if ($product_id > 0) {
                $ids[] = $product_id;
            }

            $sku = self::row_sku($row);
            if ($sku !== '') {
                $skus[] = $sku;
            }
        }

        $products_by_id = [];
        foreach (self::get_products_by_ids($ids, $blog_id) as $product) {
            $id = (int) ($product['global_product_name_id'] ?? 0);
            if ($id > 0) {
                $products_by_id[$id] = $product;
            }
        }

        $products_by_sku = [];
        foreach (self::get_products_by_skus($skus, $blog_id) as $product) {
            $sku = trim((string) ($product['global_product_sku'] ?? ''));
            if ($sku !== '') {
                $products_by_sku[$sku] = $product;
            }
        }

        $enriched = [];
        foreach ($rows as $row) {
            $row = is_array($row) ? $row : (array) $row;

            $product_id = self::row_product_id($row);
            $row_sku = self::row_sku($row);
            $product = ($product_id > 0 && isset($products_by_id[$product_id]))
                ? $products_by_id[$product_id]
                : null;

            if (!$product && $row_sku !== '' && isset($products_by_sku[$row_sku])) {
                $product = $products_by_sku[$row_sku];
            }

            $global_id = $product ? (int) ($product['global_product_name_id'] ?? 0) : $product_id;
            $sku = $row_sku !== ''
                ? $row_sku
                : (string) ($product['global_product_sku'] ?? '');

            $row['global_product_name_id'] = $global_id;
            $row['global_product_sku'] = $sku;
            $row['global_product_name'] = (string) ($product['global_product_name'] ?? '');
            $row['global_product_unit'] = (string) ($product['global_product_unit'] ?? '');

            // Alias cũ cho payload đang dùng chung với POS. Không đọc bảng local_product_name.
            $row['local_product_name_id'] = $global_id;
            $row['local_product_sku'] = $sku;
            $row['local_product_name'] = $row['global_product_name'];
            $row['local_product_unit'] = $row['global_product_unit'];

            $enriched[] = $row;
        }

        return $enriched;
    }

    public static function row_product_id(array $row)
    {
        $global_id = (int) ($row['global_product_name_id'] ?? 0);
        if ($global_id > 0) {
            return $global_id;
        }

        return (int) ($row['local_product_name_id'] ?? 0);
    }

    public static function row_sku(array $row)
    {
        foreach (['global_product_sku', 'local_product_sku', 'sku'] as $key) {
            $sku = trim((string) ($row[$key] ?? ''));
            if ($sku !== '') {
                return $sku;
            }
        }

        return '';
    }

    public static function find_under24_skus(array $skus)
    {
        global $wpdb;

        self::ensure_global_constants();

        $skus = array_values(array_unique(array_filter(array_map(static function ($sku) {
            return trim((string) $sku);
        }, $skus))));

        if (empty($skus) || !self::table_exists(TGS_TABLE_GLOBAL_MILK_UNDER24M)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($skus), '%s'));
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT global_product_sku
                 FROM ' . TGS_TABLE_GLOBAL_MILK_UNDER24M . '
                 WHERE global_product_sku IN (' . $placeholders . ')
                   AND (is_deleted = 0 OR is_deleted IS NULL)',
                ...$skus
            )
        );

        return empty($rows) ? [] : array_values(array_unique(array_map('strval', $rows)));
    }

    private static function table_exists($table)
    {
        global $wpdb;

        if (self::ensure_source() && method_exists('TGS_Global_Product_Source', 'table_exists')) {
            return TGS_Global_Product_Source::table_exists($table);
        }

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }
}
