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
?>