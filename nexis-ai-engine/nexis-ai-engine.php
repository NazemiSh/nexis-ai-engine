<?php
/**
 * Plugin Name: Nexis AI Engine
 * Plugin URI: https://github.com/NazemiSh/nexis-ai-engine
 * Description: پلتفرم تجاری هوش مصنوعی و بهینه‌ساز سئو معنایی (GEO) وردپرس با پایگاه دانش RAG، پشتیبانی چندمحیطه، مدل‌های آفلاین/ابری، بررسی خودکار آپدیت از گیت‌هاب و مدیریت لایسنس
 * Version: 1.0.1
 * Author: Nexis AI Core
 * Author URI: https://github.com/NazemiSh
 * Text Domain: nexis-ai-engine
 */

if (!defined('ABSPATH')) exit;

define('NEXIS_AI_VERSION', '1.0.1');
define('NEXIS_AI_GITHUB_REPO', 'NazemiSh/nexis-ai-engine');
define('NEXIS_AI_SECRET_SALT', 'NEXIS_CORE_SECURE_SALT_99812_xK9#');

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

// ==========================================
// موتور بررسی بروزرسانی مستقیم از گیت‌هاب (GitHub Updater)
// ==========================================
function nexis_ai_check_github_update() {
    $url = 'https://api.github.com/repos/' . NEXIS_AI_GITHUB_REPO . '/releases/latest';
    $response = wp_remote_get($url, [
        'headers' => ['User-Agent' => 'Nexis-AI-Engine-Updater/' . NEXIS_AI_VERSION],
        'timeout' => 12
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

add_action('wp_ajax_nexis_ai_check_update_now', function() {
    check_ajax_referer('nexis_ai_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'دسترسی غیرمجاز']);

    $res = nexis_ai_check_github_update();
    if (!$res) {
        wp_send_json_success([
            'status'  => 'up_to_date',
            'message' => 'نسخه فعلی (' . NEXIS_AI_VERSION . ') آخرین نسخه است یا مخزن گیت‌هاب هنوز ریلیزی ندارد.'
        ]);
    }

    if ($res['has_update']) {
        wp_send_json_success([
            'status'      => 'update_available',
            'latest'      => $res['new_version'],
            'current'     => NEXIS_AI_VERSION,
            'message'     => 'نسخه جدید ' . $res['new_version'] . ' در گیت‌هاب منتشر شده است!',
            'release_url' => $res['url']
        ]);
    } else {
        wp_send_json_success([
            'status'  => 'up_to_date',
            'message' => 'شما در حال حاضر از آخرین نسخه (' . NEXIS_AI_VERSION . ') استفاده می‌کنید.'
        ]);
    }
});

// ==========================================
// سیستم لایسنس
// ==========================================
function nexis_ai_validate_license($license_key) {
    if (empty($license_key)) return ['valid' => false, 'message' => 'کلید لایسنس وارد نشده است.'];
    $parts = explode('.', trim($license_key));
    if (count($parts) !== 2) return ['valid' => false, 'message' => 'ساختار فرمت کلید نامعتبر است.'];

    list($payload_b64, $signature) = $parts;
    $expected_sig = hash_hmac('sha256', $payload_b64, NEXIS_AI_SECRET_SALT);
    if (!hash_equals($expected_sig, $signature)) return ['valid' => false, 'message' => 'امضای امنیتی کلید نامعتبر است.'];

    $data = json_decode(base64_decode($payload_b64), true);
    if (!$data || !isset($data['domain']) || !isset($data['exp'])) return ['valid' => false, 'message' => 'داده‌های لایسنس نامعتبر است.'];

    $site_host = parse_url(home_url(), PHP_URL_HOST);
    if ($data['domain'] !== '*' && $data['domain'] !== $site_host) {
        return ['valid' => false, 'message' => "این لایسنس مخصوص دامنه {$data['domain']} صادر شده است."];
    }

    $is_lifetime = (intval($data['exp']) === 0);
    if (!$is_lifetime && time() > intval($data['exp'])) {
        return ['valid' => false, 'message' => 'تاریخ اعتبار این لایسنس منقضی شده است.'];
    }

    return [
        'valid'        => true,
        'domain'       => $data['domain'],
        'expires_at'   => $is_lifetime ? 'همیشگی / نامحدود (Lifetime)' : date('Y-m-d', $data['exp']),
        'is_lifetime'  => $is_lifetime,
        'max_requests' => isset($data['max_req']) ? intval($data['max_req']) : 0,
        'client_name'  => isset($data['client']) ? sanitize_text_field($data['client']) : 'کاربر ویژه'
    ];
}

function nexis_ai_get_license_status() {
    $settings = get_option('nexis_ai_settings', []);
    $license_key = !empty($settings['license_key']) ? trim($settings['license_key']) : '';
    $requests_count = intval(get_option('nexis_ai_requests_count', 0));

    if (!empty($license_key)) {
        $val = nexis_ai_validate_license($license_key);
        if ($val['valid']) {
            if ($val['max_requests'] > 0 && $requests_count >= $val['max_requests']) {
                return ['allowed' => false, 'status' => 'quota_exceeded', 'message' => 'سقف تعداد درخواست‌های لایسنس به پایان رسیده است.', 'details' => $val, 'count' => $requests_count];
            }
            return ['allowed' => true, 'status' => 'active', 'message' => 'لایسنس فعال و معتبر', 'details' => $val, 'count' => $requests_count];
        }
    }

    $trial_limit = 25;
    if ($requests_count < $trial_limit) {
        return [
            'allowed' => true,
            'status'  => 'trial',
            'message' => 'نسخه آزمایشی (محدود به ۲۵ ریکوئست رایگان)',
            'count'   => $requests_count,
            'limit'   => $trial_limit,
            'remain'  => ($trial_limit - $requests_count)
        ];
    }

    return [
        'allowed' => false,
        'status'  => 'expired',
        'message' => 'مهلت نسخه آزمایشی تمام شده است. لطفاً جهت ادامه لایسنس تجاری را وارد فرمایید.',
        'count'   => $requests_count
    ];
}

function nexis_ai_clean_content($content) {
    $content = preg_replace('/\[vc_[^\]]+\]|\[\/vc_[^\]]+\]/i', ' ', $content);
    $content = preg_replace('/\[elementor[^\]]*\]/i', ' ', $content);
    $content = strip_shortcodes($content);
    $content = wp_strip_all_tags($content);
    $content = preg_replace('/\s+/', ' ', $content);
    $content = str_replace(['ي', 'ك', 'ة', "\xc2\xa0", '‌'], ['ی', 'ک', 'ه', ' ', ' '], $content);
    return trim($content);
}

function nexis_ai_normalize_text($str) {
    $str = mb_strtolower($str, 'UTF-8');
    $str = str_replace(
        ['ي', 'ك', 'ة', '‌', "\xc2\xa0", '؟', '?', '!', '،', '؛', '.', ',', 'ماکروفری', 'مایکروفری', 'ماکروویو', 'مایکروفر', 'ماکروفر'],
        ['ی', 'ک', 'ه', ' ', ' ', '', '', '', '', '', '', '', 'مایکروویو', 'مایکروویو', 'مایکروویو', 'مایکروویو', 'مایکروویو'],
        $str
    );
    return trim($str);
}

function nexis_ai_get_full_post_content($post) {
    $full_text = '';

    if ($post->post_type === 'product' && function_exists('wc_get_product')) {
        $product = wc_get_product($post->ID);
        if ($product) {
            $full_text .= "نوع: محصول ووکامرس | نام محصول: " . $product->get_name() . "\n";
            $cats = wc_get_product_category_list($product->get_id(), ', ');
            if ($cats) $full_text .= "دسته‌بندی: " . wp_strip_all_tags($cats) . "\n";
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
                    $full_text .= "مشخصات فنی و ویژگی‌ها:\n - " . implode("\n - ", $attr_list) . "\n";
                }
            }

            if ($product->get_short_description()) {
                $full_text .= "خلاصه مشخصات: " . nexis_ai_clean_content($product->get_short_description()) . "\n";
            }
        }
    }

    $main_content = nexis_ai_clean_content($post->post_content);
    if (!empty($main_content)) $full_text .= "توضیحات و محتوا: " . $main_content . "\n";
    if (!empty($post->post_excerpt)) $full_text .= "گزیده: " . nexis_ai_clean_content($post->post_excerpt) . "\n";

    return trim($full_text);
}

function nexis_ai_index_single_post($post_id) {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;

    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish') return;

    $allowed_types = ['post', 'page', 'product'];
    if (!in_array($post->post_type, $allowed_types)) return;

    $title = get_the_title($post->ID);
    if (preg_match('/(استخدام|فرصت شغلی|همکاری با ما|حریم خصوصی|قوانین و مقررات)/ui', $title)) {
        return;
    }

    $clean_content = nexis_ai_get_full_post_content($post);
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
            'content'    => $clean_content,
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

    $posts = get_posts([
        'post_type'      => ['product', 'post', 'page'],
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids'
    ]);

    $count = 0;
    foreach ($posts as $p_id) {
        nexis_ai_index_single_post($p_id);
        $count++;
    }
    return $count;
}

function nexis_ai_search_knowledge_base($user_query, $limit = 5) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'nexis_ai_knowledge';

    $norm = nexis_ai_normalize_text($user_query);
    $stop_words = [
        'برای', 'یا', 'چه', 'دارید', 'دارین', 'چی', 'هست', 'مشخصات', 'رو', 'بده', 'کن', 'سلام', 'لطفا',
        'مدل', 'هایی', 'میخوام', 'چند', 'یک', 'در', 'به', 'از', 'با', 'بر', 'این', 'آن', 'است', 'شد',
        'طرز', 'تهیه', 'دستور', 'پخت', 'کجا', 'کدام', 'آیا', 'درباره', 'مربوط', 'می', 'کند', 'بخوام', 'بیاره', 'دارند', 'بهم', 'چیا'
    ];

    $raw_words = preg_split('/\s+/u', $norm);
    $search_terms = [];
    foreach ($raw_words as $w) {
        $w = trim($w);
        if (mb_strlen($w, 'UTF-8') >= 3 && !in_array($w, $stop_words)) {
            $search_terms[] = $w;
        }
    }

    if (empty($search_terms)) return [];

    $score_parts = [];
    $params = [];
    foreach ($search_terms as $term) {
        $like = '%' . $wpdb->esc_like($term) . '%';
        $score_parts[] = "(CASE WHEN title LIKE %s THEN 8 ELSE 0 END + CASE WHEN content LIKE %s THEN 2 ELSE 0 END)";
        $params[] = $like;
        $params[] = $like;
    }

    $score_formula = implode(' + ', $score_parts);
    $query = "SELECT title, url, content, post_type, ($score_formula) as relevance_score 
              FROM $table_name 
              HAVING relevance_score >= 1 
              ORDER BY relevance_score DESC, (post_type = 'product') DESC 
              LIMIT %d";

    $params[] = $limit;
    return $wpdb->get_results($wpdb->prepare($query, $params), ARRAY_A);
}

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
        wp_send_json_error(['message' => 'مدلی دریافت نشد. در صورت عدم دریافت، شناسه مدل را دستی در کادر بنویسید.']);
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
        wp_send_json_success(['message' => 'اتصال با موفقیت برقرار شد و مدل پاسخ داد.']);
    } else {
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $err = isset($body['error']['message']) ? $body['error']['message'] : "کد وضعیت HTTP: $code";
        wp_send_json_error(['message' => $err]);
    }
});

function nexis_ai_call_llm_api($system_prompt, $user_message) {
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
    $max_tokens  = isset($settings['max_tokens']) ? intval($settings['max_tokens']) : 1024;
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
        $c = intval(get_option('nexis_ai_requests_count', 0));
        update_option('nexis_ai_requests_count', $c + 1);
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
    $default_prompt = "تو مشاور ارشد و کارشناس فروش تخصصی محصولات سایت هستی.\n\nاطلاعات و مستندات موثق استخراج‌شده از وب‌سایت:\n{CONTEXT}\n\nدستورالعمل‌ها:\n۱. صرفاً بر اساس اطلاعات بالا به سوال کاربر پاسخ بده.\n۲. اگر کاربر درباره مدل‌ها یا مشخصات فنی محصولات سوال پرسید، تمام مشخصات، ویژگی‌ها و مدل‌های موجود را به شکل منظم و با بالت‌پوینت یا جدول توضیح بده.\n۳. حتماً در پایان نام محصولات لینک مرتبط را درج کن.\n۴. اگر پاسخ اصلاً در مستندات بالا موجود نیست، صرفاً پیام عدم تطابق را بازگردان.";

    $sys_template = !empty($settings['system_prompt']) ? $settings['system_prompt'] : $default_prompt;
    $offtopic_msg = !empty($settings['offtopic_message']) ? $settings['offtopic_message'] : 'من دستیار هوشمند هستم و تمرکز من راهنمایی شما در زمینه خدمات و محتوای این وب‌سایت است.';
    $model_name   = !empty($settings['default_model']) ? $settings['default_model'] : 'default';
    $rag_limit    = isset($settings['rag_limit']) ? intval($settings['rag_limit']) : 3;
    $chunk_len    = isset($settings['chunk_len']) ? intval($settings['chunk_len']) : 1500;

    $found = [];
    if (!empty($settings['enable_site_search']) && $settings['enable_site_search'] === '1') {
        $found = nexis_ai_search_knowledge_base($message, $rag_limit);
    }

    if (empty($found) && !empty($settings['strict_mode']) && $settings['strict_mode'] === '1') {
        nexis_ai_log_interaction($message, $offtopic_msg, $model_name, 'guardrail_blocked');
        wp_send_json_success(['reply' => $offtopic_msg]);
    }

    $knowledge_context = "مستندات یافت‌شده از پایگاه داده:\n";
    $product_links_markup = "";

    if (!empty($found)) {
        $product_links_markup .= "<div style='margin-top:12px; padding-top:8px; border-top:1px dashed #cbd5e1;'><strong>🔗 محصولات مرتبط در سایت:</strong><br>";
        foreach ($found as $idx => $r) {
            $num = $idx + 1;
            $type_label = ($r['post_type'] === 'product') ? '[محصول]' : '[محتوا]';
            $snippet = mb_substr($r['content'], 0, $chunk_len, 'UTF-8');
            $knowledge_context .= "--- مورد $num: $type_label {$r['title']} ---\nلینک: {$r['url']}\nمتن:\n{$snippet}\n\n";
            $product_links_markup .= "<a href='{$r['url']}' target='_blank' class='nexis-chat-link'>📦 {$r['title']}</a> ";
        }
        $product_links_markup .= "</div>";
    } else {
        $knowledge_context .= "هیچ سند مرتبطی یافت نشد.";
    }

    $final_system_prompt = str_replace('{CONTEXT}', $knowledge_context, $sys_template);
    $reply = nexis_ai_call_llm_api($final_system_prompt, $message);

    if (!empty($found) && strpos($reply, 'http') === false && strpos($reply, 'متاسفانه') === false) {
        $reply .= "\n\n" . $product_links_markup;
    }

    nexis_ai_log_interaction($message, $reply, $model_name, 'answered');
    wp_send_json_success(['reply' => $reply]);
}

// هندلر سئو
add_action('wp_ajax_nexis_ai_generate_seo', function() {
    check_ajax_referer('nexis_ai_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'دسترسی غیرمجاز']);

    $lic = nexis_ai_get_license_status();
    if (!$lic['allowed']) {
        wp_send_json_error(['message' => 'خطای لایسنس: ' . $lic['message']]);
    }

    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $post = get_post($product_id);
    if (!$post) wp_send_json_error(['message' => 'محصول یافت نشد']);

    $content = nexis_ai_get_full_post_content($post);

    $prompt = "تو متخصص ارشد سئو تکنیکال و مهندسی GEO هستی.\n"
        . "اطلاعات محصول:\n" . $content . "\n\n"
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
        wp_send_json_error(['message' => 'عدم دریافت ساختار JSON معتبر از مدل. پاسخ خام: ' . mb_substr($ai_response, 0, 300)]);
    }

    wp_send_json_success(['parsed' => true, 'data' => $parsed]);
});

// ویجت فرانت‌اند با تبدیل خودکار انواع لینک‌ها به دکمه
add_action('wp_footer', function () {
    $settings = get_option('nexis_ai_settings', []);
    if (empty($settings['enable_widget']) || $settings['enable_widget'] !== '1') return;

    $bot_name        = !empty($settings['bot_name']) ? esc_html($settings['bot_name']) : 'دستیار هوشمند نکسیس (Nexis AI)';
    $position        = !empty($settings['widget_position']) ? $settings['widget_position'] : 'bottom-left';
    $offset_x        = isset($settings['widget_offset_x']) ? intval($settings['widget_offset_x']) : 25;
    $offset_y        = isset($settings['widget_offset_y']) ? intval($settings['widget_offset_y']) : 25;
    $custom_color    = !empty($settings['widget_primary_color']) ? sanitize_hex_color($settings['widget_primary_color']) : '#0073aa';
    $theme           = !empty($settings['widget_theme']) ? $settings['widget_theme'] : 'theme-blue';
    $launcher_icon   = !empty($settings['widget_launcher_icon']) ? $settings['widget_launcher_icon'] : '💬';
    $header_avatar   = !empty($settings['widget_avatar_icon']) ? $settings['widget_avatar_icon'] : '🤖';
    $logo_url        = !empty($settings['widget_logo_url']) ? esc_url($settings['widget_logo_url']) : '';

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
        .nexis-btn-stop { background: #dc2626 !important; color: #fff !important; }
    </style>

    <div id="nexis-chat-root" style="direction: rtl; font-family: Tahoma, Vazirmatn, sans-serif;">
        <button id="nexis-chat-toggle" style="position: fixed; <?php echo $pos_btn_css; ?> width: 56px; height: 56px; border-radius: 50%; background: <?php echo $custom_color; ?>; border: none; color: #fff; cursor: pointer; box-shadow: 0 4px 15px rgba(0,0,0,0.25); display: flex; align-items: center; justify-content: center; z-index: 99999; font-size: 24px; padding: 0; overflow: hidden;">
            <?php if (!empty($logo_url)): ?>
                <img src="<?php echo $logo_url; ?>" alt="Chat Icon" style="width: 100%; height: 100%; object-fit: cover;">
            <?php else: ?>
                <span><?php echo esc_html($launcher_icon); ?></span>
            <?php endif; ?>
        </button>

        <div id="nexis-chat-box" style="display: none; position: fixed; <?php echo $pos_box_css; ?> width: 385px; max-width: 90vw; height: 530px; background: <?php echo $theme_bg; ?>; border-radius: 14px; box-shadow: 0 10px 35px rgba(0,0,0,0.22); z-index: 99999; flex-direction: column; overflow: hidden; border: 1px solid rgba(0,0,0,0.08);">
            <div style="background: <?php echo $custom_color; ?>; color: #fff; padding: 13px 16px; font-weight: bold; font-size: 14px; display: flex; justify-content: space-between; align-items: center;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="font-size: 20px;"><?php echo esc_html($header_avatar); ?></span>
                    <span><?php echo $bot_name; ?></span>
                </div>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <span id="nexis-chat-clear" title="پاک‌سازی تاریخچه" style="cursor: pointer; font-size: 14px; opacity: 0.85;">🗑</span>
                    <span id="nexis-chat-close" style="cursor: pointer; font-size: 18px;">✕</span>
                </div>
            </div>

            <div id="nexis-chat-messages" style="flex: 1; padding: 14px; overflow-y: auto; background: <?php echo $theme_chat; ?>; color: <?php echo $theme_text; ?>; font-size: 13.5px; line-height: 1.65;">
            </div>

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

        // تبدیل هوشمند انواع لینک‌ها (مارک‌داون یا آدرس مستقیم) به دکمه زیبا
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
                var welcome = { sender: 'bot', text: 'سلام! چطور می‌توانم درباره خدمات و محصولات وب‌سایت راهنمایی‌تان کنم؟' };
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

        function doSend() {
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

            var val = input.value.trim();
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
    })();
    </script>
    <?php
});

add_action('admin_menu', function () {
    add_menu_page('Nexis AI Engine', 'هوش مصنوعی Nexis', 'manage_options', 'nexis-ai-settings', 'nexis_ai_render_settings_page', 'dashicons-rest-api', 30);
    add_submenu_page('nexis-ai-settings', 'تنظیمات و مدل‌ها', 'تنظیمات و مدل‌ها', 'manage_options', 'nexis-ai-settings', 'nexis_ai_render_settings_page');
    add_submenu_page('nexis-ai-settings', 'سئو و بهینه‌سازی (GEO)', 'سئو و بهینه‌سازی (GEO)', 'manage_options', 'nexis-ai-geo', 'nexis_ai_render_geo_page');
    add_submenu_page('nexis-ai-settings', 'مدیریت لایسنس', '🔑 مدیریت لایسنس', 'manage_options', 'nexis-ai-license', 'nexis_ai_render_license_page');
    add_submenu_page('nexis-ai-settings', 'تاریخچه چت', 'تاریخچه و لاگ چت', 'manage_options', 'nexis-ai-logs', 'nexis_ai_render_logs_page');
});

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

        $settings['widget_position']      = sanitize_text_field($_POST['widget_position']);
        $settings['widget_offset_x']      = intval($_POST['widget_offset_x']);
        $settings['widget_offset_y']      = intval($_POST['widget_offset_y']);
        $settings['widget_theme']         = sanitize_text_field($_POST['widget_theme']);
        $settings['widget_primary_color'] = sanitize_hex_color($_POST['widget_primary_color']);
        $settings['widget_launcher_icon'] = sanitize_text_field($_POST['widget_launcher_icon']);
        $settings['widget_avatar_icon']   = sanitize_text_field($_POST['widget_avatar_icon']);
        $settings['widget_logo_url']      = esc_url_raw($_POST['widget_logo_url']);

        update_option('nexis_ai_settings', $settings);
        $envs = $new_envs;
        echo '<div class="updated notice is-dismissible"><p>تمامی تنظیمات با موفقیت ذخیره شدند.</p></div>';
    }

    $active_env      = !empty($settings['active_env']) ? $settings['active_env'] : key($envs);
    $default_model   = !empty($settings['default_model']) ? $settings['default_model'] : '';
    $request_timeout = !empty($settings['request_timeout']) ? intval($settings['request_timeout']) : 180;
    $total_indexed   = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");

    $available_launchers = ['💬', '🤖', '💭', '🗨️', '🎧', '⚡', '❓', '📦', '🏢', '✨'];
    $available_avatars   = ['👨‍💼', '👩‍💼', '🤖', '🎧', '👤', '🛡️', '🌟', '💼', '🎯', '🏭'];

    $default_prompt = "تو مشاور ارشد و کارشناس فروش تخصصی محصولات سایت هستی.\n\nاطلاعات و مستندات موثق استخراج‌شده از وب‌سایت:\n{CONTEXT}\n\nدستورالعمل‌ها:\n۱. صرفاً بر اساس اطلاعات بالا به سوال کاربر پاسخ بده.\n۲. اگر کاربر درباره مدل‌ها یا مشخصات فنی محصولات سوال پرسید، تمام مشخصات، ویژگی‌ها و مدل‌های موجود را به شکل منظم و با بالت‌پوینت یا جدول توضیح بده.\n۳. حتماً در پایان نام محصولات لینک مرتبط را درج کن.\n۴. اگر پاسخ اصلاً در مستندات بالا موجود نیست، صرفاً پیام عدم تطابق را بازگردان.";
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
        
        .shn-guide-box { background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; padding: 14px 18px; margin-bottom: 20px; font-size: 12.5px; line-height: 1.8; color: #334155; }
        .shn-guide-box strong { color: #0f172a; }
        .shn-star-item { margin-bottom: 4px; }
    </style>

    <div class="wrap" style="direction: rtl; text-align: right; max-width: 1100px;">
        <h1 style="margin-bottom: 15px;">مدیریت هسته هوش مصنوعی نکسیس (Nexis AI Engine)</h1>

        <!-- نوار وضعیت نسخه و بروزرسانی گیت‌هاب -->
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

        <!-- باکس راهنمای متنی به صورت ستاره‌دار برای لوکال و کلود -->
        <div class="shn-guide-box">
            <div style="font-weight: bold; margin-bottom: 8px; color: #0073aa; font-size: 13.5px;">💡 راهنمای جامع انتخاب و بهینه‌سازی مدل‌ها:</div>
            
            <div style="margin-bottom: 10px;">
                <strong>* توصیه‌ها برای سرورهای محلی و آفلاین (Local / CPU):</strong>
                <div class="shn-star-item">* مدل‌های سبک پیشنهادی: <code>qwen2.5:3b</code> (فارسی بسیار روان) | <code>gemma2:2b</code> (گوگل / پاسخ‌دهی سریع) | <code>llama3.2:3b</code> | <code>deepseek-r1:1.5b</code></div>
                <div class="shn-star-item">* برای جلوگیری از خطای Timeout در پردازش با CPU، پارامتر <strong>Max Tokens</strong> را روی ۵۱۲ یا ۱۰۲۴ و <strong>Timeout</strong> را حداقل روی ۱۸۰ ثانیه تنظیم کنید.</div>
                <div class="shn-star-item">* تعداد اسناد RAG روی ۲ الی ۳ سند بهترین توازن سرعت و دقت را در پردازش محلی فراهم می‌سازد.</div>
            </div>

            <div>
                <strong>* توصیه‌ها برای سرویس‌های ابری پرسرعت (Cloud APIs):</strong>
                <div class="shn-star-item">* سرویس‌های ابری پیشنهادی: <strong>OpenRouter</strong> (دسترسی به تمام مدل‌ها) | <strong>Groq Cloud</strong> (فوق‌سریع برای مدل‌های متن‌باز) | <strong>Nvidia NIM</strong> | <strong>OpenAI</strong></div>
                <div class="shn-star-item">* مدل‌های پیشنهادی برای کیفیت و قیمت بهینه: <code>openai/gpt-4o-mini</code> | <code>llama-3.1-8b-instant</code> | <code>deepseek-chat</code></div>
                <div class="shn-star-item">* برای سرویس‌های ابری، مقدار Timeout روی ۶۰ ثانیه و Max Tokens روی ۲۰۴۸ تا ۴۰۹۶ کارایی عالی دارد.</div>
            </div>
        </div>

        <!-- کارت پایگاه دانش و خزش اطلاعات سایت -->
        <div style="background: #fff; padding: 16px 22px; border: 1px solid #ccd0d4; border-radius: 8px; margin-bottom: 22px; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h3 style="margin-top: 0; margin-bottom: 6px; color: #0073aa;">پایگاه دانش محلی (Nexis Knowledge Base)</h3>
                <p style="margin-bottom: 0; color: #555;">تعداد صفحات و محصولات ذخیره‌شده برای پاسخ‌دهی RAG: <strong><?php echo intval($total_indexed); ?></strong> مورد</p>
            </div>
            <form method="post" action="">
                <?php wp_nonce_field('nexis_ai_reindex_nonce'); ?>
                <input type="submit" name="nexis_ai_reindex_now" class="button button-secondary" value="🔄 خزش مجدد پایگاه دانش">
            </form>
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
                                        <input type="text" name="envs[<?php echo esc_attr($env_id); ?>][base_url]" id="url-<?php echo esc_attr($env_id); ?>" value="<?php echo esc_attr($env['base_url']); ?>" class="large-text" style="direction: ltr;" placeholder="مثال: https://api.openai.com/v1 یا http://127.0.0.1:11434/v1">
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
                                        <tr><th style="width: 120px;">نوع</th><th>نام مدل</th><th>شناسه سیستمی (کلیک کنید تا به عنوان مدل انتخاب شود)</th></tr>
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

            <!-- پارامترهای پردازشی LLM -->
            <div class="nexis-card">
                <h3 style="margin-top: 0; color: #0073aa; border-bottom: 1px solid #eee; padding-bottom: 10px;">پارامترهای پردازشی (Context & Generation)</h3>
                <div style="display: flex; gap: 20px; align-items: center; flex-wrap: wrap;">
                    <div>
                        <label for="request_timeout"><strong>مهلت زمانی پاسخ (Timeout به ثانیه):</strong></label><br>
                        <input type="number" id="request_timeout" name="request_timeout" value="<?php echo intval($request_timeout); ?>" min="15" max="600" style="width: 120px; margin-top: 4px;">
                        <small style="display: block; color: #64748b;">(برای لوکال ۱۸۰ ثانیه پیشنهاد می‌شود)</small>
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
                        <input type="number" id="rag_limit" name="rag_limit" value="<?php echo intval(!empty($settings['rag_limit']) ? $settings['rag_limit'] : 3); ?>" min="1" max="15" style="width: 80px; margin-top: 4px;">
                    </div>
                    <div>
                        <label for="chunk_len"><strong>حداکثر کاراکتر هر سند:</strong></label><br>
                        <input type="number" id="chunk_len" name="chunk_len" value="<?php echo intval(!empty($settings['chunk_len']) ? $settings['chunk_len'] : 1500); ?>" min="500" max="6000" style="width: 100px; margin-top: 4px;">
                    </div>
                </div>

                <div style="margin-top: 15px;">
                    <label for="system_prompt"><strong>پرامپت سیستمی:</strong></label><br>
                    <textarea id="system_prompt" name="system_prompt" rows="6" class="large-text" style="line-height: 1.5; font-family: monospace;"><?php echo esc_textarea(!empty($settings['system_prompt']) ? $settings['system_prompt'] : $default_prompt); ?></textarea>
                </div>
            </div>

            <!-- شخصی‌سازی ویجت -->
            <div class="nexis-card">
                <h3 style="margin-top: 0; color: #0073aa; border-bottom: 1px solid #eee; padding-bottom: 10px;">شخصی‌سازی ظاهر ویجت فرانت‌اند</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="bot_name">عنوان چت‌بات</label></th>
                        <td><input type="text" id="bot_name" name="bot_name" value="<?php echo esc_attr(!empty($settings['bot_name']) ? $settings['bot_name'] : 'دستیار هوشمند نکسیس'); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row">۱. آیکون دکمه شناور گوشه صفحه (Launcher Button)</th>
                        <td>
                            <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                                <?php foreach ($available_launchers as $icon): ?>
                                    <label style="border: 1px solid #ddd; padding: 5px 10px; border-radius: 8px; cursor: pointer; background: #fafafa; font-size: 20px; display: inline-flex; align-items: center; gap: 5px;">
                                        <input type="radio" name="widget_launcher_icon" value="<?php echo esc_attr($icon); ?>" <?php checked(!empty($settings['widget_launcher_icon']) ? $settings['widget_launcher_icon'] : '💬', $icon); ?>>
                                        <?php echo esc_html($icon); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">۲. آیکون آواتار کارشناس داخل هدر چت (Header Avatar)</th>
                        <td>
                            <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                                <?php foreach ($available_avatars as $icon): ?>
                                    <label style="border: 1px solid #ddd; padding: 5px 10px; border-radius: 8px; cursor: pointer; background: #fafafa; font-size: 20px; display: inline-flex; align-items: center; gap: 5px;">
                                        <input type="radio" name="widget_avatar_icon" value="<?php echo esc_attr($icon); ?>" <?php checked(!empty($settings['widget_avatar_icon']) ? $settings['widget_avatar_icon'] : '🤖', $icon); ?>>
                                        <?php echo esc_html($icon); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="widget_logo_url">یا آپلود تصویر دلخواه</label></th>
                        <td><input type="url" id="widget_logo_url" name="widget_logo_url" value="<?php echo esc_attr(!empty($settings['widget_logo_url']) ? $settings['widget_logo_url'] : ''); ?>" class="large-text" style="direction: ltr;" placeholder="آدرس تصویر"></td>
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
                        <th scope="row"><label for="widget_primary_color">رنگ برند</label></th>
                        <td><input type="color" id="widget_primary_color" name="widget_primary_color" value="<?php echo esc_attr(!empty($settings['widget_primary_color']) ? $settings['widget_primary_color'] : '#0073aa'); ?>" style="width: 45px; height: 35px; border: 1px solid #ccc; border-radius: 4px; cursor: pointer;"></td>
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
                    status.html('<span style="color:#f87171;">خطا در برقراری ارتباط با GitHub API.</span>');
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
                alert('لطفاً ابتدا آدرس سرور (Endpoint URL) را وارد فرمایید.');
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
                alert('لطفاً آدرس سرور (Endpoint URL) را وارد فرمایید.');
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

function nexis_ai_render_geo_page() {
    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $per_page = 15;

    $query = new WP_Query([
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => $per_page,
        'paged'          => $paged
    ]);

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
        <h1 style="margin-bottom: 20px;">ممیزی سئو معنایی و هوش مصنوعی نکسیس (GEO Audit & Technical SEO)</h1>
        <p>تعداد محصولات در حال ممیزی: <strong><?php echo $total_products; ?></strong> مورد</p>

        <table class="wp-list-table widefat fixed striped nexis-geo-table" style="border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <thead>
                <tr>
                    <th style="width: 22%;">نام محصول</th>
                    <th style="width: 13%;">شناسه (SKU)</th>
                    <th style="width: 28%;">وضعیت ۴ شاخص کلیدی</th>
                    <th style="width: 11%;">امتیاز GEO</th>
                    <th style="width: 13%;">تکمیل اطلاعات</th>
                    <th style="width: 13%;">دستیار سئو AI</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                    <tr><td colspan="6" style="text-align: center; padding: 25px;">محصولی یافت نشد.</td></tr>
                <?php else: ?>
                    <?php foreach ($products as $p): 
                        $wc_prod = function_exists('wc_get_product') ? wc_get_product($p->ID) : null;
                        $sku = ($wc_prod && $wc_prod->get_sku()) ? $wc_prod->get_sku() : '';
                        $attrs = $wc_prod ? $wc_prod->get_attributes() : [];

                        $has_dim = false; $has_vol = false; $has_mat = false; $has_temp = false;
                        if (!empty($attrs)) {
                            foreach ($attrs as $attr_key => $attr_obj) {
                                $name_str = mb_strtolower(wc_attribute_label($attr_key), 'UTF-8');
                                if (strpos($name_str, 'ابعاد') !== false || strpos($name_str, 'سایز') !== false) $has_dim = true;
                                if (strpos($name_str, 'حجم') !== false || strpos($name_str, 'سی سی') !== false || strpos($name_str, 'ظرفیت') !== false) $has_vol = true;
                                if (strpos($name_str, 'جنس') !== false || strpos($name_str, 'متریال') !== false || strpos($name_str, 'پلیمر') !== false) $has_mat = true;
                                if (strpos($name_str, 'دما') !== false || strpos($name_str, 'ماکرو') !== false || strpos($name_str, 'حرارت') !== false) $has_temp = true;
                            }
                        }

                        $score = 40;
                        if (!empty($sku)) $score += 20;
                        if (!empty($attrs)) $score += 25;
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
                                    <span class="nexis-attr-tag <?php echo $has_dim ? 'nexis-attr-ok' : 'nexis-attr-miss'; ?>"><?php echo $has_dim ? '✔' : '✕'; ?> ابعاد</span>
                                    <span class="nexis-attr-tag <?php echo $has_vol ? 'nexis-attr-ok' : 'nexis-attr-miss'; ?>"><?php echo $has_vol ? '✔' : '✕'; ?> حجم</span>
                                    <span class="nexis-attr-tag <?php echo $has_mat ? 'nexis-attr-ok' : 'nexis-attr-miss'; ?>"><?php echo $has_mat ? '✔' : '✕'; ?> جنس</span>
                                    <span class="nexis-attr-tag <?php echo $has_temp ? 'nexis-attr-ok' : 'nexis-attr-miss'; ?>"><?php echo $has_temp ? '✔' : '✕'; ?> دما</span>
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

        <!-- مودال خروجی سئو -->
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

function nexis_ai_render_license_page() {
    if (isset($_POST['nexis_save_license']) && check_admin_referer('nexis_license_nonce')) {
        $settings = get_option('nexis_ai_settings', []);
        $settings['license_key'] = sanitize_text_field($_POST['license_key']);
        update_option('nexis_ai_settings', $settings);
        echo '<div class="updated notice is-dismissible"><p>اطلاعات لایسنس بررسی و ذخیره شد.</p></div>';
    }

    $settings = get_option('nexis_ai_settings', []);
    $lic = nexis_ai_get_license_status();
    $site_host = parse_url(home_url(), PHP_URL_HOST);
    ?>
    <div class="wrap" style="direction: rtl; text-align: right; max-width: 850px;">
        <h1 style="margin-bottom: 20px;">مدیریت لایسنس تجاری نکسیس (Nexis License)</h1>
        <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 10px; padding: 25px; margin-bottom: 25px;">
            <h3>دامنه فعال: <code><?php echo esc_html($site_host); ?></code></h3>
            <p>وضعیت لایسنس: <strong><?php echo esc_html($lic['message']); ?></strong></p>
            <p>تعداد ریکوئست مصرف‌شده: <strong><?php echo intval($lic['count']); ?></strong></p>
            <form method="post" action="">
                <?php wp_nonce_field('nexis_license_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th>کلید لایسنس:</th>
                        <td><input type="text" name="license_key" value="<?php echo esc_attr(!empty($settings['license_key']) ? $settings['license_key'] : ''); ?>" class="large-text" style="direction: ltr; font-family: monospace;"></td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="nexis_save_license" class="button button-primary button-hero" value="ثبت و اعتبارسنجی لایسنس">
                </p>
            </form>
        </div>
    </div>
    <?php
}

function nexis_ai_render_logs_page() {
    global $wpdb;
    $table_logs = $wpdb->prefix . 'nexis_ai_logs';
    $logs = $wpdb->get_results("SELECT * FROM $table_logs ORDER BY id DESC LIMIT 50", ARRAY_A);
    ?>
    <div class="wrap" style="direction: rtl; text-align: right; max-width: 1050px;">
        <h1>تاریخچه مکالمات نکسیس</h1>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr><th>ردیف</th><th>پرسش</th><th>پاسخ</th><th>مدل</th><th>زمان</th></tr></thead>
            <tbody>
                <?php foreach ($logs as $l): ?>
                    <tr>
                        <td><?php echo esc_html($l['id']); ?></td>
                        <td><strong><?php echo esc_html($l['user_query']); ?></strong></td>
                        <td><?php echo nl2br(esc_html($l['ai_response'])); ?></td>
                        <td><code><?php echo esc_html($l['model_used']); ?></code></td>
                        <td><small><?php echo esc_html($l['created_at']); ?></small></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
