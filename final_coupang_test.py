#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
쿠팡 파트너스 API 진단 스크립트
- 문제의 POST /v1/products/search API 호출만 테스트
- 요청의 모든 과정을 출력하여 원인 규명
"""

import os
import sys
import json
import hmac
import hashlib
import time
import urllib.parse
import urllib.request

def load_api_keys():
    env_path = "/home/novacents/.env"
    keys = {}
    try:
        with open(env_path, 'r') as f:
            for line in f:
                if '=' in line and not line.strip().startswith('#'):
                    key, value = line.split('=', 1)
                    keys[key.strip()] = value.strip().strip('"').strip("'")
    except Exception as e:
        print(f"FATAL: .env 파일 로드 실패: {e}")
    return keys.get('COUPANG_ACCESS_KEY'), keys.get('COUPANG_SECRET_KEY')

def diagnose_coupang_api(product_url):
    """API 호출의 모든 단계를 진단하고 출력"""
    
    print("="*50)
    print("🕵️  쿠팡 파트너스 API 최종 진단을 시작합니다.")
    print("="*50)

    access_key, secret_key = load_api_keys()
    if not access_key or not secret_key:
        print("❌ 진단 실패: API 키를 .env 파일에서 찾을 수 없습니다.")
        return

    print(f"✅ API 키 로드 완료 (Access Key: ...{access_key[-4:]})")

    try:
        # --- 1. 요청 정보 설정 ---
        REQUEST_METHOD = "POST"
        DOMAIN = "https://api-gateway.coupang.com"
        URL_PATH = "/v2/providers/affiliate_open_api/apis/openapi/v1/products/search"
        full_url = f"{DOMAIN}{URL_PATH}"
        request_body = {"coupangUrls": [product_url]}
        
        print("\n--- [1단계: 요청 정보] ---")
        print(f"요청 방식: {REQUEST_METHOD}")
        print(f"요청 URL: {full_url}")
        print(f"요청 본문(Body): {json.dumps(request_body)}")
        
        # --- 2. HMAC 서명 생성 ---
        datetime_gmt = time.strftime('%y%m%d', time.gmtime()) + 'T' + time.strftime('%H%M%S', time.gmtime()) + 'Z'
        message = datetime_gmt + REQUEST_METHOD + URL_PATH
        signature = hmac.new(bytes(secret_key, "utf-8"), message.encode("utf-8"), hashlib.sha256).hexdigest()
        authorization = f"CEA algorithm=HmacSHA256, access-key={access_key}, signed-date={datetime_gmt}, signature={signature}"

        print("\n--- [2단계: HMAC 서명 생성] ---")
        print(f"GMT 시간: {datetime_gmt}")
        print(f"서명용 원본 메시지: {message}")
        print(f"생성된 서명: {signature}")
        print(f"최종 Authorization 헤더: {authorization}")

        # --- 3. API 실제 호출 ---
        headers = {
            "Authorization": authorization,
            "Content-Type": "application/json",
        }

        req = urllib.request.Request(
            full_url,
            data=json.dumps(request_body).encode('utf-8'),
            headers=headers,
            method=REQUEST_METHOD
        )

        print("\n--- [3단계: API 실제 호출] ---")
        print("🚀 서버에 요청을 보냅니다...")

        with urllib.request.urlopen(req, timeout=15) as response:
            response_body = response.read().decode('utf-8')
            print("\n--- [4단계: 서버 응답] ---")
            print(f"✅ 요청 성공!")
            print(f"HTTP 상태 코드: {response.status}")
            print(f"응답 내용:\n{response_body}")

    except urllib.error.HTTPError as e:
        print("\n--- [4단계: 서버 응답] ---")
        print(f"❌ 요청 실패 (HTTP 오류)!")
        print(f"HTTP 상태 코드: {e.code}")
        print(f"오류 메시지: {e.reason}")
        try:
            # 오류 응답 본문이 있으면 읽어서 출력
            error_body = e.read().decode('utf-8')
            print(f"오류 응답 내용:\n{error_body}")
        except Exception as read_error:
            print(f"오류 응답 본문을 읽는 데 실패했습니다: {read_error}")

    except Exception as e:
        print(f"\n❌ 스크립트 실행 중 예외 발생: {type(e).__name__}")
        print(f"예외 메시지: {e}")

    print("\n" + "="*50)
    print("🕵️  진단이 종료되었습니다.")
    print("="*50)


if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("사용법: python3 final_coupang_test.py '<테스트할 쿠팡 상품 URL>'")
    else:
        test_url = sys.argv[1]
        diagnose_coupang_api(test_url)
