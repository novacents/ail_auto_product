#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
경로 수정한 최종 API 테스트 스크립트
- 정확한 경로(/v1/)를 사용하여 POST와 GET 방식을 모두 테스트합니다.
"""

import os
import sys
import json
import hmac
import hashlib
import time
import urllib.parse
import urllib.request

# --- 공통 함수 ---
def load_api_keys():
    env_path = "/home/novacents/.env"
    keys = {}
    with open(env_path, 'r') as f:
        for line in f:
            if '=' in line:
                key, value = line.split('=', 1)
                keys[key.strip()] = value.strip().strip('"').strip("'")
    return keys.get('COUPANG_ACCESS_KEY'), keys.get('COUPANG_SECRET_KEY')

def generate_hmac_signature(method, url_path_with_query, secret_key, access_key):
    path, *query = url_path_with_query.split("?")
    datetime_gmt = time.strftime('%y%m%d', time.gmtime()) + 'T' + time.strftime('%H%M%S', time.gmtime()) + 'Z'
    message = datetime_gmt + method + path + (query[0] if query else "")
    signature = hmac.new(bytes(secret_key, "utf-8"), message.encode("utf-8"), hashlib.sha256).hexdigest()
    return f"CEA algorithm=HmacSHA256, access-key={access_key}, signed-date={datetime_gmt}, signature={signature}"

# --- 테스트 실행 함수 ---
def run_test(test_name, method, url, headers, data=None):
    print("\n" + "="*20 + f" {test_name} 시작 " + "="*20)
    try:
        req_data = json.dumps(data).encode('utf-8') if data else None
        req = urllib.request.Request(url, data=req_data, headers=headers, method=method)
        with urllib.request.urlopen(req, timeout=15) as response:
            print(f"✅ 요청 성공! (HTTP 상태 코드: {response.status})")
            response_data = json.loads(response.read().decode('utf-8'))
            print("--- 응답 결과 ---")
            print(json.dumps(response_data, indent=2, ensure_ascii=False))
    except Exception as e:
        print(f"❌ 요청 실패: {e}")
    print("="*50)

# --- 메인 실행 ---
if __name__ == "__main__":
    if len(sys.argv) != 2:
        print("사용법: python3 final_path_test.py '<쿠팡 상품 URL>'")
        sys.exit(1)

    product_url = sys.argv[1]
    ACCESS_KEY, SECRET_KEY = load_api_keys()
    if not ACCESS_KEY or not SECRET_KEY:
        print("API 키를 찾을 수 없습니다.")
        sys.exit(1)

    DOMAIN = "https://api-gateway.coupang.com"
    # ⭐️ 사용자님이 찾아주신 정확한 경로 사용
    CORRECT_PATH = "/v2/providers/affiliate_open_api/apis/openapi/v1/products/search"
    
    # --- 테스트 1: POST 방식 테스트 ---
    post_headers = {
        "Authorization": generate_hmac_signature("POST", CORRECT_PATH, SECRET_KEY, ACCESS_KEY),
        "Content-Type": "application/json"
    }
    post_data = {"coupangUrls": [product_url]}
    run_test("테스트 1: POST 방식 (URL 전달)", "POST", f"{DOMAIN}{CORRECT_PATH}", post_headers, post_data)

    # --- 테스트 2: GET 방식 테스트 (itemId 사용) ---
    try:
        parsed_url = urllib.parse.urlparse(product_url)
        item_id = urllib.parse.parse_qs(parsed_url.query).get('itemId', [None])[0]
        if item_id:
            query_string = f"keyword={item_id}&limit=3"
            get_path_with_query = f"{CORRECT_PATH}?{query_string}"
            get_headers = {
                "Authorization": generate_hmac_signature("GET", get_path_with_query, SECRET_KEY, ACCESS_KEY)
            }
            run_test("테스트 2: GET 방식 (itemId 검색)", "GET", f"{DOMAIN}{get_path_with_query}", get_headers)
        else:
            print("\nURL에서 itemId를 찾을 수 없어 GET 테스트를 건너뜁니다.")
    except Exception as e:
        print(f"\nGET 방식 테스트 준비 중 오류: {e}")
