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
                throw new Exception("Python 스크립트 실행 실패 (종료 코드: {$return_code}): " . implode("\n", $output));
            }
            
            $result = implode("\n", $output);
            $json_result = json_decode($result, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("JSON 파싱 실패 (JSON 오류: " . json_last_error_msg() . "): " . $result);
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
import traceback
from google.oauth2 import service_account
from googleapiclient.discovery import build

try:
    print("DEBUG: 서비스 계정 인증 시작", file=sys.stderr)
    
    # 서비스 계정 인증
    credentials = service_account.Credentials.from_service_account_file(
        '{$this->service_account_file}',
        scopes=['https://www.googleapis.com/auth/drive']
    )
    
    print("DEBUG: Drive API 서비스 생성", file=sys.stderr)
    
    # Drive API 서비스 생성
    service = build('drive', 'v3', credentials=credentials)
    
    print("DEBUG: 이미지 파일 목록 조회 시작", file=sys.stderr)
    
    # 이미지 파일 목록 조회
    query = "'{$this->original_folder_id}' in parents and mimeType contains 'image/'"
    results = service.files().list(
        q=query,
        pageSize={$limit},
        fields="files(id, name, mimeType, createdTime, size, thumbnailLink, webViewLink)",
        orderBy="createdTime desc"
    ).execute()
    
    files = results.get('files', [])
    
    print(f"DEBUG: {len(files)}개 파일 조회 완료", file=sys.stderr)
    
    # 결과 출력
    print(json.dumps({
        'success': True,
        'files': files,
        'count': len(files)
    }))
    
except Exception as e:
    print(f"DEBUG: 예외 발생 - {str(e)}", file=sys.stderr)
    print(f"DEBUG: 스택 트레이스 - {traceback.format_exc()}", file=sys.stderr)
    print(json.dumps({
        'success': False,
        'error': str(e),
        'traceback': traceback.format_exc()
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
import traceback
from google.oauth2 import service_account
from googleapiclient.discovery import build
from googleapiclient.http import MediaIoBaseDownload

try:
    print(f"DEBUG: 파일 다운로드 시작 - ID: {$file_id}, 경로: {$destination_path}", file=sys.stderr)
    
    # 서비스 계정 인증
    credentials = service_account.Credentials.from_service_account_file(
        '{$this->service_account_file}',
        scopes=['https://www.googleapis.com/auth/drive']
    )
    
    print("DEBUG: 서비스 계정 인증 완료", file=sys.stderr)
    
    # Drive API 서비스 생성
    service = build('drive', 'v3', credentials=credentials)
    
    print("DEBUG: Drive API 서비스 생성 완료", file=sys.stderr)
    
    # 파일 다운로드
    request = service.files().get_media(fileId='{$file_id}')
    fh = io.FileIO('{$destination_path}', 'wb')
    downloader = MediaIoBaseDownload(fh, request)
    
    print("DEBUG: 파일 다운로드 진행 중...", file=sys.stderr)
    
    done = False
    while not done:
        status, done = downloader.next_chunk()
        if status:
            print(f"DEBUG: 다운로드 진행률: {int(status.progress() * 100)}%", file=sys.stderr)
    
    fh.close()
    
    print("DEBUG: 파일 다운로드 완료", file=sys.stderr)
    
    print(json.dumps({
        'success': True,
        'message': '파일 다운로드 완료'
    }))
    
except Exception as e:
    print(f"DEBUG: 다운로드 예외 발생 - {str(e)}", file=sys.stderr)
    print(f"DEBUG: 스택 트레이스 - {traceback.format_exc()}", file=sys.stderr)
    print(json.dumps({
        'success': False,
        'error': str(e),
        'traceback': traceback.format_exc()
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
import traceback
from PIL import Image
from google.oauth2 import service_account
from googleapiclient.discovery import build
from googleapiclient.http import MediaFileUpload

try:
    print(f"DEBUG: 이미지 변환 시작 - 소스: {$source_path}, 파일명: {$file_name}, 품질: {$quality}", file=sys.stderr)
    
    # 파일 존재 확인
    if not os.path.exists('{$source_path}'):
        raise Exception("소스 파일이 존재하지 않습니다: {$source_path}")
    
    print(f"DEBUG: 소스 파일 크기: {os.path.getsize('{$source_path}')} bytes", file=sys.stderr)
    
    # 이미지 열기
    print("DEBUG: 이미지 파일 열기...", file=sys.stderr)
    img = Image.open('{$source_path}')
    
    print(f"DEBUG: 이미지 정보 - 크기: {img.size}, 모드: {img.mode}", file=sys.stderr)
    
    # RGBA 모드인 경우 RGB로 변환 (WebP 호환성)
    if img.mode in ('RGBA', 'LA'):
        print("DEBUG: RGBA/LA 모드를 RGB로 변환", file=sys.stderr)
        background = Image.new('RGB', img.size, (255, 255, 255))
        background.paste(img, mask=img.split()[-1])
        img = background
    elif img.mode not in ('RGB', 'L'):
        print(f"DEBUG: {img.mode} 모드를 RGB로 변환", file=sys.stderr)
        img = img.convert('RGB')
    
    # WebP로 저장
    webp_path = '/tmp/' + os.path.splitext('{$file_name}')[0] + '.webp'
    print(f"DEBUG: WebP 변환 시작 - 저장 경로: {webp_path}", file=sys.stderr)
    
    img.save(webp_path, 'WEBP', quality={$quality}, method=6)
    
    print(f"DEBUG: WebP 변환 완료 - 파일 크기: {os.path.getsize(webp_path)} bytes", file=sys.stderr)
    
    # 서비스 계정 인증
    print("DEBUG: Google Drive 인증 시작", file=sys.stderr)
    credentials = service_account.Credentials.from_service_account_file(
        '{$this->service_account_file}',
        scopes=['https://www.googleapis.com/auth/drive']
    )
    
    # Drive API 서비스 생성
    service = build('drive', 'v3', credentials=credentials)
    print("DEBUG: Google Drive 서비스 생성 완료", file=sys.stderr)
    
    # 파일 메타데이터
    file_metadata = {
        'name': os.path.basename(webp_path),
        'parents': ['{$this->converted_folder_id}']
    }
    
    print(f"DEBUG: 파일 업로드 시작 - 파일명: {file_metadata['name']}", file=sys.stderr)
    
    # 파일 업로드
    media = MediaFileUpload(webp_path, mimetype='image/webp')
    file = service.files().create(
        body=file_metadata,
        media_body=media,
        fields='id, name, webViewLink'
    ).execute()
    
    print(f"DEBUG: 파일 업로드 완료 - 파일 ID: {file['id']}", file=sys.stderr)
    
    # 파일 공개 권한 설정
    print("DEBUG: 공개 권한 설정 시작", file=sys.stderr)
    permission = {
        'type': 'anyone',
        'role': 'reader'
    }
    service.permissions().create(
        fileId=file['id'],
        body=permission
    ).execute()
    
    print("DEBUG: 공개 권한 설정 완료", file=sys.stderr)
    
    # 임시 파일 삭제
    os.remove(webp_path)
    print("DEBUG: 임시 파일 삭제 완료", file=sys.stderr)
    
    # 공개 URL 생성
    public_url = f"https://lh3.googleusercontent.com/d/{file['id']}"
    
    print(f"DEBUG: 전체 프로세스 완료 - 공개 URL: {public_url}", file=sys.stderr)
    
    print(json.dumps({
        'success': True,
        'file_id': file['id'],
        'file_name': file['name'],
        'public_url': public_url,
        'web_view_link': file['webViewLink']
    }))
    
except Exception as e:
    print(f"DEBUG: 변환/업로드 예외 발생 - {str(e)}", file=sys.stderr)
    print(f"DEBUG: 스택 트레이스 - {traceback.format_exc()}", file=sys.stderr)
    
    # 임시 파일 정리 (오류 시)
    try:
        webp_path = '/tmp/' + os.path.splitext('{$file_name}')[0] + '.webp'
        if os.path.exists(webp_path):
            os.remove(webp_path)
            print("DEBUG: 오류로 인한 임시 파일 정리 완료", file=sys.stderr)
    except:
        pass
    
    print(json.dumps({
        'success': False,
        'error': str(e),
        'traceback': traceback.format_exc()
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