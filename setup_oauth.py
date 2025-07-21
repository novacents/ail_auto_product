#!/usr/bin/env python3
"""
Google Drive OAuth 초기 설정 스크립트
이 스크립트는 최초 1회만 실행하여 OAuth 토큰을 생성합니다.
"""

import os
import json
from google.auth.transport.requests import Request
from google.oauth2.credentials import Credentials
from google_auth_oauthlib.flow import InstalledAppFlow

# 권한 범위 설정
SCOPES = ['https://www.googleapis.com/auth/drive']

# 파일 경로
CREDENTIALS_FILE = '/var/www/novacents/tools/client_credentials.json'
TOKEN_FILE = '/var/www/novacents/tools/google_token.json'

def main():
    """OAuth 토큰 생성 메인 함수"""
    print("=== Google Drive OAuth 설정 시작 ===\n")
    
    # 기존 토큰 확인
    creds = None
    if os.path.exists(TOKEN_FILE):
        print(f"기존 토큰 파일 발견: {TOKEN_FILE}")
        creds = Credentials.from_authorized_user_file(TOKEN_FILE, SCOPES)
    
    # 토큰이 없거나 유효하지 않으면 새로 생성
    if not creds or not creds.valid:
        if creds and creds.expired and creds.refresh_token:
            print("토큰 갱신 중...")
            creds.refresh(Request())
        else:
            print("새로운 토큰 생성 중...")
            
            # OAuth 클라이언트 정보
            client_config = {
                "installed": {
                    "client_id": "558249385120-e23fac20819dq4t3abahm06rdh4narjh.apps.googleusercontent.com",
                    "project_id": "gen-lang-client-0324197132",
                    "auth_uri": "https://accounts.google.com/o/oauth2/auth",
                    "token_uri": "https://oauth2.googleapis.com/token",
                    "auth_provider_x509_cert_url": "https://www.googleapis.com/oauth2/v1/certs",
                    "client_secret": "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",
                    "redirect_uris": ["http://localhost"]
                }
            }
            
            # 임시로 client_credentials.json 파일 생성
            with open(CREDENTIALS_FILE, 'w') as f:
                json.dump(client_config, f)
            
            # OAuth 플로우 실행
            flow = InstalledAppFlow.from_client_secrets_file(
                CREDENTIALS_FILE, SCOPES)
            
            print("\n브라우저가 열립니다. Google 계정으로 로그인하세요.")
            print("로그인 후 권한을 허용해주세요.\n")
            
            creds = flow.run_local_server(port=0)
            
            # 임시 파일 삭제
            os.remove(CREDENTIALS_FILE)
        
        # 토큰 저장
        with open(TOKEN_FILE, 'w') as token:
            token.write(creds.to_json())
        
        # 파일 권한 설정
        os.chmod(TOKEN_FILE, 0o600)
        
        print(f"\n✅ 토큰이 성공적으로 저장되었습니다: {TOKEN_FILE}")
    else:
        print("✅ 유효한 토큰이 이미 존재합니다.")
    
    # 토큰 정보 출력
    print("\n=== 토큰 정보 ===")
    print(f"토큰 파일: {TOKEN_FILE}")
    print(f"만료 시간: {creds.expiry}")
    print("\n=== OAuth 설정 완료 ===")
    print("이제 image_selector.php를 사용할 수 있습니다.")

if __name__ == '__main__':
    main()