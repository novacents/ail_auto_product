#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
알리익스프레스 링크 변환 올바른 방법
공식 문서 기반 정확한 구현
파일 위치: /var/www/novacents/tools/aliexpress_correct_method.py
"""

import os
import time
import json
import hashlib
import requests
from dotenv import load_dotenv

def load_config():
    """환경변수에서 API 설정 로드"""
    env_path = '/home/novacents/.env'
    load_dotenv(env_path)
    
    config = {
        "aliexpress_app_key": os.getenv("ALIEXPRESS_APP_KEY"),
        "aliexpress_app_secret": os.getenv("ALIEXPRESS_APP_SECRET"),
    }
    
    if not config["aliexpress_app_key"] or not config["aliexpress_app_secret"]:
        print("[❌] 알리익스프레스 API 키가 .env 파일에서 로드되지 않았습니다")
        return None
    
    print(f"[✅] 알리익스프레스 API 키 로드 성공")
    masked_key = config["aliexpress_app_key"][:4] + "*" * 8 + config["aliexpress_app_key"][-4:]
    print(f"  App Key: {masked_key}")
    
    return config

def generate_aliexpress_signature(app_secret, params):
    """
    공식 문서 기준 알리익스프레스 API 서명 생성
    """
    # 1. 파라미터 정렬 (알파벳 순)
    sorted_params = sorted(params.items())
    
    # 2. 쿼리 스트링 생성 (URL 인코딩 없이)
    query_string = '&'.join([f'{k}={v}' for k, v in sorted_params])
    
    # 3. 서명 문자열 생성: app_secret + query_string + app_secret
    sign_string = app_secret + query_string + app_secret
    
    # 4. MD5 해시 생성 후 대문자 변환
    signature = hashlib.md5(sign_string.encode('utf-8')).hexdigest().upper()
    
    return signature

def test_aliexpress_link_generation_correct(config, test_url):
    """공식 문서 기준 올바른 링크 생성 테스트"""
    print(f"\n[🔗] 공식 문서 기준 알리익스프레스 링크 변환")
    print("=" * 60)
    print(f"테스트 URL: {test_url}")
    print("-" * 60)
    
    try:
        # 1. 기본 파라미터 설정
        timestamp = str(int(time.time() * 1000))
        
        # 2. URL 정리 (쿼리 파라미터 제거)
        clean_url = test_url.split('?')[0]
        print(f"[🧹] 정리된 URL: {clean_url}")
        
        # 3. API 파라미터 준비 (공식 문서 기준)
        params = {
            'app_key': config["aliexpress_app_key"],
            'method': 'aliexpress.affiliate.link.generate',
            'promotion_link_type': '0',
            'sign_method': 'md5',
            'source_values': clean_url,
            'timestamp': timestamp
        }
        
        print(f"[📋] 요청 파라미터 (서명 생성 전):")
        for key, value in params.items():
            if key == 'source_values':
                print(f"  {key}: {value[:60]}...")
            else:
                print(f"  {key}: {value}")
        
        # 4. 서명 생성
        signature = generate_aliexpress_signature(config["aliexpress_app_secret"], params)
        params['sign'] = signature
        
        print(f"\n[🔒] 서명 생성:")
        print(f"  생성된 서명: {signature}")
        
        # 5. API 호출
        base_url = "https://api-sg.aliexpress.com/sync"
        print(f"\n[⏳] API 호출 중...")
        print(f"  엔드포인트: {base_url}")
        
        response = requests.get(base_url, params=params, timeout=30)
        
        print(f"[📨] 응답 상태: {response.status_code}")
        print(f"[📨] 응답 내용:")
        response_text = response.text
        print(response_text)
        
        if response.status_code == 200:
            try:
                data = response.json()
                
                # 성공 응답 확인
                if 'aliexpress_affiliate_link_generate_response' in data:
                    result_data = data['aliexpress_affiliate_link_generate_response']
                    
                    if 'resp_result' in result_data and result_data['resp_result'].get('result'):
                        promotion_links = result_data['resp_result']['result'].get('promotion_links', [])
                        
                        if promotion_links:
                            print(f"\n[🎉] 알리익스프레스 링크 변환 성공!")
                            print(f"[📊] 변환된 링크 {len(promotion_links)}개:")
                            
                            converted_links = []
                            for i, link_info in enumerate(promotion_links, 1):
                                source_value = link_info.get('source_value', '')
                                promotion_link = link_info.get('promotion_link', '')
                                
                                print(f"  {i}. 원본: {source_value}")
                                print(f"     변환: {promotion_link}")
                                
                                converted_links.append({
                                    'original': source_value,
                                    'converted': promotion_link
                                })
                            
                            return True, converted_links
                        else:
                            print(f"[⚠️] 변환된 링크 없음")
                            return False, None
                    else:
                        print(f"[⚠️] API 결과 없음")
                        if 'resp_result' in result_data:
                            print(f"  resp_result: {result_data['resp_result']}")
                        return False, None
                elif 'error_response' in data:
                    error_info = data['error_response']
                    print(f"[❌] API 오류:")
                    print(f"  코드: {error_info.get('code')}")
                    print(f"  메시지: {error_info.get('msg')}")
                    print(f"  서브코드: {error_info.get('sub_code')}")
                    print(f"  서브메시지: {error_info.get('sub_msg')}")
                    return False, None
                else:
                    print(f"[⚠️] 예상하지 못한 응답 형식")
                    print(f"  응답 키: {list(data.keys())}")
                    return False, None
                    
            except Exception as e:
                print(f"[❌] JSON 파싱 오류: {e}")
                return False, None
        else:
            print(f"[❌] HTTP 오류: {response.status_code}")
            return False, None
            
    except Exception as e:
        print(f"[❌] 링크 변환 테스트 오류: {e}")
        return False, None

def test_simple_product_search(config):
    """간단한 상품 검색으로 API 연결 확인"""
    print(f"\n[🔍] 기본 상품 검색 테스트 (API 연결 확인)")
    print("=" * 60)
    
    try:
        # 기본 파라미터
        timestamp = str(int(time.time() * 1000))
        
        params = {
            'app_key': config["aliexpress_app_key"],
            'method': 'aliexpress.affiliate.product.query',
            'keywords': 'phone case',
            'page_no': '1',
            'page_size': '5',
            'sign_method': 'md5',
            'timestamp': timestamp
        }
        
        # 서명 생성
        signature = generate_aliexpress_signature(config["aliexpress_app_secret"], params)
        params['sign'] = signature
        
        print(f"[📋] 검색 파라미터:")
        print(f"  keywords: phone case")
        print(f"  timestamp: {timestamp}")
        print(f"  signature: {signature[:16]}...")
        
        # API 호출
        base_url = "https://api-sg.aliexpress.com/sync"
        response = requests.get(base_url, params=params, timeout=30)
        
        print(f"\n[📨] 검색 응답: {response.status_code}")
        
        if response.status_code == 200:
            try:
                data = response.json()
                
                if 'aliexpress_affiliate_product_query_response' in data:
                    result = data['aliexpress_affiliate_product_query_response']
                    
                    if 'resp_result' in result and result['resp_result'].get('result'):
                        products = result['resp_result']['result'].get('products', [])
                        print(f"[✅] 검색 성공! {len(products)}개 상품 발견")
                        
                        if products:
                            # 첫 번째 상품 정보
                            first_product = products[0]
                            product_id = first_product.get('product_id')
                            product_title = first_product.get('product_title', '')[:50]
                            
                            print(f"[📦] 첫 번째 상품:")
                            print(f"  ID: {product_id}")
                            print(f"  제목: {product_title}...")
                            
                            # 이 상품으로 링크 변환 테스트
                            product_url = f"https://www.aliexpress.com/item/{product_id}.html"
                            print(f"  URL: {product_url}")
                            
                            return True, product_url
                    else:
                        print(f"[⚠️] 검색 결과 없음")
                        if 'resp_result' in result:
                            print(f"  resp_result: {result['resp_result']}")
                elif 'error_response' in data:
                    error_info = data['error_response']
                    print(f"[❌] 검색 API 오류: {error_info.get('code')} - {error_info.get('msg')}")
                else:
                    print(f"[⚠️] 예상하지 못한 검색 응답")
                    
            except Exception as e:
                print(f"[❌] 검색 응답 파싱 오류: {e}")
        else:
            print(f"[❌] 검색 HTTP 오류: {response.status_code}")
            print(f"  응답: {response.text[:200]}...")
        
        return False, None
        
    except Exception as e:
        print(f"[❌] 검색 테스트 오류: {e}")
        return False, None

def main():
    """메인 테스트 함수"""
    print("🌏 알리익스프레스 링크 변환 올바른 방법 테스트")
    print("=" * 60)
    print("목적: 공식 문서 기준 정확한 구현으로 변환 성공")
    print("=" * 60)
    
    # 1. API 설정 로드
    config = load_config()
    if not config:
        return False
    
    # 2. 기본 API 연결 확인 (상품 검색)
    search_success, found_product_url = test_simple_product_search(config)
    
    # 3. 링크 변환 테스트
    test_urls = []
    
    # 기본 테스트 URL
    original_test_url = "https://ko.aliexpress.com/item/1005007033158191.html"
    test_urls.append(original_test_url)
    
    # 검색에서 찾은 URL 추가
    if search_success and found_product_url:
        test_urls.append(found_product_url)
    
    # 링크 변환 테스트
    success_count = 0
    for i, test_url in enumerate(test_urls, 1):
        print(f"\n[테스트 {i}/{len(test_urls)}]")
        success, converted_links = test_aliexpress_link_generation_correct(config, test_url)
        
        if success:
            success_count += 1
        
        # API 제한 고려
        if i < len(test_urls):
            print(f"\n[⏱️] 3초 대기...")
            time.sleep(3)
    
    # 4. 최종 결과
    print(f"\n{'='*60}")
    print("🏆 알리익스프레스 올바른 방법 테스트 결과")
    print("=" * 60)
    
    if success_count > 0:
        print(f"✅ 성공! {success_count}/{len(test_urls)}개 링크 변환 성공")
        print("🎉 알리익스프레스 일반 링크 → 어필리에이트 링크 변환 완전 해결!")
        print(f"\n💡 해결 방법:")
        print(f"  1. tracking_id 파라미터 제거 (필수 아님)")
        print(f"  2. 공식 문서 기준 서명 생성")
        print(f"  3. URL 쿼리 파라미터 정리")
        return True
    else:
        print(f"❌ 모든 테스트 실패 ({success_count}/{len(test_urls)})")
        print("💔 추가 분석 필요")
        
        if search_success:
            print(f"\n📊 분석 결과:")
            print(f"  - 검색 API: 정상 작동 ✅")
            print(f"  - 링크 변환 API: 실패 ❌")
            print(f"  - 원인: 링크 변환 API 구체적 문제")
        else:
            print(f"\n📊 분석 결과:")
            print(f"  - 전체 API 연결: 문제 있음")
            print(f"  - 원인: API 키, 서명, 또는 계정 문제")
        
        return False

if __name__ == "__main__":
    success = main()
    print(f"\n올바른 방법 테스트 완료: {'성공' if success else '실패'}")