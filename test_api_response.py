#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
API 응답 테스트 스크립트
두 상품의 실제 API 응답을 비교하여 차이점 파악
"""

import os
import sys
import json
import time
import hashlib
import urllib.parse
import urllib.request
from datetime import datetime

def load_env_safe():
    """환경변수 로더"""
    env_path = "/home/novacents/.env"
    env_vars = {}
    try:
        with open(env_path, 'r', encoding='utf-8') as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith('#') and '=' in line:
                    key, value = line.split('=', 1)
                    env_vars[key.strip()] = value.strip().strip('"').strip("'")
        return env_vars
    except Exception as e:
        print(f"환경변수 로드 실패: {e}")
        return {}

def generate_signature(params, app_secret):
    """MD5 서명 생성"""
    sorted_params = sorted(params.items())
    query_string = ''.join([f"{k}{v}" for k, v in sorted_params])
    sign_string = app_secret + query_string + app_secret
    return hashlib.md5(sign_string.encode('utf-8')).hexdigest().upper()

def extract_product_id(url):
    """URL에서 상품 ID 추출"""
    import re
    patterns = [
        r'/item/(\d+)\.html',
        r'/item/(\d+)$',
        r'/(\d+)\.html'
    ]
    
    for pattern in patterns:
        match = re.search(pattern, url)
        if match:
            return match.group(1)
    return None

def test_api_call(product_id, app_key, app_secret, test_name):
    """API 호출 테스트"""
    print(f"\n{'='*50}")
    print(f"테스트: {test_name}")
    print(f"상품 ID: {product_id}")
    print(f"{'='*50}")
    
    try:
        # API 파라미터
        base_params = {
            'method': 'aliexpress.affiliate.productdetail.get',
            'app_key': app_key,
            'timestamp': str(int(time.time() * 1000)),
            'format': 'json',
            'v': '2.0',
            'sign_method': 'md5',
            'product_ids': product_id,
            'fields': 'product_id,product_title,product_main_image_url,target_sale_price,target_original_price,evaluate_rate,evaluation_rate,rating,product_rating,score,rate,lastest_volume,first_level_category_name,promotion_link,seller_score,quality_score,average_rating,star_rating,evaluateScore,volume',
            'tracking_id': 'blog',
            'target_language': 'ko',
            'target_currency': 'KRW',
            'country': 'KR'
        }
        
        # 서명 생성
        base_params['sign'] = generate_signature(base_params, app_secret)
        
        # API 호출
        gateway_url = "https://api-sg.aliexpress.com/sync"
        query_string = urllib.parse.urlencode(base_params)
        full_url = f"{gateway_url}?{query_string}"
        
        print(f"API 호출 중...")
        
        req = urllib.request.Request(full_url)
        with urllib.request.urlopen(req, timeout=15) as response:
            response_text = response.read().decode('utf-8')
            
            if response.status == 200:
                data = json.loads(response_text)
                print(f"✅ API 호출 성공")
                
                # 전체 응답 출력
                print(f"\n📋 전체 API 응답:")
                print(json.dumps(data, ensure_ascii=False, indent=2))
                
                # 상품 데이터 추출 시도
                try:
                    product = None
                    if 'aliexpress_affiliate_productdetail_get_response' in data:
                        response_obj = data['aliexpress_affiliate_productdetail_get_response']
                        if 'resp_result' in response_obj:
                            resp_result = response_obj['resp_result']
                            if resp_result and 'result' in resp_result:
                                result = resp_result['result']
                                if result and 'products' in result:
                                    products_data = result['products']
                                    if 'product' in products_data:
                                        products = products_data['product']
                                        if isinstance(products, list) and len(products) > 0:
                                            product = products[0]
                                        elif isinstance(products, dict):
                                            product = products
                    
                    if product:
                        print(f"\n🎯 추출된 상품 데이터:")
                        print(json.dumps(product, ensure_ascii=False, indent=2))
                        
                        # 평점 관련 필드 체크
                        print(f"\n⭐ 평점 관련 필드 체크:")
                        rating_fields = ['evaluate_rate', 'evaluation_rate', 'rating', 'product_rating', 'score', 'rate', 'evaluateScore', 'average_rating', 'star_rating']
                        for field in rating_fields:
                            value = product.get(field, 'NOT_FOUND')
                            print(f"  {field}: {value}")
                        
                        # 판매량 관련 필드 체크
                        print(f"\n📦 판매량 관련 필드 체크:")
                        volume_fields = ['lastest_volume', 'volume', 'sales_volume', 'sold_quantity']
                        for field in volume_fields:
                            value = product.get(field, 'NOT_FOUND')
                            print(f"  {field}: {value}")
                            
                    else:
                        print(f"❌ 상품 데이터 추출 실패")
                        
                except Exception as e:
                    print(f"❌ 상품 데이터 파싱 실패: {e}")
                    
            else:
                print(f"❌ HTTP 오류: {response.status}")
                print(f"응답: {response_text}")
                
    except Exception as e:
        print(f"❌ API 호출 실패: {e}")

def main():
    """메인 함수"""
    print("🔍 API 응답 차이 분석 테스트 시작")
    
    # 환경변수 로드
    env_vars = load_env_safe()
    app_key = env_vars.get('ALIEXPRESS_APP_KEY')
    app_secret = env_vars.get('ALIEXPRESS_APP_SECRET')
    
    if not app_key or not app_secret:
        print("❌ API 키가 설정되지 않았습니다.")
        return
    
    # 테스트할 상품 URL들
    test_urls = [
        {
            'name': '1.png 상품 (문제 상품)',
            'url': 'https://ko.aliexpress.com/item/1005004261346550.html?spm=a2g0o.best.0.0.3930423aKOAoZU&afTraceInfo=1005004261346550__pc__pcBestMore2Love__SxXJNAa__1753667614503&_gl=1*1h9gd5i*_gcl_aw*R0NMLjE3NTIxMzkxMDkuQ2p3S0NBand5YjNEQmhCbEVpd0FxWkxlNU1tVEg2SXhSY0hjNkp5aXF3OWZLT2VrNUJoVVdMUDEzM1R4czFkQjNFUE41d2ExOW1VM3FSb0M4V1VRQXZEX0J3RQ..*_gcl_au*MTIyMTExMzM3MS4xNzUxNjA0MjE5*_ga*MTEwMzc0NjI1Ni4xNzUxNjA0MjE5*_ga_VED1YSGNC7*czE3NTM2Njc1NzkkbzkyJGcxJHQxNzUzNjY3NjA3JGozMiRsMCRoMA..&gatewayAdapt=glo2kor'
        },
        {
            'name': '2.png 상품 (정상 상품)',
            'url': 'https://ko.aliexpress.com/item/1005005497425944.html?spm=a2g0o.best.0.0.3930423aKOAoZU&pdp_npi=5%40dis%21KRW%21%E2%82%A913%2C371%21%E2%82%A99%2C360%21%21%21%21%21%40212e509017536676141668513ef838%2112000033311971628%21btf%21%21%21%211%210&afTraceInfo=1005005497425944__pc__pcBestMore2Love__ZkWbVZZ__1753667614499&_gl=1*5093lx*_gcl_aw*R0NMLjE3NTIxMzkxMDkuQ2p3S0NBand5YjNEQmhCbEVpd0FxWkxlNU1tVEg2SXhSY0hjNkp5aXF3OWZLT2VrNUJoVVdMUDEzM1R4czFkQjNFUE41d2ExOW1VM3FSb0M4V1VRQXZEX0J3RQ..*_gcl_au*MTIyMTExMzM3MS4xNzUxNjA0MjE5*_ga*MTEwMzc0NjI1Ni4xNzUxNjA0MjE5*_ga_VED1YSGNC7*czE3NTM2Njc1NzkkbzkyJGcxJHQxNzUzNjY3NjIzJGoxNiRsMCRoMA..&gatewayAdapt=glo2kor'
        }
    ]
    
    # 각 상품에 대해 테스트 실행
    for test_data in test_urls:
        product_id = extract_product_id(test_data['url'])
        if product_id:
            test_api_call(product_id, app_key, app_secret, test_data['name'])
        else:
            print(f"❌ {test_data['name']}: 상품 ID 추출 실패")
        
        # 테스트 간 대기
        time.sleep(2)
    
    print(f"\n{'='*50}")
    print("🎯 테스트 완료")
    print("위 결과를 비교하여 두 상품의 API 응답 차이를 확인하세요.")
    print(f"{'='*50}")

if __name__ == "__main__":
    main()