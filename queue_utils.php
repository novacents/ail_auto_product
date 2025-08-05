<?php
/**
 * 큐 파일 분할 관리 유틸리티 함수들
 * 대용량 product_queue.json 성능 문제 해결을 위한 파일 분할 시스템
 * 
 * @author Claude AI
 * @version 1.1
 * @date 2025-07-24
 */

// 디렉토리 및 파일 경로 상수
define('QUEUE_BASE_DIR', '/var/www/novacents/tools');
define('QUEUE_SPLIT_DIR', QUEUE_BASE_DIR . '/queues');
define('QUEUE_PENDING_DIR', QUEUE_SPLIT_DIR . '/pending');
define('QUEUE_PROCESSING_DIR', QUEUE_SPLIT_DIR . '/processing');
define('QUEUE_COMPLETED_DIR', QUEUE_SPLIT_DIR . '/completed');
define('QUEUE_FAILED_DIR', QUEUE_SPLIT_DIR . '/failed');
define('QUEUE_INDEX_FILE', QUEUE_BASE_DIR . '/queue_index.json');
define('QUEUE_LEGACY_FILE', QUEUE_BASE_DIR . '/product_queue.json');

/**
 * 큐 디렉토리 초기화
 */
function initialize_queue_directories() {
    $dirs = [
        QUEUE_SPLIT_DIR,
        QUEUE_PENDING_DIR,
        QUEUE_PROCESSING_DIR,
        QUEUE_COMPLETED_DIR,
        QUEUE_FAILED_DIR
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
 * 큐 ID 생성
 */
function generate_queue_id() {
    return 'queue_' . date('YmdHis') . '_' . mt_rand(1000, 9999);
}

/**
 * 상태별 디렉토리 경로 반환
 */
function get_queue_directory_by_status($status) {
    switch ($status) {
        case 'pending':
            return QUEUE_PENDING_DIR;
        case 'processing':
            return QUEUE_PROCESSING_DIR;
        case 'completed':
            return QUEUE_COMPLETED_DIR;
        case 'failed':
            return QUEUE_FAILED_DIR;
        default:
            return QUEUE_PENDING_DIR;
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
    return is_array($index) ? $index : [];
}

/**
 * 큐 인덱스 저장
 */
function save_queue_index($index) {
    $json_content = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json_content === false) {
        error_log("Failed to encode queue index to JSON");
        return false;
    }
    
    $result = file_put_contents(QUEUE_INDEX_FILE, $json_content, LOCK_EX);
    if ($result === false) {
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
    unset($index[$queue_id]);
    return save_queue_index($index);
}

/**
 * 분할된 큐 추가
 */
function add_queue_split($queue_data) {
    // 디렉토리 초기화
    if (!initialize_queue_directories()) {
        error_log("Failed to initialize queue directories");
        return false;
    }
    
    // 큐 ID 생성 (기존에 없으면)
    if (!isset($queue_data['queue_id']) || empty($queue_data['queue_id'])) {
        $queue_data['queue_id'] = generate_queue_id();
    }
    
    $queue_id = $queue_data['queue_id'];
    $timestamp = date('YmdHis');
    $filename = "queue_{$timestamp}_{$queue_id}.json";
    
    // 큐 데이터에 메타정보 추가
    $queue_data['status'] = $queue_data['status'] ?? 'pending';
    $queue_data['created_at'] = $queue_data['created_at'] ?? date('Y-m-d H:i:s');
    $queue_data['updated_at'] = date('Y-m-d H:i:s');
    $queue_data['attempts'] = $queue_data['attempts'] ?? 0;
    $queue_data['filename'] = $filename;
    
    // 상태별 디렉토리 결정
    $dir = get_queue_directory_by_status($queue_data['status']);
    $filepath = $dir . '/' . $filename;
    
    // 개별 큐 파일 저장
    $json_content = json_encode($queue_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json_content === false) {
        error_log("Failed to encode queue data to JSON for queue_id: {$queue_id}");
        return false;
    }
    
    if (!file_put_contents($filepath, $json_content, LOCK_EX)) {
        error_log("Failed to save queue file: {$filepath}");
        return false;
    }
    
    // 인덱스 업데이트
    $index_info = [
        'queue_id' => $queue_id,
        'filename' => $filename,
        'status' => $queue_data['status'],
        'title' => $queue_data['title'] ?? '',
        'created_at' => $queue_data['created_at'],
        'updated_at' => $queue_data['updated_at'],
        'attempts' => $queue_data['attempts'],
        'category_name' => $queue_data['category_name'] ?? '',
        'prompt_type_name' => $queue_data['prompt_type_name'] ?? '',
        'priority' => $queue_data['priority'] ?? 1
    ];
    
    if (!update_queue_index($queue_id, $index_info)) {
        error_log("Failed to update queue index for queue_id: {$queue_id}");
        // 파일은 생성되었지만 인덱스 업데이트 실패 - 파일 제거
        unlink($filepath);
        return false;
    }
    
    // 호환성을 위한 레거시 파일 업데이트
    update_legacy_queue_file();
    
    return $queue_id;
}

/**
 * 대기 중인 큐 목록 조회
 */
function get_pending_queues_split($limit = null) {
    $index = load_queue_index();
    $pending_queues = [];
    
    // pending 상태만 필터링
    foreach ($index as $queue_id => $queue_info) {
        if ($queue_info['status'] === 'pending') {
            $pending_queues[] = $queue_info;
        }
    }
    
    // 생성 시간순 정렬 (오래된 것부터)
    usort($pending_queues, function($a, $b) {
        return strtotime($a['created_at']) - strtotime($b['created_at']);
    });
    
    // 제한 적용
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
 * 🆕 전체 큐 목록 조회 (모든 상태)
 */
function get_all_queues_split($limit = null, $sort_by = 'created_at', $sort_order = 'DESC') {
    $index = load_queue_index();
    $all_queues = array_values($index);
    
    // 정렬
    usort($all_queues, function($a, $b) use ($sort_by, $sort_order) {
        $val_a = $a[$sort_by] ?? '';
        $val_b = $b[$sort_by] ?? '';
        
        if ($sort_by === 'created_at' || $sort_by === 'updated_at') {
            $val_a = strtotime($val_a);
            $val_b = strtotime($val_b);
        }
        
        $result = $val_a <=> $val_b;
        return $sort_order === 'DESC' ? -$result : $result;
    });
    
    // 제한 적용
    if ($limit !== null && $limit > 0) {
        $all_queues = array_slice($all_queues, 0, $limit);
    }
    
    // 실제 큐 데이터 로드 (queue_manager.php용으로 전체 데이터 필요)
    $queues = [];
    foreach ($all_queues as $queue_info) {
        $queue_data = load_queue_split($queue_info['queue_id']);
        if ($queue_data !== null) {
            $queues[] = $queue_data;
        }
    }
    
    return $queues;
}

/**
 * 🆕 상태별 큐 조회
 */
function get_queues_by_status_split($status, $limit = null) {
    $index = load_queue_index();
    $filtered_queues = [];
    
    // 해당 상태만 필터링
    foreach ($index as $queue_id => $queue_info) {
        if ($queue_info['status'] === $status) {
            $filtered_queues[] = $queue_info;
        }
    }
    
    // 생성 시간순 정렬 (최신순)
    usort($filtered_queues, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    
    // 제한 적용
    if ($limit !== null && $limit > 0) {
        $filtered_queues = array_slice($filtered_queues, 0, $limit);
    }
    
    // 실제 큐 데이터 로드
    $queues = [];
    foreach ($filtered_queues as $queue_info) {
        $queue_data = load_queue_split($queue_info['queue_id']);
        if ($queue_data !== null) {
            $queues[] = $queue_data;
        }
    }
    
    return $queues;
}

/**
 * 특정 큐 데이터 로드
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
    
    return $queue_data;
}

/**
 * 큐 상태 업데이트
 */
function update_queue_status_split($queue_id, $new_status, $error_message = null) {
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
    
    if ($error_message) {
        $queue_data['last_error'] = $error_message;
        $queue_data['attempts'] = ($queue_data['attempts'] ?? 0) + 1;
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
        'attempts' => $queue_data['attempts'] ?? 0,
        'category_name' => $queue_data['category_name'] ?? '',
        'prompt_type_name' => $queue_data['prompt_type_name'] ?? '',
        'priority' => $queue_data['priority'] ?? 1
    ];
    
    if (!update_queue_index($queue_id, $index_info)) {
        error_log("Failed to update queue index for status change: {$queue_id}");
        return false;
    }
    
    // 호환성을 위한 레거시 파일 업데이트
    update_legacy_queue_file();
    
    return true;
}

/**
 * 🆕 큐 데이터 전체 업데이트 (queue_manager.php 용)
 */
function update_queue_data_split($queue_id, $updated_data) {
    $queue_data = load_queue_split($queue_id);
    if ($queue_data === null) {
        error_log("Queue not found for data update: {$queue_id}");
        return false;
    }
    
    $old_status = $queue_data['status'];
    $old_filename = $queue_data['filename'];
    
    // 기존 메타 정보 보존하면서 데이터 업데이트
    $preserved_fields = ['queue_id', 'filename', 'created_at', 'attempts'];
    foreach ($preserved_fields as $field) {
        if (isset($queue_data[$field])) {
            $updated_data[$field] = $queue_data[$field];
        }
    }
    
    // updated_at는 항상 현재 시간으로
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
    
    // 호환성을 위한 레거시 파일 업데이트
    update_legacy_queue_file();
    
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
        update_legacy_queue_file();
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
    
    // 호환성을 위한 레거시 파일 업데이트
    update_legacy_queue_file();
    
    return true;
}

/**
 * 큐 통계 정보
 */
function get_queue_stats_split() {
    $index = load_queue_index();
    $stats = [
        'total' => count($index),
        'pending' => 0,
        'processing' => 0,
        'completed' => 0,
        'failed' => 0
    ];
    
    foreach ($index as $queue_info) {
        $status = $queue_info['status'];
        if (isset($stats[$status])) {
            $stats[$status]++;
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
        if (in_array($queue_info['status'], ['completed', 'failed'])) {
            $created_time = strtotime($queue_info['created_at']);
            if ($created_time < $cutoff_time) {
                if (remove_queue_split($queue_id)) {
                    $cleaned_count++;
                }
            }
        }
    }
    
    return $cleaned_count;
}

/**
 * 호환성을 위한 레거시 파일 업데이트
 * 기존 코드가 product_queue.json을 읽을 수 있도록 유지
 */
function update_legacy_queue_file() {
    $pending_queues = get_pending_queues_split();
    
    // 기존 형태로 변환 (기존 코드 호환)
    $legacy_format = [];
    foreach ($pending_queues as $queue) {
        $legacy_format[] = $queue;
    }
    
    $json_content = json_encode($legacy_format, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json_content === false) {
        error_log("Failed to encode legacy queue data");
        return false;
    }
    
    $result = file_put_contents(QUEUE_LEGACY_FILE, $json_content, LOCK_EX);
    if ($result === false) {
        error_log("Failed to update legacy queue file");
        return false;
    }
    
    return true;
}

/**
 * 기존 product_queue.json에서 분할 시스템으로 마이그레이션
 */
function migrate_legacy_queue_to_split() {
    if (!file_exists(QUEUE_LEGACY_FILE)) {
        return ['migrated' => 0, 'errors' => []];
    }
    
    $content = file_get_contents(QUEUE_LEGACY_FILE);
    if ($content === false) {
        return ['migrated' => 0, 'errors' => ['Failed to read legacy queue file']];
    }
    
    $legacy_queues = json_decode($content, true);
    if (!is_array($legacy_queues)) {
        return ['migrated' => 0, 'errors' => ['Invalid JSON format in legacy file']];
    }
    
    $migrated = 0;
    $errors = [];
    
    foreach ($legacy_queues as $queue_data) {
        try {
            // 기존 queue_id가 있으면 사용, 없으면 새로 생성
            if (!isset($queue_data['queue_id']) || empty($queue_data['queue_id'])) {
                $queue_data['queue_id'] = generate_queue_id();
            }
            
            // 필수 필드 확인 및 기본값 설정
            if (!isset($queue_data['status'])) {
                $queue_data['status'] = 'pending';
            }
            if (!isset($queue_data['created_at'])) {
                $queue_data['created_at'] = date('Y-m-d H:i:s');
            }
            if (!isset($queue_data['updated_at'])) {
                $queue_data['updated_at'] = date('Y-m-d H:i:s');
            }
            if (!isset($queue_data['attempts'])) {
                $queue_data['attempts'] = 0;
            }
            
            // 분할 시스템에 추가
            $result = add_queue_split($queue_data);
            if ($result) {
                $migrated++;
            } else {
                $errors[] = "Failed to migrate queue: " . ($queue_data['title'] ?? 'Unknown');
            }
            
        } catch (Exception $e) {
            $errors[] = "Migration error: " . $e->getMessage();
        }
    }
    
    // 마이그레이션 완료 후 레거시 파일 백업
    if ($migrated > 0) {
        $backup_file = QUEUE_LEGACY_FILE . '.backup.' . date('YmdHis');
        copy(QUEUE_LEGACY_FILE, $backup_file);
    }
    
    return ['migrated' => $migrated, 'errors' => $errors];
}

/**
 * 디버그 정보 출력 (개발용)
 */
function debug_queue_split_info() {
    $info = [
        'directories' => [
            'base' => QUEUE_BASE_DIR,
            'split' => QUEUE_SPLIT_DIR,
            'pending' => QUEUE_PENDING_DIR,
            'processing' => QUEUE_PROCESSING_DIR,
            'completed' => QUEUE_COMPLETED_DIR,
            'failed' => QUEUE_FAILED_DIR
        ],
        'files' => [
            'index' => QUEUE_INDEX_FILE,
            'legacy' => QUEUE_LEGACY_FILE
        ],
        'stats' => get_queue_stats_split(),
        'directory_status' => []
    ];
    
    // 디렉토리 상태 확인
    foreach ($info['directories'] as $name => $path) {
        $info['directory_status'][$name] = [
            'exists' => is_dir($path),
            'writable' => is_writable($path),
            'files_count' => is_dir($path) ? count(glob($path . '/*.json')) : 0
        ];
    }
    
    return $info;
}

// =====================================================================
// 🔒 보안 강화 기능들 - Move 버튼 안전성 보장
// =====================================================================

// 보안 관련 상수 정의
define('QUEUE_LOCKS_DIR', QUEUE_BASE_DIR . '/locks');
define('QUEUE_TRANSACTIONS_DIR', QUEUE_BASE_DIR . '/transactions');
define('PYTHON_PID_FILE', '/var/www/auto_post_products.pid');

/**
 * 🔒 큐 잠금 관리자 클래스
 */
class QueueLockManager {
    private static $lock_dir = QUEUE_LOCKS_DIR;
    
    /**
     * 큐 잠금 획득
     */
    public static function acquireLock($queue_id, $timeout = 10) {
        if (!is_dir(self::$lock_dir)) {
            if (!mkdir(self::$lock_dir, 0755, true)) {
                error_log("Failed to create locks directory: " . self::$lock_dir);
                return false;
            }
        }
        
        $lock_file = self::$lock_dir . "/{$queue_id}.lock";
        $start_time = time();
        
        while (time() - $start_time < $timeout) {
            // 기존 락 파일 만료 확인 (30초 이상 된 락은 만료)
            if (file_exists($lock_file)) {
                $lock_time = filemtime($lock_file);
                if (time() - $lock_time > 30) {
                    unlink($lock_file); // 만료된 락 제거
                    error_log("Expired lock removed: {$queue_id}");
                }
            }
            
            // 락 획득 시도
            $lock_data = [
                'queue_id' => $queue_id,
                'process_id' => getmypid(),
                'user_id' => self::getCurrentUserId(),
                'timestamp' => time(),
                'action' => 'move_status'
            ];
            
            if (!file_exists($lock_file)) {
                if (file_put_contents($lock_file, json_encode($lock_data), LOCK_EX) !== false) {
                    error_log("Lock acquired: {$queue_id}");
                    return true; // 락 획득 성공
                }
            }
            
            usleep(100000); // 0.1초 대기
        }
        
        error_log("Failed to acquire lock: {$queue_id}");
        return false; // 락 획득 실패
    }
    
    /**
     * 큐 잠금 해제
     */
    public static function releaseLock($queue_id) {
        $lock_file = self::$lock_dir . "/{$queue_id}.lock";
        if (file_exists($lock_file)) {
            unlink($lock_file);
            error_log("Lock released: {$queue_id}");
        }
    }
    
    /**
     * 큐 잠금 상태 확인
     */
    public static function isLocked($queue_id) {
        $lock_file = self::$lock_dir . "/{$queue_id}.lock";
        if (!file_exists($lock_file)) {
            return false;
        }
        
        $lock_time = filemtime($lock_file);
        return (time() - $lock_time) < 30; // 30초 이내면 락 유효
    }
    
    /**
     * 현재 사용자 ID 가져오기
     */
    private static function getCurrentUserId() {
        if (function_exists('get_current_user_id')) {
            return get_current_user_id();
        }
        return 'system';
    }
}

/**
 * 🔍 큐 상태 검증자 클래스
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
        if (!is_dir(self::$transaction_dir)) {
            if (!mkdir(self::$transaction_dir, 0755, true)) {
                error_log("Failed to create transactions directory: " . self::$transaction_dir);
                return false;
            }
        }
        
        $transaction_id = "txn_" . time() . "_" . mt_rand(1000, 9999);
        $transaction_log = self::$transaction_dir . "/{$transaction_id}.log";
        
        $transaction_data = [
            'transaction_id' => $transaction_id,
            'queue_id' => $queue_id,
            'action' => $action,
            'backup_data' => $backup_data,
            'timestamp' => date('Y-m-d H:i:s'),
            'status' => 'started'
        ];
        
        if (file_put_contents($transaction_log, json_encode($transaction_data, JSON_PRETTY_PRINT), LOCK_EX) === false) {
            error_log("Failed to create transaction log: {$transaction_id}");
            return false;
        }
        
        error_log("Transaction started: {$transaction_id} for queue: {$queue_id}");
        return $transaction_id;
    }
    
    /**
     * 트랜잭션 완료
     */
    public static function commitTransaction($transaction_id) {
        $transaction_log = self::$transaction_dir . "/{$transaction_id}.log";
        
        if (file_exists($transaction_log)) {
            unlink($transaction_log);
            error_log("Transaction committed: {$transaction_id}");
            return true;
        }
        
        return false;
    }
    
    /**
     * 트랜잭션 롤백
     */
    public static function rollbackTransaction($transaction_id) {
        $transaction_log = self::$transaction_dir . "/{$transaction_id}.log";
        
        if (!file_exists($transaction_log)) {
            return false;
        }
        
        $transaction_data = json_decode(file_get_contents($transaction_log), true);
        if (!$transaction_data) {
            return false;
        }
        
        $backup_data = $transaction_data['backup_data'];
        $queue_id = $transaction_data['queue_id'];
        
        // 백업 데이터로 복원
        $restored = self::restoreFromBackup($backup_data);
        
        // 트랜잭션 로그 삭제
        unlink($transaction_log);
        
        error_log("Transaction rolled back: {$transaction_id} for queue: {$queue_id}");
        return $restored;
    }
    
    /**
     * 백업 데이터로부터 큐 복원
     */
    private static function restoreFromBackup($backup_data) {
        try {
            $queue_id = $backup_data['queue_id'];
            $old_status = $backup_data['old_status'];
            $old_data = $backup_data['old_data'];
            
            // 1. 현재 파일 제거 (실패해도 계속 진행)
            if (isset($backup_data['new_status'])) {
                $current_dir = get_queue_directory_by_status($backup_data['new_status']);
                $current_file = $current_dir . '/' . $old_data['filename'];
                
                if (file_exists($current_file)) {
                    unlink($current_file);
                }
            }
            
            // 2. 이전 상태로 파일 복원
            $old_dir = get_queue_directory_by_status($old_status);
            $old_file = $old_dir . '/' . $old_data['filename'];
            
            $json_content = json_encode($old_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (file_put_contents($old_file, $json_content, LOCK_EX) === false) {
                error_log("Failed to restore queue file: {$old_file}");
                return false;
            }
            
            // 3. 인덱스 복원
            $index_info = [
                'queue_id' => $queue_id,
                'filename' => $old_data['filename'],
                'status' => $old_status,
                'title' => $old_data['title'] ?? '',
                'created_at' => $old_data['created_at'],
                'updated_at' => $old_data['updated_at'],
                'attempts' => $old_data['attempts'] ?? 0,
                'category_name' => $old_data['category_name'] ?? '',
                'prompt_type_name' => $old_data['prompt_type_name'] ?? '',
                'priority' => $old_data['priority'] ?? 1
            ];
            
            if (!update_queue_index($queue_id, $index_info)) {
                error_log("Failed to restore queue index: {$queue_id}");
                return false;
            }
            
            error_log("Queue restored from backup: {$queue_id}");
            return true;
            
        } catch (Exception $e) {
            error_log("Restore failed: {$e->getMessage()}");
            return false;
        }
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
 * 🔒 완전한 Move 버튼 처리 함수
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
        
        // 6. 안전한 상태 업데이트
        $result = update_queue_status_split_safe($queue_id, $next_status);
        
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
        if (time() - $lock_time > 60) { // 1분 이상 된 락 파일 삭제
            unlink($lock_file);
            $cleaned++;
        }
    }
    
    if ($cleaned > 0) {
        error_log("Cleaned up {$cleaned} expired lock files");
    }
    
    return $cleaned;
}

/**
 * 🧹 만료된 트랜잭션 로그 정리
 */
function cleanup_expired_transactions() {
    if (!is_dir(QUEUE_TRANSACTIONS_DIR)) {
        return 0;
    }
    
    $cleaned = 0;
    $transaction_files = glob(QUEUE_TRANSACTIONS_DIR . '/*.log');
    
    foreach ($transaction_files as $transaction_file) {
        $transaction_time = filemtime($transaction_file);
        if (time() - $transaction_time > 300) { // 5분 이상 된 트랜잭션 로그 삭제
            unlink($transaction_file);
            $cleaned++;
        }
    }
    
    if ($cleaned > 0) {
        error_log("Cleaned up {$cleaned} expired transaction files");
    }
    
    return $cleaned;
}

?>