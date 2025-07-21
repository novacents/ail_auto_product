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
    private $original_folder_id;
    private $converted_folder_id;
    private $python_path;
    private $token_file;
    
    public function __construct() {
        // 환경변수 로드
        $env_vars = load_env_vars();
        
        // 필수 환경변수 확인
        $required_vars = [
            'GOOGLE_DRIVE_ORIGINAL_FOLDER_ID',
            'GOOGLE_DRIVE_CONVERTED_FOLDER_ID'
        ];
        
        foreach ($required_vars as $var) {
            if (!isset($env_vars[$var])) {
                throw new Exception("필수 환경변수가 설정되지 않았습니다: {$var}");
            }
        }
        
        $this->original_folder_id = $env_vars['GOOGLE_DRIVE_ORIGINAL_FOLDER_ID'];
        $this->converted_folder_id = $env_vars['GOOGLE_DRIVE_CONVERTED_FOLDER_ID'];
        $this->python_path = '/usr/bin/python3';
        $this->token_file = '/var/www/novacents/tools/google_token.json';
        
        // OAuth 토큰 파일 존재 확인
        if (!file_exists($this->token_file)) {
            throw new Exception("OAuth 토큰 파일을 찾을 수 없습니다. setup_oauth.py를 먼저 실행하세요: {$this->token_file}");
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
            // stderr를 별도 파일로 리다이렉트하여 JSON과 분리
            $error_log = tempnam(sys_get_temp_dir(), 'gdrive_error_');
            
            $output = [];
            $return_code = 0;
            exec("{$this->python_path} {$temp_script} 2>{$error_log}", $output, $return_code);
            
            // 에러 로그 읽기 (디버깅용)
            $error_output = file_exists($error_log) ? file_get_contents($error_log) : '';
            
            // 임시 파일들 삭제
            unlink($temp_script);
            if (file_exists($error_log)) {
                unlink($error_log);
            }
            
            if ($return_code !== 0) {
                throw new Exception("Python 스크립트 실행 실패 (종료 코드: {$return_code})\nSTDOUT: " . implode("\n", $output) . "\nSTDERR: " . $error_output);
            }
            
            $result = implode("\n", $output);
            
            // 빈 결과 확인
            if (empty(trim($result))) {
                throw new Exception("Python 스크립트가 빈 결과를 반환했습니다.\nSTDERR: " . $error_output);
            }
            
            $json_result = json_decode($result, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("JSON 파싱 실패 (JSON 오류: " . json_last_error_msg() . ")\nPython 출력: " . $result . "\nSTDERR: " . $error_output);
            }
            
            return $json_result;
        } catch (Exception $e) {
            if (file_exists($temp_script)) {
                unlink($temp_script);
            }
            if (isset($error_log) && file_exists($error_log)) {
                unlink($error_log);
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
import os
from google.auth.transport.requests import Request
from google.oauth2.credentials import Credentials
from googleapiclient.discovery import build

try:
    # OAuth 토큰 로드
    creds = None
    token_file = '{$this->token_file}'
    
    if os.path.exists(token_file):
        creds = Credentials.from_authorized_user_file(token_file, ['https://www.googleapis.com/auth/drive'])
    
    if not creds:
        raise Exception("OAuth 토큰을 찾을 수 없습니다. setup_oauth.py를 실행하세요.")
    
    # 토큰 갱신 필요 시 자동 갱신
    if creds.expired and creds.refresh_token:
        creds.refresh(Request())
        # 갱신된 토큰 저장
        with open(token_file, 'w') as token:
            token.write(creds.to_json())
    
    # Drive API 서비스 생성
    service = build('drive', 'v3', credentials=creds)
    
    # 이미지 파일 목록 조회
    query = "'{$this->original_folder_id}' in parents and mimeType contains 'image/'"
    results = service.files().list(
        q=query,
        pageSize={$limit},
        fields="files(id, name, mimeType, createdTime, size, thumbnailLink, webViewLink)",
        orderBy="createdTime desc"
    ).execute()
    
    files = results.get('files', [])
    
    # 결과 출력 (JSON만)
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
import os
import io
import traceback
from google.auth.transport.requests import Request
from google.oauth2.credentials import Credentials
from googleapiclient.discovery import build
from googleapiclient.http import MediaIoBaseDownload

try:
    # OAuth 토큰 로드
    creds = None
    token_file = '{$this->token_file}'
    
    if os.path.exists(token_file):
        creds = Credentials.from_authorized_user_file(token_file, ['https://www.googleapis.com/auth/drive'])
    
    if not creds:
        raise Exception("OAuth 토큰을 찾을 수 없습니다. setup_oauth.py를 실행하세요.")
    
    # 토큰 갱신 필요 시 자동 갱신
    if creds.expired and creds.refresh_token:
        creds.refresh(Request())
        # 갱신된 토큰 저장
        with open(token_file, 'w') as token:
            token.write(creds.to_json())
    
    # Drive API 서비스 생성
    service = build('drive', 'v3', credentials=creds)
    
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
        // 상세 로그 파일 생성
        $log_file = '/var/www/novacents/tools/gdrive_debug_' . date('Ymd_His') . '.log';
        
        $script = <<<PYTHON
import json
import sys
import os
import traceback
from PIL import Image
from google.auth.transport.requests import Request
from google.oauth2.credentials import Credentials
from googleapiclient.discovery import build
from googleapiclient.http import MediaFileUpload

# 로그 파일에 디버그 정보 기록
def debug_log(message):
    with open('{$log_file}', 'a', encoding='utf-8') as f:
        f.write(str(message) + "\\n")

try:
    debug_log("=== WebP 변환 및 업로드 시작 ===")
    
    # 단계별 체크포인트
    checkpoint = "시작"
    debug_log("체크포인트: " + checkpoint)
    
    # 1. 파일 존재 확인
    checkpoint = "파일 존재 확인"
    debug_log("체크포인트: " + checkpoint)
    debug_log("소스 파일 경로: {$source_path}")
    
    if not os.path.exists('{$source_path}'):
        error_msg = "소스 파일이 존재하지 않습니다: {$source_path}"
        debug_log("ERROR: " + error_msg)
        raise Exception(error_msg)
    
    file_size = os.path.getsize('{$source_path}')
    debug_log("소스 파일 크기: " + str(file_size) + " bytes")
    
    if file_size == 0:
        error_msg = "소스 파일이 비어있습니다: {$source_path}"
        debug_log("ERROR: " + error_msg)
        raise Exception(error_msg)
    
    # 2. 이미지 열기
    checkpoint = "이미지 열기"
    debug_log("체크포인트: " + checkpoint)
    
    img = Image.open('{$source_path}')
    img_info = "크기: " + str(img.size) + ", 모드: " + str(img.mode)
    debug_log("이미지 정보: " + img_info)
    
    # 3. 이미지 모드 변환
    checkpoint = "이미지 모드 변환"
    debug_log("체크포인트: " + checkpoint)
    debug_log("원본 이미지 모드: " + str(img.mode))
    
    if img.mode in ('RGBA', 'LA'):
        debug_log("RGBA/LA 모드 -> RGB 변환 중")
        background = Image.new('RGB', img.size, (255, 255, 255))
        background.paste(img, mask=img.split()[-1])
        img = background
    elif img.mode not in ('RGB', 'L'):
        debug_log(str(img.mode) + " 모드 -> RGB 변환 중")
        img = img.convert('RGB')
    
    debug_log("변환 후 이미지 모드: " + str(img.mode))
    
    # 4. WebP 경로 생성
    checkpoint = "WebP 경로 생성"
    debug_log("체크포인트: " + checkpoint)
    
    webp_path = '/tmp/' + os.path.splitext('{$file_name}')[0] + '.webp'
    debug_log("WebP 파일 경로: " + webp_path)
    
    # 5. WebP 변환
    checkpoint = "WebP 변환"
    debug_log("체크포인트: " + checkpoint)
    debug_log("WebP 변환 품질: {$quality}, method=6")
    
    img.save(webp_path, 'WEBP', quality={$quality}, method=6)
    debug_log("WebP 변환 완료")
    
    if not os.path.exists(webp_path):
        error_msg = "WebP 변환된 파일이 생성되지 않았습니다"
        debug_log("ERROR: " + error_msg)
        raise Exception(error_msg)
    
    webp_size = os.path.getsize(webp_path)
    debug_log("WebP 파일 크기: " + str(webp_size) + " bytes")
    
    if webp_size == 0:
        error_msg = "WebP 변환된 파일이 비어있습니다"
        debug_log("ERROR: " + error_msg)
        raise Exception(error_msg)
    
    # 6. OAuth 토큰 로드
    checkpoint = "Google Drive 인증"
    debug_log("체크포인트: " + checkpoint)
    
    creds = None
    token_file = '{$this->token_file}'
    debug_log("토큰 파일: " + token_file)
    
    if os.path.exists(token_file):
        creds = Credentials.from_authorized_user_file(token_file, ['https://www.googleapis.com/auth/drive'])
        debug_log("OAuth 토큰 로드 완료")
    
    if not creds:
        error_msg = "OAuth 토큰을 찾을 수 없습니다. setup_oauth.py를 실행하세요."
        debug_log("ERROR: " + error_msg)
        raise Exception(error_msg)
    
    # 토큰 갱신 필요 시 자동 갱신
    if creds.expired and creds.refresh_token:
        debug_log("토큰 만료됨. 자동 갱신 중...")
        creds.refresh(Request())
        # 갱신된 토큰 저장
        with open(token_file, 'w') as token:
            token.write(creds.to_json())
        debug_log("토큰 갱신 완료")
    
    # 7. Drive API 서비스 생성
    checkpoint = "Drive API 서비스 생성"
    debug_log("체크포인트: " + checkpoint)
    
    service = build('drive', 'v3', credentials=creds)
    debug_log("Drive API 서비스 생성 완료")
    
    # 8. 파일 메타데이터 준비
    checkpoint = "파일 메타데이터 준비"
    debug_log("체크포인트: " + checkpoint)
    debug_log("업로드할 폴더 ID: {$this->converted_folder_id}")
    
    file_metadata = {
        'name': os.path.basename(webp_path),
        'parents': ['{$this->converted_folder_id}']
    }
    debug_log("파일 메타데이터: " + str(file_metadata))
    
    # 9. 파일 업로드
    checkpoint = "파일 업로드"
    debug_log("체크포인트: " + checkpoint)
    
    media = MediaFileUpload(webp_path, mimetype='image/webp')
    debug_log("MediaFileUpload 객체 생성 완료")
    
    debug_log("Google Drive에 파일 업로드 시작...")
    file = service.files().create(
        body=file_metadata,
        media_body=media,
        fields='id, name, webViewLink'
    ).execute()
    debug_log("Google Drive 파일 업로드 완료")
    debug_log("업로드된 파일 정보: " + str(file))
    
    if not file or 'id' not in file:
        error_msg = "파일 업로드는 성공했으나 파일 ID를 받지 못했습니다"
        debug_log("ERROR: " + error_msg)
        raise Exception(error_msg)
    
    # 10. 파일 공개 권한 설정
    checkpoint = "공개 권한 설정"
    debug_log("체크포인트: " + checkpoint)
    debug_log("파일 ID " + file['id'] + "에 공개 권한 설정 중...")
    
    permission = {
        'type': 'anyone',
        'role': 'reader'
    }
    service.permissions().create(
        fileId=file['id'],
        body=permission
    ).execute()
    debug_log("공개 권한 설정 완료")
    
    # 11. 임시 파일 삭제
    checkpoint = "임시 파일 삭제"
    debug_log("체크포인트: " + checkpoint)
    
    os.remove(webp_path)
    debug_log("임시 파일 삭제 완료: " + webp_path)
    
    # 12. 공개 URL 생성
    checkpoint = "공개 URL 생성"
    debug_log("체크포인트: " + checkpoint)
    
    public_url = "https://lh3.googleusercontent.com/d/" + file['id']
    debug_log("생성된 공개 URL: " + public_url)
    
    # 성공 응답
    success_data = {
        'success': True,
        'file_id': file['id'],
        'file_name': file['name'],
        'public_url': public_url,
        'web_view_link': file['webViewLink'],
        'debug_info': {
            'original_size': file_size,
            'webp_size': webp_size,
            'image_info': img_info
        }
    }
    
    debug_log("최종 성공 응답: " + str(success_data))
    debug_log("=== WebP 변환 및 업로드 성공 ===")
    
    print(json.dumps(success_data))
    
except Exception as e:
    # 임시 파일 정리 (오류 시)
    try:
        webp_path = '/tmp/' + os.path.splitext('{$file_name}')[0] + '.webp'
        if os.path.exists(webp_path):
            os.remove(webp_path)
            debug_log("오류 시 임시 파일 삭제: " + webp_path)
    except:
        debug_log("임시 파일 삭제 중 추가 오류 발생")
        pass
    
    error_detail = "단계: " + checkpoint + ", 오류: " + str(e)
    debug_log("ERROR: " + error_detail)
    debug_log("TRACEBACK: " + traceback.format_exc())
    debug_log("=== WebP 변환 및 업로드 실패 ===")
    
    print(json.dumps({
        'success': False,
        'error': error_detail,
        'checkpoint': checkpoint,
        'traceback': traceback.format_exc(),
        'log_file': '{$log_file}'
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