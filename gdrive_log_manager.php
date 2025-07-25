<?php
/**
 * Google Drive 로그 파일 관리 시스템
 * - gdrive_debug_*.log 파일들을 자동으로 정리
 * - 성공한 작업의 로그는 즉시 삭제 또는 logs/gdrive/ 폴더로 이동
 * - 실패한 로그는 7일간 보관 후 삭제
 * - 30일 이상 된 로그는 자동 삭제
 */

class GdriveLogManager {
    private $toolsPath;
    private $logsPath;
    private $logPrefix = 'gdrive_debug_';
    
    public function __construct() {
        $this->toolsPath = __DIR__;
        $this->logsPath = __DIR__ . '/logs/gdrive';
        
        // logs/gdrive 디렉토리가 없으면 생성
        if (!is_dir($this->logsPath)) {
            mkdir($this->logsPath, 0755, true);
            echo "로그 디렉토리 생성: {$this->logsPath}\n";
        }
    }
    
    /**
     * 성공한 로그 파일 즉시 정리 (작업 완료 직후 호출)
     */
    public function cleanupSuccessLog($logFileName) {
        $logPath = $this->toolsPath . '/' . $logFileName;
        
        if (!file_exists($logPath)) {
            return false;
        }
        
        // 로그 내용 확인 - 성공 여부 판단
        $content = file_get_contents($logPath);
        $isSuccess = (strpos($content, '=== WebP 변환 및 업로드 성공 ===') !== false);
        
        if ($isSuccess) {
            // 성공한 로그는 즉시 삭제
            if (unlink($logPath)) {
                echo "성공 로그 삭제: {$logFileName}\n";
                return true;
            }
        } else {
            // 실패한 로그는 logs/gdrive 폴더로 이동
            $newPath = $this->logsPath . '/' . $logFileName;
            if (rename($logPath, $newPath)) {
                echo "실패 로그 이동: {$logFileName} -> logs/gdrive/\n";
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 모든 gdrive_debug 로그 파일 정리
     */
    public function cleanupAllLogs() {
        $cleaned = 0;
        $moved = 0;
        
        // tools 디렉토리의 로그 파일들 처리
        $files = glob($this->toolsPath . '/' . $this->logPrefix . '*.log');
        
        foreach ($files as $file) {
            $fileName = basename($file);
            $content = file_get_contents($file);
            $isSuccess = (strpos($content, '=== WebP 변환 및 업로드 성공 ===') !== false);
            
            if ($isSuccess) {
                // 성공한 로그는 삭제
                if (unlink($file)) {
                    $cleaned++;
                    echo "[성공 로그 삭제] {$fileName}\n";
                }
            } else {
                // 실패한 로그는 logs/gdrive로 이동
                $newPath = $this->logsPath . '/' . $fileName;
                if (rename($file, $newPath)) {
                    $moved++;
                    echo "[실패 로그 이동] {$fileName} -> logs/gdrive/\n";
                }
            }
        }
        
        echo "정리 완료: 성공 로그 {$cleaned}개 삭제, 실패 로그 {$moved}개 이동\n";
        
        // 오래된 로그 파일들 삭제
        $this->deleteOldLogs();
        
        return ['cleaned' => $cleaned, 'moved' => $moved];
    }
    
    /**
     * 7일 이상 된 실패 로그와 30일 이상 된 모든 로그 삭제
     */
    public function deleteOldLogs() {
        $deleted = 0;
        $now = time();
        $sevenDays = 7 * 24 * 3600;  // 7일
        $thirtyDays = 30 * 24 * 3600; // 30일
        
        // logs/gdrive 디렉토리의 파일들 확인
        if (is_dir($this->logsPath)) {
            $files = glob($this->logsPath . '/' . $this->logPrefix . '*.log');
            
            foreach ($files as $file) {
                $fileTime = filemtime($file);
                $age = $now - $fileTime;
                $fileName = basename($file);
                
                // 7일 이상 된 파일은 삭제 (실패 로그들)
                if ($age > $sevenDays) {
                    if (unlink($file)) {
                        $deleted++;
                        $days = round($age / (24 * 3600));
                        echo "[오래된 로그 삭제] {$fileName} ({$days}일 경과)\n";
                    }
                }
            }
        }
        
        // tools 디렉토리에 남아있는 30일 이상 된 로그들도 삭제
        $files = glob($this->toolsPath . '/' . $this->logPrefix . '*.log');
        foreach ($files as $file) {
            $fileTime = filemtime($file);
            $age = $now - $fileTime;
            $fileName = basename($file);
            
            if ($age > $thirtyDays) {
                if (unlink($file)) {
                    $deleted++;
                    $days = round($age / (24 * 3600));
                    echo "[오래된 로그 삭제] {$fileName} ({$days}일 경과)\n";
                }
            }
        }
        
        if ($deleted > 0) {
            echo "오래된 로그 {$deleted}개 삭제 완료\n";
        }
        
        return $deleted;
    }
    
    /**
     * 로그 파일 통계 조회
     */
    public function getLogStats() {
        $stats = [
            'tools_success' => 0,
            'tools_failed' => 0,
            'logs_failed' => 0,
            'total_size' => 0
        ];
        
        // tools 디렉토리의 로그 파일들
        $files = glob($this->toolsPath . '/' . $this->logPrefix . '*.log');
        foreach ($files as $file) {
            $content = file_get_contents($file);
            $isSuccess = (strpos($content, '=== WebP 변환 및 업로드 성공 ===') !== false);
            
            if ($isSuccess) {
                $stats['tools_success']++;
            } else {
                $stats['tools_failed']++;
            }
            
            $stats['total_size'] += filesize($file);
        }
        
        // logs/gdrive 디렉토리의 로그 파일들
        if (is_dir($this->logsPath)) {
            $files = glob($this->logsPath . '/' . $this->logPrefix . '*.log');
            foreach ($files as $file) {
                $stats['logs_failed']++;
                $stats['total_size'] += filesize($file);
            }
        }
        
        return $stats;
    }
    
    /**
     * 로그 파일 목록 조회
     */
    public function listLogs() {
        $logs = [];
        
        // tools 디렉토리의 로그 파일들
        $files = glob($this->toolsPath . '/' . $this->logPrefix . '*.log');
        foreach ($files as $file) {
            $fileName = basename($file);
            $size = filesize($file);
            $modified = date('Y-m-d H:i:s', filemtime($file));
            $content = file_get_contents($file);
            $status = (strpos($content, '=== WebP 변환 및 업로드 성공 ===') !== false) ? 'success' : 'failed';
            
            $logs[] = [
                'file' => $fileName,
                'location' => 'tools',
                'status' => $status,
                'size' => $size,
                'modified' => $modified
            ];
        }
        
        // logs/gdrive 디렉토리의 로그 파일들
        if (is_dir($this->logsPath)) {
            $files = glob($this->logsPath . '/' . $this->logPrefix . '*.log');
            foreach ($files as $file) {
                $fileName = basename($file);
                $size = filesize($file);
                $modified = date('Y-m-d H:i:s', filemtime($file));
                
                $logs[] = [
                    'file' => $fileName,
                    'location' => 'logs/gdrive',
                    'status' => 'failed',
                    'size' => $size,
                    'modified' => $modified
                ];
            }
        }
        
        // 최신 파일부터 정렬
        usort($logs, function($a, $b) {
            return strcmp($b['modified'], $a['modified']);
        });
        
        return $logs;
    }
}

// CLI에서 직접 실행할 수 있도록
if (php_sapi_name() === 'cli' && isset($argv)) {
    $manager = new GdriveLogManager();
    
    $command = $argv[1] ?? 'cleanup';
    
    switch ($command) {
        case 'cleanup':
            echo "=== Google Drive 로그 정리 시작 ===\n";
            $result = $manager->cleanupAllLogs();
            echo "=== 정리 완료 ===\n";
            break;
            
        case 'stats':
            echo "=== Google Drive 로그 통계 ===\n";
            $stats = $manager->getLogStats();
            echo "Tools 디렉토리 성공 로그: {$stats['tools_success']}개\n";
            echo "Tools 디렉토리 실패 로그: {$stats['tools_failed']}개\n";
            echo "Logs/gdrive 실패 로그: {$stats['logs_failed']}개\n";
            echo "전체 로그 크기: " . number_format($stats['total_size']) . " bytes\n";
            break;
            
        case 'list':
            echo "=== Google Drive 로그 목록 ===\n";
            $logs = $manager->listLogs();
            foreach ($logs as $log) {
                $status = $log['status'] === 'success' ? '✅' : '❌';
                echo "{$status} [{$log['location']}] {$log['file']} ({$log['size']} bytes) - {$log['modified']}\n";
            }
            break;
            
        case 'delete-old':
            echo "=== 오래된 로그 삭제 ===\n";
            $deleted = $manager->deleteOldLogs();
            echo "삭제된 파일: {$deleted}개\n";
            break;
            
        default:
            echo "사용법: php gdrive_log_manager.php [cleanup|stats|list|delete-old]\n";
            echo "  cleanup    : 모든 로그 정리 (기본값)\n";
            echo "  stats      : 로그 통계 조회\n";
            echo "  list       : 로그 파일 목록\n";
            echo "  delete-old : 오래된 로그만 삭제\n";
            break;
    }
}
?>