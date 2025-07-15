#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
알리익스프레스 API 완전 분석 스크립트 (새 버전)
목표: 공식 API로 재고/배송 정보 추출 가능한 방법 완전 분석
성공 패턴: urllib.request + MD5 서명 + 성공한 파라미터 조합
"""

import os
import sys
import time
import json
import hashlib
import urllib.parse
import urllib.request
import re

def load_environment():
    """환경변수 로드 (성공한 패턴 사용)"""
    env_path = '/home/novacents/.env'
    env_vars = {}
    try:
        with open(env_path, 'r', encoding='utf-8') as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith('#') and '=' in line:
                    key, value = line.split('=', 1)
                    env_vars[key.strip()] = value.strip().strip('"').strip("'")
        print("✅ .env 파일 로드 성공")
        return env_vars
    except Exception as e:
        print(f"❌ .env 파일 로드 실패: {str(e)}")
        return {}

def create_api_client(env_vars):
    """API 클라이언트 설정 생성 (성공한 패턴 사용)"""
    app_key = env_vars.get('ALIEXPRESS_APP_KEY')
    app_secret = env_vars.get('ALIEXPRESS_APP_SECRET')
    tracking_id = env_vars.get('ALIEXPRESS_TRACKING_ID', 'blog')
    
    if not app_key or not app_secret:
        print("❌ API 키가 설정되지 않았습니다")
        return None
    
    client_config = {
        'app_key': app_key,
        'app_secret': app_secret,
        'tracking_id': tracking_id,
        'gateway_url': 'https://api-sg.aliexpress.com/sync'
    }
    
    print(f"✅ API 클라이언트 설정 성공")
    return client_config

def generate_signature(app_secret, params):
    """MD5 서명 생성 (성공한 패턴 사용)"""
    sorted_params = sorted(params.items())
    query_string = ''.join([f"{k}{v}" for k, v in sorted_params])
    sign_string = app_secret + query_string + app_secret
    
    return hashlib.md5(sign_string.encode('utf-8')).hexdigest().upper()

def test_api_with_debug(client_config, api_name, custom_params, description=""):
    """API 테스트 with 상세 디버깅 정보 (성공한 패턴 사용)"""
    print(f"\n🧪 {description}")
    print(f"🎯 API: {api_name}")
    
    try:
        # 기본 API 파라미터 (성공한 패턴)
        base_params = {
            'method': api_name,
            'app_key': client_config['app_key'],
            'timestamp': str(int(time.time() * 1000)),
            'format': 'json',
            'v': '2.0',
            'sign_method': 'md5',
            'tracking_id': client_config['tracking_id']
        }
        
        # 사용자 파라미터 추가
        params = {**base_params, **custom_params}
        
        # 서명 생성
        params['sign'] = generate_signature(client_config['app_secret'], params)
        
        # URL 생성
        query_string = urllib.parse.urlencode(params)
        full_url = f"{client_config['gateway_url']}?{query_string}"
        
        print("📡 API 호출 중...")
        
        # HTTP 요청 실행
        req = urllib.request.Request(full_url)
        with urllib.request.urlopen(req) as response:
            response_text = response.read().decode('utf-8')
            status_code = response.status
        
        print(f"📊 응답 상태: HTTP {status_code}, 길이: {len(response_text)}")
        
        if status_code == 200:
            # JSON 파싱 시도
            try:
                result_data = json.loads(response_text)
                print(f"✅ JSON 파싱 성공")
                
                # API별 응답 구조 분석
                expected_response_key = f"{api_name.replace('.', '_')}_response"
                
                if expected_response_key in result_data:
                    api_response = result_data[expected_response_key]
                    
                    if 'resp_result' in api_response:
                        resp_result = api_response['resp_result']
                        
                        if resp_result.get('result'):
                            result = resp_result['result']
                            print(f"✅ API 호출 성공! 데이터 발견")
                            return result, True
                        else:
                            print(f"❌ 'result'가 비어있음")
                            return resp_result, False
                    else:
                        return api_response, False
                else:
                    # 오류 응답 확인
                    if 'error_response' in result_data:
                        error = result_data['error_response']
                        print(f"🚨 API 오류: {error.get('code')} - {error.get('msg')}")
                    return result_data, False
                    
            except json.JSONDecodeError as e:
                print(f"❌ JSON 파싱 실패: {str(e)}")
                return response_text, False
        else:
            print(f"❌ HTTP 요청 실패: {status_code}")
            return None, False
            
    except Exception as e:
        print(f"❌ 예외 발생: {str(e)}")
        return None, False

def step1_test_known_working_api(client_config, product_id):
    """1단계: 기존 성공 API 재확인"""
    print("\n" + "="*80)
    print("🔄 1단계: 기존 성공 API 재확인")
    print("="*80)
    
    params = {
        'product_ids': str(product_id),
        'target_language': 'ko',
        'target_currency': 'KRW',
        'country': 'KR',
        'fields': 'product_id,product_title,product_main_image_url,target_sale_price,target_original_price,evaluate_rate,lastest_volume,first_level_category_name,promotion_link,target_sale_price_currency'
    }
    
    result, success = test_api_with_debug(
        client_config, 
        'aliexpress.affiliate.productdetail.get',
        params,
        "기존 성공 API 테스트"
    )
    
    if success and result:
        print("🎉 기존 API 정상 작동 확인!")
        return True
    else:
        print("⚠️ 기존 API 실패")
        return False

def step2_discover_new_apis(client_config, product_id):
    """2단계: 새로운 API 발견"""
    print("\n" + "="*80)
    print("🔍 2단계: 새로운 API 발견")
    print("="*80)
    
    # 기본 파라미터
    base_params = {
        'target_language': 'ko',
        'target_currency': 'KRW',
        'country': 'KR'
    }
    
    # 테스트할 API들
    api_tests = [
        {
            'name': 'aliexpress.affiliate.product.query',
            'params': {**base_params, 'keywords': 'bikini', 'page_size': '3'},
            'description': '제품 검색 API (키워드)'
        },
        {
            'name': 'aliexpress.affiliate.hotproduct.query',
            'params': {**base_params, 'keywords': 'bikini', 'page_size': '5'},
            'description': '핫 상품 조회 API'
        },
        {
            'name': 'aliexpress.affiliate.category.get',
            'params': base_params,
            'description': '카테고리 정보 API'
        }
    ]
    
    successful_apis = []
    
    for i, api_test in enumerate(api_tests, 1):
        print(f"\n🧪 테스트 {i}/{len(api_tests)}")
        
        result, success = test_api_with_debug(
            client_config,
            api_test['name'],
            api_test['params'],
            api_test['description']
        )
        
        if success:
            successful_apis.append({
                'name': api_test['name'],
                'description': api_test['description'],
                'result': result
            })
            print(f"🎉 성공한 API 발견: {api_test['name']}")
        
        time.sleep(2)
    
    return successful_apis

def extract_detailed_fields(data, prefix=""):
    """데이터에서 재고/배송/시간 관련 필드를 상세히 추출"""
    stock_fields = set()
    shipping_fields = set()
    time_fields = set()
    sample_data = {}
    
    def analyze_recursive(obj, path=""):
        if isinstance(obj, dict):
            for key, value in obj.items():
                current_path = f"{path}.{key}" if path else key
                key_lower = key.lower()
                
                # 재고 관련 키워드
                stock_keywords = ['stock', 'inventory', 'quantity', 'available', 'remain', 'count', 'amount', 'supply', 'reserve']
                if any(keyword in key_lower for keyword in stock_keywords):
                    stock_fields.add(f"{current_path}: {value}")
                    sample_data[current_path] = value
                
                # 배송 관련 키워드
                shipping_keywords = ['shipping', 'delivery', 'freight', 'logistics', 'transport', 'carrier', 'ship']
                if any(keyword in key_lower for keyword in shipping_keywords):
                    shipping_fields.add(f"{current_path}: {value}")
                    sample_data[current_path] = value
                
                # 시간 관련 키워드
                time_keywords = ['time', 'date', 'day', 'hour', 'period', 'duration', 'start', 'end', 'expire']
                if any(keyword in key_lower for keyword in time_keywords):
                    time_fields.add(f"{current_path}: {value}")
                    sample_data[current_path] = value
                
                # 재귀 분석
                if isinstance(value, (dict, list)):
                    analyze_recursive(value, current_path)
        
        elif isinstance(obj, list):
            for idx, item in enumerate(obj):
                current_path = f"{path}[{idx}]" if path else f"[{idx}]"
                analyze_recursive(item, current_path)
    
    analyze_recursive(data, prefix)
    
    return stock_fields, shipping_fields, time_fields, sample_data

def step3_deep_analysis_hotproduct(client_config):
    """3단계: 핫 상품 API 깊이 분석"""
    print("\n" + "="*80)
    print("🔥 3단계: 핫 상품 API 깊이 분석")
    print("="*80)
    
    # 다양한 키워드로 테스트
    test_keywords = ['bikini', 'electronics', 'home', 'sports', 'fashion']
    
    all_stock_fields = set()
    all_shipping_fields = set()
    all_time_fields = set()
    sample_data = {}
    
    for keyword in test_keywords[:3]:  # 3개만 테스트
        print(f"\n🔍 키워드 '{keyword}' 분석 중...")
        
        params = {
            'keywords': keyword,
            'page_size': '10',
            'target_language': 'ko',
            'target_currency': 'KRW',
            'country': 'KR'
        }
        
        result, success = test_api_with_debug(
            client_config, 
            'aliexpress.affiliate.hotproduct.query', 
            params, 
            f"핫 상품 API - {keyword}"
        )
        
        if success and result:
            stock_fields, shipping_fields, time_fields, samples = extract_detailed_fields(result, f"hotproduct_{keyword}")
            
            all_stock_fields.update(stock_fields)
            all_shipping_fields.update(shipping_fields)
            all_time_fields.update(time_fields)
            sample_data[keyword] = samples
            
            print(f"  📦 재고 관련 필드: {len(stock_fields)}개")
            print(f"  🚚 배송 관련 필드: {len(shipping_fields)}개")
            print(f"  ⏰ 시간 관련 필드: {len(time_fields)}개")
        
        time.sleep(3)
    
    # 결과 분석
    print(f"\n📊 핫 상품 API 종합 분석:")
    print(f"  📦 총 재고 관련 필드: {len(all_stock_fields)}개")
    print(f"  🚚 총 배송 관련 필드: {len(all_shipping_fields)}개")
    print(f"  ⏰ 총 시간 관련 필드: {len(all_time_fields)}개")
    
    # 중요한 재고 정보 강조
    print(f"\n💎 발견한 재고 정보:")
    for field in list(all_stock_fields)[:10]:
        print(f"  ✅ {field}")
    
    print(f"\n💎 발견한 배송 정보:")
    for field in list(all_shipping_fields)[:10]:
        print(f"  ✅ {field}")
    
    print(f"\n💎 발견한 시간 정보:")
    for field in list(all_time_fields)[:10]:
        print(f"  ✅ {field}")
    
    return {
        'stock_fields': list(all_stock_fields),
        'shipping_fields': list(all_shipping_fields),
        'time_fields': list(all_time_fields),
        'sample_data': sample_data
    }

def step4_analyze_product_query(client_config, product_id):
    """4단계: 제품 검색 API 분석"""
    print("\n" + "="*80)
    print("🔍 4단계: 제품 검색 API 분석")
    print("="*80)
    
    # 다양한 검색 방식
    test_scenarios = [
        {
            'name': '키워드 검색',
            'params': {'keywords': 'swimwear', 'page_size': '5'}
        },
        {
            'name': '가격 필터링',
            'params': {'keywords': 'bikini', 'min_sale_price': '5', 'max_sale_price': '30', 'page_size': '5'}
        }
    ]
    
    all_results = {}
    
    for scenario in test_scenarios:
        print(f"\n🔍 {scenario['name']} 테스트...")
        
        params = {
            **scenario['params'],
            'target_language': 'ko',
            'target_currency': 'KRW',
            'country': 'KR'
        }
        
        result, success = test_api_with_debug(
            client_config, 
            'aliexpress.affiliate.product.query',
            params,
            f"제품 검색 - {scenario['name']}"
        )
        
        if success and result:
            stock_fields, shipping_fields, time_fields, samples = extract_detailed_fields(result, f"query_{scenario['name']}")
            
            all_results[scenario['name']] = {
                'stock_fields': list(stock_fields),
                'shipping_fields': list(shipping_fields),
                'time_fields': list(time_fields),
                'sample_data': samples
            }
            
            print(f"  📦 재고 필드: {len(stock_fields)}개")
            print(f"  🚚 배송 필드: {len(shipping_fields)}개")
        
        time.sleep(3)
    
    return all_results

def main():
    """메인 함수"""
    print("🎯 알리익스프레스 API 완전 분석 시작")
    print("🔍 목표: 재고/배송 정보 추출 가능성 완전 탐색")
    print("🚀 방법: 성공한 HTTP 요청 패턴 + 깊이 분석")
    
    # 환경 설정
    env_vars = load_environment()
    if not env_vars:
        print("❌ 환경 설정 실패")
        return
    
    client_config = create_api_client(env_vars)
    if not client_config:
        print("❌ API 클라이언트 설정 실패")
        return
    
    # 테스트 상품
    test_product_id = "1005005759992878"
    print(f"🆔 테스트 상품 ID: {test_product_id}")
    
    # 단계별 분석 실행
    print(f"\n🚀 4단계 완전 분석 시작")
    
    # 1단계: 기존 API 확인
    connection_ok = step1_test_known_working_api(client_config, test_product_id)
    if not connection_ok:
        print("❌ 기본 연결 문제. 분석 중단.")
        return
    
    # 2단계: 새로운 API 발견
    successful_apis = step2_discover_new_apis(client_config, test_product_id)
    
    # 3단계: 핫 상품 API 깊이 분석
    hotproduct_analysis = step3_deep_analysis_hotproduct(client_config)
    
    # 4단계: 제품 검색 API 분석
    query_analysis = step4_analyze_product_query(client_config, test_product_id)
    
    # 최종 결과 종합
    print("\n" + "="*80)
    print("🏆 완전 분석 최종 결과")
    print("="*80)
    
    print(f"✅ 발견한 API: {len(successful_apis)}개")
    for api in successful_apis:
        print(f"  - {api['name']}: {api['description']}")
    
    # 재고/배송 정보 총계
    total_stock_fields = len(hotproduct_analysis.get('stock_fields', []))
    total_shipping_fields = len(hotproduct_analysis.get('shipping_fields', []))
    
    for analysis in query_analysis.values():
        total_stock_fields += len(analysis.get('stock_fields', []))
        total_shipping_fields += len(analysis.get('shipping_fields', []))
    
    print(f"\n🎯 재고/배송 정보 발견 현황:")
    print(f"  📦 총 재고 관련 필드: {total_stock_fields}개")
    print(f"  🚚 총 배송 관련 필드: {total_shipping_fields}개")
    
    # 실용적 결론
    print(f"\n💡 실용적 결론:")
    if total_stock_fields > 0:
        print(f"  ✅ 재고 정보: hotproduct.query API에서 발견 가능")
        print(f"  💡 활용 방법: 프로모션 코드 수량, 유효 기간 등")
    else:
        print(f"  ❌ 직접적인 재고 정보: 제한적")
    
    if total_shipping_fields > 0:
        print(f"  ✅ 배송 정보: 일부 API에서 발견 가능")
    else:
        print(f"  ❌ 직접적인 배송 정보: 제한적")
    
    # 권장사항
    print(f"\n🎯 권장사항:")
    print(f"  1. hotproduct.query API 활용: 프로모션 수량 정보")
    print(f"  2. productdetail.get API 메인 사용: 기본 상품 정보")
    print(f"  3. 필요시 웹 스크래핑 보완: 정확한 재고/배송 정보")
    
    # 결과 저장
    all_results = {
        'successful_apis': successful_apis,
        'hotproduct_analysis': hotproduct_analysis,
        'query_analysis': query_analysis,
        'summary': {
            'total_stock_fields': total_stock_fields,
            'total_shipping_fields': total_shipping_fields,
            'analysis_time': time.strftime('%Y-%m-%d %H:%M:%S')
        }
    }
    
    try:
        output_file = "aliexpress_complete_analysis_results.json"
        with open(output_file, 'w', encoding='utf-8') as f:
            json.dump(all_results, f, ensure_ascii=False, indent=2)
        print(f"\n💾 완전 분석 결과 저장: {output_file}")
    except Exception as e:
        print(f"⚠️ 결과 저장 실패: {str(e)}")
    
    print(f"\n🎉 알리익스프레스 API 완전 분석 완료!")

if __name__ == "__main__":
    main()
