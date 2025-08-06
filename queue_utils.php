<?php

// WordPress 함수들을 사용할 수 없는 환경에서 정의
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/');
}

/**
 * 큐 파일 관리 유틸리티 함수들 - 2단계 시스템 (pending/completed)
 * 버전: v4.0 (queue_manager_plan.md 기반 재구현)
 * 
 * 큐 디렉토리 구조:
 * /var/www/novacents/tools/queues/
 * ├── pending/     # 대기 중
 * └── completed/   # 완료
 * 
 * @author Claude AI
 * @version 4.0
 * @date 2025-08-05
 */

// 디렉토리 및 파일 경로 상수 (2단계 시스템)
define('QUEUE_BASE_DIR', '/var/www/novacents/tools');
define('QUEUE_SPLIT_DIR', QUEUE_BASE_DIR . '/queues');
define('QUEUE_PENDING_DIR', QUEUE_SPLIT_DIR . '/pending');
define('QUEUE_COMPLETED_DIR', QUEUE_SPLIT_DIR . '/completed');
// 🚫 QUEUE_LEGACY_FILE 제거됨 - product_queue.json 더이상 사용하지 않음

define('QUEUE_INDEX_FILE', QUEUE_BASE_DIR . '/queue_index.json');


/**
 * 큐 디렉토리 초기화 (2단계 시스템)
 */
function initialize_queue_directories() {
    $dirs = [
        QUEUE_SPLIT_DIR,
        QUEUE_PENDING_DIR,
        QUEUE_COMPLETED_DIR
    ];
    
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                error_log("Failed to create queue directory: {$dir}");
                return false;
            }
        }
        
        // 디렉토리 쓰기 권한 확인
        if (!is_writable($dir)) {
            error_log("Queue directory is not writable: {$dir}");
            return false;
        }
    }
    
    // 인덱스 파일 초기화
    if (!file_exists(QUEUE_INDEX_FILE)) {
        save_queue_index([]);
    }
    
    return true;
}

/**
 * 상태별 디렉토리 경로 반환 (2단계 시스템)
 */
function get_queue_directory_by_status($status) {
    switch ($status) {
        case 'pending':
            return QUEUE_PENDING_DIR;
        case 'completed':
            return QUEUE_COMPLETED_DIR;
        default:
            return QUEUE_PENDING_DIR; // 기본값: pending
    }
}

/**
 * 큐 인덱스 로드
 */
function load_queue_index() {
    if (!file_exists(QUEUE_INDEX_FILE)) {
        return [];
    }
    
    $content = file_get_contents(QUEUE_INDEX_FILE);
    if ($content === false) {
        error_log("Failed to read queue index file");
        return [];
    }
    
    $index = json_decode($content, true);
    return $index !== null ? $index : [];
}

/**
 * 큐 인덱스 저장
 */
function save_queue_index($index) {
    $json_content = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json_content === false) {
        error_log("Failed to encode queue index");
        return false;
    }
    
    if (file_put_contents(QUEUE_INDEX_FILE, $json_content, LOCK_EX) === false) {
        error_log("Failed to save queue index file");
        return false;
    }
    
    return true;
}

/**
 * 큐 인덱스 업데이트
 */
function update_queue_index($queue_id, $queue_info) {
    $index = load_queue_index();
    $index[$queue_id] = $queue_info;
    return save_queue_index($index);
}

/**
 * 큐 인덱스에서 제거
 */
function remove_from_queue_index($queue_id) {
    $index = load_queue_index();
    if (isset($index[$queue_id])) {
        unset($index[$queue_id]);
        return save_queue_index($index);
    }
    return true;
}

/**
 * 새로운 큐 추가 (분할 시스템)
 */
function add_queue_split($queue_data) {
    initialize_queue_directories();
    
    // 큐 ID 생성 (중복 방지)
    $queue_id = 'queue_' . date('Ymd_His') . '_' . substr(md5(uniqid()), 0, 8);
    $filename = $queue_id . '.json';
    
    // 기본값 설정
    $queue_data['queue_id'] = $queue_id;
    $queue_data['filename'] = $filename;
    $queue_data['status'] = $queue_data['status'] ?? 'pending';
    $queue_data['created_at'] = date('Y-m-d H:i:s');
    $queue_data['updated_at'] = date('Y-m-d H:i:s');
    $queue_data['attempts'] = 0;
    $queue_data['priority'] = $queue_data['priority'] ?? 1;
    
    // 상태에 따른 디렉토리 결정
    $dir = get_queue_directory_by_status($queue_data['status']);
    $filepath = $dir . '/' . $filename;
    
    // JSON 파일로 저장
    $json_content = json_encode($queue_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json_content === false) {
        error_log("Failed to encode queue data for queue_id: {$queue_id}");
        return false;
    }
    
    if (file_put_contents($filepath, $json_content, LOCK_EX) === false) {
        error_log("Failed to save queue file: {$filepath}");
        return false;
    }
    
    // 인덱스에 추가
    $index_info = [
        'queue_id' => $queue_id,
        'filename' => $filename,
        'status' => $queue_data['status'],
        'title' => $queue_data['title'] ?? '',
        'created_at' => $queue_data['created_at'],
        'updated_at' => $queue_data['updated_at'],
        'attempts' => $queue_data['attempts'],
        'category_name' => get_category_name($queue_data['category_id'] ?? ''),
        'prompt_type_name' => get_prompt_type_name($queue_data['prompt_type'] ?? ''),
        'priority' => $queue_data['priority']
    ];
    
    if (!update_queue_index($queue_id, $index_info)) {
        error_log("Failed to update queue index for new queue: {$queue_id}");
        return false;
    }
    
    // 🚫 레거시 파일 업데이트 제거됨 - product_queue.json 더이상 사용하지 않음
    
    return $queue_id;
}

/**
 * 카테고리 이름 가져오기
 */
function get_category_name($category_id) {
    $categories = [
        '354' => '스마트 리빙',
        '355' => '패션 & 뷰티', 
        '356' => '전자기기',
        '12' => '기타'
    ];
    
    return $categories[$category_id] ?? '알 수 없는 카테고리';
}

/**
 * 프롬프트 타입 이름 가져오기
 */
function get_prompt_type_name($prompt_type) {
    $types = [
        'essential_items' => '필수템형',
        'friend_review' => '친구 추천형',
        'professional_analysis' => '전문 분석형',
        'amazing_discovery' => '놀라움 발견형'
    ];
    
    return $types[$prompt_type] ?? '기본형';
}

/**
 * 특정 큐 로드 (분할 시스템)
 */
function load_queue_split($queue_id) {
    $index = load_queue_index();
    
    if (!isset($index[$queue_id])) {
        return null;
    }
    
    $queue_info = $index[$queue_id];
    $status = $queue_info['status'];
    $filename = $queue_info['filename'];
    
    $dir = get_queue_directory_by_status($status);
    $filepath = $dir . '/' . $filename;
    
    if (!file_exists($filepath)) {
        error_log("Queue file not found: {$filepath}");
        return null;
    }
    
    $content = file_get_contents($filepath);
    if ($content === false) {
        error_log("Failed to read queue file: {$filepath}");
        return null;
    }
    
    $queue_data = json_decode($content, true);
    if ($queue_data === null) {
        error_log("Failed to parse queue file: {$filepath}");
        return null;
    }
    
    return $queue_data;
}

/**
 * 모든 큐 목록 가져오기 (분할 시스템)
 */
function get_all_queues_split($status = null, $limit = 100) {
    $index = load_queue_index();
    $queues = [];
    
    foreach ($index as $queue_info) {
        // 상태 필터링
        if ($status !== null && $queue_info['status'] !== $status) {
            continue;
        }
        
        $queue_data = load_queue_split($queue_info['queue_id']);
        if ($queue_data !== null) {
            $queues[] = $queue_data;
        }
    }
    
    // 생성일 기준 내림차순 정렬
    usort($queues, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    
    // 제한 적용
    if ($limit > 0) {
        $queues = array_slice($queues, 0, $limit);
    }
    
    return $queues;
}


/**
 * 큐 상태 업데이트 (분할 시스템)
 */
function update_queue_status_split($queue_id, $status, $error_message = null) {
    $queue_data = load_queue_split($queue_id);
    if ($queue_data === null) {
        error_log("Queue not found for status update: {$queue_id}");
        return false;
    }
    
    $old_status = $queue_data['status'];
    $queue_data['status'] = $status;
    $queue_data['updated_at'] = date('Y-m-d H:i:s');
    
    // 오류 메시지가 있으면 추가
    if ($error_message !== null) {
        $queue_data['error_message'] = $error_message;
    }
    
    // 시도 횟수 증가 (실패한 경우)
    if ($status === 'failed') {
        $queue_data['attempts'] = ($queue_data['attempts'] ?? 0) + 1;
    }
    
    // 상태가 변경된 경우 파일 이동
    $old_dir = get_queue_directory_by_status($old_status);
    $new_dir = get_queue_directory_by_status($status);
    $filename = $queue_data['filename'];
    
    $old_path = $old_dir . '/' . $filename;
    $new_path = $new_dir . '/' . $filename;
    
    // 새 위치에 파일 저장
    $json_content = json_encode($queue_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json_content === false) {
        error_log("Failed to encode updated queue data for queue_id: {$queue_id}");
        return false;
    }
    
    if (file_put_contents($new_path, $json_content, LOCK_EX) === false) {
        error_log("Failed to save updated queue file: {$new_path}");
        return false;
    }
    
    // 기존 파일 삭제 (다른 디렉토리인 경우)
    if ($old_path !== $new_path && file_exists($old_path)) {
        unlink($old_path);
    }
    
    // 인덱스 업데이트
    $index_info = [
        'queue_id' => $queue_id,
        'filename' => $filename,
        'status' => $status,
        'title' => $queue_data['title'] ?? '',
        'created_at' => $queue_data['created_at'] ?? date('Y-m-d H:i:s'),
        'updated_at' => $queue_data['updated_at'],
        'attempts' => $queue_data['attempts'] ?? 0,
        'category_name' => $queue_data['category_name'] ?? '',
        'prompt_type_name' => $queue_data['prompt_type_name'] ?? '',
        'priority' => $queue_data['priority'] ?? 1
    ];
    
    if (!update_queue_index($queue_id, $index_info)) {
        error_log("Failed to update queue index for status update: {$queue_id}");
        return false;
    }
    
    // 🚫 레거시 파일 업데이트 제거됨 - product_queue.json 더이상 사용하지 않음
    
    return true;
}

/**
 * 큐 데이터 업데이트 (분할 시스템)
 */
function update_queue_split($queue_id, $updated_data) {
    $queue_data = load_queue_split($queue_id);
    if ($queue_data === null) {
        error_log("Queue not found for data update: {$queue_id}");
        return false;
    }
    
    $old_status = $queue_data['status'];
    $old_filename = $queue_data['filename'];
    
    // 기존 데이터와 업데이트 데이터 병합
    $updated_data = array_merge($queue_data, $updated_data);
    $updated_data['updated_at'] = date('Y-m-d H:i:s');
    
    // 상태가 변경된 경우 파일 이동
    $new_status = $updated_data['status'] ?? $old_status;
    $old_dir = get_queue_directory_by_status($old_status);
    $new_dir = get_queue_directory_by_status($new_status);
    
    $old_path = $old_dir . '/' . $old_filename;
    $new_path = $new_dir . '/' . $old_filename;
    
    // 새 데이터로 파일 저장
    $json_content = json_encode($updated_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json_content === false) {
        error_log("Failed to encode updated queue data for queue_id: {$queue_id}");
        return false;
    }
    
    if (!file_put_contents($new_path, $json_content, LOCK_EX)) {
        error_log("Failed to save updated queue file: {$new_path}");
        return false;
    }
    
    // 기존 파일 삭제 (다른 디렉토리인 경우)
    if ($old_path !== $new_path && file_exists($old_path)) {
        unlink($old_path);
    }
    
    // 인덱스 업데이트
    $index_info = [
        'queue_id' => $queue_id,
        'filename' => $old_filename,
        'status' => $new_status,
        'title' => $updated_data['title'] ?? '',
        'created_at' => $updated_data['created_at'] ?? date('Y-m-d H:i:s'),
        'updated_at' => $updated_data['updated_at'],
        'attempts' => $updated_data['attempts'] ?? 0,
        'category_name' => $updated_data['category_name'] ?? '',
        'prompt_type_name' => $updated_data['prompt_type_name'] ?? '',
        'priority' => $updated_data['priority'] ?? 1
    ];
    
    if (!update_queue_index($queue_id, $index_info)) {
        error_log("Failed to update queue index for data update: {$queue_id}");
        return false;
    }
    
    // 🚫 레거시 파일 업데이트 제거됨 - product_queue.json 더이상 사용하지 않음
    
    return true;
}

/**
 * 🆕 큐 순서 변경 (queue_manager.php 용)
 */
function reorder_queues_split($queue_ids_array) {
    $index = load_queue_index();
    $reordered_count = 0;
    
    // 우선순위를 배열 순서에 따라 설정
    foreach ($queue_ids_array as $order_index => $queue_id) {
        if (isset($index[$queue_id])) {
            // 큐 데이터 로드
            $queue_data = load_queue_split($queue_id);
            if ($queue_data !== null) {
                // 우선순위 업데이트 (낮은 숫자가 높은 우선순위)
                $queue_data['priority'] = $order_index + 1;
                $queue_data['updated_at'] = date('Y-m-d H:i:s');
                
                // 파일 업데이트
                $status = $queue_data['status'];
                $filename = $queue_data['filename'];
                $dir = get_queue_directory_by_status($status);
                $filepath = $dir . '/' . $filename;
                
                $json_content = json_encode($queue_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($json_content !== false && file_put_contents($filepath, $json_content, LOCK_EX)) {
                    // 인덱스 업데이트
                    $index[$queue_id]['priority'] = $queue_data['priority'];
                    $index[$queue_id]['updated_at'] = $queue_data['updated_at'];
                    $reordered_count++;
                }
            }
        }
    }
    
    // 인덱스 저장
    if ($reordered_count > 0) {
        save_queue_index($index);
        // 🚫 레거시 파일 업데이트 제거됨
    }
    
    return $reordered_count;
}

/**
 * 큐 삭제 (즉시 발행 등)
 */
function remove_queue_split($queue_id) {
    $queue_data = load_queue_split($queue_id);
    if ($queue_data === null) {
        return false;
    }
    
    $status = $queue_data['status'];
    $filename = $queue_data['filename'];
    $dir = get_queue_directory_by_status($status);
    $filepath = $dir . '/' . $filename;
    
    // 파일 삭제
    if (file_exists($filepath)) {
        if (!unlink($filepath)) {
            error_log("Failed to delete queue file: {$filepath}");
            return false;
        }
    }
    
    // 인덱스에서 제거
    if (!remove_from_queue_index($queue_id)) {
        error_log("Failed to remove queue from index: {$queue_id}");
        return false;
    }
    
    // 🚫 레거시 파일 업데이트 제거됨 - product_queue.json 더이상 사용하지 않음
    
    return true;
}

/**
 * 큐 통계 정보
 */
function get_queue_stats_split() {
    $index = load_queue_index();
    $stats = [
        'total' => 0,
        'pending' => 0,
        'completed' => 0
    ];
    
    foreach ($index as $queue_info) {
        // 2단계 시스템에서만 허용되는 상태만 카운트
        if (in_array($queue_info['status'], ['pending', 'completed'])) {
            $stats['total']++;
            $stats[$queue_info['status']]++;
        }
    }
    
    return $stats;
}

/**
 * 🆕 큐 검색 (queue_manager.php 용)
 */
function search_queues_split($search_term, $status = null, $limit = 50) {
    $index = load_queue_index();
    $matched_queues = [];
    
    foreach ($index as $queue_id => $queue_info) {
        // 상태 필터링
        if ($status !== null && $queue_info['status'] !== $status) {
            continue;
        }
        
        // 검색어 매칭 (제목, 카테고리, 프롬프트 타입에서 검색)
        $searchable_text = strtolower(
            $queue_info['title'] . ' ' . 
            $queue_info['category_name'] . ' ' . 
            $queue_info['prompt_type_name']
        );
        
        if (strpos($searchable_text, strtolower($search_term)) !== false) {
            $queue_data = load_queue_split($queue_id);
            if ($queue_data !== null) {
                $matched_queues[] = $queue_data;
            }
        }
    }
    
    // 최신순 정렬
    usort($matched_queues, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    
    // 제한 적용
    if ($limit > 0) {
        $matched_queues = array_slice($matched_queues, 0, $limit);
    }
    
    return $matched_queues;
}

/**
 * 완료된 큐 정리 (지정된 일수 이상 된 것들)
 */
function cleanup_completed_queues_split($days_old = 7) {
    $cutoff_time = time() - ($days_old * 24 * 60 * 60);
    $index = load_queue_index();
    $cleaned_count = 0;
    
    foreach ($index as $queue_id => $queue_info) {
        // completed 상태만 정리 (failed 상태 제거)
        if ($queue_info['status'] === 'completed') {
            $created_time = strtotime($queue_info['created_at']);
            if ($created_time < $cutoff_time) {
                if (remove_queue_split($queue_id)) {
                    $cleaned_count++;
                    error_log("Cleaned up old completed queue: {$queue_id}");
                }
            }
        }
    }
    
    error_log("Cleanup completed: {$cleaned_count} old completed queues removed");
    return $cleaned_count;
}

/**
 * 큐 시스템 디버그 정보
 */
function debug_queue_split_info() {
    $info = [];
    
    // 디렉토리 정보
    $directories = [
        'pending' => QUEUE_PENDING_DIR,
        'processing' => QUEUE_PROCESSING_DIR,
        'completed' => QUEUE_COMPLETED_DIR,
        'failed' => QUEUE_FAILED_DIR
    ];
    
    foreach ($directories as $status => $dir) {
        $files = is_dir($dir) ? array_filter(scandir($dir), function($f) { return substr($f, -5) === '.json'; }) : [];
        $info['directories'][$status] = [
            'path' => $dir,
            'exists' => is_dir($dir),
            'writable' => is_writable($dir),
            'file_count' => count($files),
            'files' => array_values($files)
        ];
    }
    
    // 인덱스 정보
    $index = load_queue_index();
    $info['index'] = [
        'file_exists' => file_exists(QUEUE_INDEX_FILE),
        'total_entries' => count($index),
        'by_status' => []
    ];
    
    foreach ($index as $queue_info) {
        $status = $queue_info['status'];
        $info['index']['by_status'][$status] = ($info['index']['by_status'][$status] ?? 0) + 1;
    }
    
    return $info;
}

/**
 * 🚫 update_legacy_queue_file() 함수 제거됨
 * product_queue.json 더이상 사용하지 않음 (queue_manager_plan.md 요구사항)
 */

/**
 * 큐 통계를 위한 별칭 함수 (queue_manager.php 호환)
 */
function get_queue_statistics() {
    return get_queue_stats_split();
}

/**
 * 🔒 파일 락킹 시스템 (동시 접근 방지)
 */
class QueueLockManager {
    /**
     * 큐 락 획득
     */
    public static function acquireLock($queue_id, $timeout = 10) {
        $lock_file = QUEUE_LOCKS_DIR . '/' . $queue_id . '.lock';
        $start_time = time();
        
        // 디렉토리 존재 확인
        if (!is_dir(QUEUE_LOCKS_DIR)) {
            mkdir(QUEUE_LOCKS_DIR, 0755, true);
        }
        
        while (time() - $start_time < $timeout) {
            // 기존 락 파일이 있는지 확인
            if (file_exists($lock_file)) {
                $lock_time = filemtime($lock_file);
                // 5분 이상 된 락은 만료된 것으로 간주
                if (time() - $lock_time > 300) {
                    unlink($lock_file);
                } else {
                    usleep(100000); // 0.1초 대기
                    continue;
                }
            }
            
            // 락 파일 생성
            $lock_data = [
                'queue_id' => $queue_id,
                'created_at' => date('Y-m-d H:i:s'),
                'process_id' => getmypid(),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ];
            
            if (file_put_contents($lock_file, json_encode($lock_data), LOCK_EX) !== false) {
                return true;
            }
            
            usleep(100000); // 0.1초 대기 후 재시도
        }
        
        return false; // 타임아웃
    }
    
    /**
     * 큐 락 해제
     */
    public static function releaseLock($queue_id) {
        $lock_file = QUEUE_LOCKS_DIR . '/' . $queue_id . '.lock';
        
        if (file_exists($lock_file)) {
            return unlink($lock_file);
        }
        
        return true;
    }
    
    /**
     * 락 상태 확인
     */
    public static function isLocked($queue_id) {
        $lock_file = QUEUE_LOCKS_DIR . '/' . $queue_id . '.lock';
        
        if (!file_exists($lock_file)) {
            return false;
        }
        
        $lock_time = filemtime($lock_file);
        // 5분 이상 된 락은 만료로 간주
        if (time() - $lock_time > 300) {
            unlink($lock_file);
            return false;
        }
        
        return true;
    }
}

/**
 * 🛡️ 큐 상태 검증자 (허용되지 않는 전환 방지)
 */
class QueueStatusValidator {
    // 허용되는 상태 전환 규칙
    private static $allowed_transitions = [
        'pending' => ['processing', 'failed'],
        'processing' => ['completed', 'failed', 'pending'],
        'completed' => ['failed', 'pending'],
        'failed' => ['pending']
    ];
    
    /**
     * 상태 전환 유효성 검증
     */
    public static function validateStatusTransition($current_status, $new_status) {
        // Move 버튼의 순환 전환 허용
        $move_cycle = ['pending', 'processing', 'completed', 'failed'];
        $current_index = array_search($current_status, $move_cycle);
        
        if ($current_index !== false) {
            $next_index = ($current_index + 1) % count($move_cycle);
            
            // Move 버튼 순환 허용
            if ($new_status === $move_cycle[$next_index]) {
                return true;
            }
        }
        
        // 기본 전환 규칙 확인
        if (isset(self::$allowed_transitions[$current_status])) {
            return in_array($new_status, self::$allowed_transitions[$current_status]);
        }
        
        return false;
    }
    
    /**
     * 큐 데이터 유효성 검증
     */
    public static function validateQueueData($queue_data) {
        $required_fields = ['queue_id', 'title', 'status', 'created_at'];
        
        foreach ($required_fields as $field) {
            if (!isset($queue_data[$field]) || empty($queue_data[$field])) {
                error_log("Missing required field: {$field}");
                return false;
            }
        }
        
        // 상태 값 검증
        $valid_statuses = ['pending', 'processing', 'completed', 'failed'];
        if (!in_array($queue_data['status'], $valid_statuses)) {
            error_log("Invalid status: " . $queue_data['status']);
            return false;
        }
        
        return true;
    }
    
    /**
     * Move 버튼 다음 상태 계산
     */
    public static function getNextMoveStatus($current_status) {
        $move_cycle = ['pending', 'processing', 'completed', 'failed'];
        $current_index = array_search($current_status, $move_cycle);
        
        if ($current_index !== false) {
            $next_index = ($current_index + 1) % count($move_cycle);
            return $move_cycle[$next_index];
        }
        
        return 'pending'; // 기본값
    }
}

/**
 * 🔄 원자적 트랜잭션 관리자
 */
class QueueTransactionManager {
    private static $transaction_dir = QUEUE_TRANSACTIONS_DIR;
    
    /**
     * 트랜잭션 시작
     */
    public static function beginTransaction($queue_id, $action, $backup_data) {
        $transaction_id = 'tx_' . $queue_id . '_' . time() . '_' . substr(md5(uniqid()), 0, 8);
        $transaction_file = self::$transaction_dir . '/' . $transaction_id . '.json';
        
        // 디렉토리 존재 확인
        if (!is_dir(self::$transaction_dir)) {
            mkdir(self::$transaction_dir, 0755, true);
        }
        
        $transaction_data = [
            'transaction_id' => $transaction_id,
            'queue_id' => $queue_id,
            'action' => $action,
            'status' => 'active',
            'started_at' => date('Y-m-d H:i:s'),
            'backup_data' => $backup_data
        ];
        
        if (file_put_contents($transaction_file, json_encode($transaction_data, JSON_PRETTY_PRINT), LOCK_EX) !== false) {
            return $transaction_id;
        }
        
        return false;
    }
    
    /**
     * 트랜잭션 완료
     */
    public static function commitTransaction($transaction_id) {
        $transaction_file = self::$transaction_dir . '/' . $transaction_id . '.json';
        
        if (file_exists($transaction_file)) {
            $transaction_data = json_decode(file_get_contents($transaction_file), true);
            if ($transaction_data) {
                $transaction_data['status'] = 'committed';
                $transaction_data['completed_at'] = date('Y-m-d H:i:s');
                
                file_put_contents($transaction_file, json_encode($transaction_data, JSON_PRETTY_PRINT), LOCK_EX);
                
                // 완료된 트랜잭션은 삭제 (선택적)
                // unlink($transaction_file);
                
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 트랜잭션 롤백
     */
    public static function rollbackTransaction($transaction_id) {
        $transaction_file = self::$transaction_dir . '/' . $transaction_id . '.json';
        
        if (file_exists($transaction_file)) {
            $transaction_data = json_decode(file_get_contents($transaction_file), true);
            if ($transaction_data && isset($transaction_data['backup_data'])) {
                $backup_data = $transaction_data['backup_data'];
                $queue_id = $transaction_data['queue_id'];
                
                // 백업 데이터로 복원 시도
                if (isset($backup_data['old_data'])) {
                    $old_data = $backup_data['old_data'];
                    $old_status = $backup_data['old_status'];
                    
                    // 파일 복원
                    $dir = get_queue_directory_by_status($old_status);
                    $filepath = $dir . '/' . $old_data['filename'];
                    
                    $json_content = json_encode($old_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if (file_put_contents($filepath, $json_content, LOCK_EX) !== false) {
                        // 인덱스 복원
                        $index_info = [
                            'queue_id' => $queue_id,
                            'filename' => $old_data['filename'],
                            'status' => $old_status,
                            'title' => $old_data['title'] ?? '',
                            'created_at' => $old_data['created_at'] ?? date('Y-m-d H:i:s'),
                            'updated_at' => $old_data['updated_at'] ?? date('Y-m-d H:i:s'),
                            'attempts' => $old_data['attempts'] ?? 0,
                            'category_name' => $old_data['category_name'] ?? '',
                            'prompt_type_name' => $old_data['prompt_type_name'] ?? '',
                            'priority' => $old_data['priority'] ?? 1
                        ];
                        
                        update_queue_index($queue_id, $index_info);
                    }
                }
                
                // 트랜잭션 상태 업데이트
                $transaction_data['status'] = 'rolled_back';
                $transaction_data['completed_at'] = date('Y-m-d H:i:s');
                file_put_contents($transaction_file, json_encode($transaction_data, JSON_PRETTY_PRINT), LOCK_EX);
                
                return true;
            }
        }
        
        return false;
    }
}

/**
 * 🤖 Python 스크립트 동기화 함수
 */
function is_queue_being_processed($queue_id) {
    // 1. processing 상태 확인
    $queue_data = load_queue_split($queue_id);
    if (!$queue_data) {
        return false;
    }
    
    if ($queue_data['status'] === 'processing') {
        // 처리 시작 시간 확인 (5분 이상 processing이면 오류로 간주)
        $updated_time = strtotime($queue_data['updated_at']);
        if (time() - $updated_time > 300) {
            error_log("Processing timeout detected for queue: {$queue_id}");
            return false; // 5분 이상 처리 중이면 이동 허용
        }
        return true;
    }
    
    // 2. Python 프로세스 실행 여부 확인
    if (file_exists(PYTHON_PID_FILE)) {
        $pid = trim(file_get_contents(PYTHON_PID_FILE));
        if ($pid && function_exists('posix_kill') && posix_kill($pid, 0)) {
            return true; // Python 스크립트 실행 중
        }
    }
    
    return false;
}

/**
 * 🔒 안전한 큐 상태 업데이트 (트랜잭션 기반)
 */
function update_queue_status_split_safe($queue_id, $new_status, $error_message = null) {
    // 1. 현재 상태 확인
    $queue_data = load_queue_split($queue_id);
    if (!$queue_data) {
        error_log("Queue not found for safe status update: {$queue_id}");
        return false;
    }
    
    // 2. 백업 데이터 준비
    $backup_data = [
        'queue_id' => $queue_id,
        'old_status' => $queue_data['status'],
        'new_status' => $new_status,
        'old_data' => $queue_data
    ];
    
    // 3. 트랜잭션 시작
    $transaction_id = QueueTransactionManager::beginTransaction($queue_id, 'status_update', $backup_data);
    if (!$transaction_id) {
        error_log("Failed to start transaction for queue: {$queue_id}");
        return false;
    }
    
    try {
        // 4. 실제 상태 업데이트
        $result = update_queue_status_split($queue_id, $new_status, $error_message);
        
        if (!$result) {
            throw new Exception("Status update failed for queue: {$queue_id}");
        }
        
        // 5. 트랜잭션 완료
        QueueTransactionManager::commitTransaction($transaction_id);
        
        error_log("Safe status update completed: {$queue_id} -> {$new_status}");
        return true;
        
    } catch (Exception $e) {
        // 6. 실패 시 롤백
        error_log("Safe status update failed, rolling back: " . $e->getMessage());
        QueueTransactionManager::rollbackTransaction($transaction_id);
        return false;
    }
}

/**
 * 🔒 완전한 Move 버튼 처리 함수 (단순 상태 변경만)
 */
function process_move_queue_status($queue_id) {
    try {
        // 1. 현재 상태 확인
        $current_queue = load_queue_split($queue_id);
        if (!$current_queue) {
            return ['success' => false, 'message' => '큐를 찾을 수 없습니다.'];
        }
        
        // 2. Python 처리 중인지 확인
        if (is_queue_being_processed($queue_id)) {
            return ['success' => false, 'message' => '현재 처리 중인 큐는 이동할 수 없습니다.'];
        }
        
        // 3. 다음 상태 결정 (Move 버튼 순환)
        $next_status = QueueStatusValidator::getNextMoveStatus($current_queue['status']);
        
        // 4. 상태 전환 검증
        if (!QueueStatusValidator::validateStatusTransition($current_queue['status'], $next_status)) {
            return ['success' => false, 'message' => '허용되지 않는 상태 전환입니다.'];
        }
        
        // 5. 락 획득
        if (!QueueLockManager::acquireLock($queue_id)) {
            return ['success' => false, 'message' => '다른 사용자가 이 큐를 수정 중입니다. 잠시 후 다시 시도하세요.'];
        }
        
        // 6. 🚨 단순 상태 변경만 수행 (검증 없이)
        $result = simple_update_queue_status($queue_id, $next_status);
        
        // 7. 락 해제
        QueueLockManager::releaseLock($queue_id);
        
        if ($result) {
            return [
                'success' => true, 
                'message' => '큐 상태가 변경되었습니다.',
                'old_status' => $current_queue['status'],
                'new_status' => $next_status
            ];
        } else {
            return ['success' => false, 'message' => '상태 변경에 실패했습니다.'];
        }
        
    } catch (Exception $e) {
        // 락 해제 (안전장치)
        QueueLockManager::releaseLock($queue_id);
        error_log("Move queue status error: " . $e->getMessage());
        return ['success' => false, 'message' => '서버 오류가 발생했습니다.'];
    }
}

/**
 * 🚨 단순 상태 변경 함수 (Move 버튼 전용, 검증 없음)
 */
function simple_update_queue_status($queue_id, $new_status) {
    // 현재 큐 데이터 로드
    $current_data = load_queue_split($queue_id);
    if (!$current_data) {
        error_log("Queue not found for simple status update: {$queue_id}");
        return false;
    }
    
    $old_status = $current_data['status'];
    
    // 상태만 변경 (다른 데이터는 건드리지 않음)
    $current_data['status'] = $new_status;
    $current_data['updated_at'] = date('Y-m-d H:i:s');
    
    // 파일 경로 설정
    $old_dir = get_queue_directory_by_status($old_status);
    $new_dir = get_queue_directory_by_status($new_status);
    $filename = $current_data['filename'];
    
    $old_path = $old_dir . '/' . $filename;
    $new_path = $new_dir . '/' . $filename;
    
    // 새 경로에 파일 저장
    $json_content = json_encode($current_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json_content === false) {
        error_log("Failed to encode queue data for simple update: {$queue_id}");
        return false;
    }
    
    if (!file_put_contents($new_path, $json_content, LOCK_EX) === false) {
        error_log("Failed to save updated queue file: {$new_path}");
        return false;
    }
    
    // 기존 파일 삭제 (다른 디렉토리인 경우)
    if ($old_path !== $new_path && file_exists($old_path)) {
        unlink($old_path);
    }
    
    // 인덱스 업데이트 (기본 정보만)
    $index_info = [
        'queue_id' => $queue_id,
        'filename' => $filename,
        'status' => $new_status,
        'title' => $current_data['title'] ?? '',
        'created_at' => $current_data['created_at'] ?? date('Y-m-d H:i:s'),
        'updated_at' => $current_data['updated_at'],
        'attempts' => $current_data['attempts'] ?? 0,
        'category_name' => $current_data['category_name'] ?? '',
        'prompt_type_name' => $current_data['prompt_type_name'] ?? '',
        'priority' => $current_data['priority'] ?? 1
    ];
    
    if (!update_queue_index($queue_id, $index_info)) {
        error_log("Failed to update queue index for simple status update: {$queue_id}");
        return false;
    }
    
    // 🚫 레거시 파일 업데이트 제거됨 - product_queue.json 더이상 사용하지 않음
    
    error_log("Simple status update completed: {$queue_id} from {$old_status} to {$new_status}");
    return true;
}

/**
 * 🧹 만료된 락 파일 정리
 */
function cleanup_expired_locks() {
    if (!is_dir(QUEUE_LOCKS_DIR)) {
        return 0;
    }
    
    $cleaned = 0;
    $lock_files = glob(QUEUE_LOCKS_DIR . '/*.lock');
    
    foreach ($lock_files as $lock_file) {
        $lock_time = filemtime($lock_file);
        // 5분 이상 된 락 파일 삭제
        if (time() - $lock_time > 300) {
            if (unlink($lock_file)) {
                $cleaned++;
            }
        }
    }
    
    return $cleaned;
}

/**
 * 🧹 만료된 트랜잭션 파일 정리
 */
function cleanup_expired_transactions() {
    if (!is_dir(QUEUE_TRANSACTIONS_DIR)) {
        return 0;
    }
    
    $cleaned = 0;
    $transaction_files = glob(QUEUE_TRANSACTIONS_DIR . '/*.json');
    
    foreach ($transaction_files as $transaction_file) {
        $transaction_time = filemtime($transaction_file);
        // 1시간 이상 된 트랜잭션 파일 삭제
        if (time() - $transaction_time > 3600) {
            if (unlink($transaction_file)) {
                $cleaned++;
            }
        }
    }
    
    return $cleaned;
}

/**
 * 대기 중인 큐 목록 조회 (2단계 시스템)
 */
function get_pending_queues_split($limit = 100) {
    $index = load_queue_index();
    $pending_queues = [];
    
    // pending 상태만 필터링
    foreach ($index as $queue_id => $queue_info) {
        if ($queue_info['status'] === 'pending') {
            $pending_queues[] = $queue_info;
        }
    }
    
    // 최신순 정렬 (modified_at > created_at 우선)
    usort($pending_queues, function($a, $b) {
        $timeA = $a['modified_at'] ?? $a['created_at'] ?? '0000-00-00 00:00:00';
        $timeB = $b['modified_at'] ?? $b['created_at'] ?? '0000-00-00 00:00:00';
        return strcmp($timeB, $timeA); // 최신순
    });
    
    // 개수 제한
    if ($limit !== null && $limit > 0) {
        $pending_queues = array_slice($pending_queues, 0, $limit);
    }
    
    // 실제 큐 데이터 로드
    $queues = [];
    foreach ($pending_queues as $queue_info) {
        $queue_data = load_queue_split($queue_info['queue_id']);
        if ($queue_data !== null) {
            $queues[] = $queue_data;
        }
    }
    
    return $queues;
}

/**
 * 완료된 큐 목록 조회 (2단계 시스템용 새 함수)
 */
function get_completed_queues_split($limit = 100) {
    $index = load_queue_index();
    $completed_queues = [];
    
    // completed 상태만 필터링
    foreach ($index as $queue_id => $queue_info) {
        if ($queue_info['status'] === 'completed') {
            $completed_queues[] = $queue_info;
        }
    }
    
    // 최신순 정렬 (modified_at > created_at 우선)
    usort($completed_queues, function($a, $b) {
        $timeA = $a['modified_at'] ?? $a['created_at'] ?? '0000-00-00 00:00:00';
        $timeB = $b['modified_at'] ?? $b['created_at'] ?? '0000-00-00 00:00:00';
        return strcmp($timeB, $timeA); // 최신순
    });
    
    // 개수 제한
    if ($limit !== null && $limit > 0) {
        $completed_queues = array_slice($completed_queues, 0, $limit);
    }
    
    // 실제 큐 데이터 로드
    $queues = [];
    foreach ($completed_queues as $queue_info) {
        $queue_data = load_queue_split($queue_info['queue_id']);
        if ($queue_data !== null) {
            $queues[] = $queue_data;
        }
    }
    
    return $queues;
}

/**
 * 상태별 큐 목록 조회 (2단계 시스템)
 */
function get_queues_by_status_split($status, $limit = null) {
    // 2단계 시스템에서만 허용되는 상태 검증
    if (!in_array($status, ['pending', 'completed'])) {
        return [];
    }
    
    if ($status === 'pending') {
        return get_pending_queues_split($limit);
    } elseif ($status === 'completed') {
        return get_completed_queues_split($limit);
    }
    
    return [];
}

/**
 * 큐 상태 업데이트 (2단계 시스템 전용)
 */
function update_queue_status_split($queue_id, $new_status, $error_message = null) {
    // 2단계 시스템에서만 허용되는 상태 검증
    if (!in_array($new_status, ['pending', 'completed'])) {
        error_log("Invalid status for 2-stage system: {$new_status}");
        return false;
    }
    
    $queue_data = load_queue_split($queue_id);
    if ($queue_data === null) {
        error_log("Queue not found for status update: {$queue_id}");
        return false;
    }
    
    $old_status = $queue_data['status'];
    $old_filename = $queue_data['filename'];
    
    // 큐 데이터 업데이트
    $queue_data['status'] = $new_status;
    $queue_data['updated_at'] = date('Y-m-d H:i:s');
    $queue_data['modified_at'] = date('Y-m-d H:i:s');
    
    if ($error_message) {
        $queue_data['last_error'] = $error_message;
    }
    
    // 상태가 변경되면 파일 이동
    $old_dir = get_queue_directory_by_status($old_status);
    $new_dir = get_queue_directory_by_status($new_status);
    
    $old_path = $old_dir . '/' . $old_filename;
    $new_path = $new_dir . '/' . $old_filename;
    
    // 새 위치에 파일 저장
    $json_content = json_encode($queue_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json_content === false) {
        error_log("Failed to encode updated queue data for queue_id: {$queue_id}");
        return false;
    }
    
    if (!file_put_contents($new_path, $json_content, LOCK_EX)) {
        error_log("Failed to save updated queue file: {$new_path}");
        return false;
    }
    
    // 기존 파일 삭제 (다른 디렉토리인 경우)
    if ($old_path !== $new_path && file_exists($old_path)) {
        unlink($old_path);
    }
    
    // 인덱스 업데이트
    $index_info = [
        'queue_id' => $queue_id,
        'filename' => $old_filename,
        'status' => $new_status,
        'title' => $queue_data['title'] ?? '',
        'created_at' => $queue_data['created_at'],
        'updated_at' => $queue_data['updated_at'],
        'modified_at' => $queue_data['modified_at'],
        'category_name' => $queue_data['category_name'] ?? '',
        'prompt_type_name' => $queue_data['prompt_type_name'] ?? '',
        'priority' => $queue_data['priority'] ?? 1
    ];
    
    if (!update_queue_index($queue_id, $index_info)) {
        error_log("Failed to update queue index for queue_id: {$queue_id}");
        return false;
    }
    
    return true;
}

/**
 * ID 추가 (호환성을 위해)
 */
function load_queue_split($queue_id) {
    $index = load_queue_index();
    
    if (!isset($index[$queue_id])) {
        return null;
    }
    
    $queue_info = $index[$queue_id];
    $status = $queue_info['status'];
    $filename = $queue_info['filename'];
    
    // 상태에 따라 디렉토리 결정
    $dir = get_queue_directory_by_status($status);
    $filepath = $dir . '/' . $filename;
    
    if (!file_exists($filepath)) {
        // 인덱스에는 있지만 파일이 없는 경우 - 인덱스에서 제거
        remove_from_queue_index($queue_id);
        error_log("Queue file not found, removed from index: {$filepath}");
        return null;
    }
    
    $content = file_get_contents($filepath);
    if ($content === false) {
        error_log("Failed to read queue file: {$filepath}");
        return null;
    }
    
    $queue_data = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Failed to decode queue JSON: " . json_last_error_msg() . " - File: {$filepath}");
        return null;
    }
    
    // 큐 데이터에 ID 추가 (호환성)
    $queue_data['id'] = $queue_id;
    
    return $queue_data;
}

error_log("queue_utils.php v4.0 loaded - 2-stage system (pending/completed)");

?>