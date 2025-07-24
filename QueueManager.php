<?php
/**
 * 파일 분할 방식 큐 관리 시스템
 * 큐를 개별 파일로 저장하여 성능 최적화
 * 
 * @author Claude AI
 * @version 1.0
 * @date 2025-07-24
 */

class QueueManager {
    
    private $baseDir;
    private $pendingDir;
    private $processingDir;
    private $completedDir;
    private $indexFile;
    private $legacyFile;
    
    public function __construct() {
        $this->baseDir = '/var/www/novacents/tools';
        $this->pendingDir = $this->baseDir . '/queues/pending';
        $this->processingDir = $this->baseDir . '/queues/processing';
        $this->completedDir = $this->baseDir . '/queues/completed';
        $this->indexFile = $this->baseDir . '/queue_index.json';
        $this->legacyFile = $this->baseDir . '/product_queue.json';
        
        $this->initializeDirectories();
    }
    
    /**
     * 디렉토리 초기화
     */
    private function initializeDirectories() {
        $dirs = [$this->pendingDir, $this->processingDir, $this->completedDir];
        
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0755, true)) {
                    throw new Exception("디렉토리 생성 실패: {$dir}");
                }
            }
        }
        
        // 인덱스 파일 초기화
        if (!file_exists($this->indexFile)) {
            $this->saveIndex([]);
        }
    }
    
    /**
     * 새 큐 추가
     */
    public function addQueue($queueData) {
        $queueId = $this->generateQueueId();
        $timestamp = date('YmdHis');
        $filename = "queue_{$timestamp}_{$queueId}.json";
        $filepath = $this->pendingDir . '/' . $filename;
        
        // 큐 데이터에 메타정보 추가
        $queueData['queue_id'] = $queueId;
        $queueData['status'] = 'pending';
        $queueData['created_at'] = date('Y-m-d H:i:s');
        $queueData['updated_at'] = date('Y-m-d H:i:s');
        $queueData['attempts'] = 0;
        $queueData['filename'] = $filename;
        
        // 개별 큐 파일 저장
        if (!file_put_contents($filepath, json_encode($queueData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
            throw new Exception("큐 파일 저장 실패: {$filepath}");
        }
        
        // 인덱스 업데이트
        $this->updateIndex($queueId, [
            'queue_id' => $queueId,
            'filename' => $filename,
            'status' => 'pending',
            'title' => $queueData['title'] ?? '',
            'created_at' => $queueData['created_at'],
            'updated_at' => $queueData['updated_at'],
            'attempts' => 0
        ]);
        
        // 레거시 파일 업데이트 (호환성)
        $this->updateLegacyFile();
        
        return $queueId;
    }
    
    /**
     * 대기 중인 큐 목록 조회
     */
    public function getPendingQueues($limit = null) {
        $index = $this->loadIndex();
        $pendingQueues = array_filter($index, function($queue) {
            return $queue['status'] === 'pending';
        });
        
        // 생성 시간순 정렬 (오래된 것부터)
        usort($pendingQueues, function($a, $b) {
            return strtotime($a['created_at']) - strtotime($b['created_at']);
        });
        
        if ($limit !== null) {
            $pendingQueues = array_slice($pendingQueues, 0, $limit);
        }
        
        // 실제 큐 데이터 로드
        $queues = [];
        foreach ($pendingQueues as $queueInfo) {
            $queueData = $this->loadQueue($queueInfo['queue_id']);
            if ($queueData) {
                $queues[] = $queueData;
            }
        }
        
        return $queues;
    }
    
    /**
     * 특정 큐 데이터 로드
     */
    public function loadQueue($queueId) {
        $index = $this->loadIndex();
        
        if (!isset($index[$queueId])) {
            return null;
        }
        
        $queueInfo = $index[$queueId];
        $status = $queueInfo['status'];
        $filename = $queueInfo['filename'];
        
        // 상태에 따라 디렉토리 결정
        $dir = $this->getDirectoryByStatus($status);
        $filepath = $dir . '/' . $filename;
        
        if (!file_exists($filepath)) {
            // 인덱스에는 있지만 파일이 없는 경우
            $this->removeFromIndex($queueId);
            return null;
        }
        
        $content = file_get_contents($filepath);
        return json_decode($content, true);
    }
    
    /**
     * 큐 상태 업데이트
     */
    public function updateQueueStatus($queueId, $status, $errorMessage = null) {
        $queueData = $this->loadQueue($queueId);
        if (!$queueData) {
            throw new Exception("큐를 찾을 수 없습니다: {$queueId}");
        }
        
        $oldStatus = $queueData['status'];
        $oldFilename = $queueData['filename'];
        
        // 큐 데이터 업데이트
        $queueData['status'] = $status;
        $queueData['updated_at'] = date('Y-m-d H:i:s');
        
        if ($errorMessage) {
            $queueData['last_error'] = $errorMessage;
            $queueData['attempts'] = ($queueData['attempts'] ?? 0) + 1;
        }
        
        // 상태가 변경되면 파일 이동
        $oldDir = $this->getDirectoryByStatus($oldStatus);
        $newDir = $this->getDirectoryByStatus($status);
        
        $oldPath = $oldDir . '/' . $oldFilename;
        $newPath = $newDir . '/' . $oldFilename;
        
        // 새 위치에 파일 저장
        if (!file_put_contents($newPath, json_encode($queueData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
            throw new Exception("큐 파일 업데이트 실패: {$newPath}");
        }
        
        // 기존 파일 삭제 (다른 디렉토리인 경우)
        if ($oldPath !== $newPath && file_exists($oldPath)) {
            unlink($oldPath);
        }
        
        // 인덱스 업데이트
        $this->updateIndex($queueId, [
            'queue_id' => $queueId,
            'filename' => $oldFilename,
            'status' => $status,
            'title' => $queueData['title'] ?? '',
            'created_at' => $queueData['created_at'],
            'updated_at' => $queueData['updated_at'],
            'attempts' => $queueData['attempts'] ?? 0
        ]);
        
        // 레거시 파일 업데이트
        $this->updateLegacyFile();
        
        return true;
    }
    
    /**
     * 큐 삭제 (즉시 발행 등)
     */
    public function removeQueue($queueId) {
        $queueData = $this->loadQueue($queueId);
        if (!$queueData) {
            return false;
        }
        
        $status = $queueData['status'];
        $filename = $queueData['filename'];
        $dir = $this->getDirectoryByStatus($status);
        $filepath = $dir . '/' . $filename;
        
        // 파일 삭제
        if (file_exists($filepath)) {
            unlink($filepath);
        }
        
        // 인덱스에서 제거
        $this->removeFromIndex($queueId);
        
        // 레거시 파일 업데이트
        $this->updateLegacyFile();
        
        return true;
    }
    
    /**
     * 완료된 큐 정리 (7일 이상 된 것들)
     */
    public function cleanupCompletedQueues($daysOld = 7) {
        $cutoffTime = time() - ($daysOld * 24 * 60 * 60);
        $index = $this->loadIndex();
        $cleanedCount = 0;
        
        foreach ($index as $queueId => $queueInfo) {
            if ($queueInfo['status'] === 'completed') {
                $createdTime = strtotime($queueInfo['created_at']);
                if ($createdTime < $cutoffTime) {
                    $this->removeQueue($queueId);
                    $cleanedCount++;
                }
            }
        }
        
        return $cleanedCount;
    }
    
    /**
     * 큐 통계 정보
     */
    public function getQueueStats() {
        $index = $this->loadIndex();
        $stats = [
            'total' => count($index),
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0
        ];
        
        foreach ($index as $queueInfo) {
            $status = $queueInfo['status'];
            if (isset($stats[$status])) {
                $stats[$status]++;
            }
        }
        
        return $stats;
    }
    
    /**
     * 기존 product_queue.json에서 마이그레이션
     */
    public function migrateFromLegacyFile() {
        if (!file_exists($this->legacyFile)) {
            return ['migrated' => 0, 'errors' => []];
        }
        
        $content = file_get_contents($this->legacyFile);
        $legacyQueues = json_decode($content, true);
        
        if (!is_array($legacyQueues)) {
            return ['migrated' => 0, 'errors' => ['Invalid JSON format']];
        }
        
        $migrated = 0;
        $errors = [];
        
        foreach ($legacyQueues as $queueData) {
            try {
                // 기존 queue_id가 있으면 사용, 없으면 새로 생성
                if (!isset($queueData['queue_id']) || empty($queueData['queue_id'])) {
                    $queueData['queue_id'] = $this->generateQueueId();
                }
                
                // 필수 필드 확인 및 기본값 설정
                if (!isset($queueData['status'])) {
                    $queueData['status'] = 'pending';
                }
                if (!isset($queueData['created_at'])) {
                    $queueData['created_at'] = date('Y-m-d H:i:s');
                }
                if (!isset($queueData['updated_at'])) {
                    $queueData['updated_at'] = date('Y-m-d H:i:s');
                }
                if (!isset($queueData['attempts'])) {
                    $queueData['attempts'] = 0;
                }
                
                // 개별 파일로 저장
                $this->saveQueueFile($queueData);
                $migrated++;
                
            } catch (Exception $e) {
                $errors[] = "Queue migration failed: " . $e->getMessage();
            }
        }
        
        // 마이그레이션 완료 후 레거시 파일 백업
        if ($migrated > 0) {
            $backupFile = $this->legacyFile . '.backup.' . date('YmdHis');
            copy($this->legacyFile, $backupFile);
        }
        
        return ['migrated' => $migrated, 'errors' => $errors];
    }
    
    // ========================================
    // Private 헬퍼 메서드들
    // ========================================
    
    private function generateQueueId() {
        return 'queue_' . date('YmdHis') . '_' . mt_rand(1000, 9999);
    }
    
    private function getDirectoryByStatus($status) {
        switch ($status) {
            case 'pending':
                return $this->pendingDir;
            case 'processing':
                return $this->processingDir;
            case 'completed':
            case 'failed':
                return $this->completedDir;
            default:
                return $this->pendingDir;
        }
    }
    
    private function loadIndex() {
        if (!file_exists($this->indexFile)) {
            return [];
        }
        
        $content = file_get_contents($this->indexFile);
        $index = json_decode($content, true);
        
        return is_array($index) ? $index : [];
    }
    
    private function saveIndex($index) {
        return file_put_contents($this->indexFile, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    private function updateIndex($queueId, $queueInfo) {
        $index = $this->loadIndex();
        $index[$queueId] = $queueInfo;
        return $this->saveIndex($index);
    }
    
    private function removeFromIndex($queueId) {
        $index = $this->loadIndex();
        unset($index[$queueId]);
        return $this->saveIndex($index);
    }
    
    private function saveQueueFile($queueData) {
        $queueId = $queueData['queue_id'];
        $status = $queueData['status'];
        $timestamp = date('YmdHis');
        $filename = "queue_{$timestamp}_{$queueId}.json";
        
        $queueData['filename'] = $filename;
        
        $dir = $this->getDirectoryByStatus($status);
        $filepath = $dir . '/' . $filename;
        
        if (!file_put_contents($filepath, json_encode($queueData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
            throw new Exception("큐 파일 저장 실패: {$filepath}");
        }
        
        // 인덱스 업데이트
        $this->updateIndex($queueId, [
            'queue_id' => $queueId,
            'filename' => $filename,
            'status' => $status,
            'title' => $queueData['title'] ?? '',
            'created_at' => $queueData['created_at'],
            'updated_at' => $queueData['updated_at'],
            'attempts' => $queueData['attempts'] ?? 0
        ]);
    }
    
    /**
     * 호환성을 위한 레거시 파일 업데이트
     * 기존 코드가 product_queue.json을 읽을 수 있도록 유지
     */
    private function updateLegacyFile() {
        $pendingQueues = $this->getPendingQueues();
        
        // 기본 형태로 변환 (기존 코드 호환)
        $legacyFormat = [];
        foreach ($pendingQueues as $queue) {
            $legacyFormat[] = $queue;
        }
        
        file_put_contents($this->legacyFile, json_encode($legacyFormat, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

/**
 * 전역 함수들 - 기존 코드와의 호환성을 위해
 */

/**
 * QueueManager 인스턴스 가져오기
 */
function getQueueManager() {
    static $instance = null;
    if ($instance === null) {
        $instance = new QueueManager();
    }
    return $instance;
}

/**
 * 큐 추가 (기존 코드 호환)
 */
function addToQueue($queueData) {
    return getQueueManager()->addQueue($queueData);
}

/**
 * 대기 중인 큐 가져오기 (기존 코드 호환)
 */
function getPendingQueues($limit = null) {
    return getQueueManager()->getPendingQueues($limit);
}

/**
 * 큐 상태 업데이트 (기존 코드 호환)
 */
function updateQueueStatus($queueId, $status, $errorMessage = null) {
    return getQueueManager()->updateQueueStatus($queueId, $status, $errorMessage);
}

/**
 * 큐 삭제 (기존 코드 호환)
 */
function removeFromQueue($queueId) {
    return getQueueManager()->removeQueue($queueId);
}