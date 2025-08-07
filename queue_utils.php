<?php
/**
 * 큐 관리 유틸리티 함수 모음
 * 버전: v3.3 (2025-08-07) - 디버깅 로그 추가
 */

// 디렉토리 경로 상수
define('QUEUE_PENDING_DIR', '/var/www/novacents/tools/queues/pending/');
define('QUEUE_COMPLETED_DIR', '/var/www/novacents/tools/queues/completed/');
define('QUEUE_INDEX_FILE', '/var/www/novacents/tools/queue_index.json');

function initialize_queue_directories() {
    $directories = [
        '/var/www/novacents/tools/queues/',
        QUEUE_PENDING_DIR,
        QUEUE_COMPLETED_DIR
    ];
    
    foreach ($directories as $dir) {
        if (!file_exists($dir)) {
            if (!mkdir($dir, 0755, true)) {
                error_log("큐 디렉토리 생성 실패: $dir");
                return false;
            }
        }
    }
    
    // 인덱스 파일 초기화
    if (!file_exists(QUEUE_INDEX_FILE)) {
        $initial_index = ['queues' => []];
        file_put_contents(QUEUE_INDEX_FILE, json_encode($initial_index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    return true;
}

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

if (!function_exists('get_prompt_type_name')) {
    function get_prompt_type_name($prompt_type) {
        $prompt_types = [
            'essential_items' => '필수템형 🎯',
            'friend_review' => '친구 추천형 👫', 
            'professional_analysis' => '전문 분석형 📊',
            'amazing_discovery' => '놀라움 발견형 ✨'
        ];
        return $prompt_types[$prompt_type] ?? '기본형';
    }
}

function save_queue_split($data, $status = 'pending') {
    if (!initialize_queue_directories()) {
        return false;
    }
    
    $queue_id = 'queue_' . date('YmdHis') . '_' . substr(uniqid(), -6);
    $data['queue_id'] = $queue_id;
    $data['status'] = $status;
    $data['created_at'] = date('Y-m-d H:i:s');
    $data['modified_at'] = date('Y-m-d H:i:s');
    
    $directory = ($status === 'completed') ? QUEUE_COMPLETED_DIR : QUEUE_PENDING_DIR;
    $file_path = $directory . $queue_id . '.json';
    
    if (file_put_contents($file_path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
        update_queue_index($queue_id, $data);
        return $queue_id;
    }
    
    return false;
}

function get_pending_queues_split() {
    if (!initialize_queue_directories()) {
        return [];
    }
    
    return get_queues_from_directory(QUEUE_PENDING_DIR, 'pending');
}

function get_completed_queues_split() {
    if (!initialize_queue_directories()) {
        return [];
    }
    
    return get_queues_from_directory(QUEUE_COMPLETED_DIR, 'completed');
}

function get_queues_from_directory($directory, $status) {
    $queues = [];
    
    if (!is_dir($directory)) {
        return $queues;
    }
    
    $files = glob($directory . '*.json');
    
    foreach ($files as $file) {
        $content = file_get_contents($file);
        if ($content !== false) {
            $queue_data = json_decode($content, true);
            if ($queue_data) {
                $queue_data['status'] = $status;
                $queues[] = $queue_data;
            }
        }
    }
    
    // 최신순 정렬
    usort($queues, function($a, $b) {
        $timeA = $a['modified_at'] ?? $a['created_at'] ?? '0000-00-00 00:00:00';
        $timeB = $b['modified_at'] ?? $b['created_at'] ?? '0000-00-00 00:00:00';
        return strcmp($timeB, $timeA);
    });
    
    return $queues;
}

function load_queue_split($queue_id) {
    $pending_file = QUEUE_PENDING_DIR . $queue_id . '.json';
    $completed_file = QUEUE_COMPLETED_DIR . $queue_id . '.json';
    
    if (file_exists($pending_file)) {
        $content = file_get_contents($pending_file);
        if ($content !== false) {
            $data = json_decode($content, true);
            if ($data) {
                $data['status'] = 'pending';
                return $data;
            }
        }
    }
    
    if (file_exists($completed_file)) {
        $content = file_get_contents($completed_file);
        if ($content !== false) {
            $data = json_decode($content, true);
            if ($data) {
                $data['status'] = 'completed';
                return $data;
            }
        }
    }
    
    return false;
}

function update_queue_status_split_v2($queue_id, $new_status) {
    error_log("update_queue_status_split_v2 호출: queue_id=$queue_id, new_status=$new_status");
    
    if (!in_array($new_status, ['pending', 'completed'])) {
        error_log("유효하지 않은 상태: $new_status");
        return false;
    }
    
    $current_data = load_queue_split($queue_id);
    if (!$current_data) {
        error_log("큐 데이터를 찾을 수 없음: $queue_id");
        return false;
    }
    
    $current_status = $current_data['status'];
    error_log("현재 상태: $current_status, 새 상태: $new_status");
    
    if ($current_status === $new_status) {
        error_log("이미 같은 상태임");
        return true; // 이미 같은 상태
    }
    
    $old_file = ($current_status === 'pending') ? QUEUE_PENDING_DIR . $queue_id . '.json' : QUEUE_COMPLETED_DIR . $queue_id . '.json';
    $new_file = ($new_status === 'pending') ? QUEUE_PENDING_DIR . $queue_id . '.json' : QUEUE_COMPLETED_DIR . $queue_id . '.json';
    
    error_log("이동: $old_file -> $new_file");
    
    // 파일 존재 확인
    if (!file_exists($old_file)) {
        error_log("기존 파일이 없음: $old_file");
        // 파일이 없으면 다른 위치 확인
        $alt_file = ($current_status === 'completed') ? QUEUE_PENDING_DIR . $queue_id . '.json' : QUEUE_COMPLETED_DIR . $queue_id . '.json';
        if (file_exists($alt_file)) {
            error_log("대체 위치에서 파일 발견: $alt_file");
            $old_file = $alt_file;
        }
    }
    
    // 데이터 업데이트
    $current_data['status'] = $new_status;
    $current_data['modified_at'] = date('Y-m-d H:i:s');
    
    // 디렉토리 확인
    $new_dir = dirname($new_file);
    if (!is_dir($new_dir)) {
        error_log("디렉토리가 없음: $new_dir");
        if (!mkdir($new_dir, 0755, true)) {
            error_log("디렉토리 생성 실패: $new_dir");
            return false;
        }
    }
    
    // 새 위치에 파일 저장
    $json_data = json_encode($current_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $write_result = file_put_contents($new_file, $json_data);
    
    if ($write_result !== false) {
        error_log("새 파일 저장 성공: $new_file");
        
        // 기존 파일 삭제 (다른 위치인 경우만)
        if (file_exists($old_file) && $old_file !== $new_file) {
            if (unlink($old_file)) {
                error_log("기존 파일 삭제 성공: $old_file");
            } else {
                error_log("기존 파일 삭제 실패: $old_file");
            }
        }
        
        // 인덱스 업데이트
        update_queue_index($queue_id, $current_data);
        
        return true;
    } else {
        error_log("파일 저장 실패: $new_file");
        error_log("JSON 데이터 길이: " . strlen($json_data));
    }
    
    return false;
}

function remove_queue_split($queue_id) {
    $pending_file = QUEUE_PENDING_DIR . $queue_id . '.json';
    $completed_file = QUEUE_COMPLETED_DIR . $queue_id . '.json';
    
    $removed = false;
    
    if (file_exists($pending_file)) {
        $removed = unlink($pending_file);
    }
    
    if (file_exists($completed_file)) {
        $removed = unlink($completed_file) || $removed;
    }
    
    if ($removed) {
        remove_from_queue_index($queue_id);
    }
    
    return $removed;
}

function update_queue_index($queue_id, $data) {
    if (!file_exists(QUEUE_INDEX_FILE)) {
        $index = ['queues' => []];
    } else {
        $index_content = file_get_contents(QUEUE_INDEX_FILE);
        $index = json_decode($index_content, true) ?: ['queues' => []];
    }
    
    // 기존 항목 찾아서 업데이트 또는 새로 추가
    $found = false;
    foreach ($index['queues'] as &$queue) {
        if ($queue['queue_id'] === $queue_id) {
            $queue = [
                'queue_id' => $queue_id,
                'title' => $data['title'] ?? '',
                'status' => $data['status'] ?? 'pending',
                'category_id' => $data['category_id'] ?? '',
                'created_at' => $data['created_at'] ?? date('Y-m-d H:i:s'),
                'modified_at' => $data['modified_at'] ?? date('Y-m-d H:i:s'),
                'keywords' => $data['keywords'] ?? []
            ];
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        $index['queues'][] = [
            'queue_id' => $queue_id,
            'title' => $data['title'] ?? '',
            'status' => $data['status'] ?? 'pending',
            'category_id' => $data['category_id'] ?? '',
            'created_at' => $data['created_at'] ?? date('Y-m-d H:i:s'),
            'modified_at' => $data['modified_at'] ?? date('Y-m-d H:i:s'),
            'keywords' => $data['keywords'] ?? []
        ];
    }
    
    file_put_contents(QUEUE_INDEX_FILE, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function remove_from_queue_index($queue_id) {
    if (!file_exists(QUEUE_INDEX_FILE)) {
        return;
    }
    
    $index_content = file_get_contents(QUEUE_INDEX_FILE);
    $index = json_decode($index_content, true);
    
    if ($index && isset($index['queues'])) {
        $index['queues'] = array_filter($index['queues'], function($queue) use ($queue_id) {
            return $queue['queue_id'] !== $queue_id;
        });
        
        $index['queues'] = array_values($index['queues']); // 배열 인덱스 재정렬
        
        file_put_contents(QUEUE_INDEX_FILE, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

function cleanup_completed_queues_split($days_old = 7) {
    if (!initialize_queue_directories()) {
        return 0;
    }
    
    $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_old} days"));
    $cleaned_count = 0;
    
    $files = glob(QUEUE_COMPLETED_DIR . '*.json');
    
    foreach ($files as $file) {
        $content = file_get_contents($file);
        if ($content !== false) {
            $queue_data = json_decode($content, true);
            if ($queue_data && isset($queue_data['created_at'])) {
                if ($queue_data['created_at'] < $cutoff_date) {
                    if (unlink($file)) {
                        remove_from_queue_index($queue_data['queue_id'] ?? basename($file, '.json'));
                        $cleaned_count++;
                    }
                }
            }
        }
    }
    
    return $cleaned_count;
}

function get_queue_stats_split() {
    $pending_count = count(get_pending_queues_split());
    $completed_count = count(get_completed_queues_split());
    
    return [
        'total' => $pending_count + $completed_count,
        'pending' => $pending_count,
        'completed' => $completed_count
    ];
}

if (!function_exists('get_keywords_count')) {
    function get_keywords_count($keywords) {
        return is_array($keywords) ? count($keywords) : 0;
    }
}

if (!function_exists('get_products_count')) {
    function get_products_count($keywords) {
        $count = 0;
        if (is_array($keywords)) {
            foreach ($keywords as $keyword) {
                if (isset($keyword['products_data']) && is_array($keyword['products_data'])) {
                    $count += count($keyword['products_data']);
                }
                if (isset($keyword['aliexpress']) && is_array($keyword['aliexpress'])) {
                    $count += count($keyword['aliexpress']);
                }
                if (isset($keyword['coupang']) && is_array($keyword['coupang'])) {
                    $count += count($keyword['coupang']);
                }
            }
        }
        return $count;
    }
}

// 레거시 함수들 (하위 호환성)
function save_queue($data, $status = 'pending') {
    return save_queue_split($data, $status);
}

function get_pending_queues() {
    return get_pending_queues_split();
}

function get_completed_queues() {
    return get_completed_queues_split();
}

function load_queue($queue_id) {
    return load_queue_split($queue_id);
}

function update_queue_status($queue_id, $new_status) {
    return update_queue_status_split_v2($queue_id, $new_status);
}

function remove_queue($queue_id) {
    return remove_queue_split($queue_id);
}

error_log("Queue Utils v3.3 로드 완료 - 디버깅 로그 추가됨");
?>