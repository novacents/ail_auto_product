<?php
/**
 * 이미지 처리 백엔드
 * Google Drive 이미지 자동화 프로젝트
 * 
 * 이미지 다운로드, WebP 변환, Google Drive 업로드, 공개 URL 생성 처리
 */

// 설정 파일 포함
require_once 'gdrive_config.php';

// 에러 로깅 활성화
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/image_processor_error.log');

// CORS 헤더 설정 (AJAX 요청 허용)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// OPTIONS 요청 처리 (브라우저 preflight 요청)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// POST 요청만 허용
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_error_response('POST 요청만 허용됩니다', 405);
}

try {
    // 액션 파라미터 확인
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['action'])) {
        send_error_response('액션이 지정되지 않았습니다');
    }
    
    $action = $input['action'];
    
    // 디버그 로그
    error_log("Image Processor - Action: " . $action);
    
    // Google Drive API 인스턴스 생성
    $gdrive = new GoogleDriveAPI();
    
    switch ($action) {
        case 'list_images':
            handleListImages($gdrive, $input);
            break;
            
        case 'process_image':
            handleProcessImage($gdrive, $input);
            break;
            
        case 'test_connection':
            handleTestConnection($gdrive);
            break;
            
        default:
            send_error_response('알 수 없는 액션입니다: ' . $action);
    }
    
} catch (Exception $e) {
    error_log('Image Processor Error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    send_error_response('처리 중 오류가 발생했습니다: ' . $e->getMessage());
}

/**
 * 이미지 목록 조회 처리
 */
function handleListImages($gdrive, $input) {
    $limit = isset($input['limit']) ? intval($input['limit']) : 50;
    
    if ($limit < 1 || $limit > 100) {
        $limit = 50;
    }
    
    $result = $gdrive->getOriginalImages($limit);
    
    if (!$result['success']) {
        send_error_response('이미지 목록 조회 실패: ' . $result['error']);
    }
    
    // 이미지 파일만 필터링
    $images = array_filter($result['files'], function($file) {
        return is_valid_image_extension($file['name']);
    });
    
    // 추가 정보 처리
    foreach ($images as &$image) {
        // 파일 크기 포맷팅
        if (isset($image['size'])) {
            $image['formatted_size'] = format_file_size($image['size']);
        }
        
        // 생성 시간 포맷팅
        if (isset($image['createdTime'])) {
            $image['formatted_date'] = date('Y-m-d H:i', strtotime($image['createdTime']));
        }
        
        // 썸네일 URL 보정
        if (!isset($image['thumbnailLink']) || empty($image['thumbnailLink'])) {
            $image['thumbnailLink'] = 'data:image/svg+xml;base64,' . base64_encode(
                '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100">' .
                '<rect width="100" height="100" fill="#f0f0f0"/>' .
                '<text x="50" y="50" text-anchor="middle" dy=".3em" font-family="Arial" font-size="12" fill="#666">이미지</text>' .
                '</svg>'
            );
        }
    }
    
    send_success_response([
        'images' => array_values($images),
        'total_count' => count($images),
        'original_count' => count($result['files'])
    ]);
}

/**
 * 이미지 처리 (다운로드, 변환, 업로드) 처리
 */
function handleProcessImage($gdrive, $input) {
    if (!isset($input['file_id']) || empty($input['file_id'])) {
        send_error_response('파일 ID가 지정되지 않았습니다');
    }
    
    $file_id = $input['file_id'];
    $quality = isset($input['quality']) ? intval($input['quality']) : 85;
    
    // 품질 범위 검증
    if ($quality < 10 || $quality > 100) {
        $quality = 85;
    }
    
    error_log("Processing image - File ID: {$file_id}, Quality: {$quality}");
    
    // 임시 디렉토리 생성
    $temp_dir = '/tmp/gdrive_image_process_' . uniqid();
    if (!mkdir($temp_dir, 0755, true)) {
        send_error_response('임시 디렉토리 생성 실패');
    }
    
    error_log("Created temp directory: {$temp_dir}");
    
    try {
        // 1단계: 원본 이미지 다운로드
        $original_path = $temp_dir . '/original_image';
        
        error_log("Step 1: Downloading file to {$original_path}");
        $download_result = $gdrive->downloadFile($file_id, $original_path);
        
        if (!$download_result['success']) {
            throw new Exception('이미지 다운로드 실패: ' . $download_result['error']);
        }
        
        if (!file_exists($original_path)) {
            throw new Exception('다운로드된 파일을 찾을 수 없습니다');
        }
        
        error_log("Step 1 완료: 파일 다운로드 성공 (크기: " . filesize($original_path) . " bytes)");
        
        // 2단계: 파일 정보 확인
        error_log("Step 2: 파일 정보 확인");
        $file_info = getimagesize($original_path);
        if ($file_info === false) {
            throw new Exception('유효하지 않은 이미지 파일입니다');
        }
        
        error_log("Step 2 완료: 이미지 정보 - {$file_info[0]}x{$file_info[1]}, MIME: {$file_info['mime']}");
        
        // 원본 파일명 가져오기 (API 호출)
        error_log("Step 3: 원본 파일명 가져오기");
        $original_filename = getOriginalFileName($gdrive, $file_id);
        error_log("Step 3 완료: 원본 파일명 - {$original_filename}");
        
        // 3단계: WebP 변환 및 업로드
        error_log("Step 4: WebP 변환 및 업로드 시작");
        $convert_result = $gdrive->convertAndUpload($original_path, $original_filename, $quality);
        
        if (!$convert_result['success']) {
            error_log("Step 4 실패: " . $convert_result['error']);
            throw new Exception('이미지 변환 및 업로드 실패: ' . $convert_result['error']);
        }
        
        error_log("Step 4 완료: 변환 및 업로드 성공");
        
        // 작업 완료 후 로그 정리 (성공한 경우)
        cleanupGdriveDebugLogs(true);
        
        // 성공 응답
        send_success_response([
            'message' => '이미지 처리가 완료되었습니다',
            'original_file_id' => $file_id,
            'converted_file_id' => $convert_result['file_id'],
            'converted_filename' => $convert_result['file_name'],
            'public_url' => $convert_result['public_url'],
            'web_view_link' => $convert_result['web_view_link'],
            'original_info' => [
                'width' => $file_info[0],
                'height' => $file_info[1],
                'mime_type' => $file_info['mime'],
                'filename' => $original_filename
            ],
            'processing_info' => [
                'quality' => $quality,
                'processed_at' => date('Y-m-d H:i:s')
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("이미지 처리 실패: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        
        // 작업 실패 후 로그 정리 (실패한 경우)
        cleanupGdriveDebugLogs(false);
        
        throw $e;
    } finally {
        // 임시 파일 및 디렉토리 정리
        cleanupTempDirectory($temp_dir);
    }
}

/**
 * Google Drive 디버그 로그 정리
 */
function cleanupGdriveDebugLogs($success = true) {
    try {
        // 로그 관리자 클래스 포함
        require_once __DIR__ . '/gdrive_log_manager.php';
        
        $logManager = new GdriveLogManager();
        
        // 가장 최근 로그 파일 찾기
        $logFiles = glob(__DIR__ . '/gdrive_debug_*.log');
        
        if (!empty($logFiles)) {
            // 가장 최근 파일 (수정 시간 기준)
            usort($logFiles, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            
            $latestLogFile = basename($logFiles[0]);
            
            // 성공한 경우 즉시 정리, 실패한 경우 logs/gdrive로 이동
            if ($success) {
                error_log("Google Drive 작업 성공 - 로그 파일 정리: {$latestLogFile}");
                $logManager->cleanupSuccessLog($latestLogFile);
            } else {
                error_log("Google Drive 작업 실패 - 로그 파일 보관: {$latestLogFile}");
                // 실패한 로그는 자동으로 logs/gdrive로 이동됨 (다음 정리 작업에서)
            }
        }
        
        // 전체 로그 정리 (오래된 로그들)
        $logManager->deleteOldLogs();
        
    } catch (Exception $e) {
        error_log("로그 정리 중 오류: " . $e->getMessage());
        // 로그 정리 실패해도 메인 작업에는 영향 없음
    }
}

/**
 * 연결 테스트 처리
 */
function handleTestConnection($gdrive) {
    try {
        // 간단한 폴더 접근 테스트
        $result = $gdrive->getOriginalImages(1);
        
        if ($result['success']) {
            send_success_response([
                'message' => 'Google Drive API 연결이 정상입니다',
                'test_timestamp' => date('Y-m-d H:i:s'),
                'original_folder_id' => $gdrive->getOriginalFolderId(),
                'converted_folder_id' => $gdrive->getConvertedFolderId(),
                'sample_file_count' => count($result['files'])
            ]);
        } else {
            send_error_response('Google Drive API 연결 실패: ' . $result['error']);
        }
        
    } catch (Exception $e) {
        send_error_response('연결 테스트 실패: ' . $e->getMessage());
    }
}

/**
 * 원본 파일명 가져오기
 */
function getOriginalFileName($gdrive, $file_id) {
    $temp_script = tempnam(sys_get_temp_dir(), 'gdrive_filename_');
    
    $env_vars = load_env_vars();
    $service_account_file = $env_vars['GOOGLE_DRIVE_SERVICE_ACCOUNT_FILE'];
    
    $script = <<<PYTHON
import json
from google.oauth2 import service_account
from googleapiclient.discovery import build

try:
    # 서비스 계정 인증
    credentials = service_account.Credentials.from_service_account_file(
        '{$service_account_file}',
        scopes=['https://www.googleapis.com/auth/drive']
    )
    
    # Drive API 서비스 생성
    service = build('drive', 'v3', credentials=credentials)
    
    # 파일 정보 가져오기
    file = service.files().get(fileId='{$file_id}', fields='name').execute()
    
    print(json.dumps({
        'success': True,
        'filename': file['name']
    }))
    
except Exception as e:
    print(json.dumps({
        'success': False,
        'error': str(e)
    }))
PYTHON;

    file_put_contents($temp_script, $script);
    chmod($temp_script, 0755);
    
    try {
        $output = [];
        $return_code = 0;
        exec("/usr/bin/python3 {$temp_script} 2>&1", $output, $return_code);
        
        unlink($temp_script);
        
        if ($return_code !== 0) {
            return "unknown_file_" . time() . ".jpg";
        }
        
        $result = json_decode(implode("\n", $output), true);
        
        if (!$result || !$result['success']) {
            return "unknown_file_" . time() . ".jpg";
        }
        
        return $result['filename'];
        
    } catch (Exception $e) {
        if (file_exists($temp_script)) {
            unlink($temp_script);
        }
        return "unknown_file_" . time() . ".jpg";
    }
}

/**
 * 임시 디렉토리 정리
 */
function cleanupTempDirectory($temp_dir) {
    if (!is_dir($temp_dir)) {
        return;
    }
    
    try {
        $files = glob($temp_dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($temp_dir);
    } catch (Exception $e) {
        error_log('임시 디렉토리 정리 실패: ' . $e->getMessage());
    }
}

/**
 * 프로세스 상태 확인 (선택적)
 */
function checkProcessStatus($process_id = null) {
    // 향후 대용량 이미지 처리 시 사용할 수 있는 상태 체크 함수
    return [
        'status' => 'ready',
        'message' => '처리 준비 완료'
    ];
}

// 디버깅용 엔드포인트 (개발 환경에서만 사용)
if (isset($_GET['debug']) && $_GET['debug'] === 'info') {
    phpinfo();
    exit;
}