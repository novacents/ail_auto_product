#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
간단한 GET 방식 테스트 스크립트
1. URL에서 상품명을 스크래핑
2. 해당 상품명으로 GET /products/search API 호출
"""

import os
import sys
import json
import hmac
import hashlib
import time
import urllib.parse
import urllib.request
import re

# --- 설정 ---
TEST_URL = "https://www.coupang.com/vp/products/8103148059" # 테스트할 상품 URL

# --- 1단계: 상품명 스크래핑 ---
def scrape_product_title(url):
    try:
        print(f"1단계: 상품명 스크래핑 시작 -> {url}")
        headers = {'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'}
        req = urllib.request.Request(url, headers=headers)
        with urllib.request.urlopen(req, timeout=10) as response:
            html = response.read().decode('utf-8', 'ignore')
            title_match = re.search(r'<title>(.+?)</title>', html)
            if title_match:
                title = title_match.group(1).replace(" - 쿠팡!", "").strip()
                print(f"✅ 스크래핑 성공! 상품명: '{title}'")
                return title
    except Exception as e:
        print(f"❌ 스크래핑 실패: {e}")
    return None

# --- 2단계: API 호출 ---
def call_search_api(keyword):
    # .env 파일에서 키 로드
    env_path = "/home/novacents/.env"
    keys = {}
    with open(env_path, 'r') as f:
        for line in f:
            if '=' in line:
                key, value = line.split('=', 1)
                keys[key.strip()] = value.strip().strip('"').strip("'")
    
    ACCESS_KEY = keys.get('COUPANG_ACCESS_KEY')
    SECRET_KEY = keys.get('COUPANG_SECRET_KEY')

    if not keyword or not ACCESS_KEY or not SECRET_KEY:
        print("❌ API 호출 실패: 키워드 또는 API 키가 없습니다.")
        return

    try:
        print(f"\n2단계: '{keyword}' 키워드로 API 호출 시작...")
        REQUEST_METHOD = "GET"
        DOMAIN = "https://api-gateway.coupang.com"
        URL_PATH = f"/v2/providers/affiliate_open_api/apis/openapi/products/search?keyword={urllib.parse.quote(keyword)}&limit=1"
        
        datetime_gmt = time.strftime('%y%m%d', time.gmtime()) + 'T' + time.strftime('%H%M%S', time.gmtime()) + 'Z'
        message = datetime_gmt + REQUEST_METHOD + URL_PATH
        signature = hmac.new(bytes(SECRET_KEY, "utf-8"), message.encode("utf-8"), hashlib.sha256).hexdigest()
        authorization = f"CEA algorithm=HmacSHA256, access-key={ACCESS_KEY}, signed-date={datetime_gmt}, signature={signature}"

        headers = {"Authorization": authorization, "Content-Type": "application/json"}
        req = urllib.request.Request(f"{DOMAIN}{URL_PATH}", headers=headers)

        with urllib.request.urlopen(req, timeout=10) as response:
            print("✅ API 호출 성공!")
            response_data = json.loads(response.read().decode('utf-8'))
            print("\n--- API 응답 결과 ---")
            # 보기 좋게 JSON 출력
            print(json.dumps(response_data, indent=2, ensure_ascii=False))

    except Exception as e:
        print(f"❌ API 호출 중 오류 발생: {e}")


# --- 메인 실행 ---
if __name__ == "__main__":
    scraped_title = scrape_product_title(TEST_URL)
    if scraped_title:
        call_search_api(scraped_title)
