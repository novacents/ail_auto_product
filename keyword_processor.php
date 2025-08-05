<?php
/**
 * 어필리에이트 상품 키워드 데이터 처리기 (4가지 프롬프트 템플릿 시스템 + 즉시 발행 지원 + 상품 분석 데이터 저장)
 * affiliate_editor.php에서 POST로 전송된 데이터를 처리하고 큐 파일에 저장하거나 즉시 발행합니다.
 * 워드프레스 환경에 전혀 종속되지 않으며, 순수 PHP로만 작동합니다.
 *
 * 파일 위치: /var/www/novacents/tools/keyword_processor.php
 * 버전: v4.9 (trim 배열 오류 수정)
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

// 5. 메인 로그 함수 (중요한 이벤트만 기록)
function main_log($message) {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] {$message}\n";
    @file_put_contents(MAIN_LOG_FILE, $log_entry, FILE_APPEND | LOCK_EX);
}

// 6. 텔레그램 알림 전송 함수 (오류 및 성공 알림용)
function send_telegram_notification($message, $is_error = false) {
    // 환경 변수에서 텔레그램 봇 토큰과 채팅 ID 가져오기
    $bot_token = getenv('TELEGRAM_BOT_TOKEN');
    $chat_id = getenv('TELEGRAM_CHAT_ID');
    
    if (empty($bot_token) || empty($chat_id)) {
        debug_log("Telegram notification skipped: Bot token or chat ID not configured");
        return false;
    }
    
    $emoji_prefix = $is_error ? "🚨" : "✅";
    $formatted_message = $emoji_prefix . " " . $message;
    
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
    $result = @file_get_contents($url, false, $context);
    
    if ($result !== false) {
        debug_log("Telegram notification sent successfully");
        return true;
    } else {
        debug_log("Failed to send Telegram notification");
        return false;
    }
}

// 7. JSON 응답 전송 함수 (즉시 발행 모드용)
function send_json_response($success, $data = []) {
    header('Content-Type: application/json; charset=utf-8');
    
    $response = [
        'success' => $success,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    if ($success) {
        $response = array_merge($response, $data);
    } else {
        $response['error'] = $data['message'] ?? 'Unknown error';
        if (isset($data['errors'])) {
            $response['errors'] = $data['errors'];
        }
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// 8. 에디터 페이지로 리다이렉트 함수 (큐 모드용)
function redirect_to_editor($success, $data = []) {
    $base_url = 'affiliate_editor.php';
    $params = [];
    
    if ($success) {
        $params['success'] = '1';
        if (isset($data['message'])) {
            $params['message'] = $data['message'];
        }
    } else {
        $params['error'] = $data['error'] ?? 'Unknown error';
    }
    
    $redirect_url = $base_url . '?' . http_build_query($params);
    
    debug_log("Redirecting to: " . $redirect_url);
    header("Location: " . $redirect_url);
    exit;
}

// 9. URL 정리 함수
function clean_url($url) {
    $url = trim($url);
    if (empty($url)) {
        return null;
    }
    
    // URL 유효성 검사
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }
    
    return $url;
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
    
    // 키워드 데이터 검사
    if (empty($data['keywords']) || !is_array($data['keywords']) || count($data['keywords']) === 0) {
        $errors[] = "키워드 데이터가 올바르지 않습니다";
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
            'products_data' => [] // 🔧 상품 분석 데이터 저장 공간
        ];
        
        // AliExpress 링크 처리
        if (isset($keyword['aliexpress']) && is_array($keyword['aliexpress'])) {
            foreach ($keyword['aliexpress'] as $link) {
                if (!empty($link) && is_string($link)) {
                    $cleaned_link = trim($link);
                    if (filter_var($cleaned_link, FILTER_VALIDATE_URL)) {
                        $cleaned_keyword['aliexpress'][] = $cleaned_link;
                        $valid_links++;
                    }
                    $total_links++;
                }
            }
        }
        
        // Coupang 링크 처리
        if (isset($keyword['coupang']) && is_array($keyword['coupang'])) {
            foreach ($keyword['coupang'] as $link) {
                if (!empty($link) && is_string($link)) {
                    $cleaned_link = trim($link);
                    if (filter_var($cleaned_link, FILTER_VALIDATE_URL)) {
                        $cleaned_keyword['coupang'][] = $cleaned_link;
                        $valid_links++;
                    }
                    $total_links++;
                }
            }
        }
        
        // 🔧 상품 분석 데이터 처리 (products_data 필드)
        if (isset($keyword['products_data']) && is_array($keyword['products_data'])) {
            $valid_products_data = [];
            
            foreach ($keyword['products_data'] as $product_data) {
                if (is_array($product_data)) {
                    // 필수 필드 확인
                    if (isset($product_data['url']) && !empty($product_data['url'])) {
                        $valid_products_data[] = $product_data;
                    } else {
                        debug_log("clean_affiliate_links: Invalid product data - missing URL");
                    }
                }
            }
            
            $cleaned_keyword['products_data'] = $valid_products_data;
            debug_log("clean_affiliate_links: Keyword '{$keyword['name']}' has " . count($valid_products_data) . " product data entries");
        }
        
        // 키워드에 유효한 링크가 하나라도 있으면 포함
        if (count($cleaned_keyword['aliexpress']) > 0 || count($cleaned_keyword['coupang']) > 0) {
            $aliexpress_count = count($cleaned_keyword['aliexpress']);
            $coupang_count = count($cleaned_keyword['coupang']);
            $products_data_count = count($cleaned_keyword['products_data']);
            
            debug_log("clean_affiliate_links: Added keyword '{$keyword['name']}' with {$aliexpress_count} AliExpress + {$coupang_count} Coupang links + {$products_data_count} product data");
            $cleaned_keywords[] = $cleaned_keyword;
        }
    }
    
    debug_log("clean_affiliate_links: Processed {$total_links} total links, {$valid_links} valid links, " . count($cleaned_keywords) . " valid keywords");
    return $cleaned_keywords;
}

// 12. 사용자 상세 정보 파싱 함수 (JSON 구조 처리)
function parse_user_details($user_details_input) {
    debug_log("parse_user_details: Parsing user details data");
    
    if (empty($user_details_input)) {
        debug_log("parse_user_details: Empty user details input provided");
        return null;
    }
    
    try {
        // 이미 배열인 경우 (POST 데이터에서 파싱된 경우)
        if (is_array($user_details_input)) {
            debug_log("parse_user_details: User details already parsed as array");
            return $user_details_input;
        }
        
        // JSON 문자열인 경우 디코딩
        if (is_string($user_details_input)) {
            $decoded = json_decode($user_details_input, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                debug_log("parse_user_details: Successfully decoded JSON user details");
                return $decoded;
            } else {
                debug_log("parse_user_details: JSON decode failed: " . json_last_error_msg());
                return null;
            }
        }
        
        debug_log("parse_user_details: Invalid user details input type: " . gettype($user_details_input));
        return null;
        
    } catch (Exception $e) {
        debug_log("parse_user_details: Exception during parsing: " . $e->getMessage());
        return null;
    }
}

// 13. 사용자 상세 정보 유효성 검사 함수
function validate_user_details($user_details) {
    debug_log("validate_user_details: Starting validation");
    
    if (!is_array($user_details) || empty($user_details)) {
        debug_log("validate_user_details: Invalid input - not array or empty");
        return false;
    }
    
    $required_fields = ['age', 'gender', 'lifestyle', 'interests'];
    $valid_field_count = 0;
    
    foreach ($required_fields as $field) {
        if (isset($user_details[$field])) {
            $value = $user_details[$field];
            
            // 중첩 배열인 경우 처리
            if (is_array($value)) {
                // 배열이 비어있지 않으면 유효한 것으로 간주
                if (!empty($value)) {
                    $valid_field_count++;
                    debug_log("validate_user_details: Field '{$field}' is valid array with " . count($value) . " items");
                }
            } else {
                // 문자열인 경우 비어있지 않으면 유효
                if (!empty(trim($value))) {
                    $valid_field_count++;
                    debug_log("validate_user_details: Field '{$field}' is valid string");
                }
            }
        }
    }
    
    // 최소 2개 이상의 필드가 유효해야 함
    $is_valid = ($valid_field_count >= 2);
    debug_log("validate_user_details: Validation result - {$valid_field_count}/4 fields valid, Overall: " . ($is_valid ? 'PASS' : 'FAIL'));
    
    return $is_valid;
}

// 14. 사용자 상세 정보 요약 생성 함수 (🔧 배열 처리 수정)
function format_user_details_summary($user_details) {
    if (!is_array($user_details)) {
        return 'Invalid user details';
    }
    
    $summary_parts = [];
    $field_count = 0;
    
    foreach ($user_details as $key => $value) {
        if (is_string($value) && !empty(trim($value))) {
            $field_count++;
        } elseif (is_array($value) && !empty($value)) {
            $field_count++;
        }
    }
    
    return "{$field_count}개 필드";
}

// 15. 카테고리 이름 가져오기 함수
function get_category_name($category_id) {
    $categories = [
        '354' => '스마트 리빙',
        '355' => '패션 & 뷰티',
        '356' => '전자기기',
        '12' => '기타'
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
        $queue_id = add_queue_split($queue_data);
        
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
    debug_log("process_immediate_publish: Starting immediate publish process");
    
    try {
        // 임시 파일 디렉토리 생성
        if (!is_dir(TEMP_DIR)) {
            if (!mkdir(TEMP_DIR, 0755, true)) {
                throw new Exception("임시 디렉토리 생성 실패: " . TEMP_DIR);
            }
        }
        
        // 임시 파일 경로 생성
        $temp_filename = 'immediate_' . uniqid() . '.json';
        $temp_filepath = TEMP_DIR . '/' . $temp_filename;
        
        // 임시 파일에 저장
        $json_content = json_encode($queue_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($temp_filepath, $json_content) === false) {
            throw new Exception("임시 파일 저장 실패: " . $temp_filepath);
        }
        
        debug_log("process_immediate_publish: Temporary file created: " . $temp_filepath);
        
        // Python 스크립트 실행
        $python_script = '/var/www/novacents/tools/auto_post_products.py';
        $command = "cd /var/www/novacents/tools && /usr/bin/python3 {$python_script} --mode immediate --immediate-file {$temp_filepath} 2>&1";
        
        debug_log("process_immediate_publish: Executing command: " . $command);
        
        $output = shell_exec($command);
        debug_log("process_immediate_publish: Python script output: " . $output);
        
        // Python 스크립트 결과 파싱
        $result = parse_python_output($output);
        
        // 성공/실패에 따른 처리
        if ($result['success']) {
            debug_log("process_immediate_publish: Immediate publish successful");
            
            $success_msg = "✅ 즉시 발행이 완료되었습니다!\n\n";
            $success_msg .= "📋 작업 정보\n";
            $success_msg .= "• 제목: " . $queue_data['title'] . "\n";
            $success_msg .= "• 카테고리: " . $queue_data['category_name'] . "\n";
            $success_msg .= "• 키워드 수: " . count($queue_data['keywords']) . "개\n";
            $success_msg .= "• 처리 시간: " . date('Y-m-d H:i:s');
            
            send_telegram_notification($success_msg);
            
            send_json_response(true, [
                'message' => '즉시 발행이 완료되었습니다.',
                'post_url' => $result['post_url'] ?? null
            ]);
        } else {
            debug_log("process_immediate_publish: Immediate publish failed: " . $result['error']);
            
            $error_msg = "❌ 즉시 발행 실패!\n\n";
            $error_msg .= "제목: " . $queue_data['title'] . "\n";
            $error_msg .= "오류: " . $result['error'] . "\n";
            $error_msg .= "시간: " . date('Y-m-d H:i:s');
            
            send_telegram_notification($error_msg, true);
            
            send_json_response(false, [
                'message' => '즉시 발행 실패: ' . $result['error']
            ]);
        }
        
    } catch (Exception $e) {
        debug_log("process_immediate_publish: Exception occurred: " . $e->getMessage());
        
        $error_msg = "🚨 즉시 발행 처리 중 시스템 오류!\n\n";
        $error_msg .= "오류: " . $e->getMessage() . "\n";
        $error_msg .= "제목: " . ($queue_data['title'] ?? 'N/A') . "\n";
        $error_msg .= "시간: " . date('Y-m-d H:i:s');
        
        send_telegram_notification($error_msg, true);
        
        send_json_response(false, [
            'message' => '시스템 오류가 발생했습니다: ' . $e->getMessage()
        ]);
    }
}

// 20. queue_manager.php 즉시 발행 처리 함수 (Scenario C)
function process_queue_manager_immediate_publish($queue_data) {
    debug_log("process_queue_manager_immediate_publish: Starting queue manager immediate publish");
    
    // queue_manager.php에서 호출된 경우는 기존 큐를 수정하지 않고
    // 임시 파일로만 처리 (중복 생성 방지)
    process_immediate_publish($queue_data);
}

// 21. Python 스크립트 출력 파싱 함수
function parse_python_output($output) {
    debug_log("parse_python_output: Parsing Python script output");
    
    if (empty($output)) {
        return [
            'success' => false,
            'error' => 'Python 스크립트에서 출력이 없습니다.'
        ];
    }
    
    // 성공 패턴 검사
    if (strpos($output, '워드프레스 발행 성공') !== false) {
        // URL 추출 시도
        if (preg_match('/워드프레스 발행 성공: (https?:\/\/[^\s]+)/', $output, $matches)) {
            return [
                'success' => true,
                'post_url' => $matches[1],
                'output' => $output
            ];
        } else {
            return [
                'success' => true,
                'output' => $output
            ];
        }
    }
    
    // 실패 패턴 검사
    if (strpos($output, '워드프레스 발행 실패') !== false || strpos($output, 'Error') !== false || strpos($output, '오류') !== false) {
        return [
            'success' => false,
            'error' => 'Python 스크립트 실행 오류',
            'output' => $output
        ];
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
            if (isset($keyword_item['coupang']) && is_array($keyword_item['coupang'])) {
                $coupang_total += safe_count($keyword_item['coupang']);
            }
            if (isset($keyword_item['aliexpress']) && is_array($keyword_item['aliexpress'])) {
                $aliexpress_total += safe_count($keyword_item['aliexpress']);
            }
            
            // 🔧 상품 분석 데이터 통계 계산
            if (isset($keyword_item['products_data']) && is_array($keyword_item['products_data'])) {
                $product_data_count = safe_count($keyword_item['products_data']);
                $total_product_data += $product_data_count;
                
                // 상품별 분석 데이터 카운트
                foreach ($keyword_item['products_data'] as $product_data) {
                    if (isset($product_data['analysis_data'])) {
                        $total_analysis_data++;
                    }
                    if (isset($product_data['generated_html'])) {
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
            
            // 🔧 사용자 상세 정보 추가
            if ($queue_data['has_user_details']) {
                $telegram_success_msg .= "• 사용자 정보: " . format_user_details_summary($user_details_data) . "\n";
            }
            
            // 🔧 썸네일 URL 정보 추가
            if ($queue_data['has_thumbnail_url']) {
                $telegram_success_msg .= "• 썸네일 URL: 제공됨\n";
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
                'title' => $input_data['title'],
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

// 🚀 메인 실행 부분
debug_log("Script execution point reached");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    debug_log("POST request received");
    debug_log("POST keys: " . implode(', ', array_keys($_POST)));
    
    try {
        // 🚨 빈 데이터 요청 필터링 (자동 새로고침으로 인한 스팸 방지)
        $title = trim($_POST['title'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $keywords_raw = $_POST['keywords'] ?? '';
        
        if (empty($title) && empty($category) && empty($keywords_raw)) {
            debug_log("Empty request detected - ignoring to prevent spam notifications");
            debug_log("Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A'));
            debug_log("HTTP Referer: " . ($_SERVER['HTTP_REFERER'] ?? 'N/A'));
            debug_log("User Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'N/A'));
            
            // JSON 응답 모드인지 확인
            $publish_mode = $_POST['publish_mode'] ?? '';
            if ($publish_mode === 'immediate') {
                echo json_encode(['success' => false, 'message' => 'Empty request ignored']);
            }
            exit; // 조용히 종료 (텔레그램 알림 없음)
        }

        // 🔧 POST 데이터 정리 및 검증
        $input_data = [
            'title' => $title,
            'category' => $category,
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
        $telegram_fatal_msg .= "파일: " . $e->getFile() . ":" . $e->getLine() . "\n";
        $telegram_fatal_msg .= "시간: " . date('Y-m-d H:i:s');
        
        send_telegram_notification($telegram_fatal_msg, true);
        
        // 클라이언트 응답
        if (isset($_POST['publish_mode']) && $_POST['publish_mode'] === 'immediate') {
            send_json_response(false, [
                'message' => '시스템에서 치명적 오류가 발생했습니다.',
                'error' => $e->getMessage()
            ]);
        } else {
            redirect_to_editor(false, ['error' => '시스템에서 치명적 오류가 발생했습니다.']);
        }
    }
} else {
    debug_log("Non-POST request received - redirecting to editor");
    header('Location: affiliate_editor.php');
    exit;
}

debug_log("=== keyword_processor.php 종료 ===");
?>