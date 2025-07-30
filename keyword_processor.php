<?php
session_start();

// 🚀 분할 파일 시스템 기반 큐 관리자 - 개선된 메모리 효율성 및 디버깅 강화
// 작성일: 2025-01-24
// 버전: 3.2
// 기능: 
// - 개별 JSON 파일로 작업 관리 (메모리 효율적)
// - 상태별 디렉토리 구조: pending/ → processing/ → completed/ → failed/
// - 즉시 발행과 일반 큐 분기 처리
// - 프롬프트 타입별 맞춤 처리 지원
// - 사용자 세부 정보 및 상품 데이터 통합 지원
// - 썸네일 URL 지원 추가 🔧
// - 강화된 오류 처리 및 디버깅

// 디버깅 및 로깅 설정
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 현재 파일 디렉토리를 기준으로 경로 설정
$current_dir = dirname(__FILE__);

// 경로 상수 정의
define('TOOLS_DIR', $current_dir);
define('QUEUE_DIR', TOOLS_DIR . '/queues');
define('QUEUE_FILE', '/var/www/novacents/tools/product_queue.json');
define('LOG_FILE', TOOLS_DIR . '/logs/queue_manager.log');

// 텔레그램 설정
define('TELEGRAM_BOT_TOKEN', getenv('TELEGRAM_BOT_TOKEN'));
define('TELEGRAM_CHAT_ID', getenv('TELEGRAM_CHAT_ID'));

// 분할 큐 시스템 디렉토리 구조
$queue_dirs = [
    'pending' => QUEUE_DIR . '/pending',
    'processing' => QUEUE_DIR . '/processing', 
    'completed' => QUEUE_DIR . '/completed',
    'failed' => QUEUE_DIR . '/failed'
];

// 분할 큐 시스템 초기화
foreach ($queue_dirs as $dir) {
    if (!file_exists($dir)) {
        if (!mkdir($dir, 0755, true)) {
            die("Failed to create queue directory: {$dir}");
        }
    }
}

// 1. 로깅 및 디버깅 함수들
function debug_log($message) {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] DEBUG: {$message}" . PHP_EOL;
    file_put_contents(LOG_FILE, $log_entry, FILE_APPEND | LOCK_EX);
}

function main_log($message) {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] MAIN: {$message}" . PHP_EOL;
    file_put_contents(LOG_FILE, $log_entry, FILE_APPEND | LOCK_EX);
}

// POST 데이터 상세 디버깅 (메모리 효율성 고려)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    debug_log("=== POST Request Started ===");
    foreach (['title', 'category', 'prompt_type'] as $key) {
        if (isset($_POST[$key])) {
            debug_log("POST[{$key}]: " . substr($_POST[$key], 0, 100) . (strlen($_POST[$key]) > 100 ? '...' : ''));
        }
    }
    
    // JSON 데이터 처리 (메모리 효율성 고려)
    foreach (['keywords', 'user_details'] as $key) {
        if (isset($_POST[$key])) {
            debug_log("POST[{$key}] (raw length): " . strlen($_POST[$key]));
            $decoded = json_decode($_POST[$key], true);
            if ($decoded !== null) {
                debug_log("POST[{$key}] (decoded type): " . gettype($decoded));
                debug_log("POST[{$key}] (decoded count): " . safe_count($decoded));
                if (is_array($decoded) && !empty($decoded)) {
                    debug_log("POST[{$key}] (first item): " . json_encode($decoded[0], JSON_UNESCAPED_UNICODE));
                    
                    // 🔧 products_data 확인
                    if (isset($decoded[0]['products_data'])) {
                        debug_log("POST[{$key}] - products_data found in first item: " . safe_count($decoded[0]['products_data']) . " products");
                    }
                }
            } else {
                debug_log("POST[{$key}] JSON decoding failed: " . json_last_error_msg());
            }
        }
    }
    
    // 🔧 썸네일 URL 디버깅
    if (isset($_POST['thumbnail_url'])) {
        debug_log("POST[thumbnail_url]: " . $_POST['thumbnail_url']);
    }
    
    debug_log("=== POST Data Debug Complete ===");
}

// 2. 유틸리티 함수들
function safe_count($data) {
    return is_array($data) ? count($data) : 0;
}

function clean_input($data) {
    if (is_array($data)) {
        return array_map('clean_input', $data);
    }
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

function sanitize_text_field($str) {
    return trim(strip_tags($str));
}

function get_category_name($category_id) {
    $categories = [
        1 => "생활용품",
        2 => "패션/뷰티", 
        3 => "홈/인테리어",
        4 => "스포츠/레저",
        5 => "자동차용품",
        6 => "디지털/가전",
        7 => "반려동물용품"
    ];
    return $categories[$category_id] ?? "알 수 없음";
}

function get_prompt_type_name($prompt_type) {
    $prompt_types = [
        'default' => "기본형 (일반적인 상품 소개)",
        'blog_casual' => "블로그형 (캐주얼한 후기 스타일)",
        'review_detailed' => "리뷰형 (상세한 사용 후기)",
        'comparison' => "비교형 (다른 제품과의 비교)",
        'howto_guide' => "가이드형 (사용법 및 팁 제공)"
    ];
    return $prompt_types[$prompt_type] ?? "알 수 없음";
}

// 3. 텔레그램 알림 함수
function send_telegram_notification($message) {
    if (empty(TELEGRAM_BOT_TOKEN) || empty(TELEGRAM_CHAT_ID)) {
        debug_log("Telegram notification skipped: Missing bot token or chat ID");
        return false;
    }
    
    $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage";
    $data = [
        'chat_id' => TELEGRAM_CHAT_ID,
        'text' => $message,
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
    
    if ($result === false) {
        debug_log("Failed to send Telegram notification");
        return false;
    }
    
    debug_log("Telegram notification sent successfully");
    return true;
}

// 4. 분할 큐 시스템 함수들

// 4-1. 큐 디렉토리 확인 및 생성
function ensure_queue_directories() {
    global $queue_dirs;
    
    foreach ($queue_dirs as $name => $path) {
        if (!file_exists($path)) {
            if (!mkdir($path, 0755, true)) {
                debug_log("Failed to create queue directory: {$path}");
                return false;
            }
            debug_log("Created queue directory: {$path}");
        }
        
        if (!is_writable($path)) {
            debug_log("Queue directory is not writable: {$path}");
            return false;
        }
    }
    
    return true;
}

// 4-2. 개별 큐 파일 저장 (메모리 효율적)
function save_queue_item($queue_data) {
    global $queue_dirs;
    
    if (!ensure_queue_directories()) {
        debug_log("save_queue_item: Failed to ensure queue directories");
        return false;
    }
    
    // 고유한 파일명 생성 (타임스탬프 + 마이크로초 + 랜덤)
    $timestamp = date('YmdHis');
    $microseconds = substr(microtime(), 2, 6);
    $random = mt_rand(10000, 99999);
    $filename = "queue_{$timestamp}_{$microseconds}_{$random}.json";
    
    $queue_file = $queue_dirs['pending'] . '/' . $filename;
    
    // 파일명을 큐 데이터에 추가
    $queue_data['filename'] = $filename;
    $queue_data['created_at'] = date('Y-m-d H:i:s');
    $queue_data['status'] = 'pending';
    
    // JSON 옵션 설정
    if (!file_exists(dirname($queue_file))) {
        if (!mkdir(dirname($queue_file), 0755, true)) {
            debug_log("save_queue_item: Failed to create directory: " . dirname($queue_file));
            return false;
        }
    }
    
    if (file_exists(dirname($queue_file)) && !is_writable(dirname($queue_file))) {
        debug_log("save_queue_item: Directory is not writable: " . dirname($queue_file));
        return false;
    }

    // Use constant names for JSON options to ensure proper functionality
    $json_options = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $json_data = json_encode($queue_data, $json_options);

    if ($json_data === false) {
        debug_log("save_queue_item: JSON encoding failed: " . json_last_error_msg());
        return false;
    }

    // 파일 쓰기 권한 확인
    if (file_exists($queue_file) && !is_writable($queue_file)) {
        debug_log("save_queue_item: Queue file exists but is not writable: " . $queue_file);
        return false;
    }

    // Attempt to write the file with error handling
    $write_result = @file_put_contents($queue_file, $json_data, LOCK_EX);
    if ($write_result === false) {
        $error = error_get_last();
        debug_log("save_queue_item: Failed to write to queue file: " . ($error['message'] ?? 'Unknown error') . ". Check file permissions.");
        return false;
    }

    debug_log("save_queue_item: Successfully saved queue item: {$filename}");
    return $filename;
}

// 4-3. 레거시 큐 시스템 지원 (하위 호환성)
function save_legacy_queue($queue_data) {
    debug_log("save_legacy_queue: Attempting to save legacy queue data");
    
    $existing_queue = [];
    if (file_exists(QUEUE_FILE)) {
        $content = file_get_contents(QUEUE_FILE);
        if ($content !== false) {
            $existing_queue = json_decode($content, true) ?? [];
        }
    }
    
    $existing_queue[] = $queue_data;
    
    $json_options = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $json_content = json_encode($existing_queue, $json_options);
    
    if ($json_content === false) {
        debug_log("save_legacy_queue: JSON encoding failed: " . json_last_error_msg());
        return false;
    }
    
    $result = file_put_contents(QUEUE_FILE, $json_content, LOCK_EX);
    if ($result === false) {
        debug_log("save_legacy_queue: Failed to write legacy queue file");
        return false;
    }
    
    debug_log("save_legacy_queue: Successfully saved legacy queue");
    return true;
}

// 4-4. 큐 통계 조회 (분할 시스템 기반)
function get_queue_stats() {
    global $queue_dirs;
    
    try {
        $stats = [
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0
        ];
        
        foreach ($queue_dirs as $status => $dir) {
            if (is_dir($dir)) {
                $files = glob($dir . '/queue_*.json');
                $stats[$status] = count($files);
            }
        }
        
        debug_log("get_queue_stats: Statistics from split system: " . json_encode($stats));
        return $stats;
    } catch (Exception $e) {
        debug_log("get_queue_stats: Exception occurred: " . $e->getMessage());
        
        // 폴백: 레거시 시스템에서 통계 조회
        $stats = ['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
        if (file_exists(QUEUE_FILE)) {
            $content = file_get_contents(QUEUE_FILE);
            if ($content !== false) {
                $queue = json_decode($content, true) ?? [];
                $stats[$status]++;
            }
        }
        debug_log("get_queue_stats: Fallback statistics: " . json_encode($stats));
        return $stats;
    }
}

// 5. 응답 처리 함수들
function send_json_response($success, $data = []) {
    $response = [
        'success' => $success,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    $response = array_merge($response, $data);
    
    debug_log("send_json_response: Sending JSON response: " . json_encode($response, JSON_UNESCAPED_UNICODE));
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

function redirect_to_editor($success, $params = []) {
    $url_params = [];
    if ($success) {
        $url_params['success'] = '1';
    }
    $url_params = array_merge($url_params, $params);
    
    $redirect_url = 'affiliate_editor.php';
    if (!empty($url_params)) {
        $redirect_url .= '?' . http_build_query($url_params);
    }
    
    debug_log("redirect_to_editor: Redirecting to {$redirect_url}");
    header("Location: {$redirect_url}");
    exit;
}

// 6. 즉시 발행 처리 함수
function process_immediate_publish($queue_data) {
    debug_log("process_immediate_publish: Starting immediate publish process");
    
    try {
        // Python 스크립트 경로들
        $script_paths = [
            __DIR__ . '/auto_post_products.py',
            '/var/www/novacents/tools/auto_post_products.py'
        ];
        
        $found_script = false;
        $output = '';
        $error_output = '';
        
        foreach ($script_paths as $script_path) {
            if (file_exists($script_path)) {
                debug_log("process_immediate_publish: Found script at: {$script_path}");
                
                // 임시 파일에 큐 데이터 저장
                $temp_file = create_temp_file($queue_data);
                if (!$temp_file) {
                    debug_log("process_immediate_publish: Failed to create temporary file");
                    continue;
                }
                
                // Python 스크립트 실행
                $command = "cd " . escapeshellarg(dirname($script_path)) . " && python3 " . escapeshellarg($script_path) . " --immediate --file " . escapeshellarg($temp_file) . " 2>&1";
                debug_log("process_immediate_publish: Executing command: {$command}");
                
                $output = shell_exec($command);
                debug_log("process_immediate_publish: Script output: " . substr($output ?? '', 0, 1000));
                
                // 임시 파일 정리
                if (file_exists($temp_file)) {
                    unlink($temp_file);
                    debug_log("process_immediate_publish: Cleaned up temporary file: {$temp_file}");
                }
                
                $found_script = true;
                break;
            }
        }
        
        if (!$found_script) {
            debug_log("process_immediate_publish: No Python script found in any of the expected locations");
            send_json_response(false, [
                'message' => 'Python 스크립트를 찾을 수 없습니다.',
                'error' => 'script_not_found'
            ]);
        }
        
        // 출력 분석 및 결과 처리
        if (empty($output)) {
            debug_log("process_immediate_publish: Empty output from Python script");
            send_json_response(false, [
                'message' => 'Python 스크립트에서 응답을 받지 못했습니다.',
                'error' => 'empty_output'
            ]);
        }
        
        // 성공/실패 판단 (출력 내용 기반)
        if (strpos($output, '[🎉]') !== false || strpos($output, '발행된 글 주소:') !== false) {
            // 성공 처리
            preg_match('/발행된 글 주소: (https?:\/\/[^\s]+)/', $output, $matches);
            $post_url = $matches[1] ?? null;
            
            debug_log("process_immediate_publish: Immediate publish successful" . ($post_url ? " - URL: {$post_url}" : ""));
            
            send_json_response(true, [
                'message' => '글이 성공적으로 발행되었습니다!',
                'post_url' => $post_url,
                'output' => $output
            ]);
        } else {
            // 실패 처리
            debug_log("process_immediate_publish: Immediate publish failed - Output: " . substr($output, 0, 500));
            
            send_json_response(false, [
                'message' => '글 발행 중 오류가 발생했습니다.',
                'error' => 'publish_failed',
                'output' => $output
            ]);
        }
        
    } catch (Exception $e) {
        debug_log("process_immediate_publish: Exception occurred: " . $e->getMessage());
        send_json_response(false, [
            'message' => '즉시 발행 처리 중 예외가 발생했습니다.',
            'error' => 'exception',
            'details' => $e->getMessage()
        ]);
    }
}

// 7. 임시 파일 생성 함수 (즉시 발행용)
function create_temp_file($queue_data) {
    $temp_dir = sys_get_temp_dir();
    $temp_file = tempnam($temp_dir, 'queue_immediate_');
    
    if (!$temp_file) {
        debug_log("create_temp_file: Failed to create temporary file");
        return false;
    }
    
    // 임시 파일용 데이터 준비
    $temp_data = $queue_data;
    $temp_data['processing_mode'] = 'immediate_publish';
    $temp_data['created_at'] = date('Y-m-d H:i:s');
    
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
    
    debug_log("create_temp_file: Created temporary file: {$temp_file}");
    return $temp_file;
}

// 8. 큐 아이템 추가 함수 (통합)
function add_to_queue($input_data) {
    debug_log("add_to_queue: Starting queue addition process");
    
    // 분할 큐 시스템에 추가
    $filename = save_queue_item($input_data);
    if (!$filename) {
        debug_log("add_to_queue: Failed to save to split queue system");
        return false;
    }
    
    // 레거시 시스템에도 추가 (하위 호환성)
    $legacy_result = save_legacy_queue($input_data);
    if (!$legacy_result) {
        debug_log("add_to_queue: Warning - Failed to save to legacy queue system");
    }
    
    debug_log("add_to_queue: Successfully added item to queue systems - Filename: {$filename}");
    return $filename;
}

// 15. 입력 데이터 검증 및 정리 함수 (프롬프트 타입 지원 추가) - 🔧 강화된 디버깅
function validate_input_data($data) {
    debug_log("validate_input_data: Starting validation with prompt type and user details support.");
    debug_log("validate_input_data: Input data structure: " . json_encode(array_keys($data), JSON_UNESCAPED_UNICODE));
    
    $errors = [];

    // 제목 검증
    if (empty($data['title']) || strlen(trim($data['title'])) < 5) {
        $errors[] = "제목은 5자 이상이어야 합니다.";
        debug_log("validate_input_data: Title validation failed.");
    } else {
        debug_log("validate_input_data: Title validation passed: " . substr($data['title'], 0, 50) . "...");
    }

    // 카테고리 검증
    if (empty($data['category']) || !is_numeric($data['category']) || $data['category'] < 1 || $data['category'] > 7) {
        $errors[] = "유효한 카테고리를 선택해야 합니다.";
        debug_log("validate_input_data: Category validation failed. Value: " . ($data['category'] ?? 'null'));
    } else {
        debug_log("validate_input_data: Category validation passed: " . get_category_name($data['category']));
    }

    // 프롬프트 타입 검증 🔧
    $valid_prompt_types = ['default', 'blog_casual', 'review_detailed', 'comparison', 'howto_guide'];
    if (empty($data['prompt_type']) || !in_array($data['prompt_type'], $valid_prompt_types)) {
        $errors[] = "유효한 프롬프트 타입을 선택해야 합니다.";
        debug_log("validate_input_data: Prompt type validation failed. Value: " . ($data['prompt_type'] ?? 'null'));
    } else {
        debug_log("validate_input_data: Prompt type validation passed: " . get_prompt_type_name($data['prompt_type']));
    }

    // 키워드 및 상품 데이터 검증
    if (empty($data['keywords']) || !is_array($data['keywords']) || count($data['keywords']) === 0) {
        $errors[] = "최소 하나의 키워드가 필요합니다.";
        debug_log("validate_input_data: Keywords validation failed - no keywords provided.");
    } else {
        debug_log("validate_input_data: Processing " . safe_count($data['keywords']) . " keywords...");
        
        foreach ($data['keywords'] as $index => $keyword_item) {
            debug_log("validate_input_data: Processing keyword {$index}: " . json_encode($keyword_item, JSON_UNESCAPED_UNICODE));
            
            if (empty($keyword_item['name']) || strlen(trim($keyword_item['name'])) < 2) {
                $errors[] = "키워드 #" . ($index + 1) . "의 이름이 너무 짧습니다.";
                debug_log("validate_input_data: Keyword {$index} name validation failed.");
                continue;
            }

            // 알리익스프레스 URL 검증
            if (empty($keyword_item['aliexpress']) || !is_array($keyword_item['aliexpress']) || count($keyword_item['aliexpress']) === 0) {
                $errors[] = "키워드 '{$keyword_item['name']}'에 알리익스프레스 상품 링크가 필요합니다.";
                debug_log("validate_input_data: Keyword {$index} AliExpress URLs validation failed - no URLs.");
                continue;
            }

            $valid_urls = 0;
            foreach ($keyword_item['aliexpress'] as $url) {
                if (!empty($url) && is_string($url) && strpos($url, 'aliexpress.com') !== false) {
                    $valid_urls++;
                }
            }

            if ($valid_urls === 0) {
                $errors[] = "키워드 '{$keyword_item['name']}'에 유효한 알리익스프레스 상품 링크가 없습니다.";
                debug_log("validate_input_data: Keyword {$index} has no valid AliExpress URLs.");
                continue;
            }

            // 🔧 상품 데이터 검증 강화
            if (empty($keyword_item['products_data']) || !is_array($keyword_item['products_data']) || count($keyword_item['products_data']) === 0) {
                $errors[] = "키워드 '{$keyword_item['name']}'의 상품 데이터가 없습니다. '분석' 버튼을 클릭하여 상품을 분석해주세요.";
                debug_log("validate_input_data: Keyword {$index} products_data validation failed - no product data.");
                continue;
            }

            debug_log("validate_input_data: Keyword {$index} validation passed - {$valid_urls} valid URLs, " . safe_count($keyword_item['products_data']) . " products.");
        }
    }

    // 사용자 세부 정보 검증 🔧
    if (empty($data['user_details']) || !is_array($data['user_details'])) {
        $errors[] = "사용자 세부 정보가 필요합니다.";
        debug_log("validate_input_data: User details validation failed.");
    } else {
        debug_log("validate_input_data: User details validation passed: " . safe_count($data['user_details']) . " fields provided.");
    }

    // 🔧 썸네일 URL 검증 (선택사항)
    if (!empty($data['thumbnail_url'])) {
        $thumbnail_url = trim($data['thumbnail_url']);
        if (!filter_var($thumbnail_url, FILTER_VALIDATE_URL)) {
            $errors[] = "썸네일 이미지 URL이 유효하지 않습니다.";
            debug_log("validate_input_data: Thumbnail URL validation failed. URL: " . $thumbnail_url);
        } else {
            debug_log("validate_input_data: Thumbnail URL validated successfully: " . $thumbnail_url);
        }
    }

    debug_log("validate_input_data: Validation completed. Errors: " . count($errors));
    return $errors;
}

// 16. 입력 데이터 정리 함수 (알리익스프레스 링크 처리 포함) - 🔧 상품 데이터 지원 추가
function clean_affiliate_links($keywords_raw) {
    debug_log("clean_affiliate_links: Processing " . safe_count($keywords_raw) . " keywords for affiliate link cleaning.");
    
    $cleaned_keywords = [];
    foreach ($keywords_raw as $index => $keyword_item) {
        debug_log("clean_affiliate_links: Processing keyword {$index}: " . json_encode($keyword_item, JSON_UNESCAPED_UNICODE));
        
        $cleaned_item = [
            'name' => clean_input($keyword_item['name'] ?? ''),
            'aliexpress' => [],
            'products_data' => [] // 🔧 상품 데이터 지원
        ];

        // 알리익스프레스 링크 정리
        if (!empty($keyword_item['aliexpress']) && is_array($keyword_item['aliexpress'])) {
            foreach ($keyword_item['aliexpress'] as $url) {
                $cleaned_url = clean_input($url);
                if (!empty($cleaned_url) && strpos($cleaned_url, 'aliexpress.com') !== false) {
                    $cleaned_item['aliexpress'][] = $cleaned_url;
                }
            }
        }

        // 🔧 상품 데이터 정리
        if (!empty($keyword_item['products_data']) && is_array($keyword_item['products_data'])) {
            $cleaned_item['products_data'] = $keyword_item['products_data']; // 상품 데이터는 이미 처리된 것으로 가정
            debug_log("clean_affiliate_links: Keyword {$index} has " . safe_count($keyword_item['products_data']) . " products.");
        }

        if (!empty($cleaned_item['aliexpress'])) {
            $cleaned_keywords[] = $cleaned_item;
            debug_log("clean_affiliate_links: Keyword {$index} processed successfully - " . count($cleaned_item['aliexpress']) . " URLs retained.");
        } else {
            debug_log("clean_affiliate_links: Keyword {$index} skipped - no valid URLs.");
        }
    }

    debug_log("clean_affiliate_links: Cleaning completed. " . count($cleaned_keywords) . " keywords retained.");
    return $cleaned_keywords;
}

// 17. 사용자 세부 정보 정리 함수 🔧
function clean_user_details($user_details_raw) {
    debug_log("clean_user_details: Processing user details.");
    
    if (empty($user_details_raw) || !is_array($user_details_raw)) {
        debug_log("clean_user_details: No user details provided or invalid format.");
        return [];
    }
    
    $cleaned_details = [];
    foreach ($user_details_raw as $key => $value) {
        $cleaned_key = clean_input($key);
        $cleaned_value = clean_input($value);
        
        if (!empty($cleaned_key) && !empty($cleaned_value)) {
            $cleaned_details[$cleaned_key] = $cleaned_value;
        }
    }
    
    debug_log("clean_user_details: User details cleaned. " . count($cleaned_details) . " fields retained.");
    return $cleaned_details;
}

// =================================================================================
// 🚀 메인 처리 로직 시작
// =================================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    debug_log("=== Main Process Started ===");
    
    try {
        // 🔧 입력 데이터 수집 (썸네일 URL 추가)
        $input_data = [
            'title' => clean_input($_POST['title'] ?? ''),
            'category' => intval($_POST['category'] ?? 0),
            'prompt_type' => clean_input($_POST['prompt_type'] ?? 'default'), // 🔧 프롬프트 타입 추가
            'keywords' => json_decode($_POST['keywords'] ?? '[]', true) ?? [],
            'user_details' => json_decode($_POST['user_details'] ?? '[]', true) ?? [], // 🔧 사용자 세부 정보 추가
            'publish_mode' => clean_input($_POST['publish_mode'] ?? 'queue'), // 🔧 발행 모드 (즉시 vs 큐)
            'thumbnail_url' => clean_input($_POST['thumbnail_url'] ?? '') // 🔧 썸네일 URL 추가
        ];

        debug_log("main_process: Input data collection completed.");
        debug_log("main_process: Title: " . substr($input_data['title'], 0, 50) . "...");
        debug_log("main_process: Category: " . get_category_name($input_data['category']));
        debug_log("main_process: Prompt Type: " . get_prompt_type_name($input_data['prompt_type']));
        debug_log("main_process: Keywords count: " . safe_count($input_data['keywords']));
        debug_log("main_process: User details provided: " . (empty($input_data['user_details']) ? 'No' : 'Yes'));
        debug_log("main_process: Publish mode: " . $input_data['publish_mode']);
        debug_log("main_process: Thumbnail URL: " . (empty($input_data['thumbnail_url']) ? 'No' : 'Yes - ' . $input_data['thumbnail_url']));

        // 입력 데이터 검증
        $validation_errors = validate_input_data($input_data);
        if (!empty($validation_errors)) {
            debug_log("main_process: Input validation failed with " . count($validation_errors) . " errors.");
            
            main_log("Input data received: Title='" . $input_data['title'] . "', Category=" . $input_data['category'] . ", Prompt Type=" . $input_data['prompt_type'] . ", Keywords=" . safe_count($input_data['keywords']) . ", User Details=" . (empty($input_data['user_details']) ? 'No' : 'Yes') . ", Publish Mode=" . $input_data['publish_mode'] . ", Thumbnail URL=" . (empty($input_data['thumbnail_url']) ? 'No' : 'Yes') . ".");
            
            // 텔레그램 알림 전송
            $telegram_msg = "❌ 데이터 검증 실패:\n\n" . implode("\n• ", $validation_errors) . "\n\n입력된 데이터:\n제목: " . $input_data['title'] . "\n카테고리: " . get_category_name($input_data['category']) . "\n프롬프트: " . get_prompt_type_name($input_data['prompt_type']) . "\n키워드 수: " . safe_count($input_data['keywords']) . "개" . (empty($input_data['thumbnail_url']) ? '' : "\n썸네일 URL: 제공됨");
            send_telegram_notification($telegram_msg);
            
            if ($input_data['publish_mode'] === 'immediate') {
                send_json_response(false, [
                    'message' => implode("\n", $validation_errors),
                    'error' => 'validation_failed'
                ]);
            } else {
                redirect_to_editor(false, ['error' => implode('; ', $validation_errors)]);
            }
        }

        debug_log("main_process: Input validation passed successfully.");

        // 🔧 데이터 정리 (상품 데이터 및 사용자 세부 정보 포함)
        $cleaned_keywords = clean_affiliate_links($input_data['keywords']);
        $cleaned_user_details = clean_user_details($input_data['user_details']);

        debug_log("main_process: Data cleaning completed. " . count($cleaned_keywords) . " keywords retained.");

        // 🔧 큐 데이터 구성 (프롬프트 타입, 사용자 세부 정보, 상품 데이터, 썸네일 URL 포함)
        $queue_data = [
            'title' => $input_data['title'],
            'category' => $input_data['category'],
            'prompt_type' => $input_data['prompt_type'], // 🔧 프롬프트 타입 추가
            'keywords' => $cleaned_keywords,
            'user_details' => $cleaned_user_details, // 🔧 사용자 세부 정보 추가
            'thumbnail_url' => !empty($input_data['thumbnail_url']) ? $input_data['thumbnail_url'] : null, // 🔧 썸네일 URL 추가
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
            'processing_mode' => $input_data['publish_mode'], // 🔧 처리 모드 추가
            'link_conversion_required' => true,
            
            // 🔧 메타 정보 추가
            'has_user_details' => !empty($cleaned_user_details),
            'has_product_data' => !empty($cleaned_keywords) && !empty($cleaned_keywords[0]['products_data']),
            'has_thumbnail_url' => !empty($input_data['thumbnail_url']), // 🔧 썸네일 URL 존재 여부
            'queue_save_attempted' => false,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        debug_log("main_process: Queue data structure prepared.");
        debug_log("main_process: Has user details: " . ($queue_data['has_user_details'] ? 'Yes' : 'No'));
        debug_log("main_process: Has product data: " . ($queue_data['has_product_data'] ? 'Yes' : 'No'));
        debug_log("main_process: Thumbnail URL included: " . ($queue_data['has_thumbnail_url'] ? 'Yes (' . $queue_data['thumbnail_url'] . ')' : 'No'));

        // 분할 큐 시스템에 추가
        $filename = add_to_queue($queue_data);
        if (!$filename) {
            debug_log("main_process: Failed to add item to split queue system.");
            main_log("Failed to add item to split queue system.");
            
            if ($input_data['publish_mode'] === 'immediate') {
                send_json_response(false, [
                    'message' => '분할 큐 시스템 저장에 실패했습니다. 파일 권한을 확인하거나 관리자에게 문의하세요.',
                    'error' => 'queue_save_failed'
                ]);
            } else {
                redirect_to_editor(false, ['error' => '분할 큐 시스템 저장에 실패했습니다. 파일 권한을 확인하거나 관리자에게 문의하세요.']);
            }
        }
        
        debug_log("main_process: Item successfully added to split queue system.");
        $queue_data['queue_save_attempted'] = true;
        
        // 🚀 즉시 발행 vs 일반 큐 분기 처리
        if ($input_data['publish_mode'] === 'immediate') {
            debug_log("main_process: Processing immediate publish request (queue already saved).");
            process_immediate_publish($queue_data);
            // process_immediate_publish() 함수에서 JSON 응답 후 exit 됨
        } else {
            debug_log("main_process: Standard queue processing - sending success notification.");
            
            // Get queue statistics for notification
            $stats = get_queue_stats();
            debug_log("main_process: Queue stats retrieved: " . json_encode($stats));

            $telegram_success_msg = "✅ 새 작업이 분할 큐 시스템에 추가되었습니다!\n\n";
            $telegram_success_msg .= "📋 <b>작업 정보</b>\n";
            $telegram_success_msg .= "• 제목: " . substr($input_data['title'], 0, 100) . (strlen($input_data['title']) > 100 ? '...' : '') . "\n";
            $telegram_success_msg .= "• 카테고리: " . get_category_name($input_data['category']) . "\n";
            $telegram_success_msg .= "• 프롬프트 타입: " . get_prompt_type_name($input_data['prompt_type']) . "\n"; // 🔧 프롬프트 타입 추가
            $telegram_success_msg .= "• 키워드 수: " . count($cleaned_keywords) . "개\n";
            
            // 🔧 사용자 세부 정보 및 상품 데이터 정보 추가
            if ($queue_data['has_user_details']) {
                $telegram_success_msg .= "• 사용자 세부 정보: 제공됨 (" . count($cleaned_user_details) . "개 필드)\n";
            }
            
            $total_products = 0;
            foreach ($cleaned_keywords as $keyword) {
                $total_products += safe_count($keyword['products_data']);
            }
            if ($total_products > 0) {
                $telegram_success_msg .= "• 상품 데이터: " . $total_products . "개 상품\n";
            }
            
            // 🔧 썸네일 URL 정보 추가
            if ($queue_data['has_thumbnail_url']) {
                $telegram_success_msg .= "• 썸네일 URL: 제공됨 (" . substr($queue_data['thumbnail_url'], 0, 50) . "...)\n";
            }
            
            $telegram_success_msg .= "\n📊 <b>현재 큐 상태</b>\n";
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

    } catch (Exception $e) {
        debug_log("main_process: Exception occurred: " . $e->getMessage());
        main_log("Exception occurred during main process: " . $e->getMessage());
        
        // 텔레그램 오류 알림
        $telegram_error_msg = "🚨 시스템 오류 발생:\n\n" . $e->getMessage() . "\n\n시간: " . date('Y-m-d H:i:s');
        send_telegram_notification($telegram_error_msg);
        
        if (isset($input_data) && $input_data['publish_mode'] === 'immediate') {
            send_json_response(false, [
                'message' => '시스템 오류가 발생했습니다.',
                'error' => 'system_exception',
                'details' => $e->getMessage()
            ]);
        } else {
            redirect_to_editor(false, ['error' => '시스템 오류가 발생했습니다: ' . $e->getMessage()]);
        }
    }
} else {
    debug_log("Non-POST request received, redirecting to editor.");
    redirect_to_editor(false, ['error' => '잘못된 요청입니다.']);
}

debug_log("=== Main Process Completed ===");
?>