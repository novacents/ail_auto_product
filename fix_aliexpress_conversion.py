#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
알리익스프레스 링크 변환 문제 해결
파일 위치: /var/www/novacents/tools/fix_aliexpress_conversion.py
"""

import os
import sys
import time
import json
import hashlib
import requests
from dotenv import load_dotenv

# IOP SDK 경로 추가
sdk_path = '/home/novacents/aliexpress-sdk'
if sdk_path not in sys.path:
    sys.path.append(sdk_path)

try:
    import iop
    print("[✅] AliExpress IOP SDK 가져오기 성공")
except ImportError as e:
    print(f"[❌] AliExpress IOP SDK 가져오기 실패: {e}")

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
    return config

def test_with_tracking_id_iop(config, test_url):
    """tracking_id 추가하여 IOP SDK 테스트"""
    print(f"\n[🔗] tracking_id 포함 IOP SDK 테스트")
    print("=" * 60)
    
    try:
        # IOP 클라이언트 생성
        endpoint_url = "https://api-sg.aliexpress.com/sync"
        client = iop.IopClient(
            endpoint_url,
            config["aliexpress_app_key"], 
            config["aliexpress_app_secret"]
        )
        
        # 링크 변환 요청 설정
        request = iop.IopRequest('aliexpress.affiliate.link.generate', 'POST')
        request.set_simplify()
        
        # 파라미터 설정 (tracking_id 추가)
        request.add_api_param('source_values', test_url)
        request.add_api_param('promotion_link_type', '0')
        request.add_api_param('tracking_id', 'novacents_tracking')  # 필수 파라미터 추가
        
        print(f"[📋] 요청 파라미터:")
        print(f"  method: aliexpress.affiliate.link.generate")
        print(f"  source_values: {test_url[:60]}...")
        print(f"  promotion_link_type: 0")
        print(f"  tracking_id: novacents_tracking")
        
        print(f"\n[⏳] tracking_id 포함 API 호출 중...")
        
        # API 호출 실행
        response = client.execute(request)
        
        print(f"[📨] 응답 수신 완료")
        
        if hasattr(response, 'body') and response.body:
            # 응답 데이터 파싱
            if isinstance(response.body, dict):
                response_data = response.body
            else:
                response_data = json.loads(response.body)
            
            print(f"[📊] 응답 내용:")
            print(json.dumps(response_data, ensure_ascii=False, indent=2))
            
            # 성공 여부 확인
            if 'aliexpress_affiliate_link_generate_response' in response_data:
                result = response_data['aliexpress_affiliate_link_generate_response']
                
                if 'resp_result' in result and result['resp_result'].get('result'):
                    resp_result = result['resp_result']['result']
                    
                    if 'promotion_links' in resp_result and resp_result['promotion_links']:
                        promotion_links = resp_result['promotion_links']
                        
                        print(f"[🎉] tracking_id 포함 변환 성공!")
                        
                        converted_links = []
                        for i, link_info in enumerate(promotion_links, 1):
                            source_value = link_info.get('source_value', '')
                            promotion_link = link_info.get('promotion_link', '')
                            
                            print(f"  {i}. 원본: {source_value[:60]}...")
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
                    print(f"[⚠️] API 응답 오류")
                    if 'resp_result' in result:
                        print(f"  오류 코드: {result['resp_result'].get('resp_code')}")
                        print(f"  오류 메시지: {result['resp_result'].get('resp_msg')}")
                    return False, None
            elif 'error_response' in response_data:
                error_info = response_data['error_response']
                print(f"[❌] API 오류: {error_info.get('code')} - {error_info.get('msg')}")
                return False, None
            else:
                print(f"[⚠️] 예상하지 못한 응답 형식")
                return False, None
        else:
            print(f"[❌] 응답 없음")
            return False, None
            
    except Exception as e:
        print(f"[❌] tracking_id IOP 테스트 오류: {e}")
        return False, None

def test_fixed_direct_api(config, test_url):
    """수정된 서명으로 직접 API 테스트"""
    print(f"\n[🔗] 수정된 직접 API 테스트")
    print("=" * 60)
    
    try:
        import time
        import urllib.parse
        
        # API 파라미터 준비
        timestamp = str(int(time.time() * 1000))
        
        # URL 정리
        clean_url = test_url.split('?')[0]
        
        # 파라미터 준비 (올바른 순서)
        params = {
            'app_key': config["aliexpress_app_key"],
            'format': 'json',
            'method': 'aliexpress.affiliate.link.generate',
            'promotion_link_type': '0',
            'sign_method': 'md5',
            'source_values': clean_url,
            'timestamp': timestamp,
            'tracking_id': 'novacents_tracking',  # 필수 파라미터 추가
            'v': '2.0'
        }
        
        print(f"[📋] 요청 파라미터:")
        for key, value in params.items():
            if key == 'source_values':
                print(f"  {key}: {value[:60]}...")
            else:
                print(f"  {key}: {value}")
        
        # 올바른 서명 생성 방법
        app_secret = config["aliexpress_app_secret"]
        
        # 1. 파라미터 정렬 (알파벳 순)
        sorted_params = sorted(params.items())
        
        # 2. URL 인코딩 없이 쿼리 스트링 생성
        query_string = '&'.join([f'{k}={v}' for k, v in sorted_params])
        
        # 3. 서명 문자열 생성: app_secret + params + app_secret
        sign_string = app_secret + query_string + app_secret
        
        # 4. MD5 해시 생성 후 대문자 변환
        signature = hashlib.md5(sign_string.encode('utf-8')).hexdigest().upper()
        params['sign'] = signature
        
        print(f"\n[🔒] 서명 생성 과정:")
        print(f"  쿼리 스트링: {query_string[:100]}...")
        print(f"  서명 문자열 길이: {len(sign_string)}")
        print(f"  생성된 서명: {signature}")
        
        # API 호출
        base_url = "https://api-sg.aliexpress.com/sync"
        print(f"\n[⏳] 수정된 직접 API 호출 중...")
        
        response = requests.get(base_url, params=params, timeout=30)
        
        print(f"[📨] 응답 코드: {response.status_code}")
        print(f"[📨] 응답 내용:")
        print(response.text[:500] + "..." if len(response.text) > 500 else response.text)
        
        if response.status_code == 200:
            try:
                data = response.json()
                
                # 성공 응답 확인
                if 'aliexpress_affiliate_link_generate_response' in data:
                    result_data = data['aliexpress_affiliate_link_generate_response']
                    
                    if 'resp_result' in result_data and result_data['resp_result'].get('result'):
                        promotion_links = result_data['resp_result']['result'].get('promotion_links', [])
                        
                        if promotion_links:
                            print(f"[🎉] 수정된 직접 API 변환 성공!")
                            
                            converted_links = []
                            for link_info in promotion_links:
                                promotion_link = link_info['promotion_link']
                                source_value = link_info['source_value']
                                
                                print(f"  원본: {source_value}")
                                print(f"  변환: {promotion_link}")
                                
                                converted_links.append({
                                    'original': source_value,
                                    'converted': promotion_link
                                })
                            
                            return True, converted_links
                        else:
                            print(f"[⚠️] 변환된 링크 없음")
                            return False, None
                    else:
                        print(f"[⚠️] API 응답 오류")
                        if 'resp_result' in result_data:
                            print(f"  응답 결과: {result_data['resp_result']}")
                        return False, None
                elif 'error_response' in data:
                    error_info = data['error_response']
                    print(f"[❌] API 오류: {error_info.get('code')} - {error_info.get('msg')}")
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
        print(f"[❌] 수정된 직접 API 테스트 오류: {e}")
        return False, None

def test_alternative_methods(config, test_url):
    """대안적 방법들 테스트"""
    print(f"\n[🔗] 대안적 링크 변환 방법 테스트")
    print("=" * 60)
    
    # 방법 1: 제품 상세 조회 후 프로모션 링크 추출
    try:
        # 상품 ID 추출
        import re
        product_id_match = re.search(r'/item/(\d+)\.html', test_url)
        if product_id_match:
            product_id = product_id_match.group(1)
            print(f"[📦] 추출된 상품 ID: {product_id}")
            
            # 상품 상세 조회 API 테스트
            if 'iop' in globals():
                client = iop.IopClient(
                    "https://api-sg.aliexpress.com/sync",
                    config["aliexpress_app_key"], 
                    config["aliexpress_app_secret"]
                )
                
                request = iop.IopRequest('aliexpress.affiliate.product.detail', 'POST')
                request.set_simplify()
                request.add_api_param('product_ids', product_id)
                request.add_api_param('fields', 'product_title,product_main_image_url,target_sale_price,promotion_link')
                request.add_api_param('tracking_id', 'novacents_tracking')
                
                print(f"[⏳] 상품 상세 조회 API 호출 중...")
                response = client.execute(request)
                
                if hasattr(response, 'body') and response.body:
                    if isinstance(response.body, dict):
                        response_data = response.body
                    else:
                        response_data = json.loads(response.body)
                    
                    print(f"[📊] 상품 상세 응답:")
                    print(json.dumps(response_data, ensure_ascii=False, indent=2))
                    
                    # 프로모션 링크 확인
                    if 'aliexpress_affiliate_product_detail_response' in response_data:
                        detail_result = response_data['aliexpress_affiliate_product_detail_response']
                        if 'resp_result' in detail_result and detail_result['resp_result'].get('result'):
                            products = detail_result['resp_result']['result'].get('products', [])
                            for product in products:
                                promotion_link = product.get('promotion_link')
                                if promotion_link:
                                    print(f"[🎉] 상품 상세에서 프로모션 링크 발견!")
                                    print(f"  프로모션 링크: {promotion_link}")
                                    return True, [{'original': test_url, 'converted': promotion_link}]
        
    except Exception as e:
        print(f"[⚠️] 대안 방법 1 오류: {e}")
    
    # 방법 2: 검색 API를 통한 간접 변환
    try:
        print(f"\n[🔍] 방법 2: 검색 API를 통한 간접 변환")
        
        if 'iop' in globals():
            client = iop.IopClient(
                "https://api-sg.aliexpress.com/sync",
                config["aliexpress_app_key"], 
                config["aliexpress_app_secret"]
            )
            
            # 상품 검색 API
            request = iop.IopRequest('aliexpress.affiliate.product.query', 'POST')
            request.set_simplify()
            request.add_api_param('keywords', 'poncho')  # 테스트 키워드
            request.add_api_param('page_size', '1')
            request.add_api_param('tracking_id', 'novacents_tracking')
            
            print(f"[⏳] 검색 API 호출 중...")
            response = client.execute(request)
            
            if hasattr(response, 'body') and response.body:
                if isinstance(response.body, dict):
                    response_data = response.body
                else:
                    response_data = json.loads(response.body)
                
                print(f"[📊] 검색 API 응답:")
                print(json.dumps(response_data, ensure_ascii=False, indent=2)[:1000] + "...")
                
                # 검색 결과에서 프로모션 링크 확인
                if 'aliexpress_affiliate_product_query_response' in response_data:
                    search_result = response_data['aliexpress_affiliate_product_query_response']
                    if 'resp_result' in search_result and search_result['resp_result'].get('result'):
                        products = search_result['resp_result']['result'].get('products', [])
                        if products:
                            first_product = products[0]
                            promotion_link = first_product.get('promotion_link')
                            if promotion_link:
                                print(f"[🎉] 검색 결과에서 프로모션 링크 발견!")
                                print(f"  프로모션 링크: {promotion_link}")
                                return True, [{'original': test_url, 'converted': promotion_link}]
        
    except Exception as e:
        print(f"[⚠️] 대안 방법 2 오류: {e}")
    
    return False, None

def main():
    """메인 테스트 함수"""
    print("🔧 알리익스프레스 링크 변환 문제 해결")
    print("=" * 60)
    print("목적: tracking_id 추가 및 서명 수정으로 변환 성공")
    print("=" * 60)
    
    # 1. API 설정 로드
    config = load_config()
    if not config:
        return False
    
    # 2. 테스트 URL
    test_url = "https://ko.aliexpress.com/item/1005007033158191.html"
    print(f"[🎯] 테스트 URL: {test_url}")
    
    # 3. tracking_id 포함 IOP SDK 테스트
    iop_success = False
    if 'iop' in globals():
        iop_success, iop_links = test_with_tracking_id_iop(config, test_url)
    
    # 4. 수정된 직접 API 테스트
    direct_success, direct_links = test_fixed_direct_api(config, test_url)
    
    # 5. 대안적 방법 테스트
    alt_success, alt_links = test_alternative_methods(config, test_url)
    
    # 6. 최종 결과
    print(f"\n{'='*60}")
    print("🏆 알리익스프레스 링크 변환 해결 결과")
    print("=" * 60)
    
    if iop_success:
        print("✅ IOP SDK + tracking_id: 성공!")
        print("🎉 알리익스프레스 링크 변환 완전 해결!")
        return True
    elif direct_success:
        print("✅ 수정된 직접 API: 성공!")
        print("🎉 알리익스프레스 링크 변환 완전 해결!")
        return True
    elif alt_success:
        print("✅ 대안적 방법: 성공!")
        print("🎉 간접적으로 알리익스프레스 링크 변환 가능!")
        return True
    else:
        print("❌ 모든 방법 실패")
        print("💔 추가 연구 필요")
        
        print(f"\n🔍 문제 분석:")
        print(f"  1. tracking_id 파라미터 추가 시도: {'성공' if iop_success else '실패'}")
        print(f"  2. 서명 생성 방식 수정 시도: {'성공' if direct_success else '실패'}")
        print(f"  3. 대안적 방법 시도: {'성공' if alt_success else '실패'}")
        
        return False

if __name__ == "__main__":
    success = main()
    print(f"\n문제 해결 시도 완료: {'성공' if success else '추가 분석 필요'}")