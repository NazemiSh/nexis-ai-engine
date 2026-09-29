<?php
/**
 * Plugin Name: Nexis AI Engine
 * Plugin URI: https://github.com/NazemiSh/nexis-ai-engine
 * Description: پلتفرم سازمانی هوش مصنوعی و بهینه‌ساز سئو معنایی (GEO) وردپرس با موتور RAG هیبریدی، استخراج عمیق المنتور، ابزار دیباگ و تست جستجو، پشتیبانی چندمحیطه و مدیریت لایسنس
 * Version: 1.0.7
 * Author: Nexis AI Core
 * Author URI: https://github.com/NazemiSh
 * Text Domain: nexis-ai-engine
 */

if (!defined('ABSPATH')) exit;

define('NEXIS_AI_VERSION', '1.0.7');
define('NEXIS_AI_GITHUB_REPO', 'NazemiSh/nexis-ai-engine');
define('NEXIS_AI_SECRET_SALT', 'NEXIS_CORE_SECURE_SALT_99812_xK9#');
define('NEXIS_AI_GITHUB_TOKEN', '');

register_activation_hook(__FILE__, 'nexis_ai_create_db_tables');

function nexis_ai_create_db_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $table_knowledge = $wpdb->prefix . 'nexis_ai_knowledge';
    $sql_knowledge = "CREATE TABLE $table_knowledge (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        post_id bigint(20) NOT NULL,
        post_type varchar(50) NOT NULL,
        title text NOT NULL,
        url text NOT NULL,
        content longtext NOT NULL,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY post_id (post_id),
        FULLTEXT KEY content_index (title, content)
    ) $charset_collate;";

    $table_logs = $wpdb->prefix . 'nexis_ai_logs';
    $sql_logs = "CREATE TABLE $table_logs (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_ip varchar(100) DEFAULT '',
        user_query text NOT NULL,
        ai_response longtext NOT NULL,
        model_used varchar(100) DEFAULT '',
        status varchar(50) DEFAULT 'success',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY status_idx (status),
        KEY created_at_idx (created_at)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_knowledge);
    dbDelta($sql_logs);
}

add_action('plugins_loaded', function() {
    global $wpdb;
    $table_logs = $wpdb->prefix . 'nexis_ai_logs';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_logs'") != $table_logs) {
        nexis_ai_create_db_tables();
    }
});

// تبدیل تقویم جلالی
function nexis_ai_gregorian_to_jalali($gy, $gm, $gd) {
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $jy = ($gy <= 1600) ? 0 : 979;
    $gy -= ($gy <= 1600) ? 621 : 1600;
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = (365 * $gy) + intval(($gy2 + 3) / 4) - intval(($gy2 + 99) / 100) + intval(($gy2 + 399) / 400) - 80 + $gd + $g_d_m[$gm - 1];
    $jy += 33 * intval($days / 12053);
    $days %= 12053;
    $jy += 4 * intval($days / 1461);
    $days %= 1461;
    $jy += intval(($days - 1) / 365);
    if ($days > 0) $days = ($days - 1) % 365;
    if ($days < 186) {
        $jm = 1 + intval($days / 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + intval(($days - 186) / 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return [$jy, $jm, $jd];
}

function nexis_ai_format_persian_datetime($datetime_str) {
    if (empty($datetime_str)) return '';
    $timestamp = strtotime($datetime_str);
    if (!$timestamp) return $datetime_str;

    if (function_exists('wp_date') && (function_exists('jdate') || class_exists('WP_Parsidate'))) {
        return wp_date('Y/m/d H:i:s', $timestamp);
    }

    $gy = intval(date('Y', $timestamp));
    $gm = intval(date('m', $timestamp));
    $gd = intval(date('d', $timestamp));
    $time_part = date('H:i:s', $timestamp);

    list($jy, $jm, $jd) = nexis_ai_gregorian_to_jalali($gy, $gm, $gd);
    return sprintf('%04d/%02d/%02d %s', $jy, $jm, $jd, $time_part);
}

// موتور آپدیت مستقیم از گیت‌هاب
function nexis_ai_check_github_update() {
    $url = 'https://api.github.com/repos/' . NEXIS_AI_GITHUB_REPO . '/releases/latest';
    $headers = [
        'User-Agent' => 'Nexis-AI-Engine-Updater/' . NEXIS_AI_VERSION,
        'Accept'     => 'application/vnd.github.v3+json'
    ];
    if (defined('NEXIS_AI_GITHUB_TOKEN') && !empty(NEXIS_AI_GITHUB_TOKEN)) {
        $headers['Authorization'] = 'Bearer ' . NEXIS_AI_GITHUB_TOKEN;
    }

    $response = wp_remote_get($url, [
        'headers' => $headers,
        'timeout' => 15
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return false;
    }

    $release = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($release) || empty($release['tag_name'])) return false;

    $latest_ver = ltrim($release['tag_name'], 'v');
    $zip_url    = isset($release['zipball_url']) ? $release['zipball_url'] : '';

    return [
        'new_version'   => $latest_ver,
        'package'       => $zip_url,
        'url'           => $release['html_url'],
        'has_update'    => version_compare(NEXIS_AI_VERSION, $latest_ver, '<'),
        'published_at'  => $release['published_at'],
        'body'          => isset($release['body']) ? $release['body'] : ''
    ];
}

add_filter('site_transient_update_plugins', function($transient) {
    if (empty($transient->checked)) return $transient;

    $update_data = nexis_ai_check_github_update();
    if ($update_data && $update_data['has_update']) {
        $plugin_slug = plugin_basename(__FILE__);
        $obj = new stdClass();
        $obj->slug        = 'nexis-ai-engine';
        $obj->plugin      = $plugin_slug;
        $obj->new_version = $update_data['new_version'];
        $obj->url         = $update_data['url'];
        $obj->package     = $update_data['package'];
        $transient->response[$plugin_slug] = $obj;
    }
    return $transient;
});

add_filter('http_request_args', function($args, $url) {
    if (strpos($url, 'api.github.com/repos/' . NEXIS_AI_GITHUB_REPO) !== false || strpos($url, 'codeload.github.com/' . NEXIS_AI_GITHUB_REPO) !== false) {
        if (defined('NEXIS_AI_GITHUB_TOKEN') && !empty(NEXIS_AI_GITHUB_TOKEN)) {
            $args['headers']['Authorization'] = 'Bearer ' . NEXIS_AI_GITHUB_TOKEN;
        }
    }
    return $args;
}, 10, 2);

add_filter('upgrader_source_selection', function($source, $remote_source, $upgrader, $hook_extra = []) {
    global $wp_filesystem;
    if (isset($hook_extra['plugin']) && strpos($hook_extra['plugin'], 'nexis-ai-engine') !== false) {
        $proper_source = trailingslashit($remote_source) . 'nexis-ai-engine/';
        if ($source !== $proper_source) {
            $wp_filesystem->move($source, $proper_source, true);
            return $proper_source;
        }
    }
    return $source;
}, 10, 4);

add_action('wp_ajax_nexis_ai_check_update_now', function() {
    check_ajax_referer('nexis_ai_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'دسترسی غیرمجاز']);

    $res = nexis_ai_check_github_update();
    if (!$res) {
        wp_send_json_success([
            'status'  => 'up_to_date',
            'message' => 'نسخه فعلی (' . NEXIS_AI_VERSION . ') آخرین نسخه است یا مخزن گیت‌هاب ریلیزی ندارد.'
        ]);
    }

    if ($res['has_update']) {
        wp_send_json_success([
            'status'      => 'update_available',
            'latest'      => $res['new_version'],
            'current'     => NEXIS_AI_VERSION,
            'message'     => 'نسخه جدید ' . $res['new_version'] . ' در دسترس است!',
            'release_url' => $res['url']
        ]);
    } else {
        wp_send_json_success([
            'status'  => 'up_to_date',
            'message' => 'شما در حال حاضر از آخرین نسخه (' . NEXIS_AI_VERSION . ') استفاده می‌کنید.'
        ]);
    }
});

// مدیریت لایسنس
function nexis_ai_validate_license($license_key) {
    if (empty($license_key)) return ['valid' => false, 'message' => 'کلید لایسنس وارد نشده است.'];
    $parts = explode('.', trim($license_key));
    if (count($parts) !== 2) return ['valid' => false, 'message' => 'ساختار فرمت کلید نامعتبر است.'];

    list($payload_b64, $signature) = $parts;
    $expected_sig = hash_hmac('sha256', $payload_b64, NEXIS_AI_SECRET_SALT);
    if (!hash_equals($expected_sig, $signature)) return ['valid' => false, 'message' => 'امضای امنیتی کلید نامعتبر است.'];

    $data = json_decode(base64_decode($payload_b64), true);
    if (!$data || !isset($data['domain']) || !isset($data['exp'])) return ['valid' => false, 'message' => 'داده‌های لایسنس خوانا نیستند.'];

    $site_host = parse_url(home_url(), PHP_URL_HOST);
    if ($data['domain'] !== '*' && strtolower($data['domain']) !== strtolower($site_host)) {
        return ['valid' => false, 'message' => "این لایسنس برای دامنه {$data['domain']} صادر شده است."];
    }

    $is_lifetime = (intval($data['exp']) === 0);
    if (!$is_lifetime && time() > intval($data['exp'])) {
        return ['valid' => false, 'message' => 'تاریخ اعتبار این لایسنس منقضی شده است.'];
    }

    return [
        'valid'        => true,
        'domain'       => $data['domain'],
        'expires_at'   => $is_lifetime ? 'همیشگی (Lifetime)' : date('Y-m-d', $data['exp']),
        'is_lifetime'  => $is_lifetime,
        'max_requests' => isset($data['max_req']) ? intval($data['max_req']) : 0,
        'client_name'  => isset($data['client']) ? sanitize_text_field($data['client']) : 'کاربر ویژه'
    ];
}

function nexis_ai_get_license_status() {
    $settings = get_option('nexis_ai_settings', []);
    $license_key = !empty($settings['license_key']) ? trim($settings['license_key']) : '';
    $trial_count = intval(get_option('nexis_ai_trial_requests', 0));

    if (!empty($license_key)) {
        $val = nexis_ai_validate_license($license_key);
        if ($val['valid']) {
            $key_hash = md5($license_key);
            $lic_count = intval(get_option('nexis_ai_lic_req_' . $key_hash, 0));
            $max_req = $val['max_requests'];

            if ($max_req > 0 && $lic_count >= $max_req) {
                return [
                    'allowed'     => false,
                    'status'      => 'quota_exceeded',
                    'message'     => 'سقف مجاز لایسنس (' . number_format($max_req) . ') به پایان رسیده است.',
                    'details'     => $val,
                    'lic_count'   => $lic_count,
                    'trial_count' => $trial_count
                ];
            }

            $req_display = ($max_req > 0) ? (number_format($lic_count) . ' از ' . number_format($max_req)) : (number_format($lic_count) . ' (نامحدود)');
            return [
                'allowed'     => true,
                'status'      => 'active',
                'message'     => 'لایسنس فعال (مصرف: ' . $req_display . ' | انقضا: ' . $val['expires_at'] . ')',
                'details'     => $val,
                'lic_count'   => $lic_count,
                'trial_count' => $trial_count
            ];
        } else {
            return [
                'allowed'     => false,
                'status'      => 'invalid',
                'message'     => 'لایسنس نامعتبر: ' . $val['message'],
                'trial_count' => $trial_count,
                'lic_count'   => 0
            ];
        }
    }

    $trial_limit = 25;
    if ($trial_count < $trial_limit) {
        return [
            'allowed'     => true,
            'status'      => 'trial',
            'message'     => 'نسخه آزمایشی (' . $trial_count . ' از ' . $trial_limit . ' ریکوئست رایگان)',
            'trial_count' => $trial_count,
            'limit'       => $trial_limit,
            'remain'      => ($trial_limit - $trial_count),
            'lic_count'   => 0
        ];
    }

    return [
        'allowed'     => false,
        'status'      => 'expired',
        'message'     => 'مهلت نسخه آزمایشی به اتمام رسیده است.',
        'trial_count' => $trial_count,
        'lic_count'   => 0
    ];
}

function nexis_ai_increment_request_count() {
    $settings = get_option('nexis_ai_settings', []);
    $license_key = !empty($settings['license_key']) ? trim($settings['license_key']) : '';

    if (!empty($license_key)) {
        $val = nexis_ai_validate_license($license_key);
        if ($val['valid']) {
            $key_hash = md5($license_key);
            $c = intval(get_option('nexis_ai_lic_req_' . $key_hash, 0));
            update_option('nexis_ai_lic_req_' . $key_hash, $c + 1);
            return;
        }
    }

    $t = intval(get_option('nexis_ai_trial_requests', 0));
    update_option('nexis_ai_trial_requests', $t + 1);
}

// استخراج عمیق المان‌های سازگار با تمام صفحه‌سازها
function nexis_ai_extract_elementor_texts($elements, &$extracted = []) {
    if (!is_array($elements)) return;
    foreach ($elements as $el) {
        if (!empty($el['settings']) && is_array($el['settings'])) {
            foreach ($el['settings'] as $k => $v) {
                if (is_string($v) && !empty($v)) {
                    if (in_array($k, ['title', 'text', 'editor', 'description', 'heading_title', 'caption', 'testimonial_content', 'alert_title'])) {
                        $cleaned = wp_strip_all_tags($v);
                        if (!empty($cleaned)) $extracted[] = $cleaned;
                    }
                } elseif (is_array($v)) {
                    foreach ($v as $sub_item) {
                        if (is_array($sub_item)) {
                            foreach (['tab_title', 'tab_content', 'item_title', 'item_description', 'list_title', 'text'] as $field_key) {
                                if (!empty($sub_item[$field_key]) && is_string($sub_item[$field_key])) {
                                    $extracted[] = wp_strip_all_tags($sub_item[$field_key]);
                                }
                            }
                        }
                    }
                }
            }
        }
        if (!empty($el['elements']) && is_array($el['elements'])) {
            nexis_ai_extract_elementor_texts($el['elements'], $extracted);
        }
    }
}

function nexis_ai_clean_content($content) {
    $content = preg_replace('/\[vc_[^\]]+\]|\[\/vc_[^\]]+\]/i', ' ', $content);
    $content = strip_shortcodes($content);
    $content = wp_strip_all_tags($content);
    $content = preg_replace('/\s+/', ' ', $content);
    $content = str_replace(['ي', 'ك', 'ة', "\xc2\xa0", '‌'], ['ی', 'ک', 'ه', ' ', ' '], $content);
    return trim($content);
}

function nexis_ai_normalize_text($str) {
    $str = mb_strtolower($str, 'UTF-8');
    $str = str_replace(
        ['ي', 'ك', 'ة', '‌', "\xc2\xa0", '؟', '?', '!', '،', '؛', '.', ',', 'ماکروفری', 'مایکروفری', 'ماکروویو', 'مایکروفر', 'ماکروفر', 'فرصت های شغلی', 'فرصت‌های شغلی'],
        ['ی', 'ک', 'ه', ' ', ' ', '', '', '', '', '', '', '', 'مایکروویو', 'مایکروویو', 'مایکروویو', 'مایکروویو', 'مایکروویو', 'استخدام فرصت های شغلی', 'استخدام فرصت های شغلی'],
        $str
    );
    return trim($str);
}

function nexis_ai_get_full_post_content($post) {
    $full_text = '';

    if ($post->post_type === 'product' && function_exists('wc_get_product')) {
        $product = wc_get_product($post->ID);
        if ($product) {
            $cats = wc_get_product_category_list($product->get_id(), ' ');
            $cats_clean = $cats ? wp_strip_all_tags($cats) : '';
            
            // افزودن نام دسته‌بندی به هویت محصول
            $full_text .= "نوع: محصول | دسته‌بندی: " . $cats_clean . " | نام کالا: " . $product->get_name() . " (ظرف " . $product->get_name() . ")\n";
            if ($product->get_sku()) $full_text .= "کد کالا (SKU): " . $product->get_sku() . "\n";
            if ($product->get_price()) $full_text .= "قیمت: " . $product->get_price() . "\n";

            $attributes = $product->get_attributes();
            if (!empty($attributes)) {
                $attr_list = [];
                foreach ($attributes as $attr) {
                    if (is_a($attr, 'WC_Product_Attribute')) {
                        $name = wc_attribute_label($attr->get_name());
                        $values = $product->get_attribute($attr->get_name());
                        $attr_list[] = "$name: $values";
                    }
                }
                if (!empty($attr_list)) {
                    $full_text .= "مشخصات فنی:\n - " . implode("\n - ", $attr_list) . "\n";
                }
            }

            if ($product->get_short_description()) {
                $full_text .= "خلاصه: " . nexis_ai_clean_content($product->get_short_description()) . "\n";
            }
        }
    }

    // استخراج محتوای المنتور
    $elementor_meta = get_post_meta($post->ID, '_elementor_data', true);
    if (!empty($elementor_meta)) {
        $elementor_data = is_array($elementor_meta) ? $elementor_meta : json_decode($elementor_meta, true);
        if (is_array($elementor_data)) {
            $extracted_texts = [];
            nexis_ai_extract_elementor_texts($elementor_data, $extracted_texts);
            if (!empty($extracted_texts)) {
                $full_text .= "محتوا و آیتم‌های صفحه:\n" . implode(" | ", array_unique($extracted_texts)) . "\n";
            }
        }
    }

    $title = get_the_title($post->ID);
    if (strpos($title, 'فرصت') !== false || strpos($title, 'شغلی') !== false || strpos($title, 'همکاری') !== false) {
        $full_text .= " کلیدواژه‌های موضوعی: استخدام، شرایط جذب نیرو، ارسال رزومه، موقعیت‌های کاری\n";
    }

    $rendered = apply_filters('the_content', $post->post_content);
    $main_content = nexis_ai_clean_content($rendered);
    if (!empty($main_content)) $full_text .= "توضیحات تکمیلی: " . $main_content . "\n";

    return trim($full_text);
}

function nexis_ai_is_post_allowed_for_index($post_id) {
    $settings = get_option('nexis_ai_settings', []);
    $allowed_ids = !empty($settings['allowed_indexed_ids']) ? $settings['allowed_indexed_ids'] : [];
    if (empty($allowed_ids)) return true;
    return in_array($post_id, $allowed_ids);
}

function nexis_ai_index_single_post($post_id) {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;

    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish' || !empty($post->post_password)) return;

    $allowed_types = ['post', 'page', 'product'];
    if (!in_array($post->post_type, $allowed_types)) return;

    if (function_exists('is_checkout') && (is_checkout() || is_cart() || is_account_page())) return;
    if (function_exists('wc_get_page_id')) {
        $cart_id = wc_get_page_id('cart');
        $checkout_id = wc_get_page_id('checkout');
        $myaccount_id = wc_get_page_id('myaccount');
        if (in_array($post->ID, [$cart_id, $checkout_id, $myaccount_id])) return;
    }

    if (!nexis_ai_is_post_allowed_for_index($post->ID)) {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'nexis_ai_knowledge', ['post_id' => $post->ID], ['%d']);
        return;
    }

    $clean_content = nexis_ai_get_full_post_content($post);
    $title = get_the_title($post->ID);
    if (empty($clean_content) && empty($title)) return;

    global $wpdb;
    $table_name = $wpdb->prefix . 'nexis_ai_knowledge';

    $wpdb->replace(
        $table_name,
        [
            'post_id'    => $post->ID,
            'post_type'  => $post->post_type,
            'title'      => $title,
            'url'        => get_permalink($post->ID),
            'content'    => mb_substr($clean_content, 0, 4500, 'UTF-8'),
            'updated_at' => current_time('mysql')
        ],
        ['%d', '%s', '%s', '%s', '%s', '%s']
    );
}
add_action('save_post', 'nexis_ai_index_single_post');

add_action('before_delete_post', function ($post_id) {
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'nexis_ai_knowledge', ['post_id' => $post_id], ['%d']);
});

function nexis_ai_crawl_all_content() {
    global $wpdb;
    $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}nexis_ai_knowledge");

    $settings = get_option('nexis_ai_settings', []);
    $allowed_ids = !empty($settings['allowed_indexed_ids']) ? $settings['allowed_indexed_ids'] : [];

    $args = [
        'post_type'      => ['product', 'post', 'page'],
        'post_status'    => 'publish',
        'posts_per_page' => 100,
        'paged'          => 1,
        'fields'         => 'ids'
    ];

    if (!empty($allowed_ids)) {
        $args['post__in'] = $allowed_ids;
    }

    $count = 0;
    while (true) {
        $query = new WP_Query($args);
        if (!$query->have_posts()) break;

        foreach ($query->posts as $p_id) {
            nexis_ai_index_single_post($p_id);
            $count++;
        }
        $args['paged']++;
        wp_reset_postdata();
    }
    return $count;
}

// موتور RAG با الگوریتم انعطاف‌پذیر
function nexis_ai_expand_query_via_llm($user_query) {
    $cache_key = 'nexis_exp_' . md5($user_query);
    $cached = get_transient($cache_key);
    if ($cached !== false && is_array($cached)) return $cached;

    $prompt = "برای جستجوی بهتر در مستندات سایت، کلمات کلیدی، نام‌های احتمالی یا اصطلاحات هم‌معنی عبارت زیر را بنویس.\nتنها خروجی کلمات جداشده با کاما باشد و هیچ توضیح دیگری ننویس:\n" . $user_query;
    $response = nexis_ai_call_llm_api("پاسخ فقط کلمات معادل با کاما است.", $prompt, 40);

    $terms = [];
    if (!empty($response) && strpos($response, 'خطا') === false) {
        $parts = explode(',', str_replace(['،', "\n", '.'], ',', $response));
        foreach ($parts as $p) {
            $p = trim($p);
            if (mb_strlen($p, 'UTF-8') >= 2) $terms[] = $p;
        }
    }

    set_transient($cache_key, $terms, 12 * HOUR_IN_SECONDS);
    return $terms;
}

function nexis_ai_search_knowledge_base($user_query, $limit = 5) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'nexis_ai_knowledge';

    $norm = nexis_ai_normalize_text($user_query);
    $stop_words = [
        'برای', 'یا', 'چه', 'دارید', 'دارین', 'چی', 'هست', 'مشخصات', 'رو', 'بده', 'کن', 'سلام', 'لطفا',
        'مدل', 'هایی', 'میخوام', 'چند', 'یک', 'در', 'به', 'از', 'با', 'بر', 'این', 'آن', 'است', 'شد',
        'طرز', 'تهیه', 'دستور', 'پخت', 'کجا', 'کدام', 'آیا', 'درباره', 'مربوط', 'می', 'کند', 'بخوام', 'بیاره', 'دارند', 'بهم', 'چیا', 'باید', 'بکنم'
    ];

    $raw_words = preg_split('/\s+/u', $norm);
    $search_terms = [];
    foreach ($raw_words as $w) {
        $w = trim($w);
        if (mb_strlen($w, 'UTF-8') >= 2 && !in_array($w, $stop_words)) {
            $search_terms[] = $w;
        }
    }

    if (empty($search_terms)) return [];

    // نگاشت خودکار اصطلاحات رایج
    if (in_array('استخدام', $search_terms) || in_array('کار', $search_terms)) {
        $search_terms[] = 'فرصت های شغلی';
        $search_terms[] = 'فرصت';
    }

    $perform_search = function($terms) use ($wpdb, $table_name, $limit) {
        if (empty($terms)) return [];

        $score_parts = [];
        $params = [];

        foreach ($terms as $term) {
            $like = '%' . $wpdb->esc_like($term) . '%';
            // کلمات تخصصی مثل «دلی» یا «فرصت» وزن بالاتر می‌گیرند
            $weight = (in_array($term, ['ظرف', 'محصول', 'بسته'])) ? 3 : 15;
            $score_parts[] = "(CASE WHEN title LIKE %s THEN ($weight * 2) WHEN content LIKE %s THEN $weight ELSE 0 END)";
            $params[] = $like;
            $params[] = $like;
        }

        $score_formula = implode(' + ', $score_parts);
        $query = "SELECT title, url, content, post_type, ($score_formula) as relevance_score 
                  FROM $table_name 
                  HAVING relevance_score >= 3
                  ORDER BY relevance_score DESC, (post_type = 'product') DESC 
                  LIMIT %d";

        $params[] = $limit;
        return $wpdb->get_results($wpdb->prepare($query, $params), ARRAY_A);
    };

    $results = $perform_search($search_terms);
    if (!empty($results)) return $results;

    $expanded = nexis_ai_expand_query_via_llm($user_query);
    if (!empty($expanded)) {
        $all_terms = array_unique(array_merge($search_terms, $expanded));
        $results = $perform_search($all_terms);
    }

    return $results;
}

// ایجکس دیباگ و تست زنده موتور جستجو
add_action('wp_ajax_nexis_ai_debug_search', function() {
    check_ajax_referer('nexis_ai_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'دسترسی غیرمجاز']);

    $term = sanitize_text_field($_POST['term']);
    if (empty($term)) wp_send_json_error(['message' => 'عبارت جستجو خالی است.']);

    $found = nexis_ai_search_knowledge_base($term, 8);
    wp_send_json_success(['results' => $found, 'count' => count($found)]);
});

function nexis_ai_log_interaction($query, $response, $model, $status) {
    global $wpdb;
    $table_logs = $wpdb->prefix . 'nexis_ai_logs';
    
    $ip = '';
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) $ip = $_SERVER['HTTP_CLIENT_IP'];
    elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    elseif (!empty($_SERVER['REMOTE_ADDR'])) $ip = $_SERVER['REMOTE_ADDR'];

    $wpdb->insert(
        $table_logs,
        [
            'user_ip'      => sanitize_text_field($ip),
            'user_query'   => $query,
            'ai_response'  => $response,
            'model_used'   => $model,
            'status'       => $status,
            'created_at'   => current_time('mysql')
        ],
        ['%s', '%s', '%s', '%s', '%s', '%s']
    );
}

// استعلام مدل‌ها
add_action('wp_ajax_nexis_ai_fetch_models', function() {
    check_ajax_referer('nexis_ai_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'دسترسی غیرمجاز']);

    $env_id   = sanitize_key($_POST['env_id']);
    $base_url = rtrim(trim($_POST['base_url']), '/');
    $api_key  = trim($_POST['api_key']);

    if (empty($base_url)) {
        wp_send_json_error(['message' => 'آدرس سرور وارد نشده است.']);
    }

    $models = [];

    if (strpos($base_url, '11434') !== false || strpos($env_id, 'ollama') !== false) {
        $root_url = preg_replace('/\/v1$/ui', '', $base_url);
        $res = wp_remote_get($root_url . '/api/tags', ['timeout' => 15]);
        if (is_wp_error($res)) {
            $res = wp_remote_get($base_url . '/models', ['timeout' => 15]);
        }

        if (!is_wp_error($res)) {
            $data = json_decode(wp_remote_retrieve_body($res), true);
            if (isset($data['models'])) {
                foreach ($data['models'] as $m) {
                    $m_id = isset($m['name']) ? $m['name'] : (isset($m['id']) ? $m['id'] : '');
                    if (!empty($m_id)) {
                        $models[] = ['id' => $m_id, 'name' => $m_id, 'type' => 'Local Offline'];
                    }
                }
            }
        }
    } else {
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent'   => 'Nexis-AI-Engine-WP/' . NEXIS_AI_VERSION
        ];
        if (!empty($api_key)) {
            $headers['Authorization'] = 'Bearer ' . $api_key;
        }

        $res = wp_remote_get($base_url . '/models', [
            'headers'   => $headers,
            'timeout'   => 25,
            'sslverify' => false
        ]);

        if (!is_wp_error($res)) {
            $code = wp_remote_retrieve_response_code($res);
            $body = wp_remote_retrieve_body($res);
            $data = json_decode($body, true);

            if ($code === 200 && isset($data['data']) && is_array($data['data'])) {
                foreach ($data['data'] as $m) {
                    $m_id = isset($m['id']) ? $m['id'] : '';
                    if (!empty($m_id)) {
                        $models[] = [
                            'id'   => $m_id,
                            'name' => isset($m['name']) ? $m['name'] : $m_id,
                            'type' => 'Cloud Provider'
                        ];
                    }
                }
            } else {
                $err_msg = isset($data['error']['message']) ? $data['error']['message'] : ($code === 403 ? 'دسترسی مسدود است (403 Forbidden)' : "کد خطا: $code");
                wp_send_json_error(['message' => $err_msg]);
            }
        } else {
            wp_send_json_error(['message' => 'خطای اتصال: ' . $res->get_error_message()]);
        }
    }

    if (empty($models)) {
        wp_send_json_error(['message' => 'مدلی دریافت نشد. در صورت عدم دریافت، شناسه مدل را دستی وارد کنید.']);
    }

    $settings = get_option('nexis_ai_settings', []);
    if (!isset($settings['environments'])) $settings['environments'] = [];
    if (!isset($settings['environments'][$env_id])) $settings['environments'][$env_id] = [];
    $settings['environments'][$env_id]['cached_models'] = $models;
    update_option('nexis_ai_settings', $settings);

    wp_send_json_success(['models' => $models, 'count' => count($models)]);
});

// تست اتصال
add_action('wp_ajax_nexis_ai_test_endpoint', function() {
    check_ajax_referer('nexis_ai_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'دسترسی غیرمجاز']);

    $settings = get_option('nexis_ai_settings', []);
    $timeout  = !empty($settings['request_timeout']) ? intval($settings['request_timeout']) : 180;

    $base_url = rtrim(trim($_POST['base_url']), '/');
    $api_key  = trim($_POST['api_key']);
    $model    = trim($_POST['model']);

    if (empty($base_url)) {
        wp_send_json_error(['message' => 'آدرس سرور خالی است.']);
    }

    $headers = [
        'Content-Type' => 'application/json',
        'User-Agent'   => 'Nexis-AI-Engine-WP/' . NEXIS_AI_VERSION
    ];
    if (!empty($api_key)) {
        $headers['Authorization'] = 'Bearer ' . $api_key;
    }

    $response = wp_remote_post($base_url . '/chat/completions', [
        'headers'   => $headers,
        'body'      => wp_json_encode([
            'model'      => $model ?: 'test',
            'messages'   => [['role' => 'user', 'content' => 'hi']],
            'max_tokens' => 5
        ]),
        'timeout'   => $timeout,
        'sslverify' => false
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error(['message' => 'خطا در ارتباط شبکه: ' . $response->get_error_message()]);
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code >= 200 && $code < 300) {
        wp_send_json_success(['message' => 'اتصال برقرار شد و مدل پاسخ داد.']);
    } else {
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $err = isset($body['error']['message']) ? $body['error']['message'] : "کد وضعیت HTTP: $code";
        wp_send_json_error(['message' => $err]);
    }
});

function nexis_ai_call_llm_api($system_prompt, $user_message, $override_max_tokens = null) {
    $lic = nexis_ai_get_license_status();
    if (!$lic['allowed']) {
        return 'خطای لایسنس: ' . $lic['message'];
    }

    $settings = get_option('nexis_ai_settings', []);
    $active_env = !empty($settings['active_env']) ? $settings['active_env'] : 'default_env';
    $env_config = isset($settings['environments'][$active_env]) ? $settings['environments'][$active_env] : [];

    $api_key     = !empty($env_config['api_key']) ? trim($env_config['api_key']) : '';
    $base_url    = !empty($env_config['base_url']) ? rtrim(trim($env_config['base_url']), '/') : '';
    $model       = !empty($settings['default_model']) ? trim($settings['default_model']) : 'gpt-4o-mini';
    $max_tokens  = $override_max_tokens ? intval($override_max_tokens) : (isset($settings['max_tokens']) ? intval($settings['max_tokens']) : 1024);
    $temperature = isset($settings['temperature']) ? floatval($settings['temperature']) : 0.3;
    $timeout     = !empty($settings['request_timeout']) ? intval($settings['request_timeout']) : 180;

    if (empty($base_url)) {
        return 'خطا: آدرس سرور هوش مصنوعی برای محیط فعال تنظیم نشده است.';
    }

    $headers = [
        'Content-Type' => 'application/json',
        'User-Agent'   => 'Nexis-AI-Engine-WP/' . NEXIS_AI_VERSION
    ];
    if (!empty($api_key)) {
        $headers['Authorization'] = 'Bearer ' . $api_key;
    }

    $response = wp_remote_post($base_url . '/chat/completions', [
        'headers'   => $headers,
        'body'      => wp_json_encode([
            'model'       => $model,
            'messages'    => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user', 'content' => $user_message]
            ],
            'max_tokens'  => $max_tokens,
            'temperature' => $temperature
        ]),
        'timeout'   => $timeout,
        'sslverify' => false
    ]);

    if (is_wp_error($response)) return 'خطای ارتباط: ' . $response->get_error_message();

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($body['choices'][0]['message']['content'])) {
        nexis_ai_increment_request_count();
        $txt = trim($body['choices'][0]['message']['content']);
        $txt = preg_replace('/<think>.*?<\/think>/is', '', $txt);
        return trim($txt);
    }

    if (isset($body['error']['message'])) {
        return 'خطای ارائه‌دهنده هوش مصنوعی: ' . $body['error']['message'];
    }

    return 'پاسخی از مدل دریافت نشد.';
}

add_action('wp_ajax_nexis_ai_chat', 'nexis_ai_ajax_chat_handler');
add_action('wp_ajax_nopriv_nexis_ai_chat', 'nexis_ai_ajax_chat_handler');

function nexis_ai_ajax_chat_handler() {
    check_ajax_referer('nexis_ai_chat_nonce', 'nonce');

    $lic = nexis_ai_get_license_status();
    if (!$lic['allowed']) {
        wp_send_json_error(['reply' => '⚠️ دسترسی هوش مصنوعی متوقف شده است: ' . $lic['message']]);
    }

    $message = isset($_POST['message']) ? sanitize_text_field($_POST['message']) : '';
    if (empty($message)) wp_send_json_error(['reply' => 'لطفاً پیامی بنویسید.']);

    $settings = get_option('nexis_ai_settings', []);
    $default_prompt = "تو مشاور رسمی و راهنمای تخصصی این وب‌سایت هستی.\n\nاطلاعات و مستندات موثق وب‌سایت:\n{CONTEXT}\n\nدستورالعمل‌ها:\n۱. با اتکا به مستندات بالا، پاسخ کامل و حرفه‌ای بده.\n۲. درباره خدمات، فرصت‌های شغلی و استخدام، تماس، درباره ما و محصولات، نام و لینک صفحه مربوطه را حتماً در متن به شکل [نام صفحه](لینک) بیاور.\n۳. ساختار پاسخ‌ها شیک، خوانا و با بالت‌پوینت باشد.";

    $sys_template = !empty($settings['system_prompt']) ? $settings['system_prompt'] : $default_prompt;
    $offtopic_msg = !empty($settings['offtopic_message']) ? $settings['offtopic_message'] : 'من دستیار هوشمند هستم و تمرکز من راهنمایی شما در زمینه خدمات و محتوای این وب‌سایت است.';
    $model_name   = !empty($settings['default_model']) ? $settings['default_model'] : 'default';
    $rag_limit    = isset($settings['rag_limit']) ? intval($settings['rag_limit']) : 5;
    $chunk_len    = isset($settings['chunk_len']) ? intval($settings['chunk_len']) : 1600;

    $found = [];
    if (!empty($settings['enable_site_search']) && $settings['enable_site_search'] === '1') {
        $found = nexis_ai_search_knowledge_base($message, $rag_limit);
    }

    if (empty($found) && !empty($settings['strict_mode']) && $settings['strict_mode'] === '1') {
        nexis_ai_log_interaction($message, $offtopic_msg, $model_name, 'guardrail_blocked');
        wp_send_json_success(['reply' => $offtopic_msg]);
    }

    $knowledge_context = "مستندات یافت‌شده از پایگاه داده:\n";
    if (!empty($found)) {
        foreach ($found as $idx => $r) {
            $num = $idx + 1;
            $type_label = ($r['post_type'] === 'product') ? '[محصول]' : '[برگه/محتوا]';
            $snippet = mb_substr($r['content'], 0, $chunk_len, 'UTF-8');
            $knowledge_context .= "--- مورد $num: $type_label {$r['title']} ---\nلینک مستقیم: {$r['url']}\nمتن:\n{$snippet}\n\n";
        }
    } else {
        $knowledge_context .= "هیچ سندی که تطابق مستقیم داشته باشد یافت نشد.";
    }

    $final_system_prompt = str_replace('{CONTEXT}', $knowledge_context, $sys_template);
    $reply = nexis_ai_call_llm_api($final_system_prompt, $message);

    nexis_ai_log_interaction($message, $reply, $model_name, 'answered');
    wp_send_json_success(['reply' => $reply]);
}

// تولید سئو و اسکیما
add_action('wp_ajax_nexis_ai_generate_seo', function() {
    check_ajax_referer('nexis_ai_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'دسترسی غیرمجاز']);

    $lic = nexis_ai_get_license_status();
    if (!$lic['allowed']) {
        wp_send_json_error(['message' => 'خطای لایسنس: ' . $lic['message']]);
    }

    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $post = get_post($product_id);
    if (!$post) wp_send_json_error(['message' => 'محتوا یافت نشد']);

    $content = nexis_ai_get_full_post_content($post);

    $prompt = "تو متخصص ارشد سئو تکنیکال و مهندسی GEO هستی.\n"
        . "اطلاعات محتوا:\n" . $content . "\n\n"
        . "تنها خروجی تو یک آبجکت معتبر JSON با کلیدهای زیر است و هیچ متن اضافی دیگری نباید تولید کنی:\n"
        . "{\n"
        . '  "meta_title": "تایتل سئو پیشنهادی حداکثر ۶۰ کاراکتر",' . "\n"
        . '  "meta_description": "توضیحات متای استاندارد بین ۱۳۰ تا ۱۵۵ کاراکتر",' . "\n"
        . '  "faqs": [{"question": "سوال اول", "answer": "پاسخ اول"}, {"question": "سوال دوم", "answer": "پاسخ دوم"}, {"question": "سوال سوم", "answer": "پاسخ سوم"}],' . "\n"
        . '  "schema_faq_code": {"@context": "https://schema.org", "@type": "FAQPage", "mainEntity": []}' . "\n"
        . "}";

    $ai_response = nexis_ai_call_llm_api("پاسخ تو فقط یک JSON استاندارد و بدون توضیح اضافه است.", $prompt);

    $clean_json = '';
    if (preg_match('/\{[\s\S]*\}/u', $ai_response, $matches)) {
        $clean_json = $matches[0];
    } else {
        $clean_json = $ai_response;
    }

    $parsed = json_decode($clean_json, true);

    if (!$parsed || !isset($parsed['meta_title'])) {
        wp_send_json_error(['message' => 'عدم دریافت ساختار JSON معتبر. پاسخ خام: ' . mb_substr($ai_response, 0, 300)]);
    }

    wp_send_json_success(['parsed' => true, 'data' => $parsed]);
});

// خروجی اکسل لاگ‌ها
add_action('admin_init', function() {
    if (isset($_GET['page']) && $_GET['page'] === 'nexis-ai-logs' && isset($_GET['action']) && $_GET['action'] === 'export_csv') {
        if (!current_user_can('manage_options')) wp_die('دسترسی غیرمجاز');

        global $wpdb;
        $table_logs = $wpdb->prefix . 'nexis_ai_logs';

        $where = ['1=1'];
        $params = [];

        if (!empty($_GET['start_date'])) {
            $where[] = "created_at >= %s";
            $params[] = sanitize_text_field($_GET['start_date']) . ' 00:00:00';
        }
        if (!empty($_GET['end_date'])) {
            $where[] = "created_at <= %s";
            $params[] = sanitize_text_field($_GET['end_date']) . ' 23:59:59';
        }

        $where_sql = implode(' AND ', $where);
        $sql = "SELECT id, user_ip, user_query, ai_response, model_used, status, created_at FROM $table_logs WHERE $where_sql ORDER BY id DESC";

        $logs = !empty($params) ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=nexis-ai-logs-' . date('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out, ['ردیف', 'آی‌پی کاربر', 'پرسش کاربر', 'پاسخ هوش مصنوعی', 'مدل مصرفی', 'وضعیت', 'تاریخ و ساعت (شمسی)']);

        foreach ($logs as $row) {
            fputcsv($out, [
                $row['id'],
                $row['user_ip'],
                $row['user_query'],
                $row['ai_response'],
                $row['model_used'],
                $row['status'],
                nexis_ai_format_persian_datetime($row['created_at'])
            ]);
        }
        fclose($out);
        exit;
    }
});

// ویجت فرانت‌اند
add_action('wp_footer', function () {
    $settings = get_option('nexis_ai_settings', []);
    if (empty($settings['enable_widget']) || $settings['enable_widget'] !== '1') return;

    $bot_name        = !empty($settings['bot_name']) ? esc_html($settings['bot_name']) : 'دستیار هوشمند نکسیس (Nexis AI)';
    $position        = !empty($settings['widget_position']) ? $settings['widget_position'] : 'bottom-left';
    $offset_x        = isset($settings['widget_offset_x']) ? intval($settings['widget_offset_x']) : 25;
    $offset_y        = isset($settings['widget_offset_y']) ? intval($settings['widget_offset_y']) : 25;
    $custom_color    = !empty($settings['widget_primary_color']) ? sanitize_hex_color($settings['widget_primary_color']) : '#0073aa';
    $stop_btn_color  = !empty($settings['widget_stop_color']) ? sanitize_hex_color($settings['widget_stop_color']) : '#dc2626';
    $theme           = !empty($settings['widget_theme']) ? $settings['widget_theme'] : 'theme-blue';
    $launcher_icon   = !empty($settings['widget_launcher_icon']) ? $settings['widget_launcher_icon'] : '💬';
    $header_avatar   = !empty($settings['widget_avatar_icon']) ? $settings['widget_avatar_icon'] : '🤖';
    $launcher_img    = !empty($settings['widget_launcher_img']) ? esc_url($settings['widget_launcher_img']) : '';
    $avatar_img      = !empty($settings['widget_avatar_img']) ? esc_url($settings['widget_avatar_img']) : '';

    $enable_prompts  = !empty($settings['enable_quick_prompts']) && $settings['enable_quick_prompts'] === '1';
    $quick_prompts_raw = !empty($settings['quick_prompts_list']) ? $settings['quick_prompts_list'] : "فرصت‌های شغلی و استخدام چگونه است؟\nکاتالوگ و لیست محصولات را بفرست\nشرایط همکاری و نحوه ثبت سفارش";
    $quick_prompts = array_filter(array_map('trim', explode("\n", $quick_prompts_raw)));

    $theme_bg   = '#ffffff';
    $theme_chat = '#f9fbfd';
    $theme_text = '#2c3338';
    $bot_bubble = '#eaf3fa';

    if ($theme === 'theme-dark') {
        $theme_bg   = '#1e293b';
        $theme_chat = '#0f172a';
        $theme_text = '#f8fafc';
        $bot_bubble = '#334155';
    } elseif ($theme === 'theme-green') {
        $custom_color = !empty($settings['widget_primary_color']) ? $custom_color : '#166534';
        $bot_bubble = '#f0fdf4';
    }

    $pos_btn_css = ($position === 'bottom-right') ? "bottom: {$offset_y}px; right: {$offset_x}px;" : "bottom: {$offset_y}px; left: {$offset_x}px;";
    $box_bottom = $offset_y + 65;
    $pos_box_css = ($position === 'bottom-right') ? "bottom: {$box_bottom}px; right: {$offset_x}px;" : "bottom: {$box_bottom}px; left: {$offset_x}px;";
    ?>
    <style>
        .nexis-chat-link { display: inline-block; background: rgba(0, 115, 170, 0.12); color: #0073aa !important; padding: 4px 10px; margin: 4px 2px; border-radius: 6px; text-decoration: none !important; font-weight: bold; font-size: 12px; border: 1px solid rgba(0, 115, 170, 0.25); transition: all 0.2s; word-break: break-all; }
        .nexis-chat-link:hover { background: #0073aa; color: #fff !important; }
        .nexis-btn-stop { background: <?php echo $stop_btn_color; ?> !important; color: #fff !important; }
        .nexis-prompt-chip { display: inline-block; background: #fff; border: 1px solid #cbd5e1; color: #334155; padding: 6px 12px; border-radius: 16px; margin: 3px 2px; font-size: 12px; cursor: pointer; transition: all 0.2s; text-align: right; }
        .nexis-prompt-chip:hover { background: <?php echo $custom_color; ?>; color: #fff; border-color: <?php echo $custom_color; ?>; }
    </style>

    <div id="nexis-chat-root" style="direction: rtl; font-family: Tahoma, Vazirmatn, sans-serif;">
        <button id="nexis-chat-toggle" style="position: fixed; <?php echo $pos_btn_css; ?> width: 56px; height: 56px; border-radius: 50%; background: <?php echo $custom_color; ?>; border: none; color: #fff; cursor: pointer; box-shadow: 0 4px 15px rgba(0,0,0,0.25); display: flex; align-items: center; justify-content: center; z-index: 99999; font-size: 24px; padding: 0; overflow: hidden;">
            <?php if (!empty($launcher_img)): ?>
                <img src="<?php echo $launcher_img; ?>" alt="Chat Button" style="width: 100%; height: 100%; object-fit: cover;">
            <?php else: ?>
                <span><?php echo esc_html($launcher_icon); ?></span>
            <?php endif; ?>
        </button>

        <div id="nexis-chat-box" style="display: none; position: fixed; <?php echo $pos_box_css; ?> width: 385px; max-width: 90vw; height: 530px; background: <?php echo $theme_bg; ?>; border-radius: 14px; box-shadow: 0 10px 35px rgba(0,0,0,0.22); z-index: 99999; flex-direction: column; overflow: hidden; border: 1px solid rgba(0,0,0,0.08);">
            <div style="background: <?php echo $custom_color; ?>; color: #fff; padding: 13px 16px; font-weight: bold; font-size: 14px; display: flex; justify-content: space-between; align-items: center;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <?php if (!empty($avatar_img)): ?>
                        <img src="<?php echo $avatar_img; ?>" alt="Avatar" style="width: 26px; height: 26px; border-radius: 50%; object-fit: cover;">
                    <?php else: ?>
                        <span style="font-size: 20px;"><?php echo esc_html($header_avatar); ?></span>
                    <?php endif; ?>
                    <span><?php echo $bot_name; ?></span>
                </div>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <span id="nexis-chat-clear" title="پاک‌سازی تاریخچه" style="cursor: pointer; font-size: 14px; opacity: 0.85;">🗑</span>
                    <span id="nexis-chat-close" style="cursor: pointer; font-size: 18px;">✕</span>
                </div>
            </div>

            <div id="nexis-chat-messages" style="flex: 1; padding: 14px; overflow-y: auto; background: <?php echo $theme_chat; ?>; color: <?php echo $theme_text; ?>; font-size: 13.5px; line-height: 1.65;">
            </div>

            <?php if ($enable_prompts && !empty($quick_prompts)): ?>
                <div id="nexis-quick-prompts-wrapper" style="padding: 6px 10px; background: rgba(0,0,0,0.02); border-top: 1px solid rgba(0,0,0,0.04); display: flex; flex-wrap: wrap;">
                    <?php foreach ($quick_prompts as $qp): ?>
                        <button type="button" class="nexis-prompt-chip" data-prompt="<?php echo esc_attr($qp); ?>">💡 <?php echo esc_html($qp); ?></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div style="padding: 10px 12px; background: <?php echo $theme_bg; ?>; border-top: 1px solid rgba(0,0,0,0.07); display: flex; gap: 8px;">
                <input type="text" id="nexis-chat-input" placeholder="پیام خود را بنویسید..." style="flex: 1; border: 1px solid #ccc; border-radius: 6px; padding: 8px 10px; font-size: 13px; outline: none; background: <?php echo ($theme === 'theme-dark') ? '#334155' : '#fff'; ?>; color: <?php echo $theme_text; ?>;">
                <button id="nexis-chat-send" style="background: <?php echo $custom_color; ?>; color: #fff; border: none; border-radius: 6px; padding: 8px 14px; cursor: pointer; font-weight: bold; font-size: 13px; min-width: 65px;">ارسال</button>
            </div>
        </div>
    </div>

    <script>
    (function(){
        var toggle = document.getElementById('nexis-chat-toggle'),
            box = document.getElementById('nexis-chat-box'),
            close = document.getElementById('nexis-chat-close'),
            clearBtn = document.getElementById('nexis-chat-clear'),
            send = document.getElementById('nexis-chat-send'),
            input = document.getElementById('nexis-chat-input'),
            msgs = document.getElementById('nexis-chat-messages');

        var primaryColor = '<?php echo $custom_color; ?>',
            botBubbleBg  = '<?php echo $bot_bubble; ?>',
            themeTextColor = '<?php echo $theme_text; ?>';

        var activeController = null;

        toggle.onclick = function(){ box.style.display = (box.style.display === 'none' || box.style.display === '') ? 'flex' : 'none'; };
        close.onclick = function(){ box.style.display = 'none'; };

        function getHistory() { try { return JSON.parse(localStorage.getItem('nexis_chat_history') || '[]'); } catch(e) { return []; } }
        function saveHistory(list) { localStorage.setItem('nexis_chat_history', JSON.stringify(list)); }

        function parseMarkdown(text) {
            var html = text;
            html = html.replace(/\[([^\]]+)\]\((https?:\/\/[^\s\)]+)\)/g, '<a href="$2" target="_blank" class="nexis-chat-link">🔗 $1</a>');
            html = html.replace(/(^|[^"'])(https?:\/\/[^\s\)<>]+)/g, '$1<a href="$2" target="_blank" class="nexis-chat-link">🔗 مشاهده لینک</a>');
            html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
            html = html.replace(/^#+\s*(.*)$/gm, '<strong style="display:block;margin:6px 0;font-size:14px;color:#0073aa;">$1</strong>');
            html = html.replace(/^>\s*(.*)$/gm, '<div style="border-right:3px solid #0073aa;padding-right:8px;margin:4px 0;color:#555;">$1</div>');
            html = html.replace(/\n/g, '<br>');
            return html;
        }

        function renderMsg(item, animate) {
            var d = document.createElement('div');
            d.style.marginBottom = '10px';
            d.style.padding = '9px 13px';
            d.style.borderRadius = '10px';
            d.style.maxWidth = '88%';
            d.style.wordBreak = 'break-word';

            if (item.sender === 'user') {
                d.style.background = primaryColor;
                d.style.color = '#fff';
                d.style.marginRight = 'auto';
                d.style.marginLeft = '0';
                d.innerText = item.text;
                msgs.appendChild(d);
            } else {
                d.style.background = botBubbleBg;
                d.style.color = themeTextColor;
                d.style.marginLeft = 'auto';
                d.style.marginRight = '0';
                msgs.appendChild(d);

                if (animate) {
                    d.innerHTML = '<span style="color:#888;">در حال نگارش...</span>';
                    setTimeout(function() {
                        d.innerHTML = parseMarkdown(item.text);
                        msgs.scrollTop = msgs.scrollHeight;
                    }, 200);
                } else {
                    d.innerHTML = parseMarkdown(item.text);
                }
            }
            msgs.scrollTop = msgs.scrollHeight;
        }

        function loadChat() {
            msgs.innerHTML = '';
            var hist = getHistory();
            if (hist.length === 0) {
                var welcome = { sender: 'bot', text: 'سلام! چطور می‌توانم درباره خدمات و محتوای وب‌سایت راهنمایی‌تان کنم؟' };
                hist.push(welcome);
                saveHistory(hist);
            }
            hist.forEach(function(item) { renderMsg(item, false); });
        }
        loadChat();

        clearBtn.onclick = function() {
            if (confirm('آیا مایلید تاریخچه پیام‌های این چت پاک شود؟')) {
                localStorage.removeItem('nexis_chat_history');
                loadChat();
            }
        };

        function resetSendButton() {
            send.innerText = 'ارسال';
            send.classList.remove('nexis-btn-stop');
            send.style.background = primaryColor;
            activeController = null;
        }

        function doSend(customText) {
            if (activeController) {
                activeController.abort();
                resetSendButton();
                var stopNotice = document.createElement('div');
                stopNotice.style.color = '#dc2626';
                stopNotice.style.fontSize = '12px';
                stopNotice.style.margin = '5px 0';
                stopNotice.innerText = '⏹ پاسخ توسط کاربر متوقف شد.';
                msgs.appendChild(stopNotice);
                return;
            }

            var val = (typeof customText === 'string') ? customText.trim() : input.value.trim();
            if (!val) return;

            var userMsg = { sender: 'user', text: val };
            var hist = getHistory();
            hist.push(userMsg);
            saveHistory(hist);
            renderMsg(userMsg, false);
            input.value = '';

            var loading = document.createElement('div');
            loading.id = 'nexis-chat-loading-item';
            loading.style.marginBottom = '10px';
            loading.style.padding = '8px 12px';
            loading.style.color = '#888';
            loading.innerText = 'در حال جستجو و پاسخ...';
            msgs.appendChild(loading);
            msgs.scrollTop = msgs.scrollHeight;

            activeController = new AbortController();
            send.innerText = '🛑 توقف';
            send.classList.add('nexis-btn-stop');

            var fd = new FormData();
            fd.append('action', 'nexis_ai_chat');
            fd.append('nonce', '<?php echo wp_create_nonce("nexis_ai_chat_nonce"); ?>');
            fd.append('message', val);

            fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
                method: 'POST',
                body: fd,
                signal: activeController.signal
            })
            .then(function(res){ return res.json(); })
            .then(function(res){
                var l = document.getElementById('nexis-chat-loading-item');
                if (l) msgs.removeChild(l);
                resetSendButton();

                var replyText = (res.success && res.data && res.data.reply) ? res.data.reply : (res.data ? res.data.reply : 'خطایی رخ داد.');
                var botMsg = { sender: 'bot', text: replyText };
                var cur = getHistory();
                cur.push(botMsg);
                saveHistory(cur);
                renderMsg(botMsg, true);
            })
            .catch(function(err){
                var l = document.getElementById('nexis-chat-loading-item');
                if (l) msgs.removeChild(l);
                resetSendButton();

                if (err.name !== 'AbortError') {
                    renderMsg({ sender: 'bot', text: 'خطا در ارتباط با سرور یا مهلت زمانی به پایان رسید.' }, false);
                }
            });
        }
        send.onclick = doSend;
        input.onkeypress = function(e){ if (e.key === 'Enter') doSend(); };

        var promptChips = document.querySelectorAll('.nexis-prompt-chip');
        promptChips.forEach(function(chip) {
            chip.onclick = function() {
                var q = this.getAttribute('data-prompt');
                doSend(q);
            };
        });
    })();
    </script>
    <?php
});

// منوهای مدیریت وردپرس
add_action('admin_menu', function () {
    add_menu_page('Nexis AI Engine', 'هوش مصنوعی Nexis', 'manage_options', 'nexis-ai-settings', 'nexis_ai_render_settings_page', 'dashicons-rest-api', 30);
    add_submenu_page('nexis-ai-settings', 'تنظیمات و مدل‌ها', 'تنظیمات و مدل‌ها', 'manage_options', 'nexis-ai-settings', 'nexis_ai_render_settings_page');
    add_submenu_page('nexis-ai-settings', 'انتخاب صفحات خزش (Tree-View)', '🌳 انتخاب صفحات خزش', 'manage_options', 'nexis-ai-tree', 'nexis_ai_render_tree_page');
    add_submenu_page('nexis-ai-settings', 'سئو و بهینه‌سازی (GEO)', 'سئو و بهینه‌سازی (GEO)', 'manage_options', 'nexis-ai-geo', 'nexis_ai_render_geo_page');
    add_submenu_page('nexis-ai-settings', 'مدیریت لایسنس', '🔑 مدیریت لایسنس', 'manage_options', 'nexis-ai-license', 'nexis_ai_render_license_page');
    add_submenu_page('nexis-ai-settings', 'تاریخچه چت', 'تاریخچه و لاگ چت', 'manage_options', 'nexis-ai-logs', 'nexis_ai_render_logs_page');
});

add_action('admin_enqueue_scripts', function($hook) {
    if (strpos($hook, 'nexis-ai') !== false) {
        wp_enqueue_media();
    }
});

// صفحه تنظیمات عمومی همراه با پنل تست زنده RAG
function nexis_ai_render_settings_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'nexis_ai_knowledge';

    if (isset($_POST['nexis_ai_reindex_now']) && check_admin_referer('nexis_ai_reindex_nonce')) {
        $indexed_count = nexis_ai_crawl_all_content();
        echo '<div class="updated notice is-dismissible"><p>خزش جامع با موفقیت انجام شد. تعداد ' . intval($indexed_count) . ' رکورد ذخیره شد.</p></div>';
    }

    $settings = get_option('nexis_ai_settings', []);

    $default_environments = [
        'env_1' => [
            'name'          => 'سرور محلی ۱',
            'base_url'      => '',
            'api_key'       => '',
            'default_model' => '',
            'cached_models' => []
        ]
    ];

    $envs = !empty($settings['environments']) ? $settings['environments'] : $default_environments;

    if (isset($_POST['nexis_ai_save_settings']) && check_admin_referer('nexis_ai_nonce')) {
        $submitted_envs = isset($_POST['envs']) && is_array($_POST['envs']) ? $_POST['envs'] : [];
        $new_envs = [];

        foreach ($submitted_envs as $env_id => $data) {
            $safe_id = sanitize_key($env_id);
            if (empty($safe_id)) continue;

            $name = sanitize_text_field(trim($data['name']));
            if (empty($name)) {
                $name = 'سرور ' . (count($new_envs) + 1);
            }

            $cached = isset($envs[$safe_id]['cached_models']) ? $envs[$safe_id]['cached_models'] : [];
            $new_envs[$safe_id] = [
                'name'          => $name,
                'base_url'      => esc_url_raw(trim($data['base_url'])),
                'api_key'       => sanitize_text_field(trim($data['api_key'])),
                'default_model' => sanitize_text_field(trim($data['default_model'])),
                'cached_models' => $cached
            ];
        }

        if (empty($new_envs)) {
            $new_envs = $default_environments;
        }

        $settings['environments']    = $new_envs;
        $settings['active_env']      = sanitize_key($_POST['active_env']);
        $settings['default_model']   = sanitize_text_field($_POST['default_model']);

        $settings['request_timeout'] = intval($_POST['request_timeout']);
        $settings['max_tokens']      = intval($_POST['max_tokens']);
        $settings['temperature']     = floatval($_POST['temperature']);
        $settings['rag_limit']       = intval($_POST['rag_limit']);
        $settings['chunk_len']       = intval($_POST['chunk_len']);

        $settings['bot_name']             = sanitize_text_field($_POST['bot_name']);
        $settings['system_prompt']        = wp_unslash($_POST['system_prompt']);
        $settings['enable_widget']        = isset($_POST['enable_widget']) ? '1' : '0';
        $settings['enable_site_search']   = isset($_POST['enable_site_search']) ? '1' : '0';
        $settings['strict_mode']          = isset($_POST['strict_mode']) ? '1' : '0';
        $settings['enable_auto_schema']   = isset($_POST['enable_auto_schema']) ? '1' : '0';
        $settings['offtopic_message']     = sanitize_textarea_field($_POST['offtopic_message']);

        $settings['enable_quick_prompts'] = isset($_POST['enable_quick_prompts']) ? '1' : '0';
        $settings['quick_prompts_list']   = sanitize_textarea_field($_POST['quick_prompts_list']);

        $settings['widget_position']      = sanitize_text_field($_POST['widget_position']);
        $settings['widget_offset_x']      = intval($_POST['widget_offset_x']);
        $settings['widget_offset_y']      = intval($_POST['widget_offset_y']);
        $settings['widget_theme']         = sanitize_text_field($_POST['widget_theme']);
        $settings['widget_primary_color'] = sanitize_hex_color($_POST['widget_primary_color']);
        $settings['widget_stop_color']    = sanitize_hex_color($_POST['widget_stop_color']);
        $settings['widget_launcher_icon'] = sanitize_text_field($_POST['widget_launcher_icon']);
        $settings['widget_avatar_icon']   = sanitize_text_field($_POST['widget_avatar_icon']);
        $settings['widget_launcher_img']  = esc_url_raw($_POST['widget_launcher_img']);
        $settings['widget_avatar_img']    = esc_url_raw($_POST['widget_avatar_img']);

        update_option('nexis_ai_settings', $settings);
        $envs = $new_envs;
        echo '<div class="updated notice is-dismissible"><p>تنظیمات با موفقیت ذخیره شدند.</p></div>';
    }

    $active_env      = !empty($settings['active_env']) ? $settings['active_env'] : key($envs);
    $default_model   = !empty($settings['default_model']) ? $settings['default_model'] : '';
    $request_timeout = !empty($settings['request_timeout']) ? intval($settings['request_timeout']) : 180;
    $total_indexed   = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");

    $available_launchers = ['💬', '🤖', '💭', '🗨️', '🎧', '⚡', '❓', '📦', '🏢', '✨'];
    $available_avatars   = ['👨‍💼', '👩‍💼', '🤖', '🎧', '👤', '🛡️', '🌟', '💼', '🎯', '🏭'];

    $default_prompt = "تو مشاور رسمی و راهنمای تخصصی این وب‌سایت هستی.\n\nاطلاعات و مستندات موثق وب‌سایت:\n{CONTEXT}\n\nدستورالعمل‌ها:\n۱. با اتکا به مستندات بالا، پاسخ کامل و حرفه‌ای بده.\n۲. درباره خدمات، فرصت‌های شغلی و استخدام، تماس، درباره ما و محصولات، نام و لینک صفحه مربوطه را حتماً در متن به شکل [نام صفحه](لینک) بیاور.\n۳. ساختار پاسخ‌ها شیک، خوانا و با بالت‌پوینت باشد.";
    ?>
    <style>
        .nexis-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 22px; margin-bottom: 22px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); }
        .nexis-tabs-bar { display: flex; gap: 6px; border-bottom: 2px solid #cbd5e1; margin-bottom: 20px; flex-wrap: wrap; align-items: center; }
        .nexis-tab-item { padding: 9px 18px; cursor: pointer; border-radius: 8px 8px 0 0; font-weight: bold; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; border-bottom: none; font-size: 13px; }
        .nexis-tab-item.active { background: #0073aa; color: #fff; border-color: #0073aa; }
        .nexis-tab-add { padding: 8px 14px; background: #166534; color: #fff; border-radius: 6px; font-weight: bold; cursor: pointer; border: none; font-size: 12px; margin-right: auto; }
        .nexis-tab-pane { display: none; }
        .nexis-tab-pane.active { display: block; }
        .active-endpoint-card { background: #f0f7ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 18px; margin-bottom: 22px; }
        .active-badge { background: #dbeafe; color: #1e40af; padding: 3px 10px; border-radius: 6px; font-weight: bold; font-size: 12px; display: inline-flex; align-items: center; gap: 4px; }
        .nexis-models-table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 12.5px; }
        .nexis-models-table th, .nexis-models-table td { border: 1px solid #e2e8f0; padding: 8px 12px; text-align: right; }
        .nexis-models-table th { background: #f8fafc; }
        .nexis-version-banner { background: #0f172a; color: #fff; border-radius: 8px; padding: 14px 20px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 22px; }
    </style>

    <div class="wrap" style="direction: rtl; text-align: right; max-width: 1100px;">
        <h1 style="margin-bottom: 15px;">مدیریت هسته هوش مصنوعی نکسیس (Nexis AI Engine)</h1>

        <div class="nexis-version-banner">
            <div>
                <span style="font-size: 15px; font-weight: bold;">⚡ نگارش هسته: <code>v<?php echo NEXIS_AI_VERSION; ?></code></span>
                <span style="margin: 0 10px; opacity: 0.5;">|</span>
                <span style="font-size: 13px; opacity: 0.85;">مخزن متصل: <code><?php echo NEXIS_AI_GITHUB_REPO; ?></code></span>
            </div>
            <div style="display: flex; gap: 10px; align-items: center;">
                <span id="nexis-update-status" style="font-size: 12.5px; margin-left: 8px;"></span>
                <button type="button" class="button" id="btn-check-github-update" style="background: #3b82f6; color: #fff; border: none; font-weight: bold; padding: 3px 14px;">
                    🔄 بررسی بروزرسانی از گیت‌هاب
                </button>
            </div>
        </div>

        <div style="background: #fff; padding: 16px 22px; border: 1px solid #ccd0d4; border-radius: 8px; margin-bottom: 22px; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h3 style="margin-top: 0; margin-bottom: 6px; color: #0073aa;">پایگاه دانش محلی (Nexis Knowledge Base)</h3>
                <p style="margin-bottom: 0; color: #555;">تعداد صفحات و محصولات ذخیره‌شده برای پاسخ‌دهی RAG: <strong><?php echo intval($total_indexed); ?></strong> مورد</p>
            </div>
            <div style="display:flex; gap:10px;">
                <a href="<?php echo admin_url('admin.php?page=nexis-ai-tree'); ?>" class="button button-secondary">🌳 مدیریت درختچه صفحات خزش</a>
                <form method="post" action="">
                    <?php wp_nonce_field('nexis_ai_reindex_nonce'); ?>
                    <input type="submit" name="nexis_ai_reindex_now" class="button button-primary" value="🔄 خزش مجدد پایگاه دانش">
                </form>
            </div>
        </div>

        <!-- ابزار تست زنده جستجو در پایگاه دانش -->
        <div class="nexis-card" style="background: #fdfdfd; border: 2px dashed #0073aa;">
            <h3 style="margin-top: 0; color: #0073aa; display: flex; align-items: center; gap: 8px;">
                🔍 ابزار عیب‌یابی و تست زنده جستجوی پایگاه دانش (RAG Search Tester)
            </h3>
            <p style="color: #64748b; font-size: 13px; margin-bottom: 12px;">
                هر کلمه‌ای (مانند «استخدام»، «ظرف دلی» یا «پلیمر») را وارد کنید تا دقیقاً ببینید موتور جستجو چه مدارکی را با چه امتیازی برای ارسال به هوش مصنوعی واکشی می‌کند:
            </p>
            <div style="display: flex; gap: 10px; align-items: center;">
                <input type="text" id="nexis_debug_term" placeholder="تست عبارت... (مثلاً: استخدام یا ظرف دلی)" class="large-text" style="max-width: 400px; padding: 7px 10px;">
                <button type="button" class="button button-primary" id="btn-run-debug-search" style="padding: 4px 15px; font-weight: bold;">تست کوئری دیتابیس</button>
            </div>
            <div id="nexis_debug_results" style="margin-top: 15px; display: none;">
                <div style="font-weight: bold; margin-bottom: 8px;" id="nexis_debug_count"></div>
                <div id="nexis_debug_items" style="max-height: 280px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; background: #fff;"></div>
            </div>
        </div>

        <form method="post" action="" id="nexis-settings-form">
            <?php wp_nonce_field('nexis_ai_nonce'); ?>

            <div class="active-endpoint-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <div>
                        <strong style="font-size: 16px;" id="active-env-title"><?php echo esc_html(isset($envs[$active_env]['name']) ? $envs[$active_env]['name'] : 'پیش‌فرض'); ?></strong>
                        <span class="active-badge">✔ Active</span>
                    </div>
                    <div>
                        <button type="button" class="button button-secondary" id="btn-quick-test" style="font-weight: bold;">⚡ Test Connection</button>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 280px 1fr; gap: 20px; align-items: flex-start;">
                    <div>
                        <label style="font-weight: bold; display: block; margin-bottom: 6px;">محیط فعال سامانه:</label>
                        <select name="active_env" id="active_env_select" style="width: 100%; padding: 8px;">
                            <?php foreach ($envs as $eid => $e): ?>
                                <option value="<?php echo esc_attr($eid); ?>" <?php selected($active_env, $eid); ?>><?php echo esc_html(!empty($e['name']) ? $e['name'] : 'محیط'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label style="font-weight: bold; display: block; margin-bottom: 6px;">انتخاب مدل فعال برای چت و سئو:</label>
                        <div style="margin-bottom: 6px;">
                            <input type="text" id="model_live_search" placeholder="🔍 فیلتر و جستجو در لیست مدل‌ها..." style="width: 100%; max-width: 480px; direction: ltr; padding: 5px 8px; border: 1px solid #94a3b8; border-radius: 4px; font-size: 12.5px;">
                        </div>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <select id="default_model_select" style="min-width: 320px; max-width: 480px; padding: 7px; direction: ltr; font-weight: bold;">
                                <option value="<?php echo esc_attr($default_model); ?>"><?php echo esc_html($default_model ?: '-- ابتدا مدلی را انتخاب یا استعلام کنید --'); ?></option>
                            </select>
                            <input type="hidden" name="default_model" id="default_model_input" value="<?php echo esc_attr($default_model); ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- تب‌های اندپوینت‌ها -->
            <div class="nexis-card">
                <div class="nexis-tabs-bar" id="nexis-tabs-container">
                    <?php 
                    $first = true;
                    foreach ($envs as $env_id => $env): 
                        $tab_name = !empty($env['name']) ? $env['name'] : 'اندپوینت';
                    ?>
                        <div class="nexis-tab-item <?php echo ($env_id === $active_env) ? 'active' : ($first && empty($active_env) ? 'active' : ''); ?>" data-id="<?php echo esc_attr($env_id); ?>">
                            <span class="tab-label-text"><?php echo esc_html($tab_name); ?></span>
                        </div>
                    <?php 
                    $first = false; 
                    endforeach; 
                    ?>
                    <button type="button" class="nexis-tab-add" id="btn-add-env">➕ افزودن اندپوینت جدید</button>
                </div>

                <div id="nexis-panes-container">
                    <?php 
                    $first = true;
                    foreach ($envs as $env_id => $env): 
                        $cached_models = !empty($env['cached_models']) ? $env['cached_models'] : [];
                        $is_active_pane = ($env_id === $active_env) || ($first && empty($active_env));
                    ?>
                        <div class="nexis-tab-pane <?php echo $is_active_pane ? 'active' : ''; ?>" id="pane-<?php echo esc_attr($env_id); ?>" data-id="<?php echo esc_attr($env_id); ?>">
                            <table class="form-table">
                                <tr>
                                    <th scope="row" style="width: 170px;">نام نمایشی (Name)</th>
                                    <td>
                                        <input type="text" name="envs[<?php echo esc_attr($env_id); ?>][name]" class="regular-text env-name-input" data-id="<?php echo esc_attr($env_id); ?>" value="<?php echo esc_attr($env['name']); ?>" placeholder="نام نمایشی">
                                        <?php if (count($envs) > 1): ?>
                                            <button type="button" class="button button-link-delete btn-del-env" data-id="<?php echo esc_attr($env_id); ?>" style="margin-right: 15px; color: #dc2626;">🗑 حذف این اندپوینت</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">آدرس سرور (Endpoint URL)</th>
                                    <td>
                                        <input type="text" name="envs[<?php echo esc_attr($env_id); ?>][base_url]" id="url-<?php echo esc_attr($env_id); ?>" value="<?php echo esc_attr($env['base_url']); ?>" class="large-text" style="direction: ltr;" placeholder="مثال: https://api.openai.com/v1">
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">کلید اتصال (API Key)</th>
                                    <td>
                                        <input type="password" name="envs[<?php echo esc_attr($env_id); ?>][api_key]" id="key-<?php echo esc_attr($env_id); ?>" value="<?php echo esc_attr($env['api_key']); ?>" class="large-text" style="direction: ltr;" placeholder="در صورت نیاز وارد کنید">
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">مدل پیش‌فرض این اندپوینت</th>
                                    <td>
                                        <input type="text" name="envs[<?php echo esc_attr($env_id); ?>][default_model]" id="defmodel-<?php echo esc_attr($env_id); ?>" value="<?php echo esc_attr(!empty($env['default_model']) ? $env['default_model'] : ''); ?>" class="regular-text" style="direction: ltr;" placeholder="شناسه مدل">
                                    </td>
                                </tr>
                            </table>

                            <div style="margin: 15px 0; display: flex; gap: 10px; align-items: center;">
                                <button type="button" class="button button-primary btn-fetch-env-models" data-id="<?php echo esc_attr($env_id); ?>" style="background: #0073aa; font-weight: bold;">
                                    🔄 استعلام و کش لیست مدل‌ها (Discover Models)
                                </button>
                                <button type="button" class="button button-secondary btn-test-single-env" data-id="<?php echo esc_attr($env_id); ?>">
                                    ⚡ تست اتصال (Test)
                                </button>
                                <span class="status-fetch-<?php echo esc_attr($env_id); ?>" style="margin-right: 10px; font-weight: bold;">
                                    <?php if (!empty($cached_models)): ?><span style="color: green;">✔ تعداد <?php echo count($cached_models); ?> مدل فعال در حافظه</span><?php endif; ?>
                                </span>
                            </div>

                            <div class="models-table-box-<?php echo esc_attr($env_id); ?>" style="<?php echo empty($cached_models) ? 'display:none;' : ''; ?> max-height: 250px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 6px;">
                                <table class="nexis-models-table">
                                    <thead>
                                        <tr><th style="width: 120px;">نوع</th><th>نام مدل</th><th>شناسه سیستمی</th></tr>
                                    </thead>
                                    <tbody id="tbody-<?php echo esc_attr($env_id); ?>">
                                        <?php if (!empty($cached_models)): foreach ($cached_models as $m): ?>
                                            <tr style="cursor: pointer;" onclick="selectThisModel('<?php echo esc_js($env_id); ?>', '<?php echo esc_js($m['id']); ?>')">
                                                <td><span style="background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-size: 11px;"><?php echo esc_html($m['type']); ?></span></td>
                                                <td><strong><?php echo esc_html($m['name']); ?></strong></td>
                                                <td><code><?php echo esc_html($m['id']); ?></code></td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php 
                    $first = false; 
                    endforeach; 
                    ?>
                </div>
            </div>

            <!-- پارامترهای پردازشی -->
            <div class="nexis-card">
                <h3 style="margin-top: 0; color: #0073aa; border-bottom: 1px solid #eee; padding-bottom: 10px;">پارامترهای پردازشی (Context & Generation)</h3>
                <div style="display: flex; gap: 20px; align-items: center; flex-wrap: wrap;">
                    <div>
                        <label for="request_timeout"><strong>مهلت زمانی پاسخ (Timeout به ثانیه):</strong></label><br>
                        <input type="number" id="request_timeout" name="request_timeout" value="<?php echo intval($request_timeout); ?>" min="15" max="600" style="width: 120px; margin-top: 4px;">
                        <small style="display: block; color: #64748b;">(برای محلی ۱۸۰ ثانیه پیشنهاد می‌شود)</small>
                    </div>
                    <div>
                        <label for="max_tokens"><strong>Max Tokens:</strong></label><br>
                        <input type="number" id="max_tokens" name="max_tokens" value="<?php echo intval(!empty($settings['max_tokens']) ? $settings['max_tokens'] : 1024); ?>" style="width: 110px; margin-top: 4px;">
                    </div>
                    <div>
                        <label for="temperature"><strong>Temperature:</strong></label><br>
                        <input type="number" step="0.1" min="0" max="1" id="temperature" name="temperature" value="<?php echo floatval(isset($settings['temperature']) ? $settings['temperature'] : 0.3); ?>" style="width: 90px; margin-top: 4px;">
                    </div>
                    <div>
                        <label for="rag_limit"><strong>تعداد اسناد RAG:</strong></label><br>
                        <input type="number" id="rag_limit" name="rag_limit" value="<?php echo intval(!empty($settings['rag_limit']) ? $settings['rag_limit'] : 5); ?>" min="1" max="15" style="width: 80px; margin-top: 4px;">
                    </div>
                    <div>
                        <label for="chunk_len"><strong>حداکثر کاراکتر هر سند:</strong></label><br>
                        <input type="number" id="chunk_len" name="chunk_len" value="<?php echo intval(!empty($settings['chunk_len']) ? $settings['chunk_len'] : 1600); ?>" min="500" max="6000" style="width: 100px; margin-top: 4px;">
                    </div>
                </div>

                <div style="margin-top: 15px;">
                    <label for="system_prompt"><strong>پرامپت سیستمی:</strong></label><br>
                    <textarea id="system_prompt" name="system_prompt" rows="6" class="large-text" style="line-height: 1.5; font-family: monospace;"><?php echo esc_textarea(!empty($settings['system_prompt']) ? $settings['system_prompt'] : $default_prompt); ?></textarea>
                </div>
            </div>

            <!-- سوالات آماده چت‌بات -->
            <div class="nexis-card">
                <h3 style="margin-top: 0; color: #0073aa; border-bottom: 1px solid #eee; padding-bottom: 10px;">سوالات پیشنهادی و پرکاربرد چت‌بات (Quick Prompts)</h3>
                <label style="display: block; margin-bottom: 10px; font-weight: bold;">
                    <input type="checkbox" name="enable_quick_prompts" value="1" <?php checked(!empty($settings['enable_quick_prompts']) ? $settings['enable_quick_prompts'] : '0', '1'); ?>>
                    نمایش دکمه‌های سوالات متداول در صفحه چت (زیر پیام خوش‌آمدگویی)
                </label>
                <label for="quick_prompts_list" style="display:block; margin-bottom:5px;">لیست سوالات پیشنهادی (هر سوال در یک سطر):</label>
                <textarea id="quick_prompts_list" name="quick_prompts_list" rows="4" class="large-text" placeholder="مثال:&#10;فرصت‌های شغلی و استخدام چگونه است؟&#10;کاتالوگ و لیست محصولات را بفرست"><?php echo esc_textarea(!empty($settings['quick_prompts_list']) ? $settings['quick_prompts_list'] : "فرصت‌های شغلی و استخدام چگونه است؟\nکاتالوگ و لیست محصولات را بفرست\nشرایط همکاری و نحوه ثبت سفارش"); ?></textarea>
            </div>

            <!-- شخصی‌سازی ظاهر ویجت -->
            <div class="nexis-card">
                <h3 style="margin-top: 0; color: #0073aa; border-bottom: 1px solid #eee; padding-bottom: 10px;">شخصی‌سازی ظاهر ویجت فرانت‌اند و تصاویر اختصاصی</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="bot_name">عنوان چت‌بات</label></th>
                        <td><input type="text" id="bot_name" name="bot_name" value="<?php echo esc_attr(!empty($settings['bot_name']) ? $settings['bot_name'] : 'دستیار هوشمند نکسیس'); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row">۱. تصویر دکمه شناور گوشه صفحه (Launcher Button)</th>
                        <td>
                            <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 10px;">
                                <?php foreach ($available_launchers as $icon): ?>
                                    <label style="border: 1px solid #ddd; padding: 5px 10px; border-radius: 8px; cursor: pointer; background: #fafafa; font-size: 20px; display: inline-flex; align-items: center; gap: 5px;">
                                        <input type="radio" name="widget_launcher_icon" value="<?php echo esc_attr($icon); ?>" <?php checked(!empty($settings['widget_launcher_icon']) ? $settings['widget_launcher_icon'] : '💬', $icon); ?>>
                                        <?php echo esc_html($icon); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div style="display:flex; gap:8px; align-items:center;">
                                <input type="url" id="widget_launcher_img" name="widget_launcher_img" value="<?php echo esc_attr(!empty($settings['widget_launcher_img']) ? $settings['widget_launcher_img'] : ''); ?>" class="regular-text" style="direction: ltr;" placeholder="یا آدرس تصویر دلخواه دکمه شناور">
                                <button type="button" class="button nexis-upload-media-btn" data-target="#widget_launcher_img">🖼️ انتخاب از گالری وردپرس</button>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">۲. تصویر آواتار کارشناس داخل هدر چت (Header Avatar)</th>
                        <td>
                            <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 10px;">
                                <?php foreach ($available_avatars as $icon): ?>
                                    <label style="border: 1px solid #ddd; padding: 5px 10px; border-radius: 8px; cursor: pointer; background: #fafafa; font-size: 20px; display: inline-flex; align-items: center; gap: 5px;">
                                        <input type="radio" name="widget_avatar_icon" value="<?php echo esc_attr($icon); ?>" <?php checked(!empty($settings['widget_avatar_icon']) ? $settings['widget_avatar_icon'] : '🤖', $icon); ?>>
                                        <?php echo esc_html($icon); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div style="display:flex; gap:8px; align-items:center;">
                                <input type="url" id="widget_avatar_img" name="widget_avatar_img" value="<?php echo esc_attr(!empty($settings['widget_avatar_img']) ? $settings['widget_avatar_img'] : ''); ?>" class="regular-text" style="direction: ltr;" placeholder="یا آدرس عکس پروفایل کارشناس">
                                <button type="button" class="button nexis-upload-media-btn" data-target="#widget_avatar_img">🖼️ انتخاب از گالری وردپرس</button>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">قالب و تم رنگی</th>
                        <td>
                            <select name="widget_theme" id="widget_theme" style="min-width: 220px;">
                                <option value="theme-blue" <?php selected(!empty($settings['widget_theme']) ? $settings['widget_theme'] : 'theme-blue', 'theme-blue'); ?>>قالب ۱: آبی سازمانی (Classic Blue)</option>
                                <option value="theme-dark" <?php selected(!empty($settings['widget_theme']) ? $settings['widget_theme'] : '', 'theme-dark'); ?>>قالب ۲: تیره و مدرن (Dark Mode)</option>
                                <option value="theme-green" <?php selected(!empty($settings['widget_theme']) ? $settings['widget_theme'] : '', 'theme-green'); ?>>قالب ۳: سبز صنعتی (Industrial Green)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="widget_primary_color">رنگ تم اصلی</label></th>
                        <td><input type="color" id="widget_primary_color" name="widget_primary_color" value="<?php echo esc_attr(!empty($settings['widget_primary_color']) ? $settings['widget_primary_color'] : '#0073aa'); ?>" style="width: 45px; height: 35px; border: 1px solid #ccc; border-radius: 4px; cursor: pointer;"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="widget_stop_color">رنگ دکمه توقف</label></th>
                        <td><input type="color" id="widget_stop_color" name="widget_stop_color" value="<?php echo esc_attr(!empty($settings['widget_stop_color']) ? $settings['widget_stop_color'] : '#dc2626'); ?>" style="width: 45px; height: 35px; border: 1px solid #ccc; border-radius: 4px; cursor: pointer;"></td>
                    </tr>
                    <tr>
                        <th scope="row">موقعیت دکمه چت</th>
                        <td>
                            <label style="margin-left: 25px;"><input type="radio" name="widget_position" value="bottom-left" <?php checked(!empty($settings['widget_position']) ? $settings['widget_position'] : 'bottom-left', 'bottom-left'); ?>> پایین - چپ</label>
                            <label><input type="radio" name="widget_position" value="bottom-right" <?php checked(!empty($settings['widget_position']) ? $settings['widget_position'] : '', 'bottom-right'); ?>> پایین - راست</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">تنظیم فاصله (Offset)</th>
                        <td>
                            <label>فاصله افقی (X): <input type="number" name="widget_offset_x" value="<?php echo intval(!empty($settings['widget_offset_x']) ? $settings['widget_offset_x'] : 25); ?>" style="width: 70px;"> px</label>
                            &nbsp;&nbsp;&nbsp;
                            <label>فاصله عمودی (Y): <input type="number" name="widget_offset_y" value="<?php echo intval(!empty($settings['widget_offset_y']) ? $settings['widget_offset_y'] : 25); ?>" style="width: 70px;"> px</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">تنظیمات رفتار هوش مصنوعی</th>
                        <td>
                            <fieldset>
                                <label style="display: block; margin-bottom: 8px;"><input type="checkbox" name="enable_widget" value="1" <?php checked(!empty($settings['enable_widget']) ? $settings['enable_widget'] : '1', '1'); ?>> فعال‌سازی ویجت چت‌بات</label>
                                <label style="display: block; margin-bottom: 8px;"><input type="checkbox" name="enable_site_search" value="1" <?php checked(!empty($settings['enable_site_search']) ? $settings['enable_site_search'] : '1', '1'); ?>> فعال‌سازی RAG و جستجو در سایت</label>
                                <label style="display: block; margin-bottom: 8px;"><input type="checkbox" name="strict_mode" value="1" <?php checked(!empty($settings['strict_mode']) ? $settings['strict_mode'] : '1', '1'); ?>> گاردریل سخت‌گیرانه (Site-Only)</label>
                                <label style="display: block;"><input type="checkbox" name="enable_auto_schema" value="1" <?php checked(!empty($settings['enable_auto_schema']) ? $settings['enable_auto_schema'] : '0', '1'); ?>> تزریق خودکار اسکیما (Schema.org JSON-LD)</label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="offtopic_message">پیام سوالات نامرتبط</label></th>
                        <td><textarea id="offtopic_message" name="offtopic_message" rows="3" class="large-text"><?php echo esc_textarea(!empty($settings['offtopic_message']) ? $settings['offtopic_message'] : 'من دستیار هوشمند هستم و تمرکز من راهنمایی شما در زمینه خدمات و محتوای این وب‌سایت است.'); ?></textarea></td>
                    </tr>
                </table>
            </div>

            <p class="submit">
                <input type="submit" name="nexis_ai_save_settings" class="button button-primary button-hero" value="💾 ذخیره کلیه تنظیمات و محیط‌ها">
            </p>
        </form>
    </div>

    <script>
    var globalEnvs = <?php echo json_encode($envs); ?>;
    var currentDefaultModel = '<?php echo esc_js($default_model); ?>';
    var originalSelectOptions = [];

    function populateModelDropdown(envId, selectedModel) {
        var $ = jQuery;
        var sel = $('#default_model_select');
        sel.empty();
        originalSelectOptions = [];

        var models = (globalEnvs[envId] && globalEnvs[envId]['cached_models']) ? globalEnvs[envId]['cached_models'] : [];

        if (models.length === 0) {
            sel.append($('<option>', { value: '', text: '-- برای این اندپوینت مدلی استعلام نشده است --' }));
            if (selectedModel) {
                sel.append($('<option>', { value: selectedModel, text: selectedModel + ' (تنظیم دستی)' }).prop('selected', true));
            }
        } else {
            $.each(models, function(i, m) {
                var opt = $('<option>', { value: m.id, text: m.name + ' (' + m.id + ')' });
                if (m.id === selectedModel) {
                    opt.prop('selected', true);
                }
                sel.append(opt);
                originalSelectOptions.push({ value: m.id, text: (m.name + ' ' + m.id).toLowerCase() });
            });
        }
        $('#default_model_input').val(sel.val() || selectedModel);
    }

    function selectThisModel(envId, modelId) {
        jQuery('#defmodel-' + envId).val(modelId);
        if (jQuery('#active_env_select').val() === envId) {
            jQuery('#default_model_input').val(modelId);
            populateModelDropdown(envId, modelId);
        }
        alert('مدل «' + modelId + '» به عنوان مدل فعال انتخاب شد.');
    }

    jQuery(document).ready(function($) {
        populateModelDropdown($('#active_env_select').val(), currentDefaultModel);

        // ابزار دیباگ زنده سرچ
        $('#btn-run-debug-search').on('click', function(e) {
            e.preventDefault();
            var term = $('#nexis_debug_term').val().trim();
            if (!term) { alert('لطفاً عبارتی را تایپ کنید.'); return; }

            var btn = $(this), countBox = $('#nexis_debug_count'), itemsBox = $('#nexis_debug_items'), mainBox = $('#nexis_debug_results');
            btn.prop('disabled', true).text('در حال جستجو...');
            mainBox.show();
            itemsBox.html('<div style="color:#64748b; padding:10px;">در حال کوئری زدن در دیتابیس پایگاه دانش...</div>');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'nexis_ai_debug_search',
                    nonce: '<?php echo wp_create_nonce("nexis_ai_admin_nonce"); ?>',
                    term: term
                },
                success: function(res) {
                    btn.prop('disabled', false).text('تست کوئری دیتابیس');
                    if (res.success) {
                        countBox.html('تعداد رکوردهای یافت‌شده: <span style="color:#0073aa;">' + res.data.count + ' مورد</span>');
                        if (res.data.count === 0) {
                            itemsBox.html('<div style="color:#dc2626; padding:10px;">❌ هیچ رکوردی در جدول دانش با این عبارت تطابق پیدا نکرد!</div>');
                        } else {
                            var html = '';
                            $.each(res.data.results, function(i, item) {
                                html += '<div style="border-bottom:1px solid #eee; padding:8px 0;">' +
                                        '<strong>[' + (i + 1) + '] ' + item.title + '</strong> ' +
                                        '<span style="background:#e0f2fe; color:#0369a1; padding:2px 6px; border-radius:4px; font-size:11px;">امتیاز: ' + item.relevance_score + '</span> ' +
                                        '<span style="color:#64748b; font-size:11.5px;">(' + item.post_type + ')</span>' +
                                        '<div style="color:#475569; font-size:12px; margin-top:4px; line-height:1.6;">' + (item.content ? item.content.substring(0, 180) + '...' : 'بدون متن') + '</div>' +
                                        '</div>';
                            });
                            itemsBox.html(html);
                        }
                    }
                },
                error: function() {
                    btn.prop('disabled', false).text('تست کوئری دیتابیس');
                    itemsBox.html('<div style="color:#dc2626; padding:10px;">خطا در برقراری ارتباط شبکه.</div>');
                }
            });
        });

        $('.nexis-upload-media-btn').on('click', function(e) {
            e.preventDefault();
            var targetInput = $($(this).data('target'));
            var customUploader = wp.media({
                title: 'انتخاب یا آپلود تصویر',
                button: { text: 'استفاده از این تصویر' },
                multiple: false
            }).on('select', function() {
                var attachment = customUploader.state().get('selection').first().toJSON();
                targetInput.val(attachment.url);
            }).open();
        });

        $('#btn-check-github-update').on('click', function(e) {
            e.preventDefault();
            var btn = $(this), status = $('#nexis-update-status');
            btn.prop('disabled', true).text('در حال بررسی...');
            status.text('');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'nexis_ai_check_update_now',
                    nonce: '<?php echo wp_create_nonce("nexis_ai_admin_nonce"); ?>'
                },
                success: function(res) {
                    btn.prop('disabled', false).text('🔄 بررسی بروزرسانی از گیت‌هاب');
                    if (res.success && res.data) {
                        if (res.data.status === 'update_available') {
                            status.html('<span style="color:#4ade80;font-weight:bold;">' + res.data.message + ' <a href="' + res.data.release_url + '" target="_blank" style="color:#fff;text-decoration:underline;">دانلود</a></span>');
                        } else {
                            status.html('<span style="color:#94a3b8;">' + res.data.message + '</span>');
                        }
                    }
                },
                error: function() {
                    btn.prop('disabled', false).text('🔄 بررسی بروزرسانی از گیت‌هاب');
                    status.html('<span style="color:#f87171;">خطا در ارتباط با گیت‌هاب.</span>');
                }
            });
        });

        $('#model_live_search').on('input', function() {
            var term = $(this).val().toLowerCase().trim();
            var sel = $('#default_model_select');
            var curVal = sel.val();
            sel.empty();

            var matches = 0;
            $.each(originalSelectOptions, function(i, item) {
                if (!term || item.text.indexOf(term) !== -1) {
                    var opt = $('<option>', { value: item.value, text: item.value });
                    if (item.value === curVal) opt.prop('selected', true);
                    sel.append(opt);
                    matches++;
                }
            });

            if (matches === 0) {
                sel.append($('<option>', { value: term, text: 'استفاده از مدل دستی: ' + term }).prop('selected', true));
            }
            $('#default_model_input').val(sel.val());
        });

        $('#default_model_select').on('change', function() {
            $('#default_model_input').val($(this).val());
        });

        $(document).on('input', '.env-name-input', function() {
            var eid = $(this).data('id');
            var val = $(this).val().trim() || 'اندپوینت';
            $('.nexis-tab-item[data-id="' + eid + '"] .tab-label-text').text(val);
            $('#active_env_select option[value="' + eid + '"]').text(val);
            if ($('#active_env_select').val() === eid) {
                $('#active-env-title').text(val);
            }
        });

        $(document).on('click', '.nexis-tab-item', function() {
            var envId = $(this).data('id');
            $('.nexis-tab-item').removeClass('active');
            $(this).addClass('active');
            $('.nexis-tab-pane').removeClass('active');
            $('#pane-' + envId).addClass('active');
        });

        $('#active_env_select').on('change', function() {
            var eid = $(this).val();
            var name = $(this).find('option:selected').text();
            var defModel = $('#defmodel-' + eid).val() || '';

            $('#active-env-title').text(name);
            populateModelDropdown(eid, defModel);
            $('.nexis-tab-item[data-id="' + eid + '"]').click();
        });

        $('#btn-quick-test').on('click', function() {
            var activeId = $('#active_env_select').val();
            $('.btn-test-single-env[data-id="' + activeId + '"]').click();
        });

        $(document).on('click', '.btn-test-single-env', function() {
            var btn = $(this), envId = btn.data('id');
            var baseUrl = $('#url-' + envId).val().trim();
            var apiKey  = $('#key-' + envId).val().trim();
            var model   = $('#default_model_input').val().trim() || $('#defmodel-' + envId).val().trim();

            if (!baseUrl) {
                alert('لطفاً آدرس سرور را وارد فرمایید.');
                return;
            }

            btn.prop('disabled', true).text('در حال تست...');
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'nexis_ai_test_endpoint',
                    nonce: '<?php echo wp_create_nonce("nexis_ai_admin_nonce"); ?>',
                    base_url: baseUrl,
                    api_key: apiKey,
                    model: model
                },
                success: function(res) {
                    btn.prop('disabled', false).text('⚡ تست اتصال (Test)');
                    alert(res.data.message);
                },
                error: function() {
                    btn.prop('disabled', false).text('⚡ تست اتصال (Test)');
                    alert('خطا در برقراری ارتباط شبکه.');
                }
            });
        });

        $('#btn-add-env').on('click', function() {
            var count = $('.nexis-tab-item').length + 1;
            var id = 'env_' + count;
            var name = 'سرور ' + count;

            var tabHtml = $('<div class="nexis-tab-item" data-id="' + id + '"><span class="tab-label-text">' + name + '</span></div>');
            $('#btn-add-env').before(tabHtml);

            var paneHtml = `
            <div class="nexis-tab-pane" id="pane-${id}" data-id="${id}">
                <table class="form-table">
                    <tr>
                        <th scope="row" style="width: 170px;">نام نمایشی (Name)</th>
                        <td>
                            <input type="text" name="envs[${id}][name]" class="regular-text env-name-input" data-id="${id}" value="${name}" placeholder="نام نمایشی">
                            <button type="button" class="button button-link-delete btn-del-env" data-id="${id}" style="margin-right: 15px; color: #dc2626;">🗑 حذف این اندپوینت</button>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">آدرس سرور (Endpoint URL)</th>
                        <td>
                            <input type="text" name="envs[${id}][base_url]" id="url-${id}" value="" class="large-text" style="direction: ltr;" placeholder="مثال: https://api.openai.com/v1">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">کلید اتصال (API Key)</th>
                        <td>
                            <input type="password" name="envs[${id}][api_key]" id="key-${id}" value="" class="large-text" style="direction: ltr;" placeholder="در صورت نیاز وارد کنید">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">مدل پیش‌فرض این اندپوینت</th>
                        <td>
                            <input type="text" name="envs[${id}][default_model]" id="defmodel-${id}" value="" class="regular-text" style="direction: ltr;" placeholder="شناسه مدل">
                        </td>
                    </tr>
                </table>
                <div style="margin: 15px 0; display: flex; gap: 10px; align-items: center;">
                    <button type="button" class="button button-primary btn-fetch-env-models" data-id="${id}" style="background: #0073aa; font-weight: bold;">
                        🔄 استعلام و کش لیست مدل‌ها (Discover Models)
                    </button>
                    <button type="button" class="button button-secondary btn-test-single-env" data-id="${id}">
                        ⚡ تست اتصال (Test)
                    </button>
                    <span class="status-fetch-${id}" style="margin-right: 10px; font-weight: bold;"></span>
                </div>
                <div class="models-table-box-${id}" style="display:none; max-height: 250px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 6px;">
                    <table class="nexis-models-table">
                        <thead><tr><th style="width: 120px;">نوع</th><th>نام مدل</th><th>شناسه سیستمی</th></tr></thead>
                        <tbody id="tbody-${id}"></tbody>
                    </table>
                </div>
            </div>`;

            $('#nexis-panes-container').append(paneHtml);
            $('#active_env_select').append($('<option>', { value: id, text: name }));
            globalEnvs[id] = { name: name, cached_models: [] };
            tabHtml.click();
            $('#active_env_select').val(id).change();
        });

        $(document).on('click', '.btn-del-env', function() {
            if (!confirm('آیا از حذف این اندپوینت مطمئن هستید؟')) return;
            var envId = $(this).data('id');
            $('.nexis-tab-item[data-id="' + envId + '"]').remove();
            $('#pane-' + envId).remove();
            $('#active_env_select option[value="' + envId + '"]').remove();
            delete globalEnvs[envId];
            $('.nexis-tab-item').first().click();
            $('#active_env_select').change();
        });

        $(document).on('click', '.btn-fetch-env-models', function(e) {
            e.preventDefault();
            var btn = $(this), envId = btn.data('id');
            var baseUrl = $('#url-' + envId).val().trim();
            var apiKey  = $('#key-' + envId).val().trim();
            var statusSpan = $('.status-fetch-' + envId);
            var tbody = $('#tbody-' + envId);
            var tableBox = $('.models-table-box-' + envId);

            if (!baseUrl) {
                alert('لطفاً آدرس سرور را وارد فرمایید.');
                return;
            }

            btn.prop('disabled', true).text('در حال استعلام...');
            statusSpan.html('<span style="color:#0073aa;">در حال اتصال...</span>');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'nexis_ai_fetch_models',
                    nonce: '<?php echo wp_create_nonce("nexis_ai_admin_nonce"); ?>',
                    env_id: envId,
                    base_url: baseUrl,
                    api_key: apiKey
                },
                success: function(res) {
                    btn.prop('disabled', false).text('🔄 استعلام و کش لیست مدل‌ها (Discover Models)');
                    if (res.success && res.data.models) {
                        statusSpan.html('<span style="color:green;">✔ تعداد ' + res.data.count + ' مدل کش شد.</span>');
                        tbody.empty();
                        $.each(res.data.models, function(i, m) {
                            tbody.append('<tr style="cursor:pointer;" onclick="selectThisModel(\'' + envId + '\', \'' + m.id + '\')"><td><span style="background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:11px;">' + m.type + '</span></td><td><strong>' + m.name + '</strong></td><td><code>' + m.id + '</code></td></tr>');
                        });
                        tableBox.show();
                        if (!globalEnvs[envId]) globalEnvs[envId] = {};
                        globalEnvs[envId]['cached_models'] = res.data.models;
                        if ($('#active_env_select').val() === envId) {
                            populateModelDropdown(envId, res.data.models[0].id);
                        }
                    } else {
                        statusSpan.html('<span style="color:red;">' + (res.data ? res.data.message : 'خطا') + '</span>');
                    }
                },
                error: function() {
                    btn.prop('disabled', false).text('🔄 استعلام و کش لیست مدل‌ها (Discover Models)');
                    statusSpan.html('<span style="color:red;">خطا در برقراری ارتباط شبکه.</span>');
                }
            });
        });
    });
    </script>
    <?php
}

// صفحه منوی درختی (Tree-View Content Selector)
function nexis_ai_render_tree_page() {
    $settings = get_option('nexis_ai_settings', []);

    if (isset($_POST['nexis_save_tree']) && check_admin_referer('nexis_tree_nonce')) {
        $allowed = isset($_POST['allowed_posts']) && is_array($_POST['allowed_posts']) ? array_map('intval', $_POST['allowed_posts']) : [];
        $settings['allowed_indexed_ids'] = $allowed;
        update_option('nexis_ai_settings', $settings);
        echo '<div class="updated notice is-dismissible"><p>تنظیمات صفحات مجاز ذخیره شد.</p></div>';
    }

    $allowed_ids = !empty($settings['allowed_indexed_ids']) ? $settings['allowed_indexed_ids'] : [];

    $pages = get_pages(['post_status' => 'publish']);
    $products = function_exists('wc_get_products') ? wc_get_products(['limit' => -1, 'status' => 'publish']) : [];
    $posts = get_posts(['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => -1]);
    ?>
    <style>
        .nexis-tree-branch { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px; margin-bottom: 20px; }
        .nexis-tree-list { list-style: none; padding-right: 20px; margin: 10px 0; }
        .nexis-tree-list li { margin: 6px 0; font-size: 13.5px; }
        .nexis-tree-list label { cursor: pointer; }
    </style>

    <div class="wrap" style="direction: rtl; text-align: right; max-width: 1000px;">
        <h1 style="margin-bottom: 15px;">انتخاب درختی صفحات مجاز جهت خزش و سئو (Tree-View)</h1>
        <p style="color: #64748b; margin-bottom: 20px;">
            صفحات و محصولاتی که مایلید هوش مصنوعی در پایگاه دانش RAG یاد بگیرد را انتخاب کنید. اگر گزینه‌ای انتخاب نشود، به صورت خودکار تمام صفحات مجاز ایندکس خواهند شد.
        </p>

        <form method="post" action="">
            <?php wp_nonce_field('nexis_tree_nonce'); ?>

            <div style="margin-bottom: 15px; display:flex; gap:10px;">
                <button type="button" class="button" id="btn-check-all">انتخاب همه</button>
                <button type="button" class="button" id="btn-uncheck-all">لغو انتخاب همه</button>
            </div>

            <div class="nexis-tree-branch">
                <h3 style="margin-top:0; color:#0073aa; border-bottom:1px solid #eee; padding-bottom:8px;">📄 برگه‌های وب‌سایت (Pages)</h3>
                <ul class="nexis-tree-list">
                    <?php foreach ($pages as $pg): 
                        $checked = empty($allowed_ids) || in_array($pg->ID, $allowed_ids);
                    ?>
                        <li>
                            <label>
                                <input type="checkbox" name="allowed_posts[]" value="<?php echo esc_attr($pg->ID); ?>" class="tree-cb" <?php checked($checked, true); ?>>
                                <strong><?php echo esc_html($pg->post_title); ?></strong>
                                <small style="color:#64748b;">(<?php echo esc_html($pg->post_name); ?>)</small>
                            </label>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <?php if (!empty($products)): ?>
                <div class="nexis-tree-branch">
                    <h3 style="margin-top:0; color:#0073aa; border-bottom:1px solid #eee; padding-bottom:8px;">📦 محصولات فروشگاه (WooCommerce Products)</h3>
                    <ul class="nexis-tree-list">
                        <?php foreach ($products as $pr): 
                            $checked = empty($allowed_ids) || in_array($pr->get_id(), $allowed_ids);
                        ?>
                            <li>
                                <label>
                                    <input type="checkbox" name="allowed_posts[]" value="<?php echo esc_attr($pr->get_id()); ?>" class="tree-cb" <?php checked($checked, true); ?>>
                                    <strong><?php echo esc_html($pr->get_name()); ?></strong>
                                    <?php if ($pr->get_sku()): ?><code style="font-size:11px;"><?php echo esc_html($pr->get_sku()); ?></code><?php endif; ?>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($posts)): ?>
                <div class="nexis-tree-branch">
                    <h3 style="margin-top:0; color:#0073aa; border-bottom:1px solid #eee; padding-bottom:8px;">✍️ نوشته‌ها و مقالات وبلاگ (Posts)</h3>
                    <ul class="nexis-tree-list">
                        <?php foreach ($posts as $pt): 
                            $checked = empty($allowed_ids) || in_array($pt->ID, $allowed_ids);
                        ?>
                            <li>
                                <label>
                                    <input type="checkbox" name="allowed_posts[]" value="<?php echo esc_attr($pt->ID); ?>" class="tree-cb" <?php checked($checked, true); ?>>
                                    <strong><?php echo esc_html($pt->post_title); ?></strong>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <p class="submit">
                <input type="submit" name="nexis_save_tree" class="button button-primary button-hero" value="💾 ذخیره درختچه محتوا">
            </p>
        </form>
    </div>

    <script>
    jQuery(document).ready(function($) {
        $('#btn-check-all').on('click', function(){ $('.tree-cb').prop('checked', true); });
        $('#btn-uncheck-all').on('click', function(){ $('.tree-cb').prop('checked', false); });
    });
    </script>
    <?php
}

// صفحه سئو و ممیزی معنایی GEO
function nexis_ai_render_geo_page() {
    $settings = get_option('nexis_ai_settings', []);

    if (isset($_POST['nexis_save_geo_settings']) && check_admin_referer('nexis_geo_nonce')) {
        $settings['geo_sector'] = sanitize_text_field($_POST['geo_sector']);
        $settings['geo_custom_indicators'] = sanitize_text_field($_POST['geo_custom_indicators']);
        update_option('nexis_ai_settings', $settings);
        echo '<div class="updated notice is-dismissible"><p>شاخص‌های سئو معنایی (GEO) بروزرسانی شدند.</p></div>';
    }

    $geo_sector = !empty($settings['geo_sector']) ? $settings['geo_sector'] : 'auto';
    $custom_ind = !empty($settings['geo_custom_indicators']) ? $settings['geo_custom_indicators'] : '';

    $active_indicators = [];
    if ($geo_sector === 'polymer') {
        $active_indicators = ['ابعاد', 'حجم', 'جنس', 'دما'];
    } elseif ($geo_sector === 'fashion') {
        $active_indicators = ['سایز', 'رنگ', 'جنس پارچه', 'رده سنی'];
    } elseif ($geo_sector === 'electronics') {
        $active_indicators = ['توان/مصرف', 'حافظه/ظرفیت', 'وزن', 'گارانتی'];
    } elseif ($geo_sector === 'custom' && !empty($custom_ind)) {
        $active_indicators = array_filter(array_map('trim', explode(',', $custom_ind)));
    }

    if (empty($active_indicators)) {
        if (function_exists('wc_get_attribute_taxonomies')) {
            $taxonomies = wc_get_attribute_taxonomies();
            if (!empty($taxonomies)) {
                $cnt = 0;
                foreach ($taxonomies as $t) {
                    $active_indicators[] = $t->attribute_label;
                    $cnt++;
                    if ($cnt >= 4) break;
                }
            }
        }
    }

    if (empty($active_indicators)) {
        $active_indicators = ['ابعاد', 'حجم', 'جنس', 'دما'];
    }

    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $per_page = 15;

    $allowed_ids = !empty($settings['allowed_indexed_ids']) ? $settings['allowed_indexed_ids'] : [];

    $query_args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => $per_page,
        'paged'          => $paged
    ];

    if (!empty($allowed_ids)) {
        $query_args['post__in'] = $allowed_ids;
    }

    $query = new WP_Query($query_args);
    $total_products = $query->found_posts;
    $total_pages    = $query->max_num_pages;
    $products       = $query->posts;
    ?>
    <style>
        .nexis-geo-table th, .nexis-geo-table td { text-align: right !important; vertical-align: middle !important; padding: 10px 12px !important; }
        .nexis-attr-tag { display: inline-block; padding: 2px 7px; border-radius: 4px; font-size: 11.5px; margin: 2px; font-weight: 500; }
        .nexis-attr-ok { background: #e6f4ea; color: #137333; border: 1px solid #ceead6; }
        .nexis-attr-miss { background: #fce8e6; color: #c5221f; border: 1px solid #fad2cf; }
    </style>

    <div class="wrap" style="direction: rtl; text-align: right; max-width: 1200px;">
        <h1 style="margin-bottom: 15px;">ممیزی سئو معنایی و هوش مصنوعی نکسیس (GEO Audit & Technical SEO)</h1>

        <form method="post" action="" style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:15px; margin-bottom:20px; display:flex; gap:15px; align-items:center; flex-wrap:wrap;">
            <?php wp_nonce_field('nexis_geo_nonce'); ?>
            <div>
                <label><strong>حوزه تخصصی وب‌سایت:</strong></label>
                <select name="geo_sector" id="geo_sector_select" style="margin-right:6px;">
                    <option value="auto" <?php selected($geo_sector, 'auto'); ?>>شناسایی خودکار بر اساس ویژگی‌های ووکامرس</option>
                    <option value="polymer" <?php selected($geo_sector, 'polymer'); ?>>صنعتی، ظروف و پلیمر (ابعاد، حجم، جنس، دما)</option>
                    <option value="fashion" <?php selected($geo_sector, 'fashion'); ?>>پوشاک و مد (سایز، رنگ، جنس پارچه، رده سنی)</option>
                    <option value="electronics" <?php selected($geo_sector, 'electronics'); ?>>دیجیتال و تجهیزات (توان، حافظه، وزن، گارانتی)</option>
                    <option value="custom" <?php selected($geo_sector, 'custom'); ?>>سفارشی و دلخواه</option>
                </select>
            </div>
            <div id="custom_ind_wrapper" style="<?php echo ($geo_sector === 'custom') ? '' : 'display:none;'; ?>">
                <input type="text" name="geo_custom_indicators" value="<?php echo esc_attr($custom_ind); ?>" placeholder="شاخص‌ها با کاما جدا شوند (حداکثر ۴ مورد)" class="regular-text">
            </div>
            <input type="submit" name="nexis_save_geo_settings" class="button button-secondary" value="اعمال شاخص‌ها">
            <span style="color:#64748b; margin-right:auto;">تعداد محصولات در حال ممیزی: <strong><?php echo $total_products; ?></strong> مورد</span>
        </form>

        <table class="wp-list-table widefat fixed striped nexis-geo-table" style="border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <thead>
                <tr>
                    <th style="width: 22%;">نام محصول</th>
                    <th style="width: 13%;">شناسه (SKU)</th>
                    <th style="width: 28%;">وضعیت شاخص‌های کلیدی (<?php echo implode(' | ', $active_indicators); ?>)</th>
                    <th style="width: 11%;">امتیاز GEO</th>
                    <th style="width: 13%;">تکمیل اطلاعات</th>
                    <th style="width: 13%;">دستیار سئو AI</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                    <tr><td colspan="6" style="text-align: center; padding: 25px;">محصولی برای ممیزی یافت نشد.</td></tr>
                <?php else: ?>
                    <?php foreach ($products as $p): 
                        $wc_prod = function_exists('wc_get_product') ? wc_get_product($p->ID) : null;
                        $sku = ($wc_prod && $wc_prod->get_sku()) ? $wc_prod->get_sku() : '';
                        $attrs = $wc_prod ? $wc_prod->get_attributes() : [];

                        $attr_statuses = [];
                        $matched_count = 0;

                        foreach ($active_indicators as $ind) {
                            $has_this = false;
                            if (!empty($attrs)) {
                                foreach ($attrs as $attr_key => $attr_obj) {
                                    $label = mb_strtolower(wc_attribute_label($attr_key), 'UTF-8');
                                    if (strpos($label, mb_strtolower($ind, 'UTF-8')) !== false) {
                                        $has_this = true;
                                        break;
                                    }
                                }
                            }
                            if ($has_this) $matched_count++;
                            $attr_statuses[$ind] = $has_this;
                        }

                        $score = 40;
                        if (!empty($sku)) $score += 20;
                        if (count($active_indicators) > 0) {
                            $score += intval(($matched_count / count($active_indicators)) * 25);
                        }
                        if (!empty($p->post_content) || ($wc_prod && !empty($wc_prod->get_short_description()))) $score += 15;

                        $badge_color = ($score >= 80) ? '#137333' : (($score >= 60) ? '#b06000' : '#c5221f');
                        $badge_bg    = ($score >= 80) ? '#e6f4ea' : (($score >= 60) ? '#fef7e0' : '#fce8e6');
                    ?>
                        <tr>
                            <td><strong><a href="<?php echo get_edit_post_link($p->ID); ?>"><?php echo esc_html($p->post_title); ?></a></strong></td>
                            <td>
                                <?php if (!empty($sku)): ?>
                                    <code><?php echo esc_html($sku); ?></code>
                                <?php else: ?>
                                    <span style="color: #c5221f; font-weight: bold;">✕ فاقد کد</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div>
                                    <?php foreach ($attr_statuses as $ind_name => $is_ok): ?>
                                        <span class="nexis-attr-tag <?php echo $is_ok ? 'nexis-attr-ok' : 'nexis-attr-miss'; ?>">
                                            <?php echo $is_ok ? '✔' : '✕'; ?> <?php echo esc_html($ind_name); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td>
                                <span style="display:inline-block; padding:3px 10px; border-radius:12px; color:<?php echo $badge_color; ?>; background:<?php echo $badge_bg; ?>; font-weight:bold;">
                                    <?php echo $score; ?>٪
                                </span>
                            </td>
                            <td>
                                <a href="<?php echo get_edit_post_link($p->ID); ?>" target="_blank" class="button button-small">✏️ ویرایش</a>
                            </td>
                            <td>
                                <button type="button" class="button button-primary nexis-ai-seo-btn" data-id="<?php echo $p->ID; ?>" data-title="<?php echo esc_attr($p->post_title); ?>">
                                    ⚡ تولید سئو و اسکیما
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
            <div class="tablenav" style="margin-top: 15px;">
                <div class="tablenav-pages">
                    <span class="pagination-links">
                        <?php
                        echo paginate_links([
                            'base'      => add_query_arg('paged', '%#%'),
                            'format'    => '',
                            'prev_text' => '&laquo; قبلی',
                            'next_text' => 'بعدی &raquo;',
                            'total'     => $total_pages,
                            'current'   => $paged
                        ]);
                        ?>
                    </span>
                </div>
            </div>
        <?php endif; ?>

        <div id="nexis-seo-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.65); z-index: 999999; justify-content: center; align-items: center;">
            <div style="background: #fff; width: 700px; max-width: 90vw; max-height: 85vh; border-radius: 12px; overflow-y: auto; padding: 25px; position: relative;">
                <span id="nexis-close-modal" style="position: absolute; top: 15px; left: 20px; font-size: 22px; cursor: pointer; color: #888;">✕</span>
                <h3 id="nexis-modal-title" style="margin-top: 0; color: #0073aa; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px;">بهینه‌سازی سئو با هوش مصنوعی</h3>
                
                <div id="nexis-modal-loading" style="text-align: center; padding: 35px; color: #555;">
                    <div style="font-size: 26px; margin-bottom: 10px;">⏳</div>
                    در حال تولید تایتل، توضیحات متا و اسکیمای ساختاریافته...
                </div>

                <div id="nexis-modal-content" style="display: none;">
                    <div style="margin-bottom: 15px;">
                        <label style="font-weight: bold; display: block; margin-bottom: 5px;">تایتل سئو پیشنهادی (Meta Title):</label>
                        <input type="text" id="nexis-out-title" class="large-text" readonly style="background: #f8fafc; font-weight: bold; padding: 8px;">
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label style="font-weight: bold; display: block; margin-bottom: 5px;">توضیحات متای استاندارد (Meta Description):</label>
                        <textarea id="nexis-out-desc" rows="3" class="large-text" readonly style="background: #f8fafc; padding: 8px;"></textarea>
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label style="font-weight: bold; display: block; margin-bottom: 5px;">۳ سوال متداول استخراج‌شده:</label>
                        <div id="nexis-out-faqs" style="background: #f1f5f9; padding: 12px; border-radius: 6px; font-size: 12.5px; line-height: 1.7;"></div>
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label style="font-weight: bold; display: block; margin-bottom: 5px;">کد اسکیما ساختاریافته (FAQPage JSON-LD):</label>
                        <textarea id="nexis-out-schema" rows="5" class="large-text" readonly style="direction: ltr; font-family: monospace; font-size: 11.5px; background: #1e293b; color: #38bdf8; padding: 10px;"></textarea>
                    </div>
                    <button type="button" id="nexis-copy-schema-btn" class="button button-secondary" style="font-weight: bold;">📋 کپی کل اسکیما در کلیپ‌بورد</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        $('#geo_sector_select').on('change', function() {
            if ($(this).val() === 'custom') {
                $('#custom_ind_wrapper').show();
            } else {
                $('#custom_ind_wrapper').hide();
            }
        });

        var modal = $('#nexis-seo-modal'), loading = $('#nexis-modal-loading'), content = $('#nexis-modal-content');
        $('#nexis-close-modal').on('click', function() { modal.css('display', 'none'); });

        $('.nexis-ai-seo-btn').on('click', function() {
            var btn = $(this), prodId = btn.data('id'), prodTitle = btn.data('title');
            $('#nexis-modal-title').text('بهینه‌سازی سئو با هوش مصنوعی: ' + prodTitle);
            modal.css('display', 'flex'); loading.show(); content.hide();

            $.ajax({
                url: ajaxurl, type: 'POST',
                data: { action: 'nexis_ai_generate_seo', nonce: '<?php echo wp_create_nonce("nexis_ai_admin_nonce"); ?>', product_id: prodId },
                success: function(res) {
                    loading.hide(); content.show();
                    if (res.success && res.data.parsed) {
                        var d = res.data.data;
                        $('#nexis-out-title').val(d.meta_title || '');
                        $('#nexis-out-desc').val(d.meta_description || '');
                        var faqHtml = '';
                        if (d.faqs && d.faqs.length) {
                            $.each(d.faqs, function(i, f) { faqHtml += '<div style="margin-bottom:8px;"><strong>س: ' + f.question + '</strong><br>پاسخ: ' + f.answer + '</div>'; });
                        }
                        $('#nexis-out-faqs').html(faqHtml || 'موردی یافت نشد.');
                        var schemaCode = (typeof d.schema_faq_code === 'object') ? JSON.stringify(d.schema_faq_code, null, 2) : d.schema_faq_code;
                        $('#nexis-out-schema').val(schemaCode || '');
                    } else {
                        $('#nexis-out-desc').val(res.data ? res.data.message : 'خطا در استخراج ساختار JSON.');
                    }
                },
                error: function() { loading.hide(); content.show(); alert('خطا در برقراری ارتباط شبکه.'); }
            });
        });

        $('#nexis-copy-schema-btn').on('click', function() {
            var copyText = document.getElementById("nexis-out-schema");
            copyText.select(); document.execCommand("copy");
            $(this).text('✔ اسکیما کپی شد!');
            var b = $(this); setTimeout(function() { b.text('📋 کپی کل اسکیما در کلیپ‌بورد'); }, 2000);
        });
    });
    </script>
    <?php
}

// صفحه مدیریت لایسنس
function nexis_ai_render_license_page() {
    $settings = get_option('nexis_ai_settings', []);

    if (isset($_POST['nexis_save_license']) && check_admin_referer('nexis_license_nonce')) {
        $settings['license_key'] = sanitize_text_field(trim($_POST['license_key']));
        update_option('nexis_ai_settings', $settings);
        echo '<div class="updated notice is-dismissible"><p>اطلاعات لایسنس ذخیره شد.</p></div>';
    }

    if (isset($_POST['nexis_delete_license']) && check_admin_referer('nexis_license_nonce')) {
        $settings['license_key'] = '';
        update_option('nexis_ai_settings', $settings);
        echo '<div class="notice notice-warning is-dismissible"><p>لایسنس حذف گردید و سیستم به حالت آزمایشی بازگشت.</p></div>';
    }

    $lic = nexis_ai_get_license_status();
    $site_host = parse_url(home_url(), PHP_URL_HOST);
    $current_key = !empty($settings['license_key']) ? $settings['license_key'] : '';

    $status_color = ($lic['status'] === 'active') ? '#166534' : (($lic['status'] === 'trial') ? '#0284c7' : '#dc2626');
    $status_bg    = ($lic['status'] === 'active') ? '#f0fdf4' : (($lic['status'] === 'trial') ? '#f0f9ff' : '#fef2f2');
    ?>
    <div class="wrap" style="direction: rtl; text-align: right; max-width: 900px;">
        <h1 style="margin-bottom: 20px;">مدیریت لایسنس تجاری نکسیس (Nexis License)</h1>
        
        <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 10px; padding: 25px; margin-bottom: 25px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 14px; margin-bottom: 20px;">
                <h3 style="margin: 0;">دامنه فعال این سامانه: <code style="font-size: 15px;"><?php echo esc_html($site_host); ?></code></h3>
                <span style="background: <?php echo $status_bg; ?>; color: <?php echo $status_color; ?>; padding: 6px 14px; border-radius: 6px; font-weight: bold; font-size: 13px;">
                    <?php echo esc_html($lic['message']); ?>
                </span>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 22px;">
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 18px;">
                    <span style="display: block; font-size: 12.5px; color: #64748b; margin-bottom: 4px;">تعداد ریکوئست‌های رایگان (Trial):</span>
                    <strong style="font-size: 18px; color: #0284c7;"><?php echo intval($lic['trial_count']); ?> / ۲۵</strong>
                </div>
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 18px;">
                    <span style="display: block; font-size: 12.5px; color: #64748b; margin-bottom: 4px;">ریکوئست‌های مصرف‌شده این لایسنس:</span>
                    <?php 
                    $max_label = (isset($lic['details']['max_requests']) && $lic['details']['max_requests'] > 0) ? number_format($lic['details']['max_requests']) : 'نامحدود';
                    ?>
                    <strong style="font-size: 18px; color: #166534;"><?php echo number_format(intval($lic['lic_count'])); ?> / <?php echo $max_label; ?></strong>
                </div>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field('nexis_license_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th style="width: 140px;"><label for="nexis_license_input">کلید لایسنس:</label></th>
                        <td>
                            <div style="position: relative; max-width: 600px;">
                                <input type="password" id="nexis_license_input" name="license_key" value="<?php echo esc_attr($current_key); ?>" class="large-text" style="direction: ltr; font-family: monospace; padding-left: 35px;" placeholder="کلید فعال‌سازی نکسیس...">
                                <span id="toggle_license_view" title="نمایش/مخفی کردن کلید" style="position: absolute; left: 10px; top: 8px; cursor: pointer; font-size: 16px; user-select: none;">👁️</span>
                            </div>
                        </td>
                    </tr>
                </table>

                <div style="margin-top: 20px; display: flex; gap: 10px; align-items: center;">
                    <input type="submit" name="nexis_save_license" class="button button-primary button-hero" value="ثبت و اعتبارسنجی لایسنس">
                    <?php if (!empty($current_key)): ?>
                        <input type="submit" name="nexis_delete_license" class="button button-link-delete" value="حذف لایسنس و بازگشت به حالت رایگان" onclick="return confirm('آیا مایلید لایسنس فعلی از سیستم پاک شود؟');" style="color: #dc2626; margin-right: 15px;">
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        $('#toggle_license_view').on('click', function() {
            var inp = $('#nexis_license_input');
            if (inp.attr('type') === 'password') {
                inp.attr('type', 'text');
                $(this).text('🔒');
            } else {
                inp.attr('type', 'password');
                $(this).text('👁️');
            }
        });
    });
    </script>
    <?php
}

// صفحه تاریخچه مکالمات
function nexis_ai_render_logs_page() {
    global $wpdb;
    $table_logs = $wpdb->prefix . 'nexis_ai_logs';

    $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
    $end_date   = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';

    $where = ['1=1'];
    $params = [];

    if (!empty($start_date)) {
        $where[] = "created_at >= %s";
        $params[] = $start_date . ' 00:00:00';
    }
    if (!empty($end_date)) {
        $where[] = "created_at <= %s";
        $params[] = $end_date . ' 23:59:59';
    }

    $where_sql = implode(' AND ', $where);

    $count_sql = "SELECT COUNT(*) FROM $table_logs WHERE $where_sql";
    $total_items = !empty($params) ? $wpdb->get_var($wpdb->prepare($count_sql, $params)) : $wpdb->get_var($count_sql);

    $per_page = 20;
    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($paged - 1) * $per_page;
    $total_pages = ceil($total_items / $per_page);

    $data_sql = "SELECT * FROM $table_logs WHERE $where_sql ORDER BY id DESC LIMIT %d OFFSET %d";
    $query_params = array_merge($params, [$per_page, $offset]);
    $logs = $wpdb->get_results($wpdb->prepare($data_sql, $query_params), ARRAY_A);

    $export_url = add_query_arg(['action' => 'export_csv', 'start_date' => $start_date, 'end_date' => $end_date]);
    ?>
    <style>
        .nexis-log-accordion { cursor: pointer; color: #0073aa; font-weight: 500; }
        .nexis-log-content { display: none; background: #f8fafc; padding: 10px 14px; border-radius: 6px; border: 1px solid #e2e8f0; margin-top: 6px; font-size: 12.5px; line-height: 1.7; white-space: pre-wrap; }
        .nexis-filter-bar { background: #fff; padding: 14px 18px; border: 1px solid #ccd0d4; border-radius: 8px; margin-bottom: 20px; display: flex; gap: 15px; align-items: center; flex-wrap: wrap; }
    </style>

    <div class="wrap" style="direction: rtl; text-align: right; max-width: 1200px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h1 style="margin: 0;">تاریخچه مکالمات هوش مصنوعی نکسیس</h1>
            <a href="<?php echo esc_url($export_url); ?>" class="button button-primary" style="background: #166534; border-color: #166534; font-weight: bold;">📥 خروجی اکسل/CSV تاریخچه</a>
        </div>

        <form method="get" action="" class="nexis-filter-bar">
            <input type="hidden" name="page" value="nexis-ai-logs">
            <div>
                <label>از تاریخ:</label>
                <input type="date" name="start_date" value="<?php echo esc_attr($start_date); ?>">
            </div>
            <div>
                <label>تا تاریخ:</label>
                <input type="date" name="end_date" value="<?php echo esc_attr($end_date); ?>">
            </div>
            <input type="submit" class="button button-secondary" value="اعمال فیلتر">
            <?php if (!empty($start_date) || !empty($end_date)): ?>
                <a href="<?php echo admin_url('admin.php?page=nexis-ai-logs'); ?>" class="button button-link-delete" style="color: #dc2626;">پاک کردن فیلتر</a>
            <?php endif; ?>
            <span style="margin-right: auto; color: #555;">مجموع رکوردهای یافت‌شده: <strong><?php echo intval($total_items); ?></strong> مورد</span>
        </form>

        <table class="wp-list-table widefat fixed striped" style="border-radius: 8px; overflow: hidden;">
            <thead>
                <tr>
                    <th style="width: 70px;">ردیف</th>
                    <th style="width: 28%;">پرسش کاربر</th>
                    <th style="width: 44%;">پاسخ هوش مصنوعی (کلیک برای باز/جمع شدن)</th>
                    <th style="width: 13%;">مدل</th>
                    <th style="width: 15%;">زمان (شمسی)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="5" style="text-align: center; padding: 25px;">هیچ مکالمه‌ای یافت نشد.</td></tr>
                <?php else: ?>
                    <?php foreach ($logs as $l): 
                        $preview = mb_substr(strip_tags($l['ai_response']), 0, 85, 'UTF-8');
                        if (mb_strlen(strip_tags($l['ai_response']), 'UTF-8') > 85) $preview .= '...';
                        $persian_date = nexis_ai_format_persian_datetime($l['created_at']);
                    ?>
                        <tr>
                            <td><?php echo esc_html($l['id']); ?></td>
                            <td><strong><?php echo esc_html($l['user_query']); ?></strong></td>
                            <td>
                                <div class="nexis-log-row">
                                    <div class="nexis-log-accordion">
                                        <span>▶ <?php echo esc_html($preview); ?></span>
                                    </div>
                                    <div class="nexis-log-content">
                                        <?php echo esc_html($l['ai_response']); ?>
                                    </div>
                                </div>
                            </td>
                            <td><code><?php echo esc_html($l['model_used']); ?></code></td>
                            <td><small style="direction: ltr; display: block; font-weight: bold;"><?php echo esc_html($persian_date); ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
            <div class="tablenav" style="margin-top: 15px;">
                <div class="tablenav-pages">
                    <span class="pagination-links">
                        <?php
                        echo paginate_links([
                            'base'      => add_query_arg('paged', '%#%'),
                            'format'    => '',
                            'prev_text' => '&laquo; قبلی',
                            'next_text' => 'بعدی &raquo;',
                            'total'     => $total_pages,
                            'current'   => $paged
                        ]);
                        ?>
                    </span>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
    jQuery(document).ready(function($) {
        $('.nexis-log-accordion').on('click', function() {
            var content = $(this).next('.nexis-log-content');
            var span = $(this).find('span');
            content.slideToggle(180);
            if (span.text().startsWith('▶')) {
                span.text(span.text().replace('▶', '▼'));
            } else {
                span.text(span.text().replace('▼', '▶'));
            }
        });
    });
    </script>
    <?php
}
