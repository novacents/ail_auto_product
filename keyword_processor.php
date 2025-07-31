<?php
/**
 * 어필리에이트 상품 키워드 데이터 처리기 (4가지 프롬프트 템플릿 시스템 + 즉시 발행 지원 + 상품 분석 데이터 저장)
 * affiliate_editor.php에서 POST로 전송된 데이터를 처리하고 큐 파일에 저장하거나 즉시 발행합니다.
 * 워드프레스 환경에 전혀 종속되지 않으며, 순수 PHP로만 작동합니다.
 *
 * 파일 위치: /var/www/novacents/tools/keyword_processor.php
 * 버전: v4.6 (파일 분할 방식 큐 관리 시스템 적용)
 */

// 1. 초기 에러 리포팅 설정 (스크립트 시작 시점부터 에러를 잡기 위함)
error_reporting(E_ALL); // 모든 PHP 에러를 보고
ini_set('display_errors', 1); // 웹 브라우저에 에러를 직접 표시
ini_set('log_errors', 1); // 에러를 웹 서버 에러 로그에 기록
ini_set('error_log', __DIR__ . '/php_error_log.txt'); // PHP 에러를 특정 파일에 기록

// 2. 파일 경로 상수 정의 (한곳에서 관리)
define('BASE_PATH', __DIR__); // 현재 파일이 있는 디렉토리
define('ENV_FILE', '/home/novacents/.env'); // .env 파일은 홈 디렉토리에 고정
define('DEBUG_LOG_FILE', BASE_PATH . '/debug_processor.txt');
define('MAIN_LOG_FILE', BASE_PATH . '/processor_log.txt');
define('QUEUE_FILE', '/var/www/novacents/tools/product_queue.json');
define('TEMP_DIR', BASE_PATH . '/temp'); // 즉시 발행용 임시 파일 디렉토리

// queue_utils.php 유틸리티 함수 포함
require_once __DIR__ . '/queue_utils.php';

// 3. 안전한 count 함수
function safe_count($value) {
    if (is_array($value) || $value instanceof Countable) {
        return count($value);
    }
    return 0;
}

// 4. 디버깅 로그 함수 (스크립트 시작부터 끝까지 모든 흐름 기록)
function debug_log($message) {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] DEBUG: {$message}\n";
    @file_put_contents(DEBUG_LOG_FILE, $log_entry, FILE_APPEND | LOCK_EX); // @ suppress errors
}

// 스크립트 시작 시 즉시 디버그 로그 기록
debug_log("=== keyword_processor.php 시작 (파일 분할 방식 큐 관리 시스템) ===");
debug_log("PHP Version: " . phpversion());
debug_log("Request Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'N/A'));
debug_log("POST Data Empty: " . (empty($_POST) ? 'YES' : 'NO'));
debug_log("Script Path: " . __FILE__);
debug_log("Base Path: " . BASE_PATH);

// 🔧 POST 데이터 상세 로깅 추가
debug_log("=== POST 데이터 상세 분석 ===");
if (!empty($_POST)) {
    foreach ($_POST as $key => $value) {
        if ($key === 'keywords') {
            debug_log("POST[{$key}] (raw): " . substr($value, 0, 500) . (strlen($value) > 500 ? '... (truncated)' : ''));
            
            // JSON 디코딩 시도
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                debug_log("POST[{$key}] (decoded type): " . gettype($decoded));
                debug_log("POST[{$key}] (decoded count): " . safe_count($decoded));
                if (is_array($decoded) && !empty($decoded)) {
                    debug_log("POST[{$key}] (first item): " . json_encode($decoded[0], JSON_UNESCAPED_UNICODE));
                    
                    // 🔧 products_data 확인
                    if (isset($decoded[0]['products_data'])) {
                        debug_log("POST[{$key}] (first item has products_data): " . safe_count($decoded[0]['products_data']) . " items");
                        if (!empty($decoded[0]['products_data'])) {
                            $first_product = $decoded[0]['products_data'][0];
                            debug_log("POST[{$key}] (first product data keys): " . implode(', ', array_keys($first_product)));
                            debug_log("POST[{$key}] (first product has analysis_data): " . (isset($first_product['analysis_data']) ? 'YES' : 'NO'));
                            debug_log("POST[{$key}] (first product has generated_html): " . (isset($first_product['generated_html']) ? 'YES' : 'NO'));
                        }
                    }
                }
            } else {
                debug_log("POST[{$key}] JSON decode error: " . json_last_error_msg());
            }
        } else {
            debug_log("POST[{$key}]: " . (is_string($value) ? substr($value, 0, 200) : gettype($value)));
        }
    }
} else {
    debug_log("No POST data received");
}
debug_log("=== POST 데이터 분석 완료 ===");


// 5. 환경 변수 로드 함수 (워드프레스 외부에서 .env 파일을 안전하게 로드)
function load_env_variables() {
    debug_log("load_env_variables: Loading environment variables from " . ENV_FILE);
    $env_vars = [];
    if (!file_exists(ENV_FILE)) {
        debug_log("load_env_variables: .env file not found at " . ENV_FILE);
        return $env_vars;
    }

    try {
        $lines = file(ENV_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            debug_log("load_env_variables: Failed to read .env file content.");
            return $env_vars;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if (strpos($line, '#') === 0 || empty($line)) {
                continue; // Skip comments and empty lines
            }
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $env_vars[trim($key)] = trim($value);
            }
        }
        debug_log("load_env_variables: Successfully loaded " . safe_count($env_vars) . " variables.");
    } catch (Exception $e) {
        debug_log("load_env_variables: Exception while reading .env file: " . $e->getMessage());
    }
    return $env_vars;
}


// 6. 일반 로그 함수 (주요 작업 흐름 기록)
function main_log($message) {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] INFO: {$message}\n";
    @file_put_contents(MAIN_LOG_FILE, $log_entry, FILE_APPEND | LOCK_EX);
}


// 7. 입력값 안전 처리 함수 (htmlspecialchars, strip_tags 등 사용)
function clean_input($data) {
    $data = trim($data);
    $data = stripslashes($data); // 매직 쿼트 제거 (PHP 설정에 따라 다름)
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8'); // HTML 특수 문자 변환
    $data = strip_tags($data); // HTML 태그 제거
    return $data;
}


// 8. 텔레그램 알림 발송 함수 (cURL 우선, file_get_contents 백업)
function send_telegram_notification($message, $urgent = false) {
    debug_log("send_telegram_notification: Attempting to send message.");
    $env_vars = load_env_variables();
    $bot_token = $env_vars['TELEGRAM_BOT_TOKEN'] ?? '';
    $chat_id = $env_vars['TELEGRAM_CHAT_ID'] ?? '';

    if (empty($bot_token) || empty($chat_id)) {
        debug_log("send_telegram_notification: TELEGRAM_BOT_TOKEN or TELEGRAM_CHAT_ID not set in .env.");
        return false;
    }

    $final_message = $urgent ? "🚨 긴급 알림 🚨\n\n" : "🤖 노바센트 어필리에이트 봇\n\n";
    $final_message .= $message;

    $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $final_message,
        'parse_mode' => 'HTML'
    ];

    $response_content = null;
    $http_code = 0;
    $success = false;

    // Try cURL first
    if (function_exists('curl_init')) {
        debug_log("send_telegram_notification: Using cURL.");
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response_content = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        debug_log("send_telegram_notification: cURL Result - HTTP: {$http_code}, Error: {$curl_error}, Response: " . substr($response_content, 0, 200));

        if ($http_code === 200 && $response_content !== false) {
            $json_response = json_decode($response_content, true);
            if (isset($json_response['ok']) && $json_response['ok'] === true) {
                $success = true;
            }
        }
    }

    // Fallback to file_get_contents if cURL failed or not available
    if (!$success && ini_get('allow_url_fopen')) {
        debug_log("send_telegram_notification: Using file_get_contents.");
        $options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($data),
                'timeout' => 15,
                'ignore_errors' => true // Allows reading response even for HTTP error codes
            ]
        ];
        $context = stream_context_create($options);
        $response_content = @file_get_contents($url, false, $context); // @ to suppress warnings
        
        // Check HTTP response header from stream context
        if (isset($http_response_header)) { // This variable is automatically set by file_get_contents
            preg_match('{HTTP/\S+\s(\d{3})}', $http_response_header[0], $match);
            $http_code = (int)$match[1];
        }

        debug_log("send_telegram_notification: file_get_contents Result - HTTP: {$http_code}, Response: " . substr($response_content, 0, 200));

        if ($http_code === 200 && $response_content !== false) {
            $json_response = json_decode($response_content, true);
            if (isset($json_response['ok']) && $json_response['ok'] === true) {
                $success = true;
            }
        }
    }

    if ($success) {
        debug_log("send_telegram_notification: Message sent successfully.");
    } else {
        debug_log("send_telegram_notification: Failed to send message through all methods.");
    }
    return $success;
}


// 9. 큐 파일 로드/저장/관리 함수들 (레거시 지원용 - 분할 시스템으로 점진적 마이그레이션)
function get_queue_file_path() {
    return QUEUE_FILE;
}

function ensure_queue_directory() {
    $queue_dir = dirname(QUEUE_FILE);
    if (!is_dir($queue_dir)) {
        debug_log("ensure_queue_directory: Creating queue directory: " . $queue_dir);
        if (!mkdir($queue_dir, 0755, true)) {
            debug_log("ensure_queue_directory: Failed to create queue directory.");
            return false;
        }
    }
    
    // 디렉토리 권한 확인
    if (!is_writable($queue_dir)) {
        debug_log("ensure_queue_directory: Queue directory is not writable: " . $queue_dir);
        return false;
    }
    
    return true;
}

function load_queue() {
    $queue_file = get_queue_file_path();
    debug_log("load_queue: Attempting to load queue from " . $queue_file);

    if (!file_exists($queue_file)) {
        debug_log("load_queue: Queue file does not exist. Returning empty array.");
        return [];
    }

    $content = @file_get_contents($queue_file); // @ to suppress warnings/errors on read failure
    if ($content === false) {
        debug_log("load_queue: Failed to read queue file content.");
        return [];
    }
    
    // 파일 내용이 비어있는 경우 빈 배열 반환
    if (trim($content) === '') {
        debug_log("load_queue: Queue file is empty. Returning empty array.");
        return [];
    }

    $queue = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        debug_log("load_queue: JSON decoding error: " . json_last_error_msg() . ". Content: " . substr($content, 0, 200));
        return []; // Decoding failed, return empty array
    }
    
    $result = is_array($queue) ? $queue : [];
    debug_log("load_queue: Successfully loaded " . safe_count($result) . " items from queue.");
    return $result;
}

function save_queue($queue) {
    $queue_file = get_queue_file_path();
    debug_log("save_queue: Attempting to save " . safe_count($queue) . " items to " . $queue_file);

    // 큐 디렉토리 확인 및 생성
    if (!ensure_queue_directory()) {
        debug_log("save_queue: Failed to ensure queue directory exists or is writable.");
        return false;
    }

    // Use named constants for JSON options to ensure proper URL handling
    $json_options = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $json_data = json_encode($queue, $json_options);

    if ($json_data === false) {
        debug_log("save_queue: JSON encoding failed: " . json_last_error_msg());
        return false;
    }

    // 파일 쓰기 권한 확인
    if (file_exists($queue_file) && !is_writable($queue_file)) {
        debug_log("save_queue: Queue file exists but is not writable: " . $queue_file);
        return false;
    }

    // Attempt to write the file with error handling
    $write_result = @file_put_contents($queue_file, $json_data, LOCK_EX);
    if ($write_result === false) {
        $error = error_get_last();
        debug_log("save_queue: Failed to write to queue file: " . ($error['message'] ?? 'Unknown error') . ". Check file permissions.");
        
        // 추가 디버깅 정보
        debug_log("save_queue: File exists: " . (file_exists($queue_file) ? 'yes' : 'no'));
        debug_log("save_queue: Directory writable: " . (is_writable(dirname($queue_file)) ? 'yes' : 'no'));
        debug_log("save_queue: File size to write: " . strlen($json_data) . " bytes");
        
        return false;
    }

    debug_log("save_queue: Successfully saved queue to " . $queue_file . " (" . $write_result . " bytes)");
    return true;
}

// 🚀 수정된 add_to_queue 함수 - 파일 분할 방식 사용
function add_to_queue($queue_data) {
    debug_log("add_to_queue: Adding new item to queue using split file system.");
    
    try {
        // 새로운 파일 분할 방식 사용
        $result = add_queue_split($queue_data);
        
        if ($result) {
            debug_log("add_to_queue: Successfully added item to split queue system.");
        } else {
            debug_log("add_to_queue: Failed to add item to split queue system.");
        }
        
        return $result;
    } catch (Exception $e) {
        debug_log("add_to_queue: Exception occurred: " . $e->getMessage());
        return false;
    }
}

function get_queue_stats() {
    debug_log("get_queue_stats: Calculating queue statistics using split file system.");
    
    try {
        // 분할 파일 시스템에서 통계 가져오기
        $pending_queues = get_pending_queues_split();
        $stats = [
            'total' => safe_count($pending_queues),
            'pending' => safe_count($pending_queues),
            'processing' => 0, // 처리 중인 큐는 별도 디렉토리에서 관리
            'completed' => 0, // 완료된 큐는 별도 디렉토리에서 관리
            'failed' => 0
        ];
        
        debug_log("get_queue_stats: Statistics from split system: " . json_encode($stats));
        return $stats;
    } catch (Exception $e) {
        debug_log("get_queue_stats: Exception occurred: " . $e->getMessage());
        // 실패 시 레거시 방식으로 fallback
        $queue = load_queue();
        $stats = [
            'total' => safe_count($queue),
            'pending' => 0, 'processing' => 0,
            'completed' => 0, 'failed' => 0
        ];
        foreach ($queue as $item) {
            $status = $item['status'] ?? 'pending';
            if (isset($stats[$status])) {
                $stats[$status]++;
            }
        }
        debug_log("get_queue_stats: Fallback statistics: " . json_encode($stats));
        return $stats;
    }
}


// 10. 리다이렉션 함수 (성공/실패 메시지 전달)
function redirect_to_editor($success, $message_params) {
    $base_url = 'affiliate_editor.php';
    $query_string = http_build_query($message_params);
    $location = $base_url . '?' . $query_string;
    debug_log("redirect_to_editor: Redirecting to {$location}");
    header("Location: {$location}");
    exit;
}

// 🚀 11. JSON 응답 함수 (즉시 발행용)
function send_json_response($success, $data = []) {
    header('Content-Type: application/json');
    $response = [
        'success' => $success,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    $response = array_merge($response, $data);
    
    debug_log("send_json_response: Sending JSON response: " . json_encode($response, JSON_UNESCAPED_UNICODE));
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}


// 12. 카테고리 매핑 함수
function get_category_name($category_id) {
    $categories = [
        '354' => 'Today\'s Pick',
        '355' => '기발한 잡화점',
        '356' => '스마트 리빙',
        '12' => '우리잇템'
    ];
    return $categories[$category_id] ?? '알 수 없는 카테고리';
}

// 🚀 13. 프롬프트 타입 관련 함수들 (새로 추가)
function get_prompt_type_name($prompt_type) {
    $prompt_types = [
        'essential_items' => '필수템형 🎯',
        'friend_review' => '친구 추천형 👫',
        'professional_analysis' => '전문 분석형 📊',
        'amazing_discovery' => '놀라움 발견형 ✨'
    ];
    return $prompt_types[$prompt_type] ?? '기본형';
}

function validate_prompt_type($prompt_type) {
    $valid_prompt_types = ['essential_items', 'friend_review', 'professional_analysis', 'amazing_discovery'];
    return in_array($prompt_type, $valid_prompt_types);
}

// 14. 사용자 상세 정보 처리 함수들 (오류 처리 강화)
function parse_user_details($user_details_json) {
    debug_log("parse_user_details: Parsing user details JSON.");
    
    if (empty($user_details_json)) {
        debug_log("parse_user_details: Empty user details JSON provided.");
        return null;
    }
    
    // 문자열이 'null' 또는 공백인 경우 처리
    $trimmed_json = trim($user_details_json);
    if ($trimmed_json === '' || $trimmed_json === 'null' || $trimmed_json === '{}') {
        debug_log("parse_user_details: User details JSON is empty or null.");
        return null;
    }
    
    try {
        $user_details = json_decode($trimmed_json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            debug_log("parse_user_details: JSON parsing error: " . json_last_error_msg());
            debug_log("parse_user_details: Problematic JSON: " . substr($trimmed_json, 0, 200));
            return null;
        }
        
        if (!is_array($user_details)) {
            debug_log("parse_user_details: Parsed result is not an array.");
            return null;
        }
        
        debug_log("parse_user_details: Successfully parsed user details with " . safe_count($user_details) . " sections.");
        return $user_details;
        
    } catch (Exception $e) {
        debug_log("parse_user_details: Exception while parsing user details: " . $e->getMessage());
        return null;
    } catch (Error $e) {
        debug_log("parse_user_details: Fatal error while parsing user details: " . $e->getMessage());
        return null;
    }
}

function validate_user_details($user_details) {
    debug_log("validate_user_details: Validating user details structure.");
    
    if (!is_array($user_details)) {
        debug_log("validate_user_details: User details is not an array.");
        return false;
    }
    
    if (empty($user_details)) {
        debug_log("validate_user_details: User details array is empty.");
        return false;
    }
    
    $allowed_sections = ['specs', 'efficiency', 'usage', 'benefits'];
    $valid_sections = 0;
    
    foreach ($user_details as $section_name => $section_data) {
        if (in_array($section_name, $allowed_sections) && is_array($section_data)) {
            $valid_sections++;
            debug_log("validate_user_details: Valid section found: {$section_name} with " . safe_count($section_data) . " fields.");
        } else {
            debug_log("validate_user_details: Invalid or unknown section: {$section_name}");
        }
    }
    
    debug_log("validate_user_details: Found {$valid_sections} valid sections out of " . safe_count($user_details) . " total sections.");
    return $valid_sections > 0; // 최소 하나의 유효한 섹션이 있으면 통과
}

function format_user_details_summary($user_details) {
    $summary = [];
    
    if (isset($user_details['specs']) && is_array($user_details['specs'])) {
        $specs_count = safe_count($user_details['specs']);
        $summary[] = "기능/스펙: {$specs_count}개 항목";
    }
    
    if (isset($user_details['efficiency']) && is_array($user_details['efficiency'])) {
        $efficiency_count = safe_count($user_details['efficiency']);
        $summary[] = "효율성 분석: {$efficiency_count}개 항목";
    }
    
    if (isset($user_details['usage']) && is_array($user_details['usage'])) {
        $usage_count = safe_count($user_details['usage']);
        $summary[] = "사용 시나리오: {$usage_count}개 항목";
    }
    
    if (isset($user_details['benefits']) && is_array($user_details['benefits'])) {
        $benefits_count = 0;
        if (isset($user_details['benefits']['advantages']) && is_array($user_details['benefits']['advantages'])) {
            $benefits_count += safe_count($user_details['benefits']['advantages']);
        }
        if (isset($user_details['benefits']['precautions'])) {
            $benefits_count += 1;
        }
        $summary[] = "장점/주의사항: {$benefits_count}개 항목";
    }
    
    return implode(', ', $summary);
}


// 15. 입력 데이터 검증 및 정리 함수 (프롬프트 타입 지원 추가) - 🔧 강화된 디버깅
function validate_input_data($data) {
    debug_log("validate_input_data: Starting validation with prompt type and user details support.");
    debug_log("validate_input_data: Input data structure: " . json_encode(array_keys($data), JSON_UNESCAPED_UNICODE));
    
    $errors = [];

    if (empty($data['title']) || strlen(trim($data['title'])) < 5) {
        $errors[] = '제목은 5자 이상이어야 합니다.';
        debug_log("validate_input_data: Title validation failed. Title: " . ($data['title'] ?? 'NULL'));
    }
    
    $valid_categories = ['354', '355', '356', '12'];
    if (empty($data['category']) || !in_array((int)$data['category'], $valid_categories)) {
        $errors[] = '유효하지 않은 카테고리입니다.';
        debug_log("validate_input_data: Category validation failed. Category: " . ($data['category'] ?? 'NULL'));
    }

    // 🚀 프롬프트 타입 검증 추가
    if (empty($data['prompt_type']) || !validate_prompt_type($data['prompt_type'])) {
        $errors[] = '유효하지 않은 프롬프트 타입입니다.';
        debug_log("validate_input_data: Prompt type validation failed. Prompt type: " . ($data['prompt_type'] ?? 'NULL'));
    }

    // 🔧 키워드 데이터 강화된 검증 및 디버깅
    debug_log("validate_input_data: Keywords raw data type: " . gettype($data['keywords'] ?? null));
    debug_log("validate_input_data: Keywords raw data (first 500 chars): " . substr(var_export($data['keywords'] ?? null, true), 0, 500));
    
    if (empty($data['keywords']) || !is_array($data['keywords'])) {
        $errors[] = '최소 하나의 키워드가 필요합니다.';
        debug_log("validate_input_data: Keywords is not array or empty. Type: " . gettype($data['keywords'] ?? null) . ", Count: " . safe_count($data['keywords'] ?? null));
    } else {
        debug_log("validate_input_data: Processing " . safe_count($data['keywords']) . " keywords...");
        
        foreach ($data['keywords'] as $index => $keyword_item) {
            debug_log("validate_input_data: Processing keyword {$index}: " . json_encode($keyword_item, JSON_UNESCAPED_UNICODE));
            
            if (empty($keyword_item['name']) || strlen(trim($keyword_item['name'])) < 2) {
                $errors[] = "키워드 #" . ($index + 1) . "의 이름이 너무 짧습니다.";
                debug_log("validate_input_data: Keyword {$index} name too short: " . ($keyword_item['name'] ?? 'NULL'));
            }

            $has_valid_link = false;
            
            // 쿠팡 링크 검증
            if (!empty($keyword_item['coupang']) && is_array($keyword_item['coupang'])) {
                debug_log("validate_input_data: Keyword {$index} has " . safe_count($keyword_item['coupang']) . " Coupang links");
                foreach ($keyword_item['coupang'] as $link_index => $link) {
                    if (!empty($link) && filter_var(trim($link), FILTER_VALIDATE_URL)) {
                        $has_valid_link = true;
                        debug_log("validate_input_data: Keyword {$index} Coupang link {$link_index} is valid");
                        break;
                    }
                }
                if (safe_count($keyword_item['coupang']) > 10) {
                    $errors[] = "키워드 '" . clean_input($keyword_item['name'] ?? '') . "'의 쿠팡 링크는 최대 10개까지 허용됩니다.";
                }
            }
            
            // 알리익스프레스 링크 검증
            if (!empty($keyword_item['aliexpress']) && is_array($keyword_item['aliexpress'])) {
                debug_log("validate_input_data: Keyword {$index} has " . safe_count($keyword_item['aliexpress']) . " AliExpress links");
                foreach ($keyword_item['aliexpress'] as $link_index => $link) {
                    if (!empty($link) && filter_var(trim($link), FILTER_VALIDATE_URL)) {
                        $has_valid_link = true;
                        debug_log("validate_input_data: Keyword {$index} AliExpress link {$link_index} is valid");
                        break;
                    }
                }
            }

            if (!$has_valid_link) {
                $errors[] = "키워드 '" . clean_input($keyword_item['name'] ?? '') . "'에 유효한 상품 링크가 없습니다.";
                debug_log("validate_input_data: Keyword {$index} has no valid links");
            } else {
                debug_log("validate_input_data: Keyword {$index} validation passed");
            }
        }
    }
    
    // 사용자 상세 정보 검증 (선택사항이므로 오류는 아님)
    if (!empty($data['user_details'])) {
        debug_log("validate_input_data: User details provided, attempting to parse...");
        $user_details = parse_user_details($data['user_details']);
        if ($user_details !== null && !validate_user_details($user_details)) {
            debug_log("validate_input_data: User details validation failed, but continuing as it's optional.");
            // 오류로 처리하지 않음 - 선택사항이므로
        }
    } else {
        debug_log("validate_input_data: No user details provided.");
    }
    
    // 썸네일 URL 검증 (선택사항)
    if (!empty($data['thumbnail_url'])) {
        $thumbnail_url = trim($data['thumbnail_url']);
        if (!filter_var($thumbnail_url, FILTER_VALIDATE_URL)) {
            $errors[] = '썸네일 이미지 URL 형식이 올바르지 않습니다.';
            debug_log("validate_input_data: Thumbnail URL validation failed. URL: " . $thumbnail_url);
        } else {
            debug_log("validate_input_data: Thumbnail URL validated successfully: " . $thumbnail_url);
        }
    } else {
        debug_log("validate_input_data: No thumbnail URL provided.");
    }
    
    debug_log("validate_input_data: Validation finished with " . safe_count($errors) . " errors.");
    if (!empty($errors)) {
        debug_log("validate_input_data: Validation errors: " . implode(' | ', $errors));
    }
    
    return $errors;
}

// 🔧 강화된 어필리에이트 링크 정리 함수 - 상품 분석 데이터 포함
function clean_affiliate_links($keywords_raw) {
    debug_log("clean_affiliate_links: Starting enhanced link cleaning with product data.");
    debug_log("clean_affiliate_links: Input type: " . gettype($keywords_raw) . ", Count: " . safe_count($keywords_raw));
    
    $cleaned_keywords = [];
    foreach ($keywords_raw as $index => $keyword_item) {
        debug_log("clean_affiliate_links: Processing keyword {$index}: " . json_encode($keyword_item, JSON_UNESCAPED_UNICODE));
        
        $cleaned_item = [
            'name' => clean_input($keyword_item['name'] ?? ''),
            'coupang' => [],
            'aliexpress' => [],
            'products_data' => [] // 🔧 새로 추가: 상품 분석 데이터
        ];

        // Clean Coupang links
        if (!empty($keyword_item['coupang']) && is_array($keyword_item['coupang'])) {
            foreach ($keyword_item['coupang'] as $link) {
                $link = trim($link);
                if (!empty($link) && filter_var($link, FILTER_VALIDATE_URL)) {
                    $cleaned_item['coupang'][] = $link;
                }
            }
            debug_log("clean_affiliate_links: Keyword {$index} cleaned Coupang links: " . safe_count($cleaned_item['coupang']));
        }

        // Clean AliExpress links
        if (!empty($keyword_item['aliexpress']) && is_array($keyword_item['aliexpress'])) {
            foreach ($keyword_item['aliexpress'] as $link) {
                $link = trim($link);
                if (!empty($link) && filter_var($link, FILTER_VALIDATE_URL)) {
                    $cleaned_item['aliexpress'][] = $link;
                }
            }
            debug_log("clean_affiliate_links: Keyword {$index} cleaned AliExpress links: " . safe_count($cleaned_item['aliexpress']));
        }

        // 🔧 상품 분석 데이터 처리
        if (!empty($keyword_item['products_data']) && is_array($keyword_item['products_data'])) {
            debug_log("clean_affiliate_links: Keyword {$index} has " . safe_count($keyword_item['products_data']) . " product data entries");
            
            foreach ($keyword_item['products_data'] as $product_data) {
                if (is_array($product_data) && !empty($product_data['url'])) {
                    $cleaned_product_data = [
                        'url' => clean_input($product_data['url']),
                        'analysis_data' => $product_data['analysis_data'] ?? null,
                        'generated_html' => $product_data['generated_html'] ?? null,
                        'user_data' => $product_data['user_data'] ?? []
                    ];
                    
                    $cleaned_item['products_data'][] = $cleaned_product_data;
                    debug_log("clean_affiliate_links: Added product data for URL: " . substr($cleaned_product_data['url'], 0, 50));
                }
            }
            
            debug_log("clean_affiliate_links: Keyword {$index} cleaned product data: " . safe_count($cleaned_item['products_data']) . " entries");
        }

        // Only add if keyword name is not empty and has at least one valid link
        if (!empty($cleaned_item['name']) && (!empty($cleaned_item['coupang']) || !empty($cleaned_item['aliexpress']))) {
            $cleaned_keywords[] = $cleaned_item;
            debug_log("clean_affiliate_links: Keyword '" . $cleaned_item['name'] . "' added to cleaned list with " . safe_count($cleaned_item['products_data']) . " product data entries.");
        } else {
            debug_log("clean_affiliate_links: Keyword '" . ($keyword_item['name'] ?? 'N/A') . "' (index {$index}) removed due to empty name or no valid links.");
        }
    }
    debug_log("clean_affiliate_links: Finished enhanced cleaning. " . safe_count($cleaned_keywords) . " keywords remain.");
    return $cleaned_keywords;
}

// 🚀 16. 즉시 발행 전용 함수들
function process_immediate_publish($queue_data) {
    debug_log("process_immediate_publish: Starting immediate publish process.");
    
    try {
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
    
    // 파일 권한 설정 (소유자만 읽기/쓰기)
    chmod($temp_file, 0600);
    
    debug_log("create_temp_file: Temporary file created successfully: " . $temp_file);
    return $temp_file;
}

function execute_python_script($temp_file) {
    debug_log("execute_python_script: Executing Python script with temp file: " . $temp_file);
    
    // Python 스크립트 경로
    $script_path = BASE_PATH . '/auto_post_products.py';
    
    if (!file_exists($script_path)) {
        debug_log("execute_python_script: Python script not found: " . $script_path);
        return ['success' => false, 'error' => 'Python 스크립트를 찾을 수 없습니다.'];
    }
    
    // 명령어 구성
    $escaped_temp_file = escapeshellarg($temp_file);
    $command = "cd " . escapeshellarg(BASE_PATH) . " && python3 auto_post_products.py --immediate-file={$escaped_temp_file} 2>&1";
    
    debug_log("execute_python_script: Command: " . $command);
    
    // Python 스크립트 실행
    $start_time = microtime(true);
    $output = shell_exec($command);
    $execution_time = round((microtime(true) - $start_time), 2);
    
    debug_log("execute_python_script: Execution completed in {$execution_time} seconds.");
    debug_log("execute_python_script: Output: " . substr($output, 0, 500));
    
    // 출력 파싱
    $result = parse_python_output($output);
    $result['execution_time'] = $execution_time;
    
    return $result;
}

function parse_python_output($output) {
    debug_log("parse_python_output: Parsing Python script output.");
    
    if (empty($output)) {
        return ['success' => false, 'error' => 'Python 스크립트에서 출력이 없습니다.'];
    }
    
    // 성공 패턴 찾기
    if (preg_match('/워드프레스 발행 성공: (https?:\/\/[^\s]+)/', $output, $matches)) {
        $post_url = $matches[1];
        debug_log("parse_python_output: Success detected. Post URL: " . $post_url);
        return [
            'success' => true,
            'post_url' => $post_url,
            'output' => $output
        ];
    }
    
    // 에러 패턴 찾기
    $error_patterns = [
        '/\[❌\] (.+)/',
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
        $summary[] = "분석 완료: {$products_with_analysis}개";
        $summary[] = "HTML 생성: {$products_with_html}개";
    }
    
    return implode(', ', $summary);
}


// 18. 메인 처리 로직 (4가지 프롬프트 템플릿 시스템 + 즉시 발행 지원 + count() 오류 수정 + 강화된 디버깅 + 상품 분석 데이터 저장 + 썸네일 URL 저장)
function main_process() {
    debug_log("main_process: Main processing started with split queue system support.");

    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            debug_log("main_process: Invalid request method. Not a POST request.");
            redirect_to_editor(false, ['error' => '잘못된 요청 방식입니다. POST 메서드만 허용됩니다.']);
        }

        // Input data collection (즉시 발행 모드 + 프롬프트 타입 + 사용자 상세 정보 + 썸네일 URL 포함)
        $input_data = [
            'title' => clean_input($_POST['title'] ?? ''),
            'category' => clean_input($_POST['category'] ?? ''),
            'prompt_type' => clean_input($_POST['prompt_type'] ?? 'essential_items'),
            'keywords' => $_POST['keywords'] ?? [],
            'user_details' => $_POST['user_details'] ?? null,
            'publish_mode' => clean_input($_POST['publish_mode'] ?? 'queue'),
            'thumbnail_url' => clean_input($_POST['thumbnail_url'] ?? '') // 🔧 썸네일 URL 추가
        ];
        
        debug_log("main_process: Input data collected successfully");
        debug_log("main_process: Title: " . $input_data['title']);
        debug_log("main_process: Category: " . $input_data['category']);
        debug_log("main_process: Prompt Type: " . $input_data['prompt_type']);
        debug_log("main_process: Keywords raw type: " . gettype($input_data['keywords']));
        debug_log("main_process: Keywords raw count: " . safe_count($input_data['keywords']));
        debug_log("main_process: User details: " . (empty($input_data['user_details']) ? 'No' : 'Yes'));
        debug_log("main_process: Publish mode: " . $input_data['publish_mode']);
        debug_log("main_process: Thumbnail URL: " . (empty($input_data['thumbnail_url']) ? 'No' : 'Yes - ' . $input_data['thumbnail_url']));
        
        // 🔧 키워드 데이터 JSON 디코딩 처리
        if (is_string($input_data['keywords'])) {
            debug_log("main_process: Keywords is string, attempting JSON decode...");
            $decoded_keywords = json_decode($input_data['keywords'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_keywords)) {
                $input_data['keywords'] = $decoded_keywords;
                debug_log("main_process: Successfully decoded keywords JSON. New count: " . safe_count($input_data['keywords']));
            } else {
                debug_log("main_process: Failed to decode keywords JSON: " . json_last_error_msg());
                debug_log("main_process: Raw keywords string: " . substr($input_data['keywords'], 0, 500));
            }
        }
        
        main_log("Input data received: Title='" . $input_data['title'] . "', Category=" . $input_data['category'] . ", Prompt Type=" . $input_data['prompt_type'] . ", Keywords=" . safe_count($input_data['keywords']) . ", User Details=" . (empty($input_data['user_details']) ? 'No' : 'Yes') . ", Publish Mode=" . $input_data['publish_mode'] . ", Thumbnail URL=" . (empty($input_data['thumbnail_url']) ? 'No' : 'Yes') . ".");

        // Data validation
        $validation_errors = validate_input_data($input_data);
        if (!empty($validation_errors)) {
            debug_log("main_process: Validation failed. Errors: " . implode(' | ', $validation_errors));
            $telegram_msg = "❌ 데이터 검증 실패:\n\n" . implode("\n• ", $validation_errors) . "\n\n입력된 데이터:\n제목: " . $input_data['title'] . "\n카테고리: " . get_category_name($input_data['category']) . "\n프롬프트: " . get_prompt_type_name($input_data['prompt_type']) . "\n키워드 수: " . safe_count($input_data['keywords']) . "개" . (empty($input_data['thumbnail_url']) ? '' : "\n썸네일 URL: 제공됨");
            send_telegram_notification($telegram_msg, true);
            main_log("Data validation failed: " . implode(', ', $validation_errors));
            
            // 즉시 발행 모드면 JSON 응답, 아니면 리다이렉트
            if ($input_data['publish_mode'] === 'immediate') {
                send_json_response(false, [
                    'message' => '데이터 검증 오류: ' . implode(' | ', $validation_errors),
                    'errors' => $validation_errors
                ]);
            } else {
                redirect_to_editor(false, ['error' => '데이터 검증 오류: ' . implode(' | ', $validation_errors)]);
            }
        }
        debug_log("main_process: Data validation passed.");

        // 🔧 강화된 상품 링크 정리 (상품 분석 데이터 포함)
        $cleaned_keywords = clean_affiliate_links($input_data['keywords']);
        if (empty($cleaned_keywords)) {
            debug_log("main_process: No valid keywords with links after cleaning.");
            $telegram_msg = "❌ 유효한 키워드 및 링크 없음:\n유효한 키워드와 상품 링크가 없어서 작업을 진행할 수 없습니다.";
            send_telegram_notification($telegram_msg, true);
            main_log("No valid keywords or links found after cleaning.");
            
            if ($input_data['publish_mode'] === 'immediate') {
                send_json_response(false, [
                    'message' => '유효한 키워드 및 상품 링크를 찾을 수 없습니다.',
                    'error' => 'no_valid_links'
                ]);
            } else {
                redirect_to_editor(false, ['error' => '유효한 키워드 및 상품 링크를 찾을 수 없습니다.']);
            }
        }
        debug_log("main_process: Product links cleaned. " . safe_count($cleaned_keywords) . " keywords remain.");

        // 사용자 상세 정보 처리 (안전하게)
        $user_details_data = null;
        try {
            if (!empty($input_data['user_details'])) {
                debug_log("main_process: Processing user details...");
                $user_details_data = parse_user_details($input_data['user_details']);
                if ($user_details_data !== null && validate_user_details($user_details_data)) {
                    debug_log("main_process: User details successfully processed: " . format_user_details_summary($user_details_data));
                } else {
                    debug_log("main_process: User details provided but failed validation. Continuing without user details.");
                    $user_details_data = null;
                }
            } else {
                debug_log("main_process: No user details provided.");
            }
        } catch (Exception $e) {
            debug_log("main_process: Exception while processing user details: " . $e->getMessage());
            $user_details_data = null;
        }

        // Create queue data structure (프롬프트 타입 + 사용자 상세 정보 + 상품 분석 데이터 + 썸네일 URL 포함)
        $queue_data = [
            'queue_id' => date('YmdHis') . '_' . random_int(10000, 99999), // Unique ID
            'title' => $input_data['title'],
            'category_id' => (int)$input_data['category'],
            'category_name' => get_category_name((int)$input_data['category']),
            'prompt_type' => $input_data['prompt_type'],
            'prompt_type_name' => get_prompt_type_name($input_data['prompt_type']),
            'keywords' => $cleaned_keywords, // 🔧 이제 products_data 포함
            'user_details' => $user_details_data,
            'thumbnail_url' => !empty($input_data['thumbnail_url']) ? $input_data['thumbnail_url'] : null, // 🔧 썸네일 URL 추가
            'processing_mode' => ($input_data['publish_mode'] === 'immediate') ? 'immediate_publish' : 'link_based_with_details_and_prompt_template_and_product_data',
            'link_conversion_required' => true, // 링크 변환 필요 여부
            'conversion_status' => [
                'coupang_converted' => 0,
                'coupang_total' => 0,
                'aliexpress_converted' => 0,
                'aliexpress_total' => 0
            ],
            'created_at' => date('Y-m-d H:i:s'),
            'status' => ($input_data['publish_mode'] === 'immediate') ? 'immediate' : 'pending',
            'priority' => ($input_data['publish_mode'] === 'immediate') ? 0 : 1, // 즉시 발행은 최고 우선순위
            'attempts' => 0,
            'last_error' => null,
            'has_user_details' => ($user_details_data !== null), // 사용자 상세 정보 존재 여부
            'has_product_data' => false, // 🔧 상품 분석 데이터 존재 여부
            'has_thumbnail_url' => !empty($input_data['thumbnail_url']) // 🔧 썸네일 URL 존재 여부
        ];
        
        // 링크 카운트 및 상품 데이터 통계 계산 (안전한 count 사용)
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
                $products_summary = format_products_data_summary($cleaned_keywords);
                $telegram_success_msg .= "• " . $products_summary . "\n";
            } else {
                $telegram_success_msg .= "• 상품 분석 데이터: 없음\n";
            }
            
            // 사용자 상세 정보 알림 추가
            if ($user_details_data !== null) {
                $telegram_success_msg .= "• 사용자 상세 정보: " . format_user_details_summary($user_details_data) . "\n";
            } else {
                $telegram_success_msg .= "• 사용자 상세 정보: 제공되지 않음\n";
            }
            
            // 🔧 썸네일 URL 알림 추가
            if ($queue_data['has_thumbnail_url']) {
                $telegram_success_msg .= "• 썸네일 URL: 제공됨 (" . substr($queue_data['thumbnail_url'], 0, 50) . "...)\n";
            } else {
                $telegram_success_msg .= "• 썸네일 URL: 제공되지 않음\n";
            }
            
            $telegram_success_msg .= "• 큐 ID: " . $queue_data['queue_id'] . "\n";
            $telegram_success_msg .= "• 등록 시간: " . $queue_data['created_at'] . "\n\n";
            $telegram_success_msg .= "📊 <b>현재 큐 상태</b>\n";
            $telegram_success_msg .= "• 대기 중: " . $stats['pending'] . "개\n";
            $telegram_success_msg .= "• 처리 중: " . $stats['processing'] . "개\n";
            $telegram_success_msg .= "• 완료: " . $stats['completed'] . "개\n";
            if ($stats['failed'] > 0) {
                $telegram_success_msg .= "• 실패: " . $stats['failed'] . "개\n";
            }
            $telegram_success_msg .= "\n🚀 파일 분할 방식 큐 관리 시스템이 순차적으로 처리할 예정입니다.";
            send_telegram_notification($telegram_success_msg);
            main_log("Item successfully added to split queue system with prompt type '{$input_data['prompt_type']}', user details, product data, and thumbnail URL. Queue stats: " . json_encode($stats));

            // Redirect to editor with success message
            redirect_to_editor(true, ['success' => '1']);
        }

        debug_log("main_process: Main processing finished. Exiting.");
    
    } catch (Exception $e) {
        debug_log("main_process: Exception caught: " . $e->getMessage() . " on line " . $e->getLine() . " in " . $e->getFile());
        debug_log("main_process: Stack trace: " . $e->getTraceAsString());
        
        $error_message_for_telegram = "❌ 키워드 처리기 오류!\n\n";
        $error_message_for_telegram .= "오류 내용: " . $e->getMessage() . "\n";
        $error_message_for_telegram .= "파일: " . $e->getFile() . "\n";
        $error_message_for_telegram .= "라인: " . $e->getLine() . "\n";
        $error_message_for_telegram .= "발생 시간: " . date('Y-m-d H:i:s') . "\n";
        
        send_telegram_notification($error_message_for_telegram, true);
        main_log("EXCEPTION: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());

        // 즉시 발행 모드인지 확인
        $publish_mode = $_POST['publish_mode'] ?? 'queue';
        if ($publish_mode === 'immediate') {
            send_json_response(false, [
                'message' => '처리 중 오류가 발생했습니다: ' . $e->getMessage(),
                'error' => 'processing_error'
            ]);
        } else {
            redirect_to_editor(false, ['error' => '처리 중 오류가 발생했습니다: ' . $e->getMessage()]);
        }
    }
}

// 스크립트 실행
try {
    main_process();
} catch (Throwable $e) { // Catch all errors and exceptions
    debug_log("Fatal Error Caught in main_process: " . $e->getMessage() . " on line " . $e->getLine() . " in " . $e->getFile());
    debug_log("Stack Trace: " . $e->getTraceAsString());

    $error_message_for_telegram = "❌ 키워드 처리기 치명적 오류!\n\n";
    $error_message_for_telegram .= "오류 내용: " . $e->getMessage() . "\n";
    $error_message_for_telegram .= "파일: " . $e->getFile() . "\n";
    $error_message_for_telegram .= "라인: " . $e->getLine() . "\n";
    $error_message_for_telegram .= "발생 시간: " . date('Y-m-d H:i:s') . "\n";
    
    send_telegram_notification($error_message_for_telegram, true);
    main_log("FATAL ERROR: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());

    // 즉시 발행 모드인지 확인
    $publish_mode = $_POST['publish_mode'] ?? 'queue';
    if ($publish_mode === 'immediate') {
        send_json_response(false, [
            'message' => '치명적인 오류가 발생했습니다: ' . $e->getMessage(),
            'error' => 'fatal_error'
        ]);
    } else {
        // Redirect to editor with a generic error message
        redirect_to_editor(false, ['error' => '치명적인 오류가 발생했습니다. 관리자에게 문의하세요.']);
    }
}

exit; // Ensure script terminates after redirect
?>
