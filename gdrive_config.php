<?php
/**
 * Google Drive API 설정 및 공통 함수
 * Google Drive 이미지 자동화 프로젝트
 * 
 * 이 파일은 Google Drive API 연결, 인증, 공통 기능을 제공합니다.
 */

// 에러 리포팅 설정
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 환경변수 로드 함수
function load_env_vars() {
    $env_path = '/home/novacents/.env';
    $env_vars = [];
    
    if (!file_exists($env_path)) {
        throw new Exception(".env 파일을 찾을 수 없습니다: {$env_path}");
    }
    
    $lines = file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) {
            continue;
        }
        
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $env_vars[trim($key)] = trim($value);
        }
    }
    
    return $env_vars;
}

// Google Drive API 클래스
class GoogleDriveAPI {
    private $service_account_file;
    private $original_folder_id;
    private $converted_folder_id;
    private $python_path;
    
    public function __construct() {
        // 환경변수 로드
        $env_vars = load_env_vars();
        
        // 필수 환경변수 확인
        $required_vars = [
            'GOOGLE_DRIVE_SERVICE_ACCOUNT_FILE',
            'GOOGLE_DRIVE_ORIGINAL_FOLDER_ID',
            'GOOGLE_DRIVE_CONVERTED_FOLDER_ID'
        ];
        
        foreach ($required_vars as $var) {
            if (!isset($env_vars[$var])) {
                throw new Exception("필수 환경변수가 설정되지 않았습니다: {$var}");
            }
        }
        
        $this->service_account_file = $env_vars['GOOGLE_DRIVE_SERVICE_ACCOUNT_FILE'];
        $this->original_folder_id = $env_vars['GOOGLE_DRIVE_ORIGINAL_FOLDER_ID'];
        $this->converted_folder_id = $env_vars['GOOGLE_DRIVE_CONVERTED_FOLDER_ID'];
        $this->python_path = '/usr/bin/python3';
        
        // 서비스 계정 파일 존재 확인
        if (!file_exists($this->service_account_file)) {
            throw new Exception("서비스 계정 파일을 찾을 수 없습니다: {$this->service_account_file}");
        }
    }
    
    /**
     * Python 스크립트를 실행하여 Google Drive API 호출
     */
    private function executePythonScript($script_content) {
        $temp_script = tempnam(sys_get_temp_dir(), 'gdrive_');
        file_put_contents($temp_script, $script_content);
        chmod($temp_script, 0755);
        
        try {
            $output = [];
            $return_code = 0;
            exec("{$this->python_path} {$temp_script} 2>&1", $output, $return_code);
            
            unlink($temp_script);
            
            if ($return_code !== 0) {
                throw new Exception("Python 스크립트 실행 실패: " . implode("\n", $output));
            }
            
            $result = implode("\n", $output);
            $json_result = json_decode($result, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("JSON 파싱 실패: " . $result);
            }
            
            return $json_result;
        } catch (Exception $e) {
            if (file_exists($temp_script)) {
                unlink($temp_script);
            }
            throw $e;
        }
    }
    
    /**
     * Original 폴더의 이미지 목록 가져오기
     */
    public function getOriginalImages($limit = 50) {
        $script = <<<PYTHON
import json
import sys
from google.oauth2 import service_account
from googleapiclient.discovery import build

try:
    # 서비스 계정 인증
    credentials = service_account.Credentials.from_service_account_file(
        '{$this->service_account_file}',
        scopes=['https://www.googleapis.com/auth/drive']
    )
    
    # Drive API 서비스 생성
    service = build('drive', 'v3', credentials=credentials)
    
    # 이미지 파일 목록 조회
    query = "'{$this->original_folder_id}' in parents and mimeType contains 'image/'"
    results = service.files().list(
        q=query,
        pageSize={$limit},
        fields="files(id, name, mimeType, createdTime, size, thumbnailLink, webViewLink)",
        orderBy="createdTime desc"
    ).execute()
    
    files = results.get('files', [])
    
    # 결과 출력
    print(json.dumps({
        'success': True,
        'files': files,
        'count': len(files)
    }))
    
except Exception as e:
    print(json.dumps({
        'success': False,
        'error': str(e)
    }))
PYTHON;

        return $this->executePythonScript($script);
    }
    
    /**
     * 파일 다운로드
     */
    public function downloadFile($file_id, $destination_path) {
        $script = <<<PYTHON
import json
import sys
import io
from google.oauth2 import service_account
from googleapiclient.discovery import build
from googleapiclient.http import MediaIoBaseDownload

try:
    # 서비스 계정 인증
    credentials = service_account.Credentials.from_service_account_file(
        '{$this->service_account_file}',
        scopes=['https://www.googleapis.com/auth/drive']
    )
    
    # Drive API 서비스 생성
    service = build('drive', 'v3', credentials=credentials)
    
    # 파일 다운로드
    request = service.files().get_media(fileId='{$file_id}')
    fh = io.FileIO('{$destination_path}', 'wb')
    downloader = MediaIoBaseDownload(fh, request)
    
    done = False
    while not done:
        status, done = downloader.next_chunk()
    
    fh.close()
    
    print(json.dumps({
        'success': True,
        'message': '파일 다운로드 완료'
    }))
    
except Exception as e:
    print(json.dumps({
        'success': False,
        'error': str(e)
    }))
PYTHON;

        return $this->executePythonScript($script);
    }
    
    /**
     * WebP 변환 및 업로드
     */
    public function convertAndUpload($source_path, $file_name, $quality = 85) {
        $script = <<<PYTHON
import json
import sys
import os
from PIL import Image
from google.oauth2 import service_account
from googleapiclient.discovery import build
from googleapiclient.http import MediaFileUpload

try:
    # 이미지를 WebP로 변환
    img = Image.open('{$source_path}')
    
    # RGBA 모드인 경우 RGB로 변환 (WebP 호환성)
    if img.mode in ('RGBA', 'LA'):
        background = Image.new('RGB', img.size, (255, 255, 255))
        background.paste(img, mask=img.split()[-1])
        img = background
    elif img.mode not in ('RGB', 'L'):
        img = img.convert('RGB')
    
    # WebP로 저장
    webp_path = '/tmp/' + os.path.splitext('{$file_name}')[0] + '.webp'
    img.save(webp_path, 'WEBP', quality={$quality}, method=6)
    
    # 서비스 계정 인증
    credentials = service_account.Credentials.from_service_account_file(
        '{$this->service_account_file}',
        scopes=['https://www.googleapis.com/auth/drive']
    )
    
    # Drive API 서비스 생성
    service = build('drive', 'v3', credentials=credentials)
    
    # 파일 메타데이터
    file_metadata = {
        'name': os.path.basename(webp_path),
        'parents': ['{$this->converted_folder_id}']
    }
    
    # 파일 업로드
    media = MediaFileUpload(webp_path, mimetype='image/webp')
    file = service.files().create(
        body=file_metadata,
        media_body=media,
        fields='id, name, webViewLink'
    ).execute()
    
    # 파일 공개 권한 설정
    permission = {
        'type': 'anyone',
        'role': 'reader'
    }
    service.permissions().create(
        fileId=file['id'],
        body=permission
    ).execute()
    
    # 임시 파일 삭제
    os.remove(webp_path)
    
    # 공개 URL 생성
    public_url = f"https://lh3.googleusercontent.com/d/{file['id']}"
    
    print(json.dumps({
        'success': True,
        'file_id': file['id'],
        'file_name': file['name'],
        'public_url': public_url,
        'web_view_link': file['webViewLink']
    }))
    
except Exception as e:
    print(json.dumps({
        'success': False,
        'error': str(e)
    }))
PYTHON;

        return $this->executePythonScript($script);
    }
    
    // Getter 메서드들
    public function getOriginalFolderId() {
        return $this->original_folder_id;
    }
    
    public function getConvertedFolderId() {
        return $this->converted_folder_id;
    }
}

// 헬퍼 함수들

/**
 * JSON 응답 전송
 */
function send_json_response($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * 에러 응답 전송
 */
function send_error_response($message, $code = 500) {
    http_response_code($code);
    send_json_response([
        'success' => false,
        'error' => $message
    ]);
}

/**
 * 성공 응답 전송
 */
function send_success_response($data) {
    send_json_response(array_merge(
        ['success' => true],
        $data
    ));
}

/**
 * 이미지 파일 확장자 확인
 */
function is_valid_image_extension($filename) {
    $valid_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($extension, $valid_extensions);
}

/**
 * 파일 크기를 읽기 쉬운 형태로 변환
 */
function format_file_size($bytes) {
    if ($bytes == 0) return '0 Bytes';
    
    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}

// 디버깅용 함수
if (!function_exists('dd')) {
    function dd($data) {
        echo '<pre>';
        var_dump($data);
        echo '</pre>';
        exit;
    }
}