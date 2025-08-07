<?php
/**
 * 키워드 처리 및 상품 데이터 분석 시스템
 * 버전: v4.1 (2025-08-06)
 * 
 * 주요 기능:
 * 1. 키워드별 상품 데이터 수집 및 분석
 * 2. AliExpress 상품 정보 처리
 * 3. 큐 시스템 연동을 통한 배치 처리
 * 4. WordPress 포스팅 자동화 지원
 */

// 오류 보고 설정
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/var/www/novacents/tools/php_error_log.txt');

// WordPress 설정 로드
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-config.php');
require_once('/var/www/novacents/tools/queue_utils.php');

// 세션 시작 (필요한 경우)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 디버그 로그 함수
function debug_log($message) {
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] [KEYWORD_PROCESSOR] $message" . PHP_EOL;
    file_put_contents('/var/www/novacents/tools/debug_log.txt', $log_message, FILE_APPEND | LOCK_EX);
}

// 1. 키워드 데이터 검증 함수
function validate_keyword_data($keyword_data) {
    debug_log("validate_keyword_data: Starting validation");
    
    if (!is_array($keyword_data)) {
        debug_log("validate_keyword_data: Invalid data type");
        return false;
    }
    
    $required_fields = ['keyword', 'category_id'];
    
    foreach ($required_fields as $field) {
        if (!isset($keyword_data[$field]) || empty($keyword_data[$field])) {
            debug_log("validate_keyword_data: Missing required field: $field");
            return false;
        }
    }
    
    debug_log("validate_keyword_data: Validation successful");
    return true;
}

// 2. 상품 데이터 정제 함수
function sanitize_product_data($product_data) {
    debug_log("sanitize_product_data: Starting sanitization");
    
    if (!is_array($product_data)) {
        debug_log("sanitize_product_data: Invalid product data");
        return false;
    }
    
    $sanitized = [];
    
    // 필수 필드들
    $fields = [
        'title' => 'string',
        'price' => 'string',
        'original_price' => 'string',
        'image_url' => 'string',
        'product_url' => 'string',
        'rating' => 'string',
        'orders' => 'string',
        'shipping' => 'string'
    ];
    
    foreach ($fields as $field => $type) {
        if (isset($product_data[$field])) {
            if ($type === 'string') {
                $sanitized[$field] = sanitize_text_field($product_data[$field]);
            } else {
                $sanitized[$field] = $product_data[$field];
            }
        } else {
            $sanitized[$field] = '';
        }
    }
    
    debug_log("sanitize_product_data: Sanitization complete");
    return $sanitized;
}

// 3. AliExpress 상품 URL 검증 함수
function validate_aliexpress_url($url) {
    debug_log("validate_aliexpress_url: Validating URL: $url");
    
    $valid_domains = [
        'aliexpress.com',
        'aliexpress.us',
        'ko.aliexpress.com',
        's.click.aliexpress.com'
    ];
    
    $parsed_url = parse_url($url);
    
    if (!$parsed_url || !isset($parsed_url['host'])) {
        debug_log("validate_aliexpress_url: Invalid URL format");
        return false;
    }
    
    $host = strtolower($parsed_url['host']);
    
    // www. 제거
    if (strpos($host, 'www.') === 0) {
        $host = substr($host, 4);
    }
    
    foreach ($valid_domains as $domain) {
        if ($host === $domain || strpos($host, '.' . $domain) !== false) {
            debug_log("validate_aliexpress_url: Valid AliExpress URL");
            return true;
        }
    }
    
    debug_log("validate_aliexpress_url: Invalid domain: $host");
    return false;
}

// 4. 키워드별 상품 수집 함수
function collect_products_by_keyword($keyword, $limit = 10) {
    debug_log("collect_products_by_keyword: Collecting products for keyword: $keyword");
    
    $products = [];
    
    // 여기서 실제 상품 수집 로직을 구현
    // 현재는 더미 데이터 반환
    for ($i = 1; $i <= $limit; $i++) {
        $products[] = [
            'title' => "Sample Product $i for $keyword",
            'price' => "$ " . rand(10, 100),
            'original_price' => "$ " . rand(50, 150),
            'image_url' => "https://example.com/image$i.jpg",
            'product_url' => "https://aliexpress.com/item/$i.html",
            'rating' => rand(40, 50) / 10,
            'orders' => rand(100, 10000),
            'shipping' => "Free Shipping"
        ];
    }
    
    debug_log("collect_products_by_keyword: Collected " . count($products) . " products");
    return $products;
}

// 5. 상품 데이터 분석 함수
function analyze_product_data($products) {
    debug_log("analyze_product_data: Analyzing " . count($products) . " products");
    
    if (empty($products)) {
        return [
            'total_count' => 0,
            'avg_price' => 0,
            'price_range' => ['min' => 0, 'max' => 0],
            'avg_rating' => 0,
            'total_orders' => 0
        ];
    }
    
    $prices = [];
    $ratings = [];
    $orders = [];
    
    foreach ($products as $product) {
        // 가격 추출
        if (isset($product['price'])) {
            $price = (float) preg_replace('/[^0-9.]/', '', $product['price']);
            if ($price > 0) {
                $prices[] = $price;
            }
        }
        
        // 평점 추출
        if (isset($product['rating'])) {
            $rating = (float) $product['rating'];
            if ($rating > 0) {
                $ratings[] = $rating;
            }
        }
        
        // 주문 수 추출
        if (isset($product['orders'])) {
            $order_count = (int) preg_replace('/[^0-9]/', '', $product['orders']);
            if ($order_count > 0) {
                $orders[] = $order_count;
            }
        }
    }
    
    $analysis = [
        'total_count' => count($products),
        'avg_price' => !empty($prices) ? round(array_sum($prices) / count($prices), 2) : 0,
        'price_range' => [
            'min' => !empty($prices) ? min($prices) : 0,
            'max' => !empty($prices) ? max($prices) : 0
        ],
        'avg_rating' => !empty($ratings) ? round(array_sum($ratings) / count($ratings), 1) : 0,
        'total_orders' => !empty($orders) ? array_sum($orders) : 0
    ];
    
    debug_log("analyze_product_data: Analysis complete");
    return $analysis;
}

// 6. 키워드 관련성 점수 계산 함수
function calculate_keyword_relevance($keyword, $product_title) {
    debug_log("calculate_keyword_relevance: Calculating relevance for: $keyword");
    
    $keyword = strtolower(trim($keyword));
    $title = strtolower(trim($product_title));
    
    $score = 0;
    
    // 정확한 매치
    if (strpos($title, $keyword) !== false) {
        $score += 100;
    }
    
    // 키워드를 단어로 분할하여 부분 매치 확인
    $keyword_words = explode(' ', $keyword);
    $title_words = explode(' ', $title);
    
    foreach ($keyword_words as $word) {
        $word = trim($word);
        if (strlen($word) > 2) { // 2글자 이상만 체크
            foreach ($title_words as $title_word) {
                if (strpos($title_word, $word) !== false) {
                    $score += 10;
                }
            }
        }
    }
    
    debug_log("calculate_keyword_relevance: Relevance score: $score");
    return $score;
}

// 7. 중복 상품 제거 함수
function remove_duplicate_products($products) {
    debug_log("remove_duplicate_products: Removing duplicates from " . count($products) . " products");
    
    $unique_products = [];
    $seen_urls = [];
    $seen_titles = [];
    
    foreach ($products as $product) {
        $url = isset($product['product_url']) ? $product['product_url'] : '';
        $title = isset($product['title']) ? strtolower(trim($product['title'])) : '';
        
        // URL 기반 중복 체크
        if (!empty($url) && !in_array($url, $seen_urls)) {
            $seen_urls[] = $url;
            $unique_products[] = $product;
        }
        // 제목 기반 중복 체크 (URL이 없는 경우)
        elseif (empty($url) && !empty($title) && !in_array($title, $seen_titles)) {
            $seen_titles[] = $title;
            $unique_products[] = $product;
        }
    }
    
    debug_log("remove_duplicate_products: Removed " . (count($products) - count($unique_products)) . " duplicates");
    return $unique_products;
}

// 8. 상품 정렬 함수
function sort_products_by_criteria($products, $criteria = 'relevance') {
    debug_log("sort_products_by_criteria: Sorting by $criteria");
    
    switch ($criteria) {
        case 'price_low':
            usort($products, function($a, $b) {
                $price_a = (float) preg_replace('/[^0-9.]/', '', $a['price'] ?? '0');
                $price_b = (float) preg_replace('/[^0-9.]/', '', $b['price'] ?? '0');
                return $price_a <=> $price_b;
            });
            break;
            
        case 'price_high':
            usort($products, function($a, $b) {
                $price_a = (float) preg_replace('/[^0-9.]/', '', $a['price'] ?? '0');
                $price_b = (float) preg_replace('/[^0-9.]/', '', $b['price'] ?? '0');
                return $price_b <=> $price_a;
            });
            break;
            
        case 'rating':
            usort($products, function($a, $b) {
                $rating_a = (float) ($a['rating'] ?? 0);
                $rating_b = (float) ($b['rating'] ?? 0);
                return $rating_b <=> $rating_a;
            });
            break;
            
        case 'orders':
            usort($products, function($a, $b) {
                $orders_a = (int) preg_replace('/[^0-9]/', '', $a['orders'] ?? '0');
                $orders_b = (int) preg_replace('/[^0-9]/', '', $b['orders'] ?? '0');
                return $orders_b <=> $orders_a;
            });
            break;
            
        case 'relevance':
        default:
            // 관련성 점수로 정렬 (이미 계산되어 있다고 가정)
            if (isset($products[0]['relevance_score'])) {
                usort($products, function($a, $b) {
                    return ($b['relevance_score'] ?? 0) <=> ($a['relevance_score'] ?? 0);
                });
            }
            break;
    }
    
    debug_log("sort_products_by_criteria: Sorting complete");
    return $products;
}

// 9. 상품 필터링 함수
function filter_products($products, $filters = []) {
    debug_log("filter_products: Applying filters to " . count($products) . " products");
    
    $filtered = $products;
    
    // 가격 필터
    if (isset($filters['min_price']) && $filters['min_price'] > 0) {
        $filtered = array_filter($filtered, function($product) use ($filters) {
            $price = (float) preg_replace('/[^0-9.]/', '', $product['price'] ?? '0');
            return $price >= $filters['min_price'];
        });
    }
    
    if (isset($filters['max_price']) && $filters['max_price'] > 0) {
        $filtered = array_filter($filtered, function($product) use ($filters) {
            $price = (float) preg_replace('/[^0-9.]/', '', $product['price'] ?? '0');
            return $price <= $filters['max_price'];
        });
    }
    
    // 평점 필터
    if (isset($filters['min_rating']) && $filters['min_rating'] > 0) {
        $filtered = array_filter($filtered, function($product) use ($filters) {
            $rating = (float) ($product['rating'] ?? 0);
            return $rating >= $filters['min_rating'];
        });
    }
    
    // 주문 수 필터
    if (isset($filters['min_orders']) && $filters['min_orders'] > 0) {
        $filtered = array_filter($filtered, function($product) use ($filters) {
            $orders = (int) preg_replace('/[^0-9]/', '', $product['orders'] ?? '0');
            return $orders >= $filters['min_orders'];
        });
    }
    
    // 키워드 필터
    if (isset($filters['exclude_keywords']) && !empty($filters['exclude_keywords'])) {
        $exclude_words = array_map('strtolower', $filters['exclude_keywords']);
        $filtered = array_filter($filtered, function($product) use ($exclude_words) {
            $title = strtolower($product['title'] ?? '');
            foreach ($exclude_words as $word) {
                if (strpos($title, $word) !== false) {
                    return false;
                }
            }
            return true;
        });
    }
    
    $filtered = array_values($filtered); // 배열 인덱스 재정렬
    
    debug_log("filter_products: Filtered to " . count($filtered) . " products");
    return $filtered;
}

// 10. 상품 이미지 URL 검증 및 최적화 함수
function optimize_product_images($products) {
    debug_log("optimize_product_images: Optimizing images for " . count($products) . " products");
    
    foreach ($products as &$product) {
        if (isset($product['image_url'])) {
            $image_url = $product['image_url'];
            
            // 이미지 URL 검증
            if (filter_var($image_url, FILTER_VALIDATE_URL)) {
                // AliExpress 이미지 최적화
                if (strpos($image_url, 'aliexpress') !== false || strpos($image_url, 'aliimg') !== false) {
                    // 고해상도 이미지로 변경
                    $image_url = str_replace('_50x50.jpg', '_300x300.jpg', $image_url);
                    $image_url = str_replace('_100x100.jpg', '_300x300.jpg', $image_url);
                    
                    // 웹p 형식 제거 (호환성을 위해)
                    $image_url = str_replace('.webp', '.jpg', $image_url);
                    
                    $product['image_url'] = $image_url;
                }
            } else {
                // 잘못된 이미지 URL인 경우 기본 이미지로 대체
                $product['image_url'] = 'https://via.placeholder.com/300x300?text=No+Image';
            }
        }
    }
    
    debug_log("optimize_product_images: Image optimization complete");
    return $products;
}

// 11. 상품 데이터 집계 함수
function aggregate_keyword_data($keywords_data) {
    debug_log("aggregate_keyword_data: Aggregating data for " . count($keywords_data) . " keywords");
    
    $aggregated = [
        'total_keywords' => count($keywords_data),
        'total_products' => 0,
        'categories' => [],
        'price_ranges' => [],
        'avg_ratings' => [],
        'top_products' => []
    ];
    
    foreach ($keywords_data as $keyword_data) {
        // 상품 수 집계
        if (isset($keyword_data['products_data'])) {
            $aggregated['total_products'] += count($keyword_data['products_data']);
        }
        
        // 카테고리별 집계
        if (isset($keyword_data['category_id'])) {
            $category = $keyword_data['category_id'];
            if (!isset($aggregated['categories'][$category])) {
                $aggregated['categories'][$category] = 0;
            }
            $aggregated['categories'][$category]++;
        }
        
        // 상품 분석 데이터 집계
        if (isset($keyword_data['analysis'])) {
            $analysis = $keyword_data['analysis'];
            
            if (isset($analysis['price_range'])) {
                $aggregated['price_ranges'][] = $analysis['price_range'];
            }
            
            if (isset($analysis['avg_rating'])) {
                $aggregated['avg_ratings'][] = $analysis['avg_rating'];
            }
        }
    }
    
    // 전체 평균 계산
    if (!empty($aggregated['avg_ratings'])) {
        $aggregated['overall_avg_rating'] = array_sum($aggregated['avg_ratings']) / count($aggregated['avg_ratings']);
    }
    
    debug_log("aggregate_keyword_data: Aggregation complete");
    return $aggregated;
}

// 12. 데이터 내보내기 함수
function export_keyword_data($data, $format = 'json') {
    debug_log("export_keyword_data: Exporting data in $format format");
    
    switch ($format) {
        case 'json':
            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            
        case 'csv':
            $csv = '';
            $header = ['keyword', 'category', 'products_count', 'avg_price', 'avg_rating'];
            $csv .= implode(',', $header) . "\n";
            
            if (isset($data['keywords']) && is_array($data['keywords'])) {
                foreach ($data['keywords'] as $keyword_data) {
                    $row = [
                        $keyword_data['keyword'] ?? '',
                        get_category_name($keyword_data['category_id'] ?? ''),
                        isset($keyword_data['products_data']) ? count($keyword_data['products_data']) : 0,
                        $keyword_data['analysis']['avg_price'] ?? 0,
                        $keyword_data['analysis']['avg_rating'] ?? 0
                    ];
                    $csv .= implode(',', $row) . "\n";
                }
            }
            return $csv;
            
        case 'xml':
            $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
            $xml .= '<keyword_data>' . "\n";
            
            if (isset($data['keywords']) && is_array($data['keywords'])) {
                foreach ($data['keywords'] as $keyword_data) {
                    $xml .= '  <keyword>' . "\n";
                    $xml .= '    <name>' . htmlspecialchars($keyword_data['keyword'] ?? '') . '</name>' . "\n";
                    $xml .= '    <category>' . htmlspecialchars(get_category_name($keyword_data['category_id'] ?? '')) . '</category>' . "\n";
                    $xml .= '    <products_count>' . (isset($keyword_data['products_data']) ? count($keyword_data['products_data']) : 0) . '</products_count>' . "\n";
                    $xml .= '  </keyword>' . "\n";
                }
            }
            
            $xml .= '</keyword_data>';
            return $xml;
            
        default:
            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}

// 13. 캐시 관리 함수
function manage_keyword_cache($action, $key = null, $data = null, $expiry = 3600) {
    debug_log("manage_keyword_cache: $action operation");
    
    $cache_dir = '/var/www/novacents/tools/cache/';
    
    // 캐시 디렉토리 생성
    if (!file_exists($cache_dir)) {
        mkdir($cache_dir, 0755, true);
    }
    
    switch ($action) {
        case 'get':
            if ($key) {
                $cache_file = $cache_dir . md5($key) . '.cache';
                if (file_exists($cache_file)) {
                    $cache_data = json_decode(file_get_contents($cache_file), true);
                    if ($cache_data && isset($cache_data['expiry']) && $cache_data['expiry'] > time()) {
                        debug_log("manage_keyword_cache: Cache hit for key: $key");
                        return $cache_data['data'];
                    } else {
                        unlink($cache_file);
                    }
                }
            }
            return null;
            
        case 'set':
            if ($key && $data) {
                $cache_file = $cache_dir . md5($key) . '.cache';
                $cache_content = [
                    'data' => $data,
                    'expiry' => time() + $expiry,
                    'created' => time()
                ];
                file_put_contents($cache_file, json_encode($cache_content));
                debug_log("manage_keyword_cache: Cache set for key: $key");
                return true;
            }
            return false;
            
        case 'clear':
            $files = glob($cache_dir . '*.cache');
            foreach ($files as $file) {
                unlink($file);
            }
            debug_log("manage_keyword_cache: All cache cleared");
            return true;
            
        case 'cleanup':
            $files = glob($cache_dir . '*.cache');
            $cleaned = 0;
            foreach ($files as $file) {
                $cache_data = json_decode(file_get_contents($file), true);
                if (!$cache_data || !isset($cache_data['expiry']) || $cache_data['expiry'] <= time()) {
                    unlink($file);
                    $cleaned++;
                }
            }
            debug_log("manage_keyword_cache: Cleaned $cleaned expired cache files");
            return $cleaned;
            
        default:
            return false;
    }
}

// 14. 오류 처리 및 복구 함수
function handle_processing_error($error_message, $context = []) {
    debug_log("handle_processing_error: $error_message");
    
    $error_data = [
        'timestamp' => date('Y-m-d H:i:s'),
        'error' => $error_message,
        'context' => $context,
        'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5)
    ];
    
    // 오류 로그 파일에 저장
    $error_log_file = '/var/www/novacents/tools/error_log.json';
    $existing_errors = [];
    
    if (file_exists($error_log_file)) {
        $existing_errors = json_decode(file_get_contents($error_log_file), true) ?: [];
    }
    
    $existing_errors[] = $error_data;
    
    // 최근 100개 오류만 보관
    if (count($existing_errors) > 100) {
        $existing_errors = array_slice($existing_errors, -100);
    }
    
    file_put_contents($error_log_file, json_encode($existing_errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    return false;
}

// 15. 카테고리명 가져오기 함수
function get_category_name($category_id) {
    $categories = [
        '354' => 'Today\'s Pick',
        '355' => '기발한 잡화점',
        '356' => '스마트 리빙',
        '12' => '우리잇템'
    ];
    
    return $categories[$category_id] ?? '알 수 없는 카테고리';
}

// 16. 프롬프트 타입 이름 가져오기 함수
function get_prompt_type_name($prompt_type) {
    $types = [
        'essential_items' => '필수템형',
        'friend_review' => '친구 추천형',
        'professional_analysis' => '전문 분석형',
        'amazing_discovery' => '놀라움 발견형'
    ];
    
    return $types[$prompt_type] ?? '기본형';
}

// 17. 큐에 데이터 추가 함수 (분할 시스템 사용)
function add_to_queue($queue_data) {
    debug_log("add_to_queue: Adding item to split queue system");
    
    try {
        $queue_id = save_queue_split($queue_data);
        
        if ($queue_id) {
            debug_log("add_to_queue: Successfully added to queue with ID: {$queue_id}");
            return $queue_id;
        } else {
            debug_log("add_to_queue: Failed to add to queue");
            return false;
        }
    } catch (Exception $e) {
        debug_log("add_to_queue: Exception occurred: " . $e->getMessage());
        return false;
    }
}

// 18. 큐 통계 가져오기 함수
function get_queue_stats() {
    debug_log("get_queue_stats: Retrieving queue statistics from split system");
    
    try {
        $stats = get_queue_stats_split();
        debug_log("get_queue_stats: Retrieved stats - Total: " . ($stats['total'] ?? 0));
        return $stats;
    } catch (Exception $e) {
        debug_log("get_queue_stats: Exception occurred: " . $e->getMessage());
        return ['total' => 0, 'pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
    }
}

// 19. 즉시 발행 처리 함수 (affiliate_editor.php에서 호출)
function process_immediate_publish($queue_data) {
    debug_log("process_immediate_publish: Processing immediate publish request");
    
    try {
        // WordPress 포스트 생성을 위한 데이터 준비
        $post_data = [
            'post_title' => $queue_data['title'] ?? 'New Product Post',
            'post_content' => generate_post_content($queue_data),
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_category' => [$queue_data['category_id'] ?? 12]
        ];
        
        // WordPress 포스트 생성
        $post_id = wp_insert_post($post_data);
        
        if ($post_id && !is_wp_error($post_id)) {
            debug_log("process_immediate_publish: Post created successfully with ID: $post_id");
            
            // 메타 데이터 추가
            if (isset($queue_data['keywords']) && is_array($queue_data['keywords'])) {
                update_post_meta($post_id, 'affiliate_keywords', json_encode($queue_data['keywords']));
            }
            
            if (isset($queue_data['prompt_type'])) {
                update_post_meta($post_id, 'prompt_type', $queue_data['prompt_type']);
            }
            
            return [
                'success' => true,
                'post_id' => $post_id,
                'message' => '포스트가 성공적으로 발행되었습니다.'
            ];
        } else {
            $error_message = is_wp_error($post_id) ? $post_id->get_error_message() : 'Unknown error';
            debug_log("process_immediate_publish: Failed to create post: $error_message");
            
            return [
                'success' => false,
                'message' => "포스트 생성 실패: $error_message"
            ];
        }
    } catch (Exception $e) {
        debug_log("process_immediate_publish: Exception occurred: " . $e->getMessage());
        return [
            'success' => false,
            'message' => "처리 중 오류 발생: " . $e->getMessage()
        ];
    }
}

// 20. 포스트 내용 생성 함수
function generate_post_content($queue_data) {
    debug_log("generate_post_content: Generating post content");
    
    $content = '';
    
    // 제목
    if (isset($queue_data['title'])) {
        $content .= "<h2>" . esc_html($queue_data['title']) . "</h2>\n\n";
    }
    
    // 키워드별 상품 정보
    if (isset($queue_data['keywords']) && is_array($queue_data['keywords'])) {
        foreach ($queue_data['keywords'] as $keyword_data) {
            if (isset($keyword_data['keyword'])) {
                $content .= "<h3>🔍 " . esc_html($keyword_data['keyword']) . "</h3>\n";
            }
            
            // 상품 목록
            if (isset($keyword_data['products_data']) && is_array($keyword_data['products_data'])) {
                $content .= "<div class='products-grid'>\n";
                
                foreach ($keyword_data['products_data'] as $product) {
                    $content .= "<div class='product-item'>\n";
                    
                    // 상품 이미지
                    if (isset($product['image_url'])) {
                        $content .= "<img src='" . esc_url($product['image_url']) . "' alt='" . esc_attr($product['title'] ?? '') . "' style='max-width: 200px;'>\n";
                    }
                    
                    // 상품 제목
                    if (isset($product['title'])) {
                        $title = esc_html($product['title']);
                        if (isset($product['product_url'])) {
                            $content .= "<h4><a href='" . esc_url($product['product_url']) . "' target='_blank'>$title</a></h4>\n";
                        } else {
                            $content .= "<h4>$title</h4>\n";
                        }
                    }
                    
                    // 가격 정보
                    if (isset($product['price'])) {
                        $content .= "<p class='price'>💰 " . esc_html($product['price']);
                        if (isset($product['original_price']) && $product['original_price'] !== $product['price']) {
                            $content .= " <s>" . esc_html($product['original_price']) . "</s>";
                        }
                        $content .= "</p>\n";
                    }
                    
                    // 평점 및 주문 수
                    if (isset($product['rating']) || isset($product['orders'])) {
                        $content .= "<p class='rating-orders'>";
                        if (isset($product['rating'])) {
                            $content .= "⭐ " . esc_html($product['rating']);
                        }
                        if (isset($product['orders'])) {
                            $content .= " 📦 " . esc_html($product['orders']) . " orders";
                        }
                        $content .= "</p>\n";
                    }
                    
                    // 배송 정보
                    if (isset($product['shipping'])) {
                        $content .= "<p class='shipping'>🚚 " . esc_html($product['shipping']) . "</p>\n";
                    }
                    
                    $content .= "</div>\n\n";
                }
                
                $content .= "</div>\n\n";
            }
        }
    }
    
    // 추가 CSS 스타일
    $content .= "<style>\n";
    $content .= ".products-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin: 20px 0; }\n";
    $content .= ".product-item { border: 1px solid #ddd; padding: 15px; border-radius: 8px; }\n";
    $content .= ".price { font-weight: bold; color: #e74c3c; }\n";
    $content .= ".rating-orders { color: #666; font-size: 0.9em; }\n";
    $content .= ".shipping { color: #27ae60; font-size: 0.9em; }\n";
    $content .= "</style>\n";
    
    debug_log("generate_post_content: Content generation complete");
    return $content;
}

// AJAX 요청 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'save_to_queue':
                debug_log("main_process: Processing save_to_queue request");
                
                $queue_data = json_decode($_POST['queue_data'] ?? '{}', true);
                
                if (!$queue_data) {
                    throw new Exception('Invalid queue data');
                }
                
                $queue_id = save_queue_split($queue_data);
                
                if ($queue_id) {
                    debug_log("main_process: Successfully saved to queue with ID: {$queue_id}");
                    echo json_encode([
                        'success' => true,
                        'message' => '큐에 성공적으로 저장되었습니다.',
                        'queue_id' => $queue_id,
                        'data' => $queue_data
                    ]);
                } else {
                    debug_log("main_process: Failed to add item to split queue system. Check save_queue_split function.");
                    throw new Exception('큐 저장 실패');
                }
                break;
                
            case 'immediate_publish':
                debug_log("main_process: Processing immediate_publish request");
                
                $queue_data = json_decode($_POST['queue_data'] ?? '{}', true);
                
                if (!$queue_data) {
                    throw new Exception('Invalid queue data');
                }
                
                $result = process_immediate_publish($queue_data);
                echo json_encode($result);
                break;
                
            case 'get_queue_stats':
                debug_log("main_process: Processing get_queue_stats request");
                
                $stats = get_queue_stats();
                echo json_encode([
                    'success' => true,
                    'stats' => $stats
                ]);
                break;
                
            default:
                throw new Exception('Invalid action: ' . $action);
        }
    } catch (Exception $e) {
        debug_log("main_process: Exception caught: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    
    exit;
}

debug_log("keyword_processor.php: Script loaded successfully");
?>