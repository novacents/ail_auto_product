<?php
/**
 * 어필리에이트 에디터 키워드 프로세서 - 고급 큐 시스템 적용 버전
 * 버전: v3.0 (파일 분할 시스템 + 상품 분석 데이터)
 */

// WordPress 환경 설정
if (!defined('ABSPATH')) {
    require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-config.php');
}

// 큐 관리 함수 포함
require_once __DIR__ . '/queue_utils.php';

// 권한 확인
if (!current_user_can('manage_options')) {
    wp_die('접근 권한이 없습니다.');
}

// 필요한 상수 정의
define('TEMP_DIR', '/var/www/novacents/tools/temp');
define('MAX_EXECUTION_TIME', 300);

// 시간 제한 설정
set_time_limit(MAX_EXECUTION_TIME);

// 🔧 1. 디버그 로그 함수
function debug_log($message) {
    $timestamp = date('Y-m-d H:i:s');
    $log_file = '/var/www/novacents/tools/keyword_processor.log';
    $log_entry = "[{$timestamp}] {$message}" . PHP_EOL;
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

// 🔧 2. 메인 로그 함수
function main_log($message) {
    $timestamp = date('Y-m-d H:i:s');
    $log_file = '/var/www/novacents/tools/main_process.log';
    $log_entry = "[{$timestamp}] {$message}" . PHP_EOL;
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

// 🔧 3. JSON 응답 함수
function send_json_response($success, $data = []) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 🔧 4. 에디터로 리다이렉트 함수
function redirect_to_editor($success, $data = []) {
    $query_params = http_build_query(array_merge(['success' => $success ? 1 : 0], $data));
    $redirect_url = '/tools/affiliate_editor.php?' . $query_params;
    header('Location: ' . $redirect_url);
    exit;
}

// 🔧 5. 텔레그램 알림 함수
function send_telegram_notification($message, $is_error = false) {
    $config_file = '/var/www/novacents/tools/telegram_config.json';
    
    if (!file_exists($config_file)) {
        debug_log("Telegram config file not found: {$config_file}");
        return false;
    }
    
    try {
        $config = json_decode(file_get_contents($config_file), true);
        if (!$config || !isset($config['bot_token']) || !isset($config['chat_id'])) {
            debug_log("Invalid telegram config format");
            return false;
        }
        
        $bot_token = $config['bot_token'];
        $chat_id = $config['chat_id'];
        
        // 에러인 경우 이모지 추가
        $formatted_message = $is_error ? "🚨 " . $message : $message;
        
        $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
        $data = [
            'chat_id' => $chat_id,
            'text' => $formatted_message,
            'parse_mode' => 'HTML'
        ];
        
        $options = [
            'http' => [
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query($data),
                'timeout' => 10
            ]
        ];
        
        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);
        
        if ($result === false) {
            debug_log("Failed to send telegram notification");
            return false;
        }
        
        debug_log("Telegram notification sent successfully");
        return true;
        
    } catch (Exception $e) {
        debug_log("Telegram notification error: " . $e->getMessage());
        return false;
    }
}

// 🔧 6. 안전한 배열 카운트 함수
function safe_count($var) {
    if (is_array($var) || is_countable($var)) {
        return count($var);
    }
    return 0;
}

// 🔧 7. 프롬프트 타입 이름 매핑 함수
function get_prompt_type_name($prompt_type) {
    $prompt_types = [
        'essential_items' => '필수템형 🎯',
        'friend_review' => '친구 추천형 👫',
        'professional_analysis' => '전문 분석형 📊',
        'amazing_discovery' => '놀라움 발견형 ✨'
    ];
    return $prompt_types[$prompt_type] ?? '기본형';
}

// 🔧 8. 카테고리 이름 매핑 함수
function get_category_name($category_id) {
    $categories = [
        '354' => 'Today\'s Pick',
        '355' => '기발한 잡화점',
        '356' => '스마트 리빙',
        '12' => '우리잇템'
    ];
    return $categories[$category_id] ?? '알 수 없는 카테고리';
}

// 🔧 9. 큐 추가 함수 (분할 시스템 사용)
function add_to_queue($queue_data) {
    debug_log("add_to_queue: Adding to split queue system.");
    
    // 분할 시스템 함수 호출
    $result = add_queue_split($queue_data);
    
    if ($result) {
        debug_log("add_to_queue: Successfully added to split system with ID: " . $result);
        return true;
    } else {
        debug_log("add_to_queue: Failed to add to split system.");
        return false;
    }
}

// 🔧 10. 큐 통계 조회 함수 (분할 시스템 사용)
function get_queue_stats() {
    return get_queue_stats_split();
}

// 🔧 11. 입력 데이터 유효성 검사
function validate_input_data($data) {
    $required_fields = ['title', 'category', 'prompt_type', 'keywords'];
    
    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            return "필수 필드가 누락되었습니다: {$field}";
        }
    }
    
    // 키워드 배열 검사
    if (!is_array($data['keywords'])) {
        return "키워드 데이터가 배열 형태가 아닙니다.";
    }
    
    return null; // 유효성 검사 통과
}

// 🔧 12. 키워드 데이터 정리 함수
function clean_keywords_data($keywords) {
    debug_log("clean_keywords_data: Starting keyword data cleaning process.");
    
    $cleaned = [];
    foreach ($keywords as $index => $keyword) {
        if (!is_array($keyword)) {
            debug_log("clean_keywords_data: Invalid keyword data at index {$index}, skipping.");
            continue;
        }
        
        $clean_keyword = [
            'name' => trim($keyword['name'] ?? ''),
            'aliexpress' => [],
            'coupang' => [],
            'products_data' => [] // 🔧 상품 분석 데이터 필드 추가
        ];
        
        // AliExpress 링크 정리
        if (isset($keyword['aliexpress']) && is_array($keyword['aliexpress'])) {
            foreach ($keyword['aliexpress'] as $link) {
                if (is_string($link) && !empty(trim($link))) {
                    $clean_keyword['aliexpress'][] = trim($link);
                }
            }
        }
        
        // 쿠팡 링크 정리
        if (isset($keyword['coupang']) && is_array($keyword['coupang'])) {
            foreach ($keyword['coupang'] as $link) {
                if (is_string($link) && !empty(trim($link))) {
                    $clean_keyword['coupang'][] = trim($link);
                }
            }
        }
        
        // 🔧 상품 분석 데이터 정리
        if (isset($keyword['products_data']) && is_array($keyword['products_data'])) {
            foreach ($keyword['products_data'] as $product_data) {
                if (is_array($product_data) && !empty($product_data)) {
                    $clean_keyword['products_data'][] = $product_data;
                }
            }
        }
        
        // 빈 키워드는 제외
        if (!empty($clean_keyword['name'])) {
            $cleaned[] = $clean_keyword;
        }
    }
    
    debug_log("clean_keywords_data: Cleaned " . count($cleaned) . " keywords from " . count($keywords) . " original items.");
    return $cleaned;
}

// 🔧 13. 사용자 세부사항 정리 함수
function clean_user_details($user_details) {
    if (!is_array($user_details)) {
        return [];
    }
    
    $cleaned = [];
    foreach ($user_details as $key => $value) {
        if (!empty(trim($value))) {
            $cleaned[$key] = trim($value);
        }
    }
    
    return $cleaned;
}

// 🔧 14. 큐 ID 생성 함수
function generate_queue_id() {
    return date('YmdHis') . '_' . mt_rand(10000, 99999);
}

// 🔧 15. 메인 프로세스 함수
function main_process($input_data) {
    debug_log("main_process: Starting main processing function.");
    debug_log("main_process: Input data received - Title: " . ($input_data['title'] ?? 'N/A'));
    
    // 입력 데이터 유효성 검사
    $validation_error = validate_input_data($input_data);
    if ($validation_error) {
        debug_log("main_process: Validation failed - " . $validation_error);
        main_log("Validation failed: " . $validation_error);
        redirect_to_editor(false, ['error' => $validation_error]);
    }
    
    debug_log("main_process: Input validation passed.");
    
    // 키워드 데이터 정리
    $cleaned_keywords = clean_keywords_data($input_data['keywords']);
    if (empty($cleaned_keywords)) {
        debug_log("main_process: No valid keywords found after cleaning.");
        main_log("No valid keywords found after cleaning.");
        redirect_to_editor(false, ['error' => '유효한 키워드가 없습니다.']);
    }
    
    debug_log("main_process: Keywords cleaned successfully. Count: " . count($cleaned_keywords));
    
    // 사용자 세부사항 정리
    $cleaned_user_details = clean_user_details($input_data['user_details'] ?? []);
    debug_log("main_process: User details cleaned. Count: " . count($cleaned_user_details));
    
    // 큐 데이터 구조 생성
    $queue_data = [
        'queue_id' => generate_queue_id(),
        'title' => trim($input_data['title']),
        'category_id' => $input_data['category'],
        'category_name' => get_category_name($input_data['category']),
        'prompt_type' => $input_data['prompt_type'],
        'prompt_type_name' => get_prompt_type_name($input_data['prompt_type']),
        'keywords' => $cleaned_keywords,
        'user_details' => $cleaned_user_details,
        'has_user_details' => !empty($cleaned_user_details),
        'thumbnail_url' => trim($input_data['thumbnail_url'] ?? ''),
        'has_thumbnail_url' => !empty(trim($input_data['thumbnail_url'] ?? '')),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        'status' => 'pending',
        'attempts' => 0,
        'priority' => 1,
        'conversion_status' => [
            'total_keywords' => count($cleaned_keywords),
            'coupang_total' => 0,
            'aliexpress_total' => 0
        ]
    ];
    
    // 링크 수 계산 및 상품 데이터 확인
    $coupang_total = 0;
    $aliexpress_total = 0;
    $total_product_data = 0;
    
    foreach ($cleaned_keywords as $keyword_item) {
        $coupang_total += safe_count($keyword_item['coupang'] ?? []);
        $aliexpress_total += safe_count($keyword_item['aliexpress'] ?? []);
        $total_product_data += safe_count($keyword_item['products_data'] ?? []);
    }
    
    $queue_data['conversion_status']['coupang_total'] = $coupang_total;
    $queue_data['conversion_status']['aliexpress_total'] = $aliexpress_total;
    $queue_data['has_product_data'] = ($total_product_data > 0);
    
    debug_log("main_process: Queue data structure created. ID: " . $queue_data['queue_id']);
    debug_log("main_process: Prompt type: " . $input_data['prompt_type'] . " (" . get_prompt_type_name($input_data['prompt_type']) . ")");
    debug_log("main_process: Link counts - Coupang: {$coupang_total}, AliExpress: {$aliexpress_total}");
    debug_log("main_process: Product data entries: {$total_product_data}");
    debug_log("main_process: User details included: " . ($queue_data['has_user_details'] ? 'Yes' : 'No'));
    debug_log("main_process: Product data included: " . ($queue_data['has_product_data'] ? 'Yes' : 'No'));
    debug_log("main_process: Thumbnail URL included: " . ($queue_data['has_thumbnail_url'] ? 'Yes (' . $queue_data['thumbnail_url'] . ')' : 'No'));
    debug_log("main_process: Publish mode: " . $input_data['publish_mode']);

    // 🚀 즉시 발행 vs 큐 저장 분기 처리
    if ($input_data['publish_mode'] === 'immediate') {
        debug_log("main_process: Processing immediate publish request.");
        process_immediate_publish($queue_data);
        // process_immediate_publish() 함수에서 JSON 응답 후 exit 됨
    } else {
        debug_log("main_process: Processing queue mode request using split system.");
        
        // Add to split queue system
        if (!add_to_queue($queue_data)) {
            debug_log("main_process: Failed to add item to split queue system. Check add_queue_split function.");
            $telegram_msg = "❌ 분할 큐 시스템 저장 실패!\n파일 권한 또는 JSON 인코딩 문제일 수 있습니다.\n\n추가 정보:\n- 큐 디렉토리: /var/www/novacents/tools/queues/\n- 디렉토리 권한 확인 필요";
            send_telegram_notification($telegram_msg, true);
            main_log("Failed to add item to split queue system.");
            redirect_to_editor(false, ['error' => '분할 큐 시스템 저장에 실패했습니다. 파일 권한을 확인하거나 관리자에게 문의하세요.']);
        }
        debug_log("main_process: Item successfully added to split queue system.");

        // Get queue statistics for notification
        $stats = get_queue_stats();
        debug_log("main_process: Queue stats retrieved: " . json_encode($stats));

        $telegram_success_msg = "✅ 새 작업이 분할 큐 시스템에 추가되었습니다!\n\n";
        $telegram_success_msg .= "📋 <b>작업 정보</b>\n";
        $telegram_success_msg .= "• 제목: " . $input_data['title'] . "\n";
        $telegram_success_msg .= "• 카테고리: " . $queue_data['category_name'] . "\n";
        $telegram_success_msg .= "• 프롬프트 타입: " . $queue_data['prompt_type_name'] . "\n";
        $telegram_success_msg .= "• 키워드 수: " . safe_count($cleaned_keywords) . "개\n";
        $telegram_success_msg .= "• 처리 모드: 파일 분할 방식 + 상품 분석 데이터\n";
        $telegram_success_msg .= "• 쿠팡 링크: " . $coupang_total . "개\n";
        $telegram_success_msg .= "• 알리익스프레스 링크: " . $aliexpress_total . "개\n";
        
        // 🔧 상품 분석 데이터 정보 추가
        if ($queue_data['has_product_data']) {
            $telegram_success_msg .= "• " . format_products_data_summary($cleaned_keywords) . "\n";
        }
        
        $telegram_success_msg .= "\n📊 <b>큐 현황</b>\n";
        $telegram_success_msg .= "• 전체: " . ($stats['total'] ?? 0) . "개\n";
        $telegram_success_msg .= "• 대기: " . ($stats['pending'] ?? 0) . "개\n";
        $telegram_success_msg .= "• 처리중: " . ($stats['processing'] ?? 0) . "개\n";
        $telegram_success_msg .= "• 완료: " . ($stats['completed'] ?? 0) . "개\n";
        $telegram_success_msg .= "• 실패: " . ($stats['failed'] ?? 0) . "개";
        
        send_telegram_notification($telegram_success_msg);
        debug_log("main_process: Telegram success notification sent.");
        main_log("New item added to split queue system successfully. Queue ID: " . $queue_data['queue_id']);
        
        // 성공 리다이렉트
        redirect_to_editor(true, [
            'message' => '작업이 분할 큐 시스템에 성공적으로 추가되었습니다.',
            'queue_id' => $queue_data['queue_id'],
            'title' => $queue_data['title'],
            'category' => $queue_data['category_name'],
            'prompt_type' => $queue_data['prompt_type_name'],
            'keywords_count' => count($cleaned_keywords),
            'coupang_links' => $coupang_total,
            'aliexpress_links' => $aliexpress_total,
            'queue_stats' => $stats
        ]);
    }
}

// 🚀 16. 즉시 발행 전용 함수들
function process_immediate_publish($queue_data) {
    debug_log("process_immediate_publish: Starting immediate publish process.");
    
    try {
        // 🆕 즉시 발행용 큐 레코드 생성
        debug_log("process_immediate_publish: Creating queue record for immediate publish tracking.");
        $queue_id = add_queue_split($queue_data);
        if (!$queue_id) {
            throw new Exception("즉시 발행용 큐 레코드 생성 실패");
        }
        debug_log("process_immediate_publish: Queue record created with ID: " . $queue_id);
        
        // 🆕 processing 상태로 즉시 변경
        update_queue_status_split($queue_id, 'processing');
        debug_log("process_immediate_publish: Queue status updated to processing.");
        
        // 임시 파일 생성
        $temp_file = create_temp_file($queue_data);
        if (!$temp_file) {
            throw new Exception("임시 파일 생성 실패");
        }
        
        debug_log("process_immediate_publish: Temporary file created: " . $temp_file);
        
        // Python 스크립트 실행
        $result = execute_python_script($temp_file);
        
        // 결과 파싱
        if ($result['success']) {
            // 🆕 성공 시 completed 상태로 변경
            update_queue_status_split($queue_id, 'completed');
            debug_log("process_immediate_publish: Queue status updated to completed.");
            
            // 성공 알림
            $telegram_msg = "✅ 즉시 발행 완료!\n";
            $telegram_msg .= "제목: " . $queue_data['title'] . "\n";
            $telegram_msg .= "프롬프트: " . $queue_data['prompt_type_name'] . "\n";
            $telegram_msg .= "URL: " . $result['post_url'] . "\n";
            $telegram_msg .= "🗂️ 임시파일: " . basename($temp_file) . "\n";
            $telegram_msg .= "💡 서버 정리: " . $temp_file;
            
            send_telegram_notification($telegram_msg);
            
            // JSON 응답
            send_json_response(true, [
                'message' => '글이 성공적으로 발행되었습니다!',
                'post_url' => $result['post_url'],
                'temp_file' => basename($temp_file),
                'temp_file_path' => $temp_file,
                'prompt_type' => $queue_data['prompt_type_name']
            ]);
        } else {
            throw new Exception($result['error'] ?? '글 발행 중 오류 발생');
        }
        
    } catch (Exception $e) {
        debug_log("process_immediate_publish: Error - " . $e->getMessage());
        
        // 🆕 실패 시 failed 상태로 변경
        if (isset($queue_id)) {
            update_queue_status_split($queue_id, 'failed', $e->getMessage());
            debug_log("process_immediate_publish: Queue status updated to failed.");
        }
        
        // 실패 알림
        $telegram_msg = "❌ 즉시 발행 실패!\n";
        $telegram_msg .= "제목: " . $queue_data['title'] . "\n";
        $telegram_msg .= "오류: " . $e->getMessage();
        send_telegram_notification($telegram_msg, true);
        
        // JSON 오류 응답
        send_json_response(false, [
            'message' => '글 발행 중 오류가 발생했습니다: ' . $e->getMessage(),
            'error' => $e->getMessage()
        ]);
    }
}

function create_temp_file($queue_data) {
    debug_log("create_temp_file: Creating temporary file for immediate publish.");
    
    // temp 디렉토리 확인
    if (!is_dir(TEMP_DIR)) {
        debug_log("create_temp_file: Creating temp directory: " . TEMP_DIR);
        if (!mkdir(TEMP_DIR, 0755, true)) {
            debug_log("create_temp_file: Failed to create temp directory.");
            return false;
        }
    }
    
    // 고유한 임시 파일명 생성
    $timestamp = date('Y-m-d_H-i-s');
    $random = random_int(1000, 9999);
    $safe_title = preg_replace('/[^a-zA-Z0-9가-힣]/', '', $queue_data['title']);
    $safe_title = mb_substr($safe_title, 0, 20); // 파일명 길이 제한
    
    $filename = "immediate_{$timestamp}_{$safe_title}_{$random}.json";
    $temp_file = TEMP_DIR . '/' . $filename;
    
    // JSON 데이터 생성
    $temp_data = [
        'mode' => 'immediate',
        'job_data' => $queue_data,
        'created_at' => date('Y-m-d H:i:s'),
        'php_process_id' => getmypid()
    ];
    
    // 파일 생성
    $json_options = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $json_content = json_encode($temp_data, $json_options);
    
    if ($json_content === false) {
        debug_log("create_temp_file: JSON encoding failed: " . json_last_error_msg());
        return false;
    }
    
    if (file_put_contents($temp_file, $json_content, LOCK_EX) === false) {
        debug_log("create_temp_file: Failed to write temporary file.");
        return false;
    }
    
    debug_log("create_temp_file: Temporary file created successfully: " . $temp_file);
    return $temp_file;
}

function execute_python_script($temp_file) {
    debug_log("execute_python_script: Starting Python script execution.");
    
    $python_script = '/var/www/novacents/tools/auto_post_products.py';
    if (!file_exists($python_script)) {
        debug_log("execute_python_script: Python script not found: " . $python_script);
        return [
            'success' => false,
            'error' => 'Python 스크립트를 찾을 수 없습니다.'
        ];
    }
    
    // Python 명령어 구성
    $command = "cd /var/www/novacents/tools && python3 {$python_script} --immediate --temp-file " . escapeshellarg($temp_file) . " 2>&1";
    debug_log("execute_python_script: Executing command: " . $command);
    
    // Python 스크립트 실행
    $output = shell_exec($command);
    debug_log("execute_python_script: Python output: " . $output);
    
    // 출력 파싱
    return parse_python_output($output);
}

function parse_python_output($output) {
    debug_log("parse_python_output: Parsing Python script output.");
    
    // 성공 패턴 확인
    if (preg_match('/워드프레스 발행 성공: (https?:\/\/[^\s]+)/', $output, $matches)) {
        $post_url = $matches[1];
        debug_log("parse_python_output: Success detected. URL: " . $post_url);
        return [
            'success' => true,
            'post_url' => $post_url,
            'output' => $output
        ];
    }
    
    // 오류 패턴 확인
    $error_patterns = [
        '/오류: (.+)/',
        '/Error: (.+)/',
        '/Exception: (.+)/',
        '/Failed: (.+)/'
    ];
    
    foreach ($error_patterns as $pattern) {
        if (preg_match($pattern, $output, $matches)) {
            $error = $matches[1];
            debug_log("parse_python_output: Error detected: " . $error);
            return [
                'success' => false,
                'error' => $error,
                'output' => $output
            ];
        }
    }
    
    // 패턴을 찾을 수 없는 경우
    debug_log("parse_python_output: No clear success/error pattern found.");
    return [
        'success' => false,
        'error' => 'Python 스크립트 실행 결과를 확인할 수 없습니다.',
        'output' => $output
    ];
}

// 🔧 17. 상품 분석 데이터 요약 함수 (새로 추가)
function format_products_data_summary($keywords) {
    $total_products = 0;
    $products_with_analysis = 0;
    $products_with_html = 0;
    
    foreach ($keywords as $keyword) {
        if (isset($keyword['products_data']) && is_array($keyword['products_data'])) {
            $total_products += safe_count($keyword['products_data']);
            
            foreach ($keyword['products_data'] as $product_data) {
                if (!empty($product_data['analysis_data'])) {
                    $products_with_analysis++;
                }
                if (!empty($product_data['generated_html'])) {
                    $products_with_html++;
                }
            }
        }
    }
    
    $summary = [];
    if ($total_products > 0) {
        $summary[] = "상품 데이터: {$total_products}개";
        if ($products_with_analysis > 0) {
            $summary[] = "분석 완료: {$products_with_analysis}개";
        }
        if ($products_with_html > 0) {
            $summary[] = "HTML 생성: {$products_with_html}개";
        }
    }
    
    return implode(', ', $summary);
}

// 🚀 메인 실행 코드
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    debug_log("=== New POST request received ===");
    debug_log("POST data keys: " . implode(', ', array_keys($_POST)));
    
    try {
        // POST 데이터 처리
        $input_data = [
            'title' => $_POST['title'] ?? '',
            'category' => $_POST['category'] ?? '',
            'prompt_type' => $_POST['prompt_type'] ?? '',
            'keywords' => [],
            'user_details' => [],
            'thumbnail_url' => $_POST['thumbnail_url'] ?? '',
            'publish_mode' => $_POST['publish_mode'] ?? 'queue' // 기본값: queue
        ];
        
        // 키워드 데이터 파싱
        if (isset($_POST['keywords'])) {
            $keywords_json = $_POST['keywords'];
            $parsed_keywords = json_decode($keywords_json, true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($parsed_keywords)) {
                $input_data['keywords'] = $parsed_keywords;
                debug_log("Keywords parsed successfully. Count: " . count($parsed_keywords));
            } else {
                debug_log("Keywords JSON parsing failed: " . json_last_error_msg());
                main_log("Keywords JSON parsing failed: " . json_last_error_msg());
                redirect_to_editor(false, ['error' => '키워드 데이터 형식이 올바르지 않습니다.']);
            }
        }
        
        // 사용자 세부사항 파싱
        if (isset($_POST['user_details'])) {
            $user_details_json = $_POST['user_details'];
            $parsed_user_details = json_decode($user_details_json, true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($parsed_user_details)) {
                $input_data['user_details'] = $parsed_user_details;
                debug_log("User details parsed successfully. Count: " . count($parsed_user_details));
            } else {
                debug_log("User details JSON parsing failed: " . json_last_error_msg());
                // 사용자 세부사항은 선택사항이므로 오류로 처리하지 않음
                $input_data['user_details'] = [];
            }
        }
        
        debug_log("Input data prepared successfully.");
        debug_log("Title: " . $input_data['title']);
        debug_log("Category: " . $input_data['category']);
        debug_log("Prompt type: " . $input_data['prompt_type']);
        debug_log("Keywords count: " . count($input_data['keywords']));
        debug_log("User details count: " . count($input_data['user_details']));
        debug_log("Thumbnail URL: " . ($input_data['thumbnail_url'] ? 'Yes' : 'No'));
        debug_log("Publish mode: " . $input_data['publish_mode']);
        
        // 메인 프로세스 실행
        main_process($input_data);
        
    } catch (Exception $e) {
        debug_log("Fatal error in main execution: " . $e->getMessage());
        debug_log("Stack trace: " . $e->getTraceAsString());
        main_log("Fatal error: " . $e->getMessage());
        
        // 텔레그램 오류 알림
        $telegram_error_msg = "🚨 키워드 프로세서 오류 발생!\n\n";
        $telegram_error_msg .= "오류 메시지: " . $e->getMessage() . "\n";
        $telegram_error_msg .= "파일: " . $e->getFile() . "\n";
        $telegram_error_msg .= "라인: " . $e->getLine() . "\n";
        $telegram_error_msg .= "시간: " . date('Y-m-d H:i:s');
        
        send_telegram_notification($telegram_error_msg, true);
        
        redirect_to_editor(false, ['error' => '처리 중 오류가 발생했습니다: ' . $e->getMessage()]);
    }
} else {
    debug_log("Non-POST request received. Method: " . $_SERVER['REQUEST_METHOD']);
    redirect_to_editor(false, ['error' => '잘못된 요청 방식입니다.']);
}
?>