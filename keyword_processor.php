<?php
/**
 * 어필리에이트 상품 키워드 데이터 처리기 (4가지 프롬프트 템플릿 시스템 + 즉시 발행 지원 + 상품 분석 데이터 저장)
 * affiliate_editor.php에서 POST로 전송된 데이터를 처리하고 큐 파일에 저장하거나 즉시 발행합니다.
 * 워드프레스 환경에 전혀 종속되지 않으며, 순수 PHP로만 작동합니다.
 *
 * 파일 위치: /var/www/novacents/tools/keyword_processor.php
 * 버전: v4.8 (validate_user_details 함수 수정 - 중첩 배열 처리)
 */

// 1. 초기 에러 리포팅 설정 (프로덕션 모드)
error_reporting(E_ALL); // 모든 PHP 에러를 보고
ini_set('display_errors', 0); // 프로덕션에서는 에러를 브라우저에 표시하지 않음
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
            debug_log("load_env_variables: Failed to read .env file");
            return $env_vars;
        }

        foreach ($lines as $line) {
            if (strpos($line, '=') !== false && !str_starts_with(trim($line), '#')) {
                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value, " \t\n\r\0\x0B\"'");
                if (!empty($name)) {
                    $env_vars[$name] = $value;
                    debug_log("load_env_variables: Loaded {$name}");
                }
            }
        }
        debug_log("load_env_variables: Successfully loaded " . count($env_vars) . " environment variables");
    } catch (Exception $e) {
        debug_log("load_env_variables: Exception occurred: " . $e->getMessage());
    }

    return $env_vars;
}

// 6. 메인 로그 함수 (중요한 작업 로그용)
function main_log($message) {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] MAIN: {$message}\n";
    @file_put_contents(MAIN_LOG_FILE, $log_entry, FILE_APPEND | LOCK_EX);
}

// 7. JSON 응답 전송 함수 (AJAX 응답용) - 출력 버퍼 정리 강화
function send_json_response($success, $data = [], $message = '') {
    // 모든 출력 버퍼 정리
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();
    
    // 헤더 설정 (중복 방지)
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    }
    
    $response = [
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    // 이전 출력 완전 제거 후 JSON만 출력
    ob_clean();
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    debug_log("send_json_response: Clean JSON response sent. Success: " . ($success ? 'YES' : 'NO') . ", Message: " . $message);
    exit;
}

// 8. 리다이렉트 함수 (폼 제출 후 처리용)
function redirect_to_editor($success, $params = []) {
    $base_url = '/tools/affiliate_editor.php';
    $query_params = array_merge(['success' => $success ? '1' : '0'], $params);
    $redirect_url = $base_url . '?' . http_build_query($query_params);
    
    debug_log("redirect_to_editor: Redirecting to " . $redirect_url);
    if (!headers_sent()) {
        header('Location: ' . $redirect_url);
    }
    exit;
}

// 9. 텔레그램 알림 함수
function send_telegram_notification($message, $is_error = false) {
    debug_log("send_telegram_notification: Attempting to send notification. Is error: " . ($is_error ? 'YES' : 'NO'));
    
    $env_vars = load_env_variables();
    if (!isset($env_vars['TELEGRAM_BOT_TOKEN']) || !isset($env_vars['TELEGRAM_CHAT_ID'])) {
        debug_log("send_telegram_notification: Telegram credentials not found in environment variables");
        return false;
    }

    $bot_token = $env_vars['TELEGRAM_BOT_TOKEN'];
    $chat_id = $env_vars['TELEGRAM_CHAT_ID'];
    
    // 에러인 경우 이모지 추가
    $formatted_message = $is_error ? "🚨 " . $message : $message;
    
    try {
        $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
        $data = [
            'chat_id' => $chat_id,
            'text' => $formatted_message,
            'parse_mode' => 'HTML'
        ];
        
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/x-www-form-urlencoded',
                'content' => http_build_query($data),
                'timeout' => 10
            ]
        ]);
        
        $result = file_get_contents($url, false, $context);
        if ($result !== false) {
            debug_log("send_telegram_notification: Notification sent successfully");
            return true;
        } else {
            debug_log("send_telegram_notification: Failed to send notification");
            return false;
        }
    } catch (Exception $e) {
        debug_log("send_telegram_notification: Exception occurred: " . $e->getMessage());
        return false;
    }
}

// 10. 입력 데이터 유효성 검사 함수
function validate_input_data($data) {
    debug_log("validate_input_data: Starting validation process");
    
    $errors = [];
    
    // 제목 검사
    if (empty($data['title']) || strlen(trim($data['title'])) < 3) {
        $errors[] = "제목은 3자 이상이어야 합니다";
    }
    
    // 카테고리 검사
    $valid_categories = ['354', '355', '356', '12'];
    if (empty($data['category']) || !in_array($data['category'], $valid_categories)) {
        $errors[] = "올바른 카테고리를 선택해주세요";
    }
    
    // 프롬프트 타입 검사
    $valid_prompt_types = ['essential_items', 'friend_review', 'professional_analysis', 'amazing_discovery'];
    if (empty($data['prompt_type']) || !in_array($data['prompt_type'], $valid_prompt_types)) {
        $errors[] = "올바른 프롬프트 타입을 선택해주세요";
    }
    
    // 키워드 검사
    if (empty($data['keywords']) || !is_array($data['keywords'])) {
        $errors[] = "키워드 데이터가 올바르지 않습니다";
    } else {
        $valid_keywords = 0;
        foreach ($data['keywords'] as $keyword) {
            if (is_array($keyword) && !empty($keyword['name'])) {
                $valid_keywords++;
            }
        }
        if ($valid_keywords === 0) {
            $errors[] = "유효한 키워드가 없습니다";
        }
    }
    
    debug_log("validate_input_data: Validation completed. Errors: " . count($errors));
    return $errors;
}

// 11. 키워드 데이터 정리 및 링크 검증 함수
function clean_affiliate_links($keywords) {
    debug_log("clean_affiliate_links: Starting link cleaning process");
    
    $cleaned_keywords = [];
    $total_links = 0;
    $valid_links = 0;
    
    foreach ($keywords as $keyword_index => $keyword) {
        if (!is_array($keyword) || empty($keyword['name'])) {
            debug_log("clean_affiliate_links: Skipping invalid keyword at index {$keyword_index}");
            continue;
        }
        
        $cleaned_keyword = [
            'name' => trim($keyword['name']),
            'aliexpress' => [],
            'coupang' => [],
            'products_data' => [] // 🔧 상품 분석 데이터 필드 추가
        ];
        
        // AliExpress 링크 처리
        if (isset($keyword['aliexpress']) && is_array($keyword['aliexpress'])) {
            foreach ($keyword['aliexpress'] as $link) {
                $total_links++;
                if (is_string($link) && !empty(trim($link)) && strpos($link, 'aliexpress.com') !== false) {
                    $cleaned_keyword['aliexpress'][] = trim($link);
                    $valid_links++;
                }
            }
        }
        
        // 쿠팡 링크 처리
        if (isset($keyword['coupang']) && is_array($keyword['coupang'])) {
            foreach ($keyword['coupang'] as $link) {
                $total_links++;
                if (is_string($link) && !empty(trim($link)) && strpos($link, 'coupang.com') !== false) {
                    $cleaned_keyword['coupang'][] = trim($link);
                    $valid_links++;
                }
            }
        }
        
        // 🔧 상품 분석 데이터 처리
        if (isset($keyword['products_data']) && is_array($keyword['products_data'])) {
            foreach ($keyword['products_data'] as $product_data) {
                if (is_array($product_data) && !empty($product_data)) {
                    $cleaned_keyword['products_data'][] = $product_data;
                }
            }
            debug_log("clean_affiliate_links: Keyword '{$cleaned_keyword['name']}' has " . count($cleaned_keyword['products_data']) . " product data entries");
        }
        
        // 유효한 링크 또는 상품 데이터가 있는 키워드만 포함
        if (!empty($cleaned_keyword['aliexpress']) || !empty($cleaned_keyword['coupang']) || !empty($cleaned_keyword['products_data'])) {
            $cleaned_keywords[] = $cleaned_keyword;
            debug_log("clean_affiliate_links: Added keyword '{$cleaned_keyword['name']}' with " . 
                     count($cleaned_keyword['aliexpress']) . " AliExpress + " . 
                     count($cleaned_keyword['coupang']) . " Coupang links + " .
                     count($cleaned_keyword['products_data']) . " product data");
        } else {
            debug_log("clean_affiliate_links: Skipped keyword '{$cleaned_keyword['name']}' - no valid links or product data");
        }
    }
    
    debug_log("clean_affiliate_links: Processed {$total_links} total links, {$valid_links} valid links, " . count($cleaned_keywords) . " valid keywords");
    return $cleaned_keywords;
}

// 12. 사용자 세부 정보 파싱 함수
function parse_user_details($user_details_json) {
    debug_log("parse_user_details: Parsing user details data");
    
    if (empty($user_details_json)) {
        debug_log("parse_user_details: No user details provided");
        return null;
    }
    
    if (is_string($user_details_json)) {
        $parsed = json_decode($user_details_json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            debug_log("parse_user_details: JSON parsing failed: " . json_last_error_msg());
            return null;
        }
        $user_details_json = $parsed;
    }
    
    if (!is_array($user_details_json)) {
        debug_log("parse_user_details: User details is not an array");
        return null;
    }
    
    debug_log("parse_user_details: Successfully parsed user details with " . count($user_details_json) . " fields");
    return $user_details_json;
}

// 13. 사용자 세부 정보 유효성 검사 함수 (중첩 배열 처리 수정)
function validate_user_details($user_details) {
    if (!is_array($user_details) || empty($user_details)) {
        return false;
    }
    
    // 최소한 하나의 유효한 필드가 있는지 확인 (중첩 배열 처리)
    foreach ($user_details as $key => $value) {
        if (is_string($value) && !empty(trim($value))) {
            return true;
        } elseif (is_array($value) && !empty($value)) {
            // 중첩 배열인 경우 재귀적으로 검사
            if (validate_user_details($value)) {
                return true;
            }
        }
    }
    
    return false;
}

// 14. 사용자 세부 정보 요약 함수
function format_user_details_summary($user_details) {
    if (!is_array($user_details)) {
        return "없음";
    }
    
    $summary_parts = [];
    $field_count = 0;
    
    foreach ($user_details as $key => $value) {
        if (!empty(trim($value))) {
            $field_count++;
        }
    }
    
    return "{$field_count}개 필드";
}

// 15. 카테고리 이름 매핑 함수
function get_category_name($category_id) {
    $categories = [
        '354' => 'Today\'s Pick',
        '355' => '기발한 잡화점',
        '356' => '스마트 리빙',
        '12' => '우리잇템'
    ];
    return $categories[$category_id] ?? '알 수 없는 카테고리';
}

// 16. 프롬프트 타입 이름 매핑 함수
function get_prompt_type_name($prompt_type) {
    $prompt_types = [
        'essential_items' => '필수템형 🎯',
        'friend_review' => '친구 추천형 👫',
        'professional_analysis' => '전문 분석형 📊',
        'amazing_discovery' => '놀라움 발견형 ✨'
    ];
    return $prompt_types[$prompt_type] ?? '기본형';
}

// 17. URL 정리 함수
function clean_url($url) {
    return trim($url);
}

// 18. 큐 추가 래퍼 함수 (분할 시스템 사용)
function add_to_queue($queue_data) {
    debug_log("add_to_queue: Adding item to split queue system");
    
    try {
        $queue_id = add_queue_split($queue_data);
        if ($queue_id) {
            debug_log("add_to_queue: Successfully added to split queue with ID: " . $queue_id);
            return $queue_id;
        } else {
            debug_log("add_to_queue: Failed to add to split queue");
            return false;
        }
    } catch (Exception $e) {
        debug_log("add_to_queue: Exception occurred: " . $e->getMessage());
        return false;
    }
}

// 19. 큐 통계 조회 래퍼 함수 (분할 시스템 사용)
function get_queue_stats() {
    return get_queue_stats_split();
}

// 🆕 20. queue_manager.php에서의 즉시 발행 처리 함수 (시나리오 C)
// queue_manager_plan.md의 시나리오 C에 따라 큐 상태는 변경하지 않고 임시 파일 기반으로만 처리
function process_queue_manager_immediate_publish($queue_data) {
    debug_log("process_queue_manager_immediate_publish: Starting queue manager immediate publish process (Scenario C).");
    
    try {
        // 임시 파일 생성 (큐 레코드는 생성하지 않음)
        $temp_file = create_temp_file($queue_data);
        if (!$temp_file) {
            throw new Exception("임시 파일 생성 실패");
        }
        
        debug_log("process_queue_manager_immediate_publish: Temporary file created: " . $temp_file);
        
        // Python 스크립트 실행
        $result = execute_python_script($temp_file);
        
        // 결과 파싱
        if ($result['success']) {
            // 성공 알림
            $telegram_msg = "✅ 큐 관리자 즉시 발행 완료!\n";
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
        debug_log("process_queue_manager_immediate_publish: Error - " . $e->getMessage());
        
        // 실패 알림
        $telegram_msg = "❌ 큐 관리자 즉시 발행 실패!\n";
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

// 21. affiliate_editor.php에서의 즉시 발행 처리 함수 (시나리오 B)
function process_immediate_publish($queue_data) {
    debug_log("process_immediate_publish: Starting immediate publish process (Scenario B).");
    
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
    
    // Python 명령어 구성 (에러 스트림 분리)
    $command = "cd /var/www/novacents/tools && python3 {$python_script} --immediate --temp-file " . escapeshellarg($temp_file);
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

// 22. 메인 프로세스 함수
function main_process($input_data) {
    debug_log("main_process: Starting main processing function");
    debug_log("main_process: Title: " . ($input_data['title'] ?? 'N/A'));
    debug_log("main_process: Category: " . ($input_data['category'] ?? 'N/A'));
    debug_log("main_process: Prompt type: " . ($input_data['prompt_type'] ?? 'N/A'));
    debug_log("main_process: Keywords count: " . safe_count($input_data['keywords'] ?? []));
    debug_log("main_process: Publish mode: " . ($input_data['publish_mode'] ?? 'queue'));
    
    // 🆕 요청 출처 구분 (queue_manager.php vs affiliate_editor.php)
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $is_from_queue_manager = strpos($referer, 'queue_manager.php') !== false;
    debug_log("main_process: Request from queue_manager: " . ($is_from_queue_manager ? 'YES' : 'NO'));
    
    try {
        // 🔧 입력 데이터 유효성 검사
        $validation_errors = validate_input_data($input_data);
        if (!empty($validation_errors)) {
            debug_log("main_process: Validation failed. Errors: " . implode(', ', $validation_errors));
            
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

        // 🔧 큐 데이터 구조 생성 (분할 시스템 + 상품 분석 데이터 지원)
        $queue_data = [
            'title' => trim($input_data['title']),
            'category_id' => $input_data['category'],
            'category_name' => get_category_name($input_data['category']),
            'prompt_type' => $input_data['prompt_type'],
            'prompt_type_name' => get_prompt_type_name($input_data['prompt_type']),
            'keywords' => $cleaned_keywords, // 🔧 이제 products_data 포함
            'user_details' => $user_details_data,
            'thumbnail_url' => !empty($input_data['thumbnail_url']) ? clean_url($input_data['thumbnail_url']) : null, // 🔧 썸네일 URL 추가
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
        $total_analysis_data = 0;
        $total_generated_html = 0;
        
        foreach ($cleaned_keywords as $keyword_item) {
            $coupang_total += safe_count($keyword_item['coupang'] ?? []);
            $aliexpress_total += safe_count($keyword_item['aliexpress'] ?? []);
            
            // 🔧 상품 분석 데이터 통계
            if (isset($keyword_item['products_data']) && is_array($keyword_item['products_data'])) {
                $total_product_data += count($keyword_item['products_data']);
                foreach ($keyword_item['products_data'] as $product_data) {
                    if (!empty($product_data['analysis_data'])) {
                        $total_analysis_data++;
                    }
                    if (!empty($product_data['generated_html'])) {
                        $total_generated_html++;
                    }
                }
            }
        }
        
        $queue_data['conversion_status']['coupang_total'] = $coupang_total;
        $queue_data['conversion_status']['aliexpress_total'] = $aliexpress_total;
        $queue_data['has_product_data'] = ($total_product_data > 0);
        
        debug_log("main_process: Queue data structure created successfully.");
        debug_log("main_process: Link counts - Coupang: {$coupang_total}, AliExpress: {$aliexpress_total}");
        debug_log("main_process: Product data - Total entries: {$total_product_data}, Analysis: {$total_analysis_data}, HTML: {$total_generated_html}");
        debug_log("main_process: User details included: " . ($queue_data['has_user_details'] ? 'Yes' : 'No'));
        debug_log("main_process: Product data included: " . ($queue_data['has_product_data'] ? 'Yes' : 'No'));
        debug_log("main_process: Thumbnail URL included: " . ($queue_data['has_thumbnail_url'] ? 'Yes (' . $queue_data['thumbnail_url'] . ')' : 'No'));
        debug_log("main_process: Publish mode: " . $input_data['publish_mode']);

        // 🚀 즉시 발행 vs 큐 저장 분기 처리
        if ($input_data['publish_mode'] === 'immediate') {
            debug_log("main_process: Processing immediate publish request.");
            
            // 🆕 요청 출처에 따른 분기 처리
            if ($is_from_queue_manager) {
                debug_log("main_process: Using queue manager immediate publish (Scenario C).");
                process_queue_manager_immediate_publish($queue_data);
            } else {
                debug_log("main_process: Using affiliate editor immediate publish (Scenario B).");
                process_immediate_publish($queue_data);
            }
            // 위 함수들에서 JSON 응답 후 exit 됨
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
                $telegram_success_msg .= "• 상품 데이터: {$total_product_data}개 (분석: {$total_analysis_data}개, HTML: {$total_generated_html}개)\n";
            }
            
            if ($queue_data['has_user_details']) {
                $telegram_success_msg .= "• 사용자 세부사항: " . format_user_details_summary($user_details_data) . "\n";
            }
            
            if ($queue_data['has_thumbnail_url']) {
                $telegram_success_msg .= "• 썸네일 URL: 포함됨\n";
            }
            
            $telegram_success_msg .= "\n📊 <b>큐 현황</b>\n";
            $telegram_success_msg .= "• 전체: " . ($stats['total'] ?? 0) . "개\n";
            $telegram_success_msg .= "• 대기: " . ($stats['pending'] ?? 0) . "개\n";
            $telegram_success_msg .= "• 처리중: " . ($stats['processing'] ?? 0) . "개\n";
            $telegram_success_msg .= "• 완료: " . ($stats['completed'] ?? 0) . "개\n";
            $telegram_success_msg .= "• 실패: " . ($stats['failed'] ?? 0) . "개";
            
            send_telegram_notification($telegram_success_msg);
            debug_log("main_process: Telegram success notification sent.");
            main_log("New item added to split queue system successfully.");
            
            // 성공 리다이렉트
            redirect_to_editor(true, [
                'message' => '작업이 분할 큐 시스템에 성공적으로 추가되었습니다.',
                'title' => $queue_data['title'],
                'category' => $queue_data['category_name'],
                'prompt_type' => $queue_data['prompt_type_name'],
                'keywords_count' => count($cleaned_keywords),
                'coupang_links' => $coupang_total,
                'aliexpress_links' => $aliexpress_total,
                'queue_stats' => $stats
            ]);
        }
        
    } catch (Exception $e) {
        debug_log("main_process: Exception occurred: " . $e->getMessage());
        debug_log("main_process: Stack trace: " . $e->getTraceAsString());
        
        $error_message_for_telegram = "🚨 키워드 프로세서 처리 중 오류 발생!\n\n";
        $error_message_for_telegram .= "오류 메시지: " . $e->getMessage() . "\n";
        $error_message_for_telegram .= "파일: " . $e->getFile() . "\n";
        $error_message_for_telegram .= "라인: " . $e->getLine() . "\n";
        $error_message_for_telegram .= "시간: " . date('Y-m-d H:i:s') . "\n";
        if (!empty($input_data['title'])) {
            $error_message_for_telegram .= "제목: " . $input_data['title'] . "\n";
        }
        
        send_telegram_notification($error_message_for_telegram, true);
        main_log("EXCEPTION: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());

        // 즉시 발행 모드인지 확인
        $publish_mode = $_POST['publish_mode'] ?? 'queue';
        if ($publish_mode === 'immediate') {
            send_json_response(false, [
                'message' => '처리 중 오류가 발생했습니다: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ]);
        } else {
            redirect_to_editor(false, ['error' => '처리 중 오류가 발생했습니다: ' . $e->getMessage()]);
        }
    }
}

// 🚀 메인 실행 코드
debug_log("=== Main execution starting ===");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    debug_log("POST request received");
    debug_log("POST keys: " . implode(', ', array_keys($_POST)));
    
    try {
        // 🔧 POST 데이터 정리 및 검증
        $input_data = [
            'title' => trim($_POST['title'] ?? ''),
            'category' => trim($_POST['category'] ?? ''),
            'prompt_type' => trim($_POST['prompt_type'] ?? ''),
            'keywords' => [],
            'user_details' => [],
            'thumbnail_url' => trim($_POST['thumbnail_url'] ?? ''),
            'publish_mode' => trim($_POST['publish_mode'] ?? 'queue')
        ];
        
        debug_log("Input data basic fields prepared");
        debug_log("Title: " . $input_data['title']);
        debug_log("Category: " . $input_data['category']);
        debug_log("Prompt type: " . $input_data['prompt_type']);
        debug_log("Publish mode: " . $input_data['publish_mode']);
        debug_log("Thumbnail URL: " . ($input_data['thumbnail_url'] ? 'Yes' : 'No'));
        
        // 🔧 키워드 데이터 파싱 (JSON)
        if (isset($_POST['keywords']) && !empty($_POST['keywords'])) {
            debug_log("Processing keywords JSON data...");
            $keywords_json = $_POST['keywords'];
            $parsed_keywords = json_decode($keywords_json, true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($parsed_keywords)) {
                $input_data['keywords'] = $parsed_keywords;
                debug_log("Keywords successfully parsed. Count: " . count($parsed_keywords));
            } else {
                $error_msg = "키워드 JSON 파싱 실패: " . json_last_error_msg();
                debug_log($error_msg);
                main_log($error_msg);
                redirect_to_editor(false, ['error' => '키워드 데이터 형식이 올바르지 않습니다.']);
            }
        } else {
            debug_log("No keywords data provided");
        }
        
        // 🔧 사용자 세부 정보 파싱 (JSON, 선택사항)
        if (isset($_POST['user_details']) && !empty($_POST['user_details'])) {
            debug_log("Processing user details JSON data...");
            $user_details_json = $_POST['user_details'];
            $parsed_user_details = json_decode($user_details_json, true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($parsed_user_details)) {
                $input_data['user_details'] = $parsed_user_details;
                debug_log("User details successfully parsed. Fields: " . count($parsed_user_details));
            } else {
                debug_log("User details JSON parsing failed: " . json_last_error_msg() . " - Continuing without user details");
                $input_data['user_details'] = [];
            }
        } else {
            debug_log("No user details provided");
        }
        
        debug_log("All input data prepared successfully");
        debug_log("Final keywords count: " . count($input_data['keywords']));
        debug_log("Final user details count: " . count($input_data['user_details']));
        
        // 🚀 메인 프로세스 실행
        main_process($input_data);
        
    } catch (Exception $e) {
        debug_log("Fatal error in POST processing: " . $e->getMessage());
        debug_log("Stack trace: " . $e->getTraceAsString());
        main_log("Fatal error: " . $e->getMessage());
        
        // 텔레그램 치명적 오류 알림
        $telegram_fatal_msg = "🆘 키워드 프로세서 치명적 오류!\n\n";
        $telegram_fatal_msg .= "오류: " . $e->getMessage() . "\n";
        $telegram_fatal_msg .= "파일: " . $e->getFile() . "\n";
        $telegram_fatal_msg .= "라인: " . $e->getLine() . "\n";
        $telegram_fatal_msg .= "시간: " . date('Y-m-d H:i:s');
        
        send_telegram_notification($telegram_fatal_msg, true);
        
        // 즉시 발행 모드인지 확인하여 적절한 응답 전송
        $publish_mode = $_POST['publish_mode'] ?? 'queue';
        if ($publish_mode === 'immediate') {
            send_json_response(false, [
                'message' => '시스템 오류가 발생했습니다: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ]);
        } else {
            redirect_to_editor(false, ['error' => '시스템 오류가 발생했습니다: ' . $e->getMessage()]);
        }
    }
} else {
    debug_log("Non-POST request received. Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'));
    redirect_to_editor(false, ['error' => '잘못된 요청 방식입니다. POST 요청만 허용됩니다.']);
}

debug_log("=== Script execution completed ===");
?>