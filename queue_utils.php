<?php
/**
 * 큐 관리 유틸리티 함수들 - 새로운 2단계 시스템 (pending/completed)
 * 버전: v4.0 (split system)
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/');
}

// 큐 관련 상수 정의
define('QUEUE_BASE_DIR', '/var/www/novacents/tools/queues/');
define('QUEUE_SPLIT_DIR', QUEUE_BASE_DIR);
define('QUEUE_PENDING_DIR', QUEUE_BASE_DIR . 'pending/');
define('QUEUE_COMPLETED_DIR', QUEUE_BASE_DIR . 'completed/');
define('QUEUE_INDEX_FILE', QUEUE_BASE_DIR . 'queue_index.json');

/**
 * 큐 디렉토리 초기화
 */
function initialize_queue_directories() {
    $directories = [
        QUEUE_BASE_DIR,
        QUEUE_PENDING_DIR, 
        QUEUE_COMPLETED_DIR
    ];
    
    foreach ($directories as $dir) {
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                error_log("Failed to create queue directory: " . $dir);
                return false;
            }
        }
    }
    
    // 인덱스 파일 생성
    if (!file_exists(QUEUE_INDEX_FILE)) {
        $initial_index = ['pending' => [], 'completed' => []];
        if (!file_put_contents(QUEUE_INDEX_FILE, json_encode($initial_index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
            error_log("Failed to create queue index file");
            return false;
        }
    }
    
    return true;
}

/**
 * pending 상태의 큐 목록 가져오기
 */
function get_pending_queues_split() {
    if (!initialize_queue_directories()) {
        return [];
    }
    
    $queues = [];
    $files = glob(QUEUE_PENDING_DIR . '*.json');
    
    if ($files === false) {
        return [];
    }
    
    foreach ($files as $file) {
        $content = file_get_contents($file);
        if ($content !== false) {
            $queue = json_decode($content, true);
            if ($queue !== null && is_array($queue)) {
                $queue['queue_id'] = basename($file, '.json');
                $queue['status'] = 'pending';
                $queue['file_path'] = $file;
                $queues[] = $queue;
            }
        }
    }
    
    return $queues;
}

/**
 * completed 상태의 큐 목록 가져오기
 */
function get_completed_queues_split() {
    if (!initialize_queue_directories()) {
        return [];
    }
    
    $queues = [];
    $files = glob(QUEUE_COMPLETED_DIR . '*.json');
    
    if ($files === false) {
        return [];
    }
    
    foreach ($files as $file) {
        $content = file_get_contents($file);
        if ($content !== false) {
            $queue = json_decode($content, true);
            if ($queue !== null && is_array($queue)) {
                $queue['queue_id'] = basename($file, '.json');
                $queue['status'] = 'completed';
                $queue['file_path'] = $file;
                $queues[] = $queue;
            }
        }
    }
    
    return $queues;
}

/**
 * 큐 상태 변경 (v2 - 파일 이동 방식)
 */
function update_queue_status_split_v2($queue_id, $new_status) {
    if (!initialize_queue_directories()) {
        return false;
    }
    
    if (!in_array($new_status, ['pending', 'completed'])) {
        return false;
    }
    
    // 현재 파일 위치 찾기
    $current_file = null;
    $current_status = null;
    
    $pending_file = QUEUE_PENDING_DIR . $queue_id . '.json';
    $completed_file = QUEUE_COMPLETED_DIR . $queue_id . '.json';
    
    if (file_exists($pending_file)) {
        $current_file = $pending_file;
        $current_status = 'pending';
    } elseif (file_exists($completed_file)) {
        $current_file = $completed_file;
        $current_status = 'completed';
    }
    
    if (!$current_file || $current_status === $new_status) {
        return false; // 파일이 없거나 이미 같은 상태
    }
    
    // 대상 파일 경로 결정
    $target_file = ($new_status === 'pending') ? $pending_file : $completed_file;
    
    // 파일 내용 로드 및 상태 업데이트
    $content = file_get_contents($current_file);
    if ($content === false) {
        return false;
    }
    
    $queue_data = json_decode($content, true);
    if ($queue_data === null) {
        return false;
    }
    
    // 상태 및 수정 시간 업데이트
    $queue_data['status'] = $new_status;
    $queue_data['modified_at'] = date('Y-m-d H:i:s');
    
    // completed 상태로 변경 시 완료 시간 기록
    if ($new_status === 'completed' && !isset($queue_data['completed_at'])) {
        $queue_data['completed_at'] = date('Y-m-d H:i:s');
    }
    
    // 새 위치에 파일 저장
    if (!file_put_contents($target_file, json_encode($queue_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
        return false;
    }
    
    // 기존 파일 삭제
    if (!unlink($current_file)) {
        error_log("Warning: Could not delete old queue file: " . $current_file);
    }
    
    return true;
}

/**
 * 큐 저장 (분할 시스템)
 */
function save_queue_split($queue_data) {
    if (!initialize_queue_directories()) {
        return false;
    }
    
    // 큐 ID 생성
    $queue_id = 'queue_' . date('YmdHis') . '_' . substr(md5(uniqid(rand(), true)), 0, 8);
    
    // 기본값 설정
    $queue_data['queue_id'] = $queue_id;
    $queue_data['status'] = $queue_data['status'] ?? 'pending';
    $queue_data['created_at'] = date('Y-m-d H:i:s');
    $queue_data['modified_at'] = date('Y-m-d H:i:s');
    
    // 상태에 따라 저장 위치 결정
    if ($queue_data['status'] === 'completed') {
        $file_path = QUEUE_COMPLETED_DIR . $queue_id . '.json';
    } else {
        $file_path = QUEUE_PENDING_DIR . $queue_id . '.json';
    }
    
    // 파일 저장
    if (!file_put_contents($file_path, json_encode($queue_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
        error_log("Failed to save queue file: " . $file_path);
        return false;
    }
    
    return $queue_id;
}

/**
 * 카테고리 이름 가져오기
 */
if (!function_exists('get_category_name')) {
    function get_category_name($category_id) {
        $categories = [
            '354' => 'Today\'s Pick',
            '355' => '기발한 잡화점', 
            '356' => '스마트 리빙',
            '12' => '우리잇템'
        ];
        
        return $categories[$category_id] ?? '알 수 없는 카테고리';
    }
}

/**
 * 프롬프트 타입 이름 가져오기
 */
if (!function_exists('get_prompt_type_name')) {
    function get_prompt_type_name($prompt_type) {
        $types = [
            'essential_items' => '필수템형 🎯',
            'friend_review' => '친구 추천형 👫',
            'professional_analysis' => '전문 분석형 📊',
            'amazing_discovery' => '놀라움 발견형 ✨'
        ];
        
        return $types[$prompt_type] ?? '기본형';
    }
}

/**
 * 특정 큐 로드 (분할 시스템)
 */
function load_queue_split($queue_id) {
    if (!initialize_queue_directories()) {
        return null;
    }
    
    // pending과 completed 디렉토리에서 검색
    $possible_files = [
        QUEUE_PENDING_DIR . $queue_id . '.json',
        QUEUE_COMPLETED_DIR . $queue_id . '.json'
    ];
    
    foreach ($possible_files as $file_path) {
        if (file_exists($file_path)) {
            $content = file_get_contents($file_path);
            if ($content !== false) {
                $queue_data = json_decode($content, true);
                if ($queue_data !== null && is_array($queue_data)) {
                    $queue_data['queue_id'] = $queue_id;
                    $queue_data['file_path'] = $file_path;
                    return $queue_data;
                }
            }
        }
    }
    
    return null;
}

/**
 * 큐 삭제 (분할 시스템)
 */
function remove_queue_split($queue_id) {
    if (!initialize_queue_directories()) {
        return false;
    }
    
    // pending과 completed 디렉토리에서 검색하여 삭제
    $possible_files = [
        QUEUE_PENDING_DIR . $queue_id . '.json',
        QUEUE_COMPLETED_DIR . $queue_id . '.json'
    ];
    
    $deleted = false;
    foreach ($possible_files as $file_path) {
        if (file_exists($file_path)) {
            if (unlink($file_path)) {
                $deleted = true;
            } else {
                error_log("Failed to delete queue file: " . $file_path);
            }
        }
    }
    
    return $deleted;
}

/**
 * completed 상태 큐 정리 (오래된 파일 삭제)
 */
function cleanup_completed_queues_split($days_old = 7) {
    if (!initialize_queue_directories()) {
        return 0;
    }
    
    $files = glob(QUEUE_COMPLETED_DIR . '*.json');
    if ($files === false) {
        return 0;
    }
    
    $cutoff_time = time() - ($days_old * 24 * 60 * 60);
    $deleted_count = 0;
    
    foreach ($files as $file) {
        $file_time = filemtime($file);
        if ($file_time !== false && $file_time < $cutoff_time) {
            if (unlink($file)) {
                $deleted_count++;
            } else {
                error_log("Failed to delete old completed queue file: " . $file);
            }
        }
    }
    
    return $deleted_count;
}

/**
 * 큐 통계 정보
 */
function get_queue_stats_split() {
    $pending = get_pending_queues_split();
    $completed = get_completed_queues_split();
    
    return [
        'total' => count($pending) + count($completed),
        'pending' => count($pending),
        'completed' => count($completed)
    ];
}

/**
 * 큐 검색 (제목, 키워드 기반)
 */
function search_queues_split($search_term, $status = 'all') {
    $results = [];
    
    if ($status === 'all' || $status === 'pending') {
        $pending_queues = get_pending_queues_split();
        $results = array_merge($results, filter_queues_by_search($pending_queues, $search_term));
    }
    
    if ($status === 'all' || $status === 'completed') {
        $completed_queues = get_completed_queues_split();
        $results = array_merge($results, filter_queues_by_search($completed_queues, $search_term));
    }
    
    return $results;
}

/**
 * 검색어로 큐 필터링
 */
function filter_queues_by_search($queues, $search_term) {
    if (empty($search_term)) {
        return $queues;
    }
    
    $search_lower = mb_strtolower($search_term, 'UTF-8');
    
    return array_filter($queues, function($queue) use ($search_lower) {
        // 제목 검색
        if (isset($queue['title']) && mb_strpos(mb_strtolower($queue['title'], 'UTF-8'), $search_lower) !== false) {
            return true;
        }
        
        // 키워드 검색
        if (isset($queue['keywords']) && is_array($queue['keywords'])) {
            foreach ($queue['keywords'] as $keyword) {
                if (isset($keyword['name']) && mb_strpos(mb_strtolower($keyword['name'], 'UTF-8'), $search_lower) !== false) {
                    return true;
                }
            }
        }
        
        return false;
    });
}
?>