#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
🔍 알리익스프레스 어필리에이트 API 응답 확인 테스트
- 실제 API에서 어떤 필드에 어떤 데이터가 오는지 확인
- 모든 필드 상세 출력
"""

import os
import sys
import json
import time
import hashlib
import urllib.parse
import urllib.request
import re

def load_env():
    """환경변수 로드"""
    env_path = "/home/novacents/.env"
    env_vars = {}
    try:
        with open(env_path, 'r', encoding='utf-8') as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith('#') and '=' in line:
                    key, value = line.split('=', 1)
                    env_vars[key.strip()] = value.strip().strip('"').strip("'")
        print("✅ 환경변수 로드 성공")
        return env_vars
    except Exception as e:
        print(f"❌ 환경변수 로드 실패: {e}")
        return {}

def generate_signature(app_secret, params):
    """MD5 서명 생성"""
    sorted_params = sorted(params.items())
    query_string = ''.join([f"{k}{v}" for k, v in sorted_params])
    sign_string = app_secret + query_string + app_secret
    return hashlib.md5(sign_string.encode('utf-8')).hexdigest().upper()

def extract_product_id(url):
    """URL에서 상품 ID 추출"""
    patterns = [
        r'/item/(\d+)\.html',
        r'/item/(\d+)$',
        r'/(\d+)\.html'
    ]
    
    for pattern in patterns:
        match = re.search(pattern, url)
        if match:
            product_id = match.group(1)
            print(f"🆔 상품 ID 추출 성공: {product_id}")
            return product_id
    
    print(f"❌ 상품 ID 추출 실패: {url}")
    return None

def test_aliexpress_api():
    """알리익스프레스 API 테스트"""
    
    # 테스트 URL
    test_url = "https://ko.aliexpress.com/item/1005008869366603.html?spm=a2g0o.best.0.0.6acd423a8IROd6&afTraceInfo=1005008869366603__pc__pcBestMore2Love__PhTl4Kq__1752558964452&_gl=1*1wl0jw6*_gcl_aw*R0NMLjE3NTIxMzkxMDkuQ2p3S0NBand5YjNEQmhCbEVpd0FxWkxlNU1tVEg2SXhSY0hjNkp5aXF3OWZLT2VrNUJoVVdMUDEzM1R4czFkQjNFUE41d2ExOW1VM3FSb0M4V1VRQXZEX0J3RQ..*_gcl_au*MTIyMTExMzM3MS4xNzUxNjA0MjE5*_ga*MTEwMzc0NjI1Ni4xNzUxNjA0MjE5*_ga_VED1YSGNC7*czE3NTI1NjQzNjMkbzQzJGcwJHQxNzUyNTY0MzYzJGo2MCRsMCRoMA..&gatewayAdapt=glo2kor"
    
    print(f"🔍 테스트 URL: {test_url}")
    print("=" * 80)
    
    # 환경변수 로드
    env_vars = load_env()
    app_key = env_vars.get('ALIEXPRESS_APP_KEY')
    app_secret = env_vars.get('ALIEXPRESS_APP_SECRET')
    tracking_id = env_vars.get('ALIEXPRESS_TRACKING_ID', 'blog')
    
    if not app_key or not app_secret:
        print("❌ 알리익스프레스 API 키가 설정되지 않았습니다.")
        return
    
    print(f"✅ API 키 확인: {app_key[:10]}...")
    print(f"✅ Tracking ID: {tracking_id}")
    
    # 상품 ID 추출
    product_id = extract_product_id(test_url)
    if not product_id:
        print("❌ 상품 ID 추출 실패")
        return
    
    try:
        # API 파라미터 설정
        base_params = {
            'method': 'aliexpress.affiliate.productdetail.get',
            'app_key': app_key,
            'timestamp': str(int(time.time() * 1000)),
            'format': 'json',
            'v': '2.0',
            'sign_method': 'md5',
            'product_ids': product_id,
            'fields': 'product_id,product_title,product_main_image_url,target_sale_price,target_original_price,evaluate_rate,lastest_volume,first_level_category_name,promotion_link,shop_id,shop_url,shop_name,second_level_category_name,product_video_url,product_small_image_urls',
            'tracking_id': tracking_id,
            'target_language': 'ko',      # 🔥 핵심 파라미터
            'target_currency': 'KRW',
            'country': 'KR'
        }
        
        print("\n📋 API 파라미터:")
        for key, value in base_params.items():
            if key != 'app_key':  # 보안을 위해 app_key는 일부만 표시
                print(f"  {key}: {value}")
        
        # 서명 생성
        base_params['sign'] = generate_signature(app_secret, base_params)
        
        # API 호출
        gateway_url = "https://api-sg.aliexpress.com/sync"
        query_string = urllib.parse.urlencode(base_params)
        full_url = f"{gateway_url}?{query_string}"
        
        print(f"\n📡 API 호출 중...")
        
        req = urllib.request.Request(full_url)
        with urllib.request.urlopen(req, timeout=15) as response:
            response_text = response.read().decode('utf-8')
            
            if response.status == 200:
                print(f"✅ API 호출 성공 (HTTP {response.status})")
                
                # JSON 파싱
                data = json.loads(response_text)
                
                print("\n" + "=" * 80)
                print("🔍 전체 API 응답 구조:")
                print("=" * 80)
                print(json.dumps(data, indent=2, ensure_ascii=False))
                
                # 상품 데이터 추출 및 상세 분석
                print("\n" + "=" * 80)
                print("📊 상품 데이터 상세 분석:")
                print("=" * 80)
                
                if 'aliexpress_affiliate_productdetail_get_response' in data:
                    resp_result = data['aliexpress_affiliate_productdetail_get_response']['resp_result']
                    
                    if resp_result.get('result') and resp_result['result'].get('products'):
                        products_data = resp_result['result']['products']
                        
                        if 'product' in products_data:
                            products = products_data['product']
                            
                            if isinstance(products, list) and len(products) > 0:
                                product = products[0]
                            elif isinstance(products, dict):
                                product = products
                            else:
                                print("❌ 상품 데이터 구조 오류")
                                return
                            
                            print("📦 개별 필드 상세 정보:")
                            print("-" * 50)
                            
                            # 모든 필드 하나씩 출력
                            for key, value in product.items():
                                if key == 'product_title':
                                    has_korean = bool(re.search(r'[가-힣]', str(value)))
                                    print(f"📝 {key}: {value} (한국어: {'✅' if has_korean else '❌'})")
                                elif key == 'evaluate_rate':
                                    print(f"⭐ {key}: {value} (타입: {type(value)})")
                                elif key == 'lastest_volume':
                                    print(f"📦 {key}: {value} (타입: {type(value)})")
                                elif key == 'target_sale_price':
                                    print(f"💰 {key}: {value} (타입: {type(value)})")
                                elif key == 'promotion_link':
                                    is_affiliate = 's.click.aliexpress.com' in str(value)
                                    print(f"🔗 {key}: {value} (어필리에이트: {'✅' if is_affiliate else '❌'})")
                                else:
                                    print(f"🔸 {key}: {value}")
                            
                            print("\n" + "-" * 50)
                            print("🎯 핵심 필드 요약:")
                            print(f"  상품명: {product.get('product_title', 'N/A')}")
                            print(f"  가격: {product.get('target_sale_price', 'N/A')}")
                            print(f"  평점: {product.get('evaluate_rate', 'N/A')}")
                            print(f"  판매량: {product.get('lastest_volume', 'N/A')}")
                            print(f"  이미지: {product.get('product_main_image_url', 'N/A')}")
                            print(f"  어필리에이트링크: {product.get('promotion_link', 'N/A')}")
                        else:
                            print("❌ products에서 'product' 키를 찾을 수 없음")
                    else:
                        print("❌ result 또는 products가 응답에 없음")
                else:
                    print("❌ 예상된 응답 구조를 찾을 수 없음")
            else:
                print(f"❌ API 호출 실패 (HTTP {response.status})")
                
    except Exception as e:
        print(f"❌ 오류 발생: {e}")

if __name__ == "__main__":
    print("🔍 알리익스프레스 어필리에이트 API 응답 확인 테스트")
    print("=" * 80)
    test_aliexpress_api()
