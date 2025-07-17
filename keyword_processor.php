<?php
/**
 * 어필리에이트 상품 키워드 데이터 처리기 (4가지 프롬프트 템플릿 시스템 지원)
 * affiliate_editor.php에서 POST로 전송된 데이터를 처리하고 큐 파일에 저장합니다.
 * 워드프레스 환경에 전혀 종속되지 않으며, 순수 PHP로만 작동합니다.
 *
 * 파일 위치: /var/www/novacents/tools/keyword_processor.php
 * 버전: v3.0 (4가지 프롬프트 템플릿 시스템 지원)
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
define('QUEUE_FILE', BASE_PATH . '/product_queue.json');

// 3. 디버깅 로그 함수 (스크립트 시작부터 끝까지 모든 흐름 기록)
function debug_log($message) {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] DEBUG: {$message}\n";
    @file_put_contents(DEBUG_LOG_FILE, $log_entry, FILE_APPEND | LOCK_EX); // @ suppress errors
}

// 스크립트 시작 시 즉시 디버그 로그 기록
debug_log("=== keyword_processor.php 시작 (4가지 프롬프트 템플릿 시스템 지원 버전) ===");
debug_log("PHP Version: " . phpversion());
debug_log("Request Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'N/A'));
debug_log("POST Data Empty: " . (empty($_POST) ? 'YES' : 'NO'));
debug_log("Script Path: " . __FILE__);
debug_log("Base Path: " . BASE_PATH);


// 4. 환경 변수 로드 함수 (워드프레스 외부에서 .env 파일을 안전하게 로드)
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
        debug_log("load_env_variables: Successfully loaded " . count($env_vars) . " variables.");
    } catch (Exception $e) {
        debug_log("load_env_variables: Exception while reading .env file: " . $e->getMessage());
    }
    return $env_vars;
}


// 5. 일반 로그 함수 (주요 작업 흐름 기록)
function main_log($message) {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] INFO: {$message}\n";
    @file_put_contents(MAIN_LOG_FILE, $log_entry, FILE_APPEND | LOCK_EX);
}


// 6. 입력값 안전 처리 함수 (htmlspecialchars, strip_tags 등 사용)
function clean_input($data) {
    $data = trim($data);
    $data = stripslashes($data); // 매직 쿼트 제거 (PHP 설정에 따라 다름)
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8'); // HTML 특수 문자 변환
    $data = strip_tags($data); // HTML 태그 제거
    return $data;
}


// 7. 텔레그램 알림 발송 함수 (cURL 우선, file_get_contents 백업)
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


// 8. 큐 파일 로드/저장/관리 함수들
function get_queue_file_path() {
    return QUEUE_FILE;
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
    debug_log("load_queue: Successfully loaded " . count($result) . " items from queue.");
    return $result;
}

function save_queue($queue) {
    $queue_file = get_queue_file_path();
    debug_log("save_queue: Attempting to save " . count($queue) . " items to " . $queue_file);

    // Use numerical values for JSON options to avoid potential parsing issues
    $json_options = 128 | 256 | 64; // JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    $json_data = json_encode($queue, $json_options);

    if ($json_data === false) {
        debug_log("save_queue: JSON encoding failed: " . json_last_error_msg());
        return false;
    }

    // Attempt to write the file with error handling
    if (@file_put_contents($queue_file, $json_data, LOCK_EX) === false) { // @ suppress errors
        $error = error_get_last();
        debug_log("save_queue: Failed to write to queue file: " . ($error['message'] ?? 'Unknown error') . ". Check file permissions.");
        return false;
    }

    debug_log("save_queue: Successfully saved queue to " . $queue_file);
    return true;
}

function add_to_queue($queue_data) {
    debug_log("add_to_queue: Adding new item to queue.");
    $queue = load_queue();
    $queue[] = $queue_data;
    return save_queue($queue);
}

function get_queue_stats() {
    debug_log("get_queue_stats: Calculating queue statistics.");
    $queue = load_queue();
    $stats = [
        'total' => count($queue),
        'pending' => 0, 'processing' => 0,
        'completed' => 0, 'failed' => 0
    ];
    foreach ($queue as $item) {
        $status = $item['status'] ?? 'pending';
        if (isset($stats[$status])) {
            $stats[$status]++;
        }
    }
    debug_log("get_queue_stats: Statistics: " . json_encode($stats));
    return $stats;
}


// 9. 리다이렉션 함수 (성공/실패 메시지 전달)
function redirect_to_editor($success, $message_params) {
    $base_url = 'affiliate_editor.php';
    $query_string = http_build_query($message_params);
    $location = $base_url . '?' . $query_string;
    debug_log("redirect_to_editor: Redirecting to {$location}");
    header("Location: {$location}");
    exit;
}


// 10. 카테고리 매핑 함수
function get_category_name($category_id) {
    $categories = [
        '354' => 'Today\'s Pick',
        '355' => '기발한 잡화점',
        '356' => '스마트 리빙',
        '12' => '우리잇템'
    ];
    return $categories[$category_id] ?? '알 수 없는 카테고리';
}

// 🚀 11. 프롬프트 타입 관련 함수들 (새로 추가)
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

// 12. 사용자 상세 정보 처리 함수들
function parse_user_details($user_details_json) {
    debug_log("parse_user_details: Parsing user details JSON.");
    
    if (empty($user_details_json)) {
        debug_log("parse_user_details: Empty user details JSON provided.");
        return null;
    }
    
    try {
        $user_details = json_decode($user_details_json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            debug_log("parse_user_details: JSON parsing error: " . json_last_error_msg());
            return null;
        }
        
        debug_log("parse_user_details: Successfully parsed user details with " . count($user_details) . " sections.");
        return $user_details;
        
    } catch (Exception $e) {
        debug_log("parse_user_details: Exception while parsing user details: " . $e->getMessage());
        return null;
    }
}

function validate_user_details($user_details) {
    debug_log("validate_user_details: Validating user details structure.");
    
    if (!is_array($user_details)) {
        debug_log("validate_user_details: User details is not an array.");
        return false;
    }
    
    $allowed_sections = ['specs', 'efficiency', 'usage', 'benefits'];
    $valid_sections = 0;
    
    foreach ($user_details as $section_name => $section_data) {
        if (in_array($section_name, $allowed_sections) && is_array($section_data)) {
            $valid_sections++;
            debug_log("validate_user_details: Valid section found: {$section_name} with " . count($section_data) . " fields.");
        } else {
            debug_log("validate_user_details: Invalid or unknown section: {$section_name}");
        }
    }
    
    debug_log("validate_user_details: Found {$valid_sections} valid sections out of " . count($user_details) . " total sections.");
    return $valid_sections > 0; // 최소 하나의 유효한 섹션이 있으면 통과
}

function format_user_details_summary($user_details) {
    $summary = [];
    
    if (isset($user_details['specs']) && is_array($user_details['specs'])) {
        $specs_count = count($user_details['specs']);
        $summary[] = "기능/스펙: {$specs_count}개 항목";
    }
    
    if (isset($user_details['efficiency']) && is_array($user_details['efficiency'])) {
        $efficiency_count = count($user_details['efficiency']);
        $summary[] = "효율성 분석: {$efficiency_count}개 항목";
    }
    
    if (isset($user_details['usage']) && is_array($user_details['usage'])) {
        $usage_count = count($user_details['usage']);
        $summary[] = "사용 시나리오: {$usage_count}개 항목";
    }
    
    if (isset($user_details['benefits']) && is_array($user_details['benefits'])) {
        $benefits_count = 0;
        if (isset($user_details['benefits']['advantages']) && is_array($user_details['benefits']['advantages'])) {
            $benefits_count += count($user_details['benefits']['advantages']);
        }
        if (isset($user_details['benefits']['precautions'])) {
            $benefits_count += 1;
        }
        $summary[] = "장점/주의사항: {$benefits_count}개 항목";
    }
    
    return implode(', ', $summary);
}


// 13. 입력 데이터 검증 및 정리 함수 (프롬프트 타입 지원 추가)
function validate_input_data($data) {
    debug_log("validate_input_data: Starting validation with prompt type and user details support.");
    $errors = [];

    if (empty($data['title']) || strlen(trim($data['title'])) < 5) {
        $errors[] = '제목은 5자 이상이어야 합니다.';
    }
    
    $valid_categories = ['354', '355', '356', '12'];
    if (empty($data['category']) || !in_array((int)$data['category'], $valid_categories)) {
        $errors[] = '유효하지 않은 카테고리입니다.';
    }

    // 🚀 프롬프트 타입 검증 추가
    if (empty($data['prompt_type']) || !validate_prompt_type($data['prompt_type'])) {
        $errors[] = '유효하지 않은 프롬프트 타입입니다.';
    }

    if (empty($data['keywords']) || !is_array($data['keywords'])) {
        $errors[] = '최소 하나의 키워드가 필요합니다.';
    } else {
        foreach ($data['keywords'] as $index => $keyword_item) {
            if (empty($keyword_item['name']) || strlen(trim($keyword_item['name'])) < 2) {
                $errors[] = "키워드 #" . ($index + 1) . "의 이름이 너무 짧습니다.";
            }

            $has_valid_link = false;
            if (!empty($keyword_item['coupang']) && is_array($keyword_item['coupang'])) {
                foreach ($keyword_item['coupang'] as $link) {
                    if (!empty($link) && filter_var(trim($link), FILTER_VALIDATE_URL)) {
                        $has_valid_link = true;
                        break;
                    }
                }
                if (count($keyword_item['coupang']) > 10) {
                    $errors[] = "키워드 '" . clean_input($keyword_item['name'] ?? '') . "'의 쿠팡 링크는 최대 10개까지 허용됩니다.";
                }
            }
            
            if (!empty($keyword_item['aliexpress']) && is_array($keyword_item['aliexpress'])) {
                foreach ($keyword_item['aliexpress'] as $link) {
                    if (!empty($link) && filter_var(trim($link), FILTER_VALIDATE_URL)) {
                        $has_valid_link = true;
                        break;
                    }
                }
            }

            if (!$has_valid_link) {
                $errors[] = "키워드 '" . clean_input($keyword_item['name'] ?? '') . "'에 유효한 상품 링크가 없습니다.";
            }
        }
    }
    
    // 사용자 상세 정보 검증 (선택사항이므로 오류는 아님)
    if (!empty($data['user_details'])) {
        $user_details = parse_user_details($data['user_details']);
        if ($user_details !== null && !validate_user_details($user_details)) {
            debug_log("validate_input_data: User details validation failed, but continuing as it's optional.");
            // 오류로 처리하지 않음 - 선택사항이므로
        }
    }
    
    debug_log("validate_input_data: Validation finished with " . count($errors) . " errors.");
    return $errors;
}

function clean_affiliate_links($keywords_raw) {
    debug_log("clean_affiliate_links: Starting link cleaning.");
    $cleaned_keywords = [];
    foreach ($keywords_raw as $index => $keyword_item) {
        $cleaned_item = [
            'name' => clean_input($keyword_item['name'] ?? ''),
            'coupang' => [],
            'aliexpress' => []
        ];

        // Clean Coupang links
        if (!empty($keyword_item['coupang']) && is_array($keyword_item['coupang'])) {
            foreach ($keyword_item['coupang'] as $link) {
                $link = trim($link);
                if (!empty($link) && filter_var($link, FILTER_VALIDATE_URL)) {
                    $cleaned_item['coupang'][] = $link;
                }
            }
        }

        // Clean AliExpress links
        if (!empty($keyword_item['aliexpress']) && is_array($keyword_item['aliexpress'])) {
            foreach ($keyword_item['aliexpress'] as $link) {
                $link = trim($link);
                if (!empty($link) && filter_var($link, FILTER_VALIDATE_URL)) {
                    $cleaned_item['aliexpress'][] = $link;
                }
            }
        }

        // Only add if keyword name is not empty and has at least one valid link
        if (!empty($cleaned_item['name']) && (!empty($cleaned_item['coupang']) || !empty($cleaned_item['aliexpress']))) {
            $cleaned_keywords[] = $cleaned_item;
        } else {
            debug_log("clean_affiliate_links: Keyword '" . ($keyword_item['name'] ?? 'N/A') . "' (index {$index}) removed due to empty name or no valid links.");
        }
    }
    debug_log("clean_affiliate_links: Finished cleaning. " . count($cleaned_keywords) . " keywords remain.");
    return $cleaned_keywords;
}


// 14. 메인 처리 로직 (4가지 프롬프트 템플릿 시스템 지원)
function main_process() {
    debug_log("main_process: Main processing started with 4-prompt template system support.");

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        debug_log("main_process: Invalid request method. Not a POST request.");
        redirect_to_editor(false, ['error' => '잘못된 요청 방식입니다. POST 메서드만 허용됩니다.']);
    }

    // Input data collection (프롬프트 타입 + 사용자 상세 정보 포함)
    $input_data = [
        'title' => clean_input($_POST['title'] ?? ''),
        'category' => clean_input($_POST['category'] ?? ''),
        'prompt_type' => clean_input($_POST['prompt_type'] ?? 'essential_items'), // 🚀 새로 추가된 프롬프트 타입
        'keywords' => $_POST['keywords'] ?? [],
        'user_details' => $_POST['user_details'] ?? null
    ];
    
    debug_log("main_process: Input data collected: " . json_encode($input_data, JSON_UNESCAPED_UNICODE));
    main_log("Input data received: Title='" . $input_data['title'] . "', Category=" . $input_data['category'] . ", Prompt Type=" . $input_data['prompt_type'] . ", Keywords=" . count($input_data['keywords']) . ", User Details=" . (empty($input_data['user_details']) ? 'No' : 'Yes') . ".");

    // Data validation
    $validation_errors = validate_input_data($input_data);
    if (!empty($validation_errors)) {
        debug_log("main_process: Validation failed. Errors: " . implode(' | ', $validation_errors));
        $telegram_msg = "❌ 데이터 검증 실패:\n\n" . implode("\n• ", $validation_errors) . "\n\n입력된 데이터:\n제목: " . $input_data['title'] . "\n카테고리: " . get_category_name($input_data['category']) . "\n프롬프트: " . get_prompt_type_name($input_data['prompt_type']) . "\n키워드 수: " . count($input_data['keywords']) . "개";
        send_telegram_notification($telegram_msg, true);
        main_log("Data validation failed: " . implode(', ', $validation_errors));
        redirect_to_editor(false, ['error' => '데이터 검증 오류: ' . implode(' | ', $validation_errors)]);
    }
    debug_log("main_process: Data validation passed.");

    // Clean product links (일반 상품 링크)
    $cleaned_keywords = clean_affiliate_links($input_data['keywords']);
    if (empty($cleaned_keywords)) {
        debug_log("main_process: No valid keywords with links after cleaning.");
        $telegram_msg = "❌ 유효한 키워드 및 링크 없음:\n유효한 키워드와 상품 링크가 없어서 작업을 진행할 수 없습니다.";
        send_telegram_notification($telegram_msg, true);
        main_log("No valid keywords or links found after cleaning.");
        redirect_to_editor(false, ['error' => '유효한 키워드 및 상품 링크를 찾을 수 없습니다.']);
    }
    debug_log("main_process: Product links cleaned. " . count($cleaned_keywords) . " keywords remain.");

    // 사용자 상세 정보 처리
    $user_details_data = null;
    if (!empty($input_data['user_details'])) {
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

    // Create queue data structure (프롬프트 타입 + 사용자 상세 정보 포함)
    $queue_data = [
        'queue_id' => date('YmdHis') . '_' . random_int(10000, 99999), // Unique ID
        'title' => $input_data['title'],
        'category_id' => (int)$input_data['category'],
        'category_name' => get_category_name((int)$input_data['category']),
        'prompt_type' => $input_data['prompt_type'], // 🚀 새로 추가된 프롬프트 타입
        'prompt_type_name' => get_prompt_type_name($input_data['prompt_type']), // 🚀 프롬프트 타입명
        'keywords' => $cleaned_keywords,
        'user_details' => $user_details_data,
        'processing_mode' => 'link_based_with_details_and_prompt_template', // 새로운 처리 모드
        'link_conversion_required' => true, // 링크 변환 필요 여부
        'conversion_status' => [
            'coupang_converted' => 0,
            'coupang_total' => 0,
            'aliexpress_converted' => 0,
            'aliexpress_total' => 0
        ],
        'created_at' => date('Y-m-d H:i:s'),
        'status' => 'pending', // Initial status
        'priority' => 1, // Default priority
        'attempts' => 0,
        'last_error' => null,
        'has_user_details' => ($user_details_data !== null) // 사용자 상세 정보 존재 여부
    ];
    
    // 링크 카운트 계산
    $coupang_total = 0;
    $aliexpress_total = 0;
    foreach ($cleaned_keywords as $keyword_item) {
        $coupang_total += count($keyword_item['coupang'] ?? []);
        $aliexpress_total += count($keyword_item['aliexpress'] ?? []);
    }
    $queue_data['conversion_status']['coupang_total'] = $coupang_total;
    $queue_data['conversion_status']['aliexpress_total'] = $aliexpress_total;
    
    debug_log("main_process: Queue data structure created. ID: " . $queue_data['queue_id']);
    debug_log("main_process: Prompt type: " . $input_data['prompt_type'] . " (" . get_prompt_type_name($input_data['prompt_type']) . ")");
    debug_log("main_process: Link counts - Coupang: {$coupang_total}, AliExpress: {$aliexpress_total}");
    debug_log("main_process: User details included: " . ($queue_data['has_user_details'] ? 'Yes' : 'No'));

    // Add to queue file
    if (!add_to_queue($queue_data)) {
        debug_log("main_process: Failed to add item to queue. check add_to_queue and save_queue functions.");
        $telegram_msg = "❌ 큐 파일 저장 실패!\n파일 권한 또는 JSON 인코딩 문제일 수 있습니다.";
        send_telegram_notification($telegram_msg, true);
        main_log("Failed to add item to queue.");
        redirect_to_editor(false, ['error' => '큐 파일 저장에 실패했습니다. 관리자에게 문의하세요.']);
    }
    debug_log("main_process: Item successfully added to queue.");

    // Get queue statistics for notification
    $stats = get_queue_stats();
    debug_log("main_process: Queue stats retrieved: " . json_encode($stats));

    $telegram_success_msg = "✅ 새 작업이 발행 대기열에 추가되었습니다!\n\n";
    $telegram_success_msg .= "📋 <b>작업 정보</b>\n";
    $telegram_success_msg .= "• 제목: " . $input_data['title'] . "\n";
    $telegram_success_msg .= "• 카테고리: " . $queue_data['category_name'] . "\n";
    $telegram_success_msg .= "• 프롬프트 타입: " . $queue_data['prompt_type_name'] . "\n"; // 🚀 프롬프트 타입 정보 추가
    $telegram_success_msg .= "• 키워드 수: " . count($cleaned_keywords) . "개\n";
    $telegram_success_msg .= "• 처리 모드: 4가지 프롬프트 템플릿 시스템\n";
    $telegram_success_msg .= "• 쿠팡 링크: " . $coupang_total . "개\n";
    $telegram_success_msg .= "• 알리익스프레스 링크: " . $aliexpress_total . "개\n";
    
    // 사용자 상세 정보 알림 추가
    if ($user_details_data !== null) {
        $telegram_success_msg .= "• 사용자 상세 정보: " . format_user_details_summary($user_details_data) . "\n";
    } else {
        $telegram_success_msg .= "• 사용자 상세 정보: 제공되지 않음\n";
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
    $telegram_success_msg .= "\n🚀 4가지 프롬프트 템플릿 자동화 시스템이 순차적으로 처리할 예정입니다.";
    send_telegram_notification($telegram_success_msg);
    main_log("Item successfully added to queue with prompt type '{$input_data['prompt_type']}' and user details. Queue stats: " . json_encode($stats));

    // Redirect to editor with success message
    redirect_to_editor(true, ['success' => '1']);

    debug_log("main_process: Main processing finished. Exiting.");
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

    // Redirect to editor with a generic error message
    redirect_to_editor(false, ['error' => '치명적인 오류가 발생했습니다. 관리자에게 문의하세요.']);
}

exit; // Ensure script terminates after redirect
?>
