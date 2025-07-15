#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
안전한 API 관리자 (SafeAPIManager)
쿠팡 파트너스 API 호출 제한 및 캐싱 시스템

파일 위치: /var/www/novacents/tools/safe_api_manager.py
"""

import os
import sys
import time
import json
import hmac
import hashlib
import requests
from datetime import datetime, timedelta
from dotenv import load_dotenv


class SafeAPIManager:
    """
    쿠팡 파트너스 API 안전 관리자
    - 1시간 슬라이딩 윈도우로 호출 제한 관리 (시간당 10회)
    - 다단계 캐싱 시스템 (메모리 → 파일 → API)
    - 429 에러 자동 처리 및 대기
    """
    
    def __init__(self, mode="development"):
        """
        초기화
        :param mode: "development" 또는 "production"
        """
        self.mode = mode
        self.cache_dir = "/var/www/novacents/tools/cache"
        self.coupang_cache_file = os.path.join(self.cache_dir, "coupang_cache.json")
        self.api_usage_file = os.path.join(self.cache_dir, "api_usage.json")
        self.error_log_file = os.path.join(self.cache_dir, "error_log.json")
        
        # 조용한 모드 확인 (JSON 출력 시 로그 최소화)
        self.quiet_mode = os.environ.get('SAFE_API_QUIET') == '1'
        
        # 메모리 캐시
        self.memory_cache = {}
        
        # API 설정
        self.config = self._load_api_config()
        
        # 알리익스프레스 SDK 초기화
        self._init_aliexpress_sdk()
        
        # 캐시 디렉토리 생성
        self._ensure_cache_dir()
        
        if not self.quiet_mode:
            print(f"[⚙️] SafeAPIManager 초기화 완료 (모드: {mode})")
    
    def _log(self, message):
        """조용한 모드를 고려한 로그 출력"""
        if not self.quiet_mode:
            print(message)
    
    def _load_api_config(self):
        """환경변수에서 API 설정 로드"""
        env_path = '/home/novacents/.env'
        load_dotenv(env_path)
        
        config = {
            "coupang_access_key": os.getenv("COUPANG_ACCESS_KEY"),
            "coupang_secret_key": os.getenv("COUPANG_SECRET_KEY"),
            "aliexpress_app_key": os.getenv("ALIEXPRESS_APP_KEY"),
            "aliexpress_app_secret": os.getenv("ALIEXPRESS_APP_SECRET"),
        }
        
        # 쿠팡 API 키 필수 체크
        if not config["coupang_access_key"] or not config["coupang_secret_key"]:
            raise ValueError("쿠팡 API 키가 .env 파일에서 로드되지 않았습니다")
        
        # 알리익스프레스 API 키 선택적 체크 (경고만)
        if not config["aliexpress_app_key"] or not config["aliexpress_app_secret"]:
            print("[⚠️] 알리익스프레스 API 키가 없습니다. 알리익스프레스 기능은 사용할 수 없습니다.")
        
        return config
    
    def _ensure_cache_dir(self):
        """캐시 디렉토리 생성"""
        if not os.path.exists(self.cache_dir):
            os.makedirs(self.cache_dir)
            print(f"[📁] 캐시 디렉토리 생성: {self.cache_dir}")
    
    def _load_json_file(self, filepath, default=None):
        """JSON 파일 안전 로드"""
        if default is None:
            default = {}
        
        try:
            if os.path.exists(filepath):
                with open(filepath, 'r', encoding='utf-8') as f:
                    return json.load(f)
            return default
        except (json.JSONDecodeError, IOError) as e:
            print(f"[⚠️] JSON 파일 로드 실패 ({filepath}): {e}")
            return default
    
    def _save_json_file(self, filepath, data):
        """JSON 파일 안전 저장"""
        try:
            with open(filepath, 'w', encoding='utf-8') as f:
                json.dump(data, f, ensure_ascii=False, indent=2)
            return True
        except IOError as e:
            print(f"[❌] JSON 파일 저장 실패 ({filepath}): {e}")
            return False
    
    def _get_cache_key(self, keyword, limit=5):
        """캐시 키 생성"""
        return f"{keyword.lower().strip()}_{limit}"
    
    def _is_cache_valid(self, timestamp, hours=1):
        """캐시 유효성 확인"""
        try:
            cache_time = datetime.fromisoformat(timestamp)
            return datetime.now() - cache_time < timedelta(hours=hours)
        except:
            return False
    
    def _get_memory_cache(self, cache_key):
        """메모리 캐시에서 검색"""
        if cache_key in self.memory_cache:
            cache_data = self.memory_cache[cache_key]
            if self._is_cache_valid(cache_data['timestamp']):
                print(f"[💾] 메모리 캐시 히트: {cache_key}")
                return cache_data['data']
            else:
                # 만료된 캐시 삭제
                del self.memory_cache[cache_key]
        return None
    
    def _set_memory_cache(self, cache_key, data):
        """메모리 캐시에 저장"""
        self.memory_cache[cache_key] = {
            'data': data,
            'timestamp': datetime.now().isoformat()
        }
        print(f"[💾] 메모리 캐시 저장: {cache_key}")
    
    def _get_file_cache(self, cache_key):
        """파일 캐시에서 검색"""
        cache_data = self._load_json_file(self.coupang_cache_file, {})
        
        if cache_key in cache_data:
            entry = cache_data[cache_key]
            if self._is_cache_valid(entry['timestamp'], hours=1):
                print(f"[📄] 파일 캐시 히트: {cache_key}")
                # 메모리 캐시에도 복사
                self._set_memory_cache(cache_key, entry['data'])
                return entry['data']
            else:
                # 만료된 캐시 삭제
                del cache_data[cache_key]
                self._save_json_file(self.coupang_cache_file, cache_data)
        
        return None
    
    def _set_file_cache(self, cache_key, data):
        """파일 캐시에 저장"""
        cache_data = self._load_json_file(self.coupang_cache_file, {})
        
        cache_data[cache_key] = {
            'data': data,
            'timestamp': datetime.now().isoformat()
        }
        
        if self._save_json_file(self.coupang_cache_file, cache_data):
            print(f"[📄] 파일 캐시 저장: {cache_key}")
            # 메모리 캐시에도 저장
            self._set_memory_cache(cache_key, data)
    
    def _get_api_usage_history(self):
        """API 사용 기록 로드"""
        return self._load_json_file(self.api_usage_file, [])
    
    def _add_api_usage_record(self):
        """API 사용 기록 추가"""
        usage_history = self._get_api_usage_history()
        usage_history.append(datetime.now().isoformat())
        
        # 1시간 이전 기록 정리
        one_hour_ago = datetime.now() - timedelta(hours=1)
        usage_history = [
            timestamp for timestamp in usage_history
            if datetime.fromisoformat(timestamp) > one_hour_ago
        ]
        
        self._save_json_file(self.api_usage_file, usage_history)
        return len(usage_history)
    
    def _get_current_usage_count(self):
        """현재 1시간 내 API 호출 횟수"""
        usage_history = self._get_api_usage_history()
        one_hour_ago = datetime.now() - timedelta(hours=1)
        
        current_usage = [
            timestamp for timestamp in usage_history
            if datetime.fromisoformat(timestamp) > one_hour_ago
        ]
        
        return len(current_usage)
    
    def _can_make_api_call(self):
        """API 호출 가능 여부 확인"""
        current_usage = self._get_current_usage_count()
        limit = 8 if self.mode == "development" else 9  # 안전 마진
        
        can_call = current_usage < limit
        print(f"[📊] 현재 사용량: {current_usage}/10 (제한: {limit}) - {'가능' if can_call else '불가능'}")
        
        return can_call
    
    def _log_error(self, error_type, details):
        """에러 로그 기록"""
        error_log = self._load_json_file(self.error_log_file, [])
        
        error_entry = {
            'timestamp': datetime.now().isoformat(),
            'error_type': error_type,
            'details': details,
            'mode': self.mode
        }
        
        error_log.append(error_entry)
        
        # 최근 100개 기록만 유지
        if len(error_log) > 100:
            error_log = error_log[-100:]
        
        self._save_json_file(self.error_log_file, error_log)
    
    def _handle_429_error(self, attempt_count=1):
        """429 에러 처리 및 대기"""
        if self.mode == "development":
            wait_time = 3600  # 개발 모드: 1시간 대기
        else:
            # 운영 모드: 단계별 대기 (10분 → 30분 → 1시간 → 2시간)
            wait_times = [600, 1800, 3600, 7200]  # 초 단위
            wait_time = wait_times[min(attempt_count - 1, len(wait_times) - 1)]
        
        wait_minutes = wait_time // 60
        print(f"[⏰] 429 에러 처리: {wait_minutes}분 대기 (시도 #{attempt_count})")
        
        self._log_error("429_error", {
            "attempt_count": attempt_count,
            "wait_time_seconds": wait_time,
            "wait_time_minutes": wait_minutes
        })
        
        # 실제 환경에서는 대기, 테스트에서는 로그만
        if not self._is_test_mode():
            time.sleep(wait_time)
    
    def _is_test_mode(self):
        """테스트 모드 확인 (대기 시간 건너뛰기용)"""
        return os.environ.get("SAFE_API_TEST_MODE") == "1"
    
    def _generate_coupang_signature(self, method, url_path, secret_key, access_key):
        """
        쿠팡 파트너스 API HMAC 서명 생성
        integrated_api_test.py의 성공한 로직 활용
        """
        # GMT 시간대 명시적 설정
        os.environ["TZ"] = "GMT+0"
        if hasattr(time, 'tzset'):
            time.tzset()
        
        # GMT 시간을 정확한 형식으로 생성
        datetime_gmt = time.strftime('%y%m%d') + 'T' + time.strftime('%H%M%S') + 'Z'
        
        # URL 경로와 쿼리 분리
        path_parts = url_path.split("?")
        path = path_parts[0]
        query = path_parts[1] if len(path_parts) > 1 else ""
        
        # 서명 메시지 생성
        message = datetime_gmt + method + path + query
        
        # HMAC 서명 계산
        signature = hmac.new(
            secret_key.encode('utf-8'),
            message.encode('utf-8'),
            hashlib.sha256
        ).hexdigest()
        
        # Authorization 헤더 생성
        authorization = f"CEA algorithm=HmacSHA256, access-key={access_key}, signed-date={datetime_gmt}, signature={signature}"
        
        return authorization
    
    def get_coupang_best_products(self, category_id=None, limit=10):
        """
        쿠팡 베스트 상품 조회 (공식 가이드 추가 기능)
        :param category_id: 카테고리 ID (선택사항)
        :param limit: 결과 개수 제한
        :return: (success, products_list)
        """
        print(f"\n[🏆] 쿠팡 베스트 상품 조회 (카테고리: {category_id}, limit: {limit})")
        
        cache_key = f"best_{category_id or 'all'}_{limit}"
        
        # 캐시 확인
        cached_result = self._get_memory_cache(cache_key)
        if cached_result:
            return True, cached_result
        
        cached_result = self._get_file_cache(cache_key)
        if cached_result:
            return True, cached_result
        
        # API 호출 가능 여부 확인
        if not self._can_make_api_call():
            print(f"[❌] API 호출 제한 초과")
            return False, []
        
        try:
            # 베스트 상품 API 호출
            url_path = "/v2/providers/affiliate_open_api/apis/openapi/products/bestcategories"
            if category_id:
                url_path += f"?categoryId={category_id}&limit={limit}"
            else:
                url_path += f"?limit={limit}"
            
            headers = {
                "Authorization": self._generate_coupang_signature(
                    "GET", url_path, 
                    self.config["coupang_secret_key"], 
                    self.config["coupang_access_key"]
                ),
                "Content-Type": "application/json"
            }
            
            url = "https://api-gateway.coupang.com" + url_path
            
            self._add_api_usage_record()
            print(f"[📡] 베스트 상품 API 호출: {url}")
            
            response = requests.get(url, headers=headers, timeout=30)
            
            if response.status_code == 200:
                data = response.json()
                if data.get('rCode') == '0':
                    products = self._format_coupang_response(data)
                    self._set_file_cache(cache_key, products)
                    print(f"[✅] 베스트 상품 조회 성공: {len(products)}개")
                    return True, products
                else:
                    error_msg = data.get('rMessage', '알 수 없는 오류')
                    print(f"[❌] 베스트 상품 API 오류: {error_msg}")
                    return False, []
            
            elif response.status_code == 429:
                self._handle_429_error()
                return False, []
            
            else:
                print(f"[❌] HTTP 오류: {response.status_code}")
                return False, []
                
        except Exception as e:
            print(f"[❌] 베스트 상품 조회 예외: {str(e)}")
            self._log_error("best_products_error", str(e))
            return False, []

    def get_coupang_category_products(self, category_id, limit=10):
        """
        쿠팡 카테고리별 상품 조회 (공식 가이드 추가 기능)
        :param category_id: 카테고리 ID
        :param limit: 결과 개수 제한
        :return: (success, products_list)
        """
        print(f"\n[📂] 쿠팡 카테고리별 상품 조회 (카테고리: {category_id}, limit: {limit})")
        
        cache_key = f"category_{category_id}_{limit}"
        
        # 캐시 확인
        cached_result = self._get_memory_cache(cache_key)
        if cached_result:
            return True, cached_result
        
        cached_result = self._get_file_cache(cache_key)
        if cached_result:
            return True, cached_result
        
        # API 호출 가능 여부 확인
        if not self._can_make_api_call():
            print(f"[❌] API 호출 제한 초과")
            return False, []
        
        try:
            # 카테고리 상품 API 호출
            url_path = f"/v2/providers/affiliate_open_api/apis/openapi/products/categories?categoryId={category_id}&limit={limit}"
            
            headers = {
                "Authorization": self._generate_coupang_signature(
                    "GET", url_path, 
                    self.config["coupang_secret_key"], 
                    self.config["coupang_access_key"]
                ),
                "Content-Type": "application/json"
            }
            
            url = "https://api-gateway.coupang.com" + url_path
            
            self._add_api_usage_record()
            print(f"[📡] 카테고리 상품 API 호출: {url}")
            
            response = requests.get(url, headers=headers, timeout=30)
            
            if response.status_code == 200:
                data = response.json()
                if data.get('rCode') == '0':
                    products = self._format_coupang_response(data)
                    self._set_file_cache(cache_key, products)
                    print(f"[✅] 카테고리 상품 조회 성공: {len(products)}개")
                    return True, products
                else:
                    error_msg = data.get('rMessage', '알 수 없는 오류')
                    print(f"[❌] 카테고리 상품 API 오류: {error_msg}")
                    return False, []
            
            elif response.status_code == 429:
                self._handle_429_error()
                return False, []
            
            else:
                print(f"[❌] HTTP 오류: {response.status_code}")
                return False, []
                
        except Exception as e:
            print(f"[❌] 카테고리 상품 조회 예외: {str(e)}")
            self._log_error("category_products_error", str(e))
            return False, []

    def search_coupang_safe(self, keyword, limit=5, force_api=False):
        """
        안전한 쿠팡 상품 검색 (향상된 검색 API 활용)
        :param keyword: 검색 키워드
        :param limit: 결과 개수 제한
        :param force_api: 캐시 무시하고 강제 API 호출
        :return: (success, products_list)
        """
        print(f"\n[🔍] 안전한 쿠팡 검색: '{keyword}' (limit: {limit})")
        
        cache_key = self._get_cache_key(keyword, limit)
        
        # 1단계: 메모리 캐시 확인
        if not force_api:
            cached_data = self._get_memory_cache(cache_key)
            if cached_data is not None:
                return True, cached_data
            
            # 2단계: 파일 캐시 확인
            cached_data = self._get_file_cache(cache_key)
            if cached_data is not None:
                return True, cached_data
        
        # 3단계: API 호출 여부 확인
        if not self._can_make_api_call():
            print(f"[🚫] API 호출 제한 도달 - 캐시된 데이터만 사용 가능")
            return False, []
        
        # 4단계: 실제 API 호출
        print(f"[📡] 실제 쿠팡 API 호출 중...")
        
        try:
            # API 엔드포인트 설정
            domain = "https://api-gateway.coupang.com"
            url_path = "/v2/providers/affiliate_open_api/apis/openapi/products/search"
            
            # 쿼리 파라미터를 URL에 포함
            query_params = f"keyword={requests.utils.quote(keyword)}&limit={limit}"
            full_url_path = f"{url_path}?{query_params}"
            full_url = domain + full_url_path
            
            # HMAC 서명 생성
            authorization = self._generate_coupang_signature(
                "GET", 
                full_url_path,
                self.config["coupang_secret_key"], 
                self.config["coupang_access_key"]
            )
            
            # 헤더 설정
            headers = {
                "Authorization": authorization,
                "Content-Type": "application/json"
            }
            
            # API 호출
            response = requests.get(full_url, headers=headers, timeout=30)
            
            # 429 에러 처리
            if response.status_code == 429:
                print(f"[⚠️] 429 에러 발생 - 제한 초과")
                self._handle_429_error()
                return False, []
            
            response.raise_for_status()
            
            # API 사용 기록 추가
            current_usage = self._add_api_usage_record()
            print(f"[📊] API 호출 기록됨: {current_usage}/10")
            
            # 응답 처리
            data = response.json()
            
            if data.get("rCode") == "0" and data.get("data"):
                # 성공적인 응답 처리
                formatted_products = self._format_coupang_response(data["data"], keyword)
                
                # 캐시에 저장
                self._set_file_cache(cache_key, formatted_products)
                
                print(f"[✅] 쿠팡 API 호출 성공: {len(formatted_products)}개 상품")
                return True, formatted_products
            else:
                print(f"[⚠️] 쿠팡 API 응답 오류: {data.get('rMessage', '알 수 없는 오류')}")
                return False, []
            
        except requests.exceptions.RequestException as e:
            print(f"[❌] 쿠팡 API 호출 오류: {e}")
            self._log_error("api_request_error", {"error": str(e), "keyword": keyword})
            return False, []
        except Exception as e:
            print(f"[❌] 쿠팡 처리 중 오류: {e}")
            self._log_error("general_error", {"error": str(e), "keyword": keyword})
            return False, []
    
    def _format_coupang_response(self, data, keyword):
        """쿠팡 API 응답 데이터 형식화"""
        formatted_products = []
        
        if isinstance(data, dict):
            # landingUrl 형태의 응답인 경우 실제 상품 데이터 확인
            if 'productData' in data and data['productData']:
                # 실제 상품 데이터가 있는 경우
                for product in data['productData']:
                    formatted_product = {
                        "platform": "쿠팡",
                        "product_id": product.get("productId"),
                        "title": product.get("productName"),
                        "price": product.get("salesPrice") or product.get("productPrice"),
                        "original_price": product.get("originalPrice"),
                        "discount_rate": product.get("discountRate"),
                        "currency": "KRW",
                        "image_url": product.get("productImage"),
                        "product_url": product.get("productUrl"),
                        "affiliate_url": product.get("productUrl"),
                        "vendor": "쿠팡",
                        "brand_name": product.get("brandName"),
                        "category_name": product.get("categoryName"),
                        "is_rocket": product.get("isRocket"),
                        "is_free_shipping": product.get("isFreeShipping"),
                        "rating": product.get("rating", "평점 정보 없음"),
                        "review_count": product.get("reviewCount", "리뷰 정보 없음"),
                        "original_data": product
                    }
                    formatted_products.append(formatted_product)
            else:
                # landingUrl만 있는 경우
                formatted_product = {
                    "platform": "쿠팡",
                    "product_id": "landing_page",
                    "title": f"{keyword} 관련 상품 모음",
                    "price": "가격 정보 없음",
                    "currency": "KRW",
                    "image_url": "이미지 정보 없음",
                    "product_url": data.get("landingUrl", ""),
                    "affiliate_url": data.get("landingUrl", ""),
                    "vendor": "쿠팡",
                    "commission_rate": "알 수 없음",
                    "rating": "평점 정보 없음",
                    "review_count": "리뷰 정보 없음",
                    "original_data": data
                }
                formatted_products.append(formatted_product)
            
        elif isinstance(data, list):
            # 일반적인 상품 리스트 형태
            for product in data:
                formatted_product = {
                    "platform": "쿠팡",
                    "product_id": product.get("productId"),
                    "title": product.get("productName"),
                    "price": product.get("productPrice"),
                    "currency": "KRW",
                    "image_url": product.get("productImage"),
                    "product_url": product.get("productUrl"),
                    "affiliate_url": product.get("productUrl"),
                    "vendor": product.get("vendorItemName"),
                    "commission_rate": "알 수 없음",
                    "rating": "평점 정보 없음",
                    "review_count": "리뷰 정보 없음",
                    "original_data": product
                }
                formatted_products.append(formatted_product)
        
        return formatted_products
    
    def get_cache_stats(self):
        """캐시 통계 정보"""
        cache_data = self._load_json_file(self.coupang_cache_file, {})
        usage_history = self._get_api_usage_history()
        error_log = self._load_json_file(self.error_log_file, [])
        
        # 유효한 캐시 엔트리 수
        valid_cache_count = sum(
            1 for entry in cache_data.values()
            if self._is_cache_valid(entry['timestamp'])
        )
        
        stats = {
            "memory_cache_count": len(self.memory_cache),
            "file_cache_total": len(cache_data),
            "file_cache_valid": valid_cache_count,
            "api_calls_last_hour": len(usage_history),
            "total_errors": len(error_log),
            "mode": self.mode
        }
        
        return stats
    
    def clear_cache(self, cache_type="all"):
        """캐시 정리"""
        if cache_type in ["all", "memory"]:
            self.memory_cache.clear()
            print("[🧹] 메모리 캐시 정리 완료")
        
        if cache_type in ["all", "file"]:
            if os.path.exists(self.coupang_cache_file):
                os.remove(self.coupang_cache_file)
                print("[🧹] 파일 캐시 정리 완료")
        
        if cache_type in ["all", "usage"]:
            if os.path.exists(self.api_usage_file):
                os.remove(self.api_usage_file)
                print("[🧹] API 사용 기록 정리 완료")
    
    def extract_coupang_product_id(self, url):
        """
        쿠팡 URL에서 상품 ID 추출
        예: https://www.coupang.com/products/123456789 → 123456789
        """
        import re
        
        # 다양한 쿠팡 URL 패턴 지원
        patterns = [
            r'/products/(\d+)',  # 기본 패턴
            r'/vp/products/(\d+)',  # 일부 변형
            r'productId=(\d+)',  # 쿼리 파라미터
        ]
        
        for pattern in patterns:
            match = re.search(pattern, url)
            if match:
                return match.group(1)
        
        return None
    
    def convert_coupang_to_affiliate_link(self, product_url):
        """
        쿠팡 일반 상품 링크를 어필리에이트 링크로 변환
        테스트에서 검증된 deeplink API 사용
        :param product_url: 쿠팡 일반 상품 URL
        :return: (success, affiliate_link, product_info)
        """
        print(f"\n[🔗] 쿠팡 링크 변환 (deeplink API): {product_url}")
        
        # 링크 변환도 API 제한에 포함되므로 확인
        if not self._can_make_api_call():
            print(f"[🚫] API 호출 제한 도달 - 링크 변환 불가")
            return False, None, None
        
        try:
            # 작동하는 deeplink API 엔드포인트 사용
            domain = "https://api-gateway.coupang.com"
            url_path = "/v2/providers/affiliate_open_api/apis/openapi/deeplink"
            
            # 요청 데이터 준비
            request_data = {
                "coupangUrls": [product_url],
                "subId": "novacents_workflow"
            }
            
            # HMAC 서명 생성 (POST 메서드)
            authorization = self._generate_coupang_signature(
                "POST", 
                url_path,
                self.config["coupang_secret_key"], 
                self.config["coupang_access_key"]
            )
            
            # 헤더 설정
            headers = {
                "Authorization": authorization,
                "Content-Type": "application/json"
            }
            
            # API 호출
            full_url = domain + url_path
            response = requests.post(full_url, json=request_data, headers=headers, timeout=30)
            
            # 429 에러 처리
            if response.status_code == 429:
                print(f"[⚠️] 링크 변환 429 에러 발생")
                self._handle_429_error()
                return False, None, None
            
            response.raise_for_status()
            
            # API 사용 기록 추가
            current_usage = self._add_api_usage_record()
            print(f"[📊] 링크 변환 API 호출 기록됨: {current_usage}/10")
            
            # 응답 처리 (deeplink API 응답 구조)
            data = response.json()
            
            if data.get("rCode") == "0" and data.get("data"):
                # deeplink API 성공 응답 처리
                link_data = data["data"][0] if isinstance(data["data"], list) else data["data"]
                
                affiliate_link = link_data.get("shortenUrl", "")
                landing_url = link_data.get("landingUrl", "")
                
                if affiliate_link:
                    print(f"[✅] 쿠팡 링크 변환 성공 (deeplink)")
                    print(f"[🔗] 어필리에이트 링크: {affiliate_link}")
                    print(f"[🔗] 랜딩 URL: {landing_url}")
                    
                    # 상품 정보도 함께 가져오기 (상품 ID 추출해서 검색)
                    product_id = self.extract_coupang_product_id(product_url)
                    product_info = None
                    
                    if product_id:
                        # 상품 ID로 상세 정보 검색 시도
                        success, products = self.search_coupang_safe(product_id, limit=1)
                        if success and products:
                            product_info = products[0]
                            # 어필리에이트 링크로 업데이트
                            product_info["affiliate_url"] = affiliate_link
                    
                    return True, affiliate_link, product_info
                else:
                    print(f"[⚠️] 쿠팡 deeplink 응답에 링크가 없음")
                    return False, None, None
            else:
                print(f"[⚠️] 쿠팡 deeplink 오류: {data.get('rMessage', '알 수 없는 오류')}")
                return False, None, None
            
        except requests.exceptions.RequestException as e:
            print(f"[❌] 쿠팡 링크 변환 API 호출 오류: {e}")
            self._log_error("link_conversion_error", {"error": str(e), "url": product_url})
            return False, None, None
        except Exception as e:
            print(f"[❌] 쿠팡 링크 변환 처리 중 오류: {e}")
            self._log_error("link_conversion_general_error", {"error": str(e), "url": product_url})
            return False, None, None
    
    def _init_aliexpress_sdk(self):
        """알리익스프레스 SDK 초기화"""
        try:
            # SDK 경로 추가
            sdk_path = '/home/novacents/aliexpress-sdk'
            if sdk_path not in sys.path:
                sys.path.append(sdk_path)
            
            # 공식 IOP SDK 로드 시도
            import iop
            self.aliexpress_sdk = iop
            print("[✅] 알리익스프레스 공식 IOP SDK 로드 성공")
            
        except ImportError as e:
            print(f"[⚠️] 알리익스프레스 SDK 로드 실패: {e}")
            self.aliexpress_sdk = None
        except Exception as e:
            print(f"[⚠️] 알리익스프레스 SDK 초기화 실패: {e}")
            self.aliexpress_sdk = None
    
    def extract_aliexpress_product_id(self, url):
        """
        알리익스프레스 URL에서 상품 ID 추출
        예: https://www.aliexpress.com/item/123456789.html → 123456789
        """
        import re
        
        # 다양한 알리익스프레스 URL 패턴 지원
        patterns = [
            r'/item/(\d+)\.html',  # 기본 패턴
            r'/item/(\d+)$',       # .html 없는 경우
            r'productId=(\d+)',    # 쿼리 파라미터
            r'/(\d+)\.html',       # 간단한 형태
        ]
        
        for pattern in patterns:
            match = re.search(pattern, url)
            if match:
                return match.group(1)
        
        return None
    
    def _generate_aliexpress_signature(self, params):
        """
        알리익스프레스 API MD5 서명 생성
        알리익스프레스 공식 가이드 기반
        """
        import hashlib
        
        app_secret = self.config["aliexpress_app_secret"]
        
        # 파라미터 정렬
        sorted_params = sorted(params.items())
        
        # 쿼리 스트링 생성 (URL 인코딩 없이)
        query_string = '&'.join([f'{k}={v}' for k, v in sorted_params])
        print(f"[DEBUG] AliExpress Query String: {query_string[:100]}...")
        
        # 서명 문자열 생성 (app_secret + query_string + app_secret)
        sign_string = app_secret + query_string + app_secret
        print(f"[DEBUG] AliExpress Sign String: {sign_string[:50]}...(길이: {len(sign_string)})")
        
        # MD5 해시 생성 후 대문자 변환
        signature = hashlib.md5(sign_string.encode('utf-8')).hexdigest().upper()
        
        return signature
    
    def convert_aliexpress_to_affiliate_link(self, product_url):
        """
        알리익스프레스 일반 상품 링크를 어필리에이트 링크로 변환
        테스트에서 검증된 공식 IOP SDK + tracking_id="default" 사용
        :param product_url: 알리익스프레스 일반 상품 URL
        :return: (success, affiliate_link, product_info)
        """
        print(f"\n[🔗] 알리익스프레스 링크 변환 (공식 SDK): {product_url}")
        
        # API 키 및 SDK 확인
        if not self.config["aliexpress_app_key"] or not self.config["aliexpress_app_secret"]:
            print(f"[❌] 알리익스프레스 API 키가 설정되지 않았습니다")
            return False, None, None
        
        if not self.aliexpress_sdk:
            print(f"[❌] 알리익스프레스 SDK가 로드되지 않았습니다")
            return False, None, None
        
        try:
            # 공식 IOP SDK 클라이언트 생성
            client = self.aliexpress_sdk.IopClient(
                'https://api-sg.aliexpress.com/sync',
                self.config["aliexpress_app_key"], 
                self.config["aliexpress_app_secret"]
            )
            
            # URL 정리 (쿼리 파라미터 제거)
            clean_url = product_url.split('?')[0]
            print(f"[🧹] 정리된 URL: {clean_url}")
            
            # 링크 변환 요청 생성
            request = self.aliexpress_sdk.IopRequest('aliexpress.affiliate.link.generate', 'POST')
            request.set_simplify()
            request.add_api_param('source_values', clean_url)
            request.add_api_param('promotion_link_type', '0')
            request.add_api_param('tracking_id', 'default')  # 테스트에서 성공한 tracking_id
            
            print(f"[📋] 요청 파라미터:")
            print(f"  source_values: {clean_url}")
            print(f"  promotion_link_type: 0")
            print(f"  tracking_id: default")
            
            # API 실행
            print(f"[⏳] 공식 SDK로 API 호출 중...")
            response = client.execute(request)
            
            print(f"[📨] 응답:")
            print(f"  Type: {response.type}")
            print(f"  Code: {response.code}")
            print(f"  Message: {response.message}")
            
            # 응답 처리
            if response.body and 'resp_result' in response.body:
                result = response.body['resp_result'].get('result', {})
                promotion_links = result.get('promotion_links', [])
                
                if promotion_links:
                    affiliate_link = promotion_links[0]['promotion_link']
                    source_value = promotion_links[0]['source_value']
                    
                    print(f"[✅] 알리익스프레스 링크 변환 성공 (공식 SDK)")
                    print(f"[📄] 원본: {source_value}")
                    print(f"[🔗] 어필리에이트: {affiliate_link}")
                    
                    # 상품 정보도 함께 가져오기
                    product_id = self.extract_aliexpress_product_id(product_url)
                    product_info = None
                    
                    if product_id:
                        # 상품 상세 정보 조회 시도 (공식 SDK 사용)
                        product_info = self._get_aliexpress_product_details_sdk(product_id, client)
                        if product_info:
                            # 어필리에이트 링크로 업데이트
                            product_info["affiliate_url"] = affiliate_link
                    
                    return True, affiliate_link, product_info
                else:
                    print(f"[⚠️] 알리익스프레스 링크 변환 응답에 링크가 없음")
                    return False, None, None
            else:
                print(f"[⚠️] 알리익스프레스 API 응답 오류")
                if response.body:
                    print(f"  응답 본문: {response.body}")
                return False, None, None
            
        except Exception as e:
            print(f"[❌] 알리익스프레스 링크 변환 처리 중 오류: {e}")
            self._log_error("aliexpress_link_conversion_general_error", {"error": str(e), "url": product_url})
            return False, None, None
    
    def _get_aliexpress_product_details_sdk(self, product_id, client):
        """
        알리익스프레스 상품 상세 정보 조회 (공식 SDK 사용, API 가이드 완전 활용)
        :param product_id: 상품 ID
        :param client: IOP 클라이언트
        :return: 상품 정보 딕셔너리 또는 None
        """
        try:
            # 상품 상세 API 호출 (API 가이드의 모든 필드 활용)
            detail_request = self.aliexpress_sdk.IopRequest('aliexpress.affiliate.product.detail', 'POST')
            detail_request.set_simplify()
            detail_request.add_api_param('product_ids', product_id)
            # API 가이드에서 제공하는 모든 유용한 필드들
            detail_request.add_api_param('fields', 'product_id,product_title,product_main_image_url,product_video_url,shop_url,shop_id,first_level_category_id,first_level_category_name,second_level_category_id,second_level_category_name,target_sale_price,target_sale_price_currency,target_original_price,target_original_price_currency,evaluate_rate,30days_commission,volume,platform_product_type,plus_product,relevant_market_commission_rate')
            detail_request.add_api_param('tracking_id', 'default')
            
            print(f"[📋] 알리익스프레스 상품 상세 정보 조회: {product_id}")
            detail_response = client.execute(detail_request)
            
            if detail_response.body and 'resp_result' in detail_response.body:
                detail_result = detail_response.body['resp_result'].get('result', {})
                products = detail_result.get('products', [])
                
                if products:
                    product = products[0]
                    
                    # USD를 KRW로 변환 (환율 1400원 적용)
                    usd_price = float(product.get('target_sale_price', 0))
                    krw_price = int(usd_price * 1400)
                    
                    # 원가 정보
                    usd_original_price = float(product.get('target_original_price', usd_price))
                    krw_original_price = int(usd_original_price * 1400)
                    
                    # 할인율 계산
                    discount_rate = 0
                    if usd_original_price > 0 and usd_original_price != usd_price:
                        discount_rate = round(((usd_original_price - usd_price) / usd_original_price) * 100)
                    
                    # 평점 정보 개선
                    rating_value = product.get("evaluate_rate", "0")
                    try:
                        rating_float = float(rating_value)
                        rating_display = f"{rating_float:.1f}점" if rating_float > 0 else "평점 정보 없음"
                    except:
                        rating_display = "평점 정보 없음"
                    
                    # 판매량 정보 개선
                    volume = product.get("volume", "0")
                    try:
                        volume_int = int(volume)
                        volume_display = f"{volume_int:,}개 판매" if volume_int > 0 else "판매량 정보 없음"
                    except:
                        volume_display = "판매량 정보 없음"
                    
                    # 수수료 정보 개선
                    commission = product.get("30days_commission", "0")
                    commission_rate = product.get("relevant_market_commission_rate", "0")
                    
                    formatted_product = {
                        "platform": "알리익스프레스",
                        "product_id": product_id,
                        "title": product.get("product_title", "상품명 없음"),
                        "price": f"${usd_price:.2f} (약 {krw_price:,}원)",
                        "original_price": f"${usd_original_price:.2f} (약 {krw_original_price:,}원)" if usd_original_price != usd_price else f"${usd_price:.2f} (약 {krw_price:,}원)",
                        "discount_rate": f"{discount_rate}%" if discount_rate > 0 else "할인 없음",
                        "currency": "USD/KRW",
                        "image_url": product.get("product_main_image_url", ""),
                        "video_url": product.get("product_video_url", ""),
                        "product_url": f"https://www.aliexpress.com/item/{product_id}.html",
                        "affiliate_url": "",  # 변환된 링크로 나중에 업데이트
                        "vendor": "알리익스프레스",
                        "shop_id": product.get("shop_id", ""),
                        "shop_url": product.get("shop_url", ""),
                        "category": product.get("first_level_category_name", ""),
                        "subcategory": product.get("second_level_category_name", ""),
                        "rating": rating_display,
                        "review_count": volume_display,
                        "commission": f"${commission}" if commission and commission != "0" else "수수료 정보 없음",
                        "commission_rate": commission_rate if commission_rate and commission_rate != "0" else "수수료율 정보 없음",
                        "is_plus": product.get("plus_product", False),
                        "product_type": product.get("platform_product_type", "ALL"),
                        "original_data": product
                    }
                    
                    print(f"[✅] 알리익스프레스 상품 상세 정보 조회 성공 (SDK): {formatted_product['title']}")
                    print(f"[💰] 가격: {formatted_product['price']}, 할인율: {formatted_product['discount_rate']}")
                    print(f"[⭐] 평점: {formatted_product['rating']}, 판매량: {formatted_product['review_count']}")
                    return formatted_product
            
            print(f"[⚠️] 알리익스프레스 상품 정보를 찾을 수 없습니다 (SDK)")
            return None
            
        except Exception as e:
            print(f"[❌] 알리익스프레스 상품 정보 조회 오류 (SDK): {e}")
            return None
    
    def _api_call_with_retry(self, api_func, api_name, max_retries=3, delay=1):
        """
        API 호출 재시도 로직 (공식 가이드 기반)
        :param api_func: API 호출 함수
        :param api_name: API 명칭 (로깅용)
        :param max_retries: 최대 재시도 횟수
        :param delay: 재시도 간 대기 시간 (초)
        :return: API 응답 또는 None
        """
        for attempt in range(1, max_retries + 1):
            try:
                print(f"[🔁] {api_name} API 호출 시도 #{attempt}")
                response = api_func()
                
                # 알리익스프레스 오류 확인
                if hasattr(response, 'body') and response.body:
                    if 'error_response' in response.body:
                        error_code = response.body['error_response'].get('code')
                        error_msg = response.body['error_response'].get('msg', '알 수 없는 오류')
                        
                        print(f"[❌] {api_name} API 오류: {error_code} - {error_msg}")
                        
                        # 서비스 오류인 경우 재시도
                        if error_code in ['5000', '1000', '2000']:  # 서비스 오류
                            wait_time = delay * (2 ** (attempt - 1))  # 지수적 백오프
                            print(f"[⏰] 서비스 오류 - {wait_time}초 대기 후 재시도")
                            if not self._is_test_mode():
                                time.sleep(wait_time)
                            continue
                        else:
                            # 다른 오류는 즉시 반환
                            self._log_error(f"{api_name}_api_error", {
                                'error_code': error_code,
                                'error_msg': error_msg,
                                'attempt': attempt
                            })
                            return None
                
                # 성공적인 응답
                print(f"[✅] {api_name} API 호출 성공 (시도 #{attempt})")
                return response
                
            except Exception as e:
                print(f"[❌] {api_name} API 호출 예외 (시도 #{attempt}): {str(e)}")
                
                if attempt == max_retries:
                    self._log_error(f"{api_name}_api_exception", {
                        'exception': str(e),
                        'max_attempts': max_retries
                    })
                    return None
                
                # 재시도 대기
                wait_time = delay * attempt
                print(f"[⏰] {wait_time}초 대기 후 재시도...")
                if not self._is_test_mode():
                    time.sleep(wait_time)
        
        return None
    
    def _format_aliexpress_response(self, products):
        """
        알리익스프레스 API 응답 포맷팅 (공식 가이드 기반)
        모든 필드 정보 활용
        :param products: 알리익스프레스 상품 리스트
        :return: 포맷팅된 상품 리스트
        """
        formatted_products = []
        
        for product in products:
            try:
                # USD 를 KRW로 변환 (환율 1400원 적용)
                usd_price = float(product.get('target_sale_price', 0))
                krw_price = int(usd_price * 1400)
                
                # 원가 정보
                usd_original_price = float(product.get('target_original_price', usd_price))
                krw_original_price = int(usd_original_price * 1400)
                
                # 할인율 계산
                discount_rate = 0
                if usd_original_price > 0 and usd_original_price != usd_price:
                    discount_rate = round(((usd_original_price - usd_price) / usd_original_price) * 100)
                
                # 평점 정보 개선
                rating_value = product.get("evaluate_rate", "0")
                try:
                    rating_float = float(rating_value)
                    rating_display = f"{rating_float:.1f}점" if rating_float > 0 else "평점 정보 없음"
                except:
                    rating_display = "평점 정보 없음"
                
                # 판매량 정보 개선
                volume = product.get("volume", "0")
                try:
                    volume_int = int(volume)
                    volume_display = f"{volume_int:,}개 판매" if volume_int > 0 else "판매량 정보 없음"
                except:
                    volume_display = "판매량 정보 없음"
                
                # 수수료 정보 개선
                commission = product.get("30days_commission", "0")
                commission_rate = product.get("relevant_market_commission_rate", "0")
                
                formatted_product = {
                    "platform": "알리익스프레스",
                    "product_id": product.get("product_id"),
                    "title": product.get("product_title", "상품명 없음"),
                    "price": f"${usd_price:.2f} (약 {krw_price:,}원)",
                    "original_price": f"${usd_original_price:.2f} (약 {krw_original_price:,}원)" if usd_original_price != usd_price else f"${usd_price:.2f} (약 {krw_price:,}원)",
                    "discount_rate": f"{discount_rate}%" if discount_rate > 0 else "할인 없음",
                    "currency": "USD/KRW",
                    "image_url": product.get("product_main_image_url", ""),
                    "video_url": product.get("product_video_url", ""),
                    "product_url": f"https://www.aliexpress.com/item/{product.get('product_id')}.html",
                    "affiliate_url": "",  # 변환된 링크로 나중에 업데이트
                    "vendor": "알리익스프레스",
                    "shop_id": product.get("shop_id", ""),
                    "shop_url": product.get("shop_url", ""),
                    "category": product.get("first_level_category_name", ""),
                    "subcategory": product.get("second_level_category_name", ""),
                    "rating": rating_display,
                    "review_count": volume_display,
                    "commission": f"${commission}" if commission and commission != "0" else "수수료 정보 없음",
                    "commission_rate": commission_rate if commission_rate and commission_rate != "0" else "수수료율 정보 없음",
                    "is_plus": product.get("plus_product", False),
                    "product_type": product.get("platform_product_type", "ALL"),
                    "original_data": product
                }
                
                formatted_products.append(formatted_product)
                
            except Exception as e:
                print(f"[⚠️] 상품 포맷팅 오류: {e}")
                continue
        
        return formatted_products
    
    def _get_aliexpress_product_details(self, product_id):
        """
        알리익스프레스 상품 상세 정보 조회 (레거시 메서드 - 호환성 유지)
        :param product_id: 상품 ID
        :return: 상품 정보 딕셔너리 또는 None
        """
        if self.aliexpress_sdk:
            # SDK가 있으면 새로운 메서드 사용
            client = self.aliexpress_sdk.IopClient(
                'https://api-sg.aliexpress.com/sync',
                self.config["aliexpress_app_key"], 
                self.config["aliexpress_app_secret"]
            )
            return self._get_aliexpress_product_details_sdk(product_id, client)
        else:
            print(f"[⚠️] 알리익스프레스 SDK 없음 - 상품 정보 조회 불가")
            return None
    
    def search_aliexpress_advanced(self, keyword, category_ids=None, min_price=None, max_price=None, page_no=1, page_size=20):
        """
        고급 알리익스프레스 상품 검색 (공식 가이드 추가 기능)
        카테고리, 가격 범위, 페이지 지원
        :param keyword: 검색 키워드
        :param category_ids: 카테고리 ID 목록 (콤마 구분)
        :param min_price: 최소 가격 (USD)
        :param max_price: 최대 가격 (USD)
        :param page_no: 페이지 번호
        :param page_size: 페이지 크기 (최대 50)
        :return: (success, products_list, total_count)
        """
        print(f"\n[🔍] 고급 알리익스프레스 검색: '{keyword}'")
        print(f"  파라미터: 카테고리={category_ids}, 가격={min_price}-{max_price}, 페이지={page_no}/{page_size}")
        
        # API 키 및 SDK 확인
        if not self.config["aliexpress_app_key"] or not self.config["aliexpress_app_secret"]:
            print(f"[❌] 알리익스프레스 API 키가 설정되지 않았습니다")
            return False, [], 0
        
        if not self.aliexpress_sdk:
            print(f"[❌] 알리익스프레스 SDK가 로드되지 않았습니다")
            return False, [], 0
        
        cache_key = f"aliexpress_adv_{keyword}_{category_ids}_{min_price}_{max_price}_{page_no}_{page_size}"
        
        # 캐시 확인
        cached_result = self._get_memory_cache(cache_key)
        if cached_result:
            return True, cached_result['products'], cached_result['total_count']
        
        try:
            # 공식 IOP SDK 클라이언트 생성
            client = self.aliexpress_sdk.IopClient(
                'https://api-sg.aliexpress.com/sync',
                self.config["aliexpress_app_key"], 
                self.config["aliexpress_app_secret"]
            )
            
            # 공식 가이드에 따른 상품 검색 요청
            request = self.aliexpress_sdk.IopRequest('aliexpress.affiliate.product.query', 'GET')
            request.set_simplify()
            request.add_api_param('keywords', keyword)
            request.add_api_param('page_no', str(page_no))
            request.add_api_param('page_size', str(min(page_size, 50)))  # 최대 50개 제한
            request.add_api_param('tracking_id', 'default')
            
            # 선택적 파라미터 추가
            if category_ids:
                request.add_api_param('category_ids', str(category_ids))
            if min_price:
                request.add_api_param('min_sale_price', str(min_price))
            if max_price:
                request.add_api_param('max_sale_price', str(max_price))
            
            print(f"[📡] 고급 검색 API 호출 중...")
            response = client.execute(request)
            
            print(f"[📜] 응답: {response.type}, 코드: {response.code}")
            
            if response.body and 'resp_result' in response.body:
                result = response.body['resp_result'].get('result', {})
                products = result.get('products', [])
                total_count = result.get('total_record_count', 0)
                
                if products:
                    formatted_products = self._format_aliexpress_response(products)
                    
                    # 캐시 저장
                    cache_data = {
                        'products': formatted_products,
                        'total_count': total_count
                    }
                    self._set_memory_cache(cache_key, cache_data)
                    
                    print(f"[✅] 고급 검색 성공: {len(formatted_products)}개 / 전체 {total_count}개")
                    return True, formatted_products, total_count
                else:
                    print(f"[⚠️] 검색 결과 없음")
                    return True, [], 0
            else:
                print(f"[❌] 잘못된 응답 구조")
                return False, [], 0
                
        except Exception as e:
            print(f"[❌] 고급 검색 예외: {str(e)}")
            self._log_error("aliexpress_advanced_search_error", str(e))
            return False, [], 0

    def search_aliexpress_safe(self, keyword, limit=5, force_api=False):
        """
        안전한 알리익스프레스 상품 검색 (API 가이드 기반 개선)
        공식 IOP SDK 활용
        :param keyword: 검색 키워드
        :param limit: 결과 개수 제한
        :param force_api: 캐시 무시하고 강제 API 호출
        :return: (success, products_list)
        """
        print(f"\n[🔍] 안전한 알리익스프레스 검색: '{keyword}' (limit: {limit})")
        
        # API 키 및 SDK 확인
        if not self.config["aliexpress_app_key"] or not self.config["aliexpress_app_secret"]:
            print(f"[❌] 알리익스프레스 API 키가 설정되지 않았습니다")
            return False, []
        
        if not self.aliexpress_sdk:
            print(f"[❌] 알리익스프레스 SDK가 로드되지 않았습니다")
            return False, []
        
        cache_key = f"aliexpress_{self._get_cache_key(keyword, limit)}"
        
        # 1단계: 메모리 캐시 확인
        if not force_api:
            cached_data = self._get_memory_cache(cache_key)
            if cached_data is not None:
                return True, cached_data
        
        # 2단계: 실제 API 호출
        try:
            # 공식 IOP SDK 클라이언트 생성
            client = self.aliexpress_sdk.IopClient(
                'https://api-sg.aliexpress.com/sync',
                self.config["aliexpress_app_key"], 
                self.config["aliexpress_app_secret"]
            )
            
            # 상품 검색 요청 생성 (API 가이드 기반)
            request = self.aliexpress_sdk.IopRequest('aliexpress.affiliate.product.query', 'POST')
            request.set_simplify()
            request.add_api_param('keywords', keyword)
            request.add_api_param('page_size', min(limit, 50))  # API 가이드: 최대 50개
            request.add_api_param('page_no', '1')
            request.add_api_param('tracking_id', 'default')
            # API 가이드에 따른 추가 파라미터
            request.add_api_param('fields', 'product_id,product_title,product_main_image_url,product_video_url,shop_url,target_sale_price,target_sale_price_currency,target_original_price,target_original_price_currency,evaluate_rate,volume,30days_commission,relevant_market_commission_rate,plus_product')
            
            print(f"[📋] 검색 요청 파라미터:")
            print(f"  keywords: {keyword}")
            print(f"  page_size: {min(limit, 50)}")
            print(f"  tracking_id: default")
            
            # API 실행 (재시도 로직 포함)
            print(f"[⏳] 공식 SDK로 상품 검색 중...")
            response = self._api_call_with_retry(lambda: client.execute(request), "aliexpress_search")
            
            if not response:
                print(f"[❌] 알리익스프레스 API 호출 실패 (재시도 횟수 초과)")
                return False, []
            
            print(f"[📨] 응답:")
            print(f"  Type: {response.type}")
            print(f"  Code: {response.code}")
            print(f"  Message: {response.message}")
            
            # 응답 처리 (실제 응답 구조 기반으로 수정)
            if response.body and 'resp_result' in response.body:
                resp_result = response.body['resp_result']
                
                if resp_result and resp_result.get('resp_code') == 200:
                    result = resp_result.get('result', {})
                    products = result.get('products', [])
                    
                    if products:
                        formatted_products = self._format_aliexpress_response(products)
                        
                        # 캐시에 저장
                        self._set_memory_cache(cache_key, formatted_products)
                        
                        print(f"[✅] 알리익스프레스 API 호출 성공: {len(formatted_products)}개 상품")
                        return True, formatted_products
                    else:
                        print(f"[⚠️] 알리익스프레스 검색 결과 없음")
                        return False, []
                else:
                    print(f"[⚠️] 알리익스프레스 API 응답 코드 오류: {resp_result.get('resp_code', 'N/A')} - {resp_result.get('resp_msg', 'N/A')}")
                    return False, []
            else:
                print(f"[⚠️] 알리익스프레스 API 응답 구조 오류")
                if response.body:
                    print(f"  응답 본문: {str(response.body)[:500]}...")
                return False, []
            
        except Exception as e:
            print(f"[❌] 알리익스프레스 검색 처리 중 오류: {e}")
            self._log_error("aliexpress_search_error", {"error": str(e), "keyword": keyword})
            return False, []
    
    def _format_aliexpress_response(self, products):
        """알리익스프레스 API 응답 데이터 형식화 (API 가이드 기반)"""
        formatted_products = []
        
        for product in products:
            # USD를 KRW로 변환 (환율 1400원 적용)
            usd_price = float(product.get('target_sale_price', 0))
            krw_price = int(usd_price * 1400)
            
            # 할인율 계산
            original_price = float(product.get('target_original_price', 0))
            discount_rate = 0
            if original_price > 0 and original_price != usd_price:
                discount_rate = round(((original_price - usd_price) / original_price) * 100)
            
            # 평점 정보 개선
            rating_value = product.get("evaluate_rate", "0")
            try:
                rating_float = float(rating_value)
                rating_display = f"{rating_float:.1f}점" if rating_float > 0 else "평점 정보 없음"
            except:
                rating_display = "평점 정보 없음"
            
            # 판매량 정보 개선
            volume = product.get("volume", "0")
            try:
                volume_int = int(volume)
                volume_display = f"{volume_int:,}개 판매" if volume_int > 0 else "판매량 정보 없음"
            except:
                volume_display = "판매량 정보 없음"
            
            formatted_product = {
                "platform": "알리익스프레스",
                "product_id": product.get("product_id"),
                "title": product.get("product_title", "상품명 없음"),
                "price": f"${usd_price:.2f} (약 {krw_price:,}원)",
                "original_price": f"${original_price:.2f} (약 {int(original_price * 1400):,}원)" if original_price > 0 and original_price != usd_price else f"${usd_price:.2f} (약 {krw_price:,}원)",
                "discount_rate": f"{discount_rate}%" if discount_rate > 0 else "할인 없음",
                "currency": "USD/KRW",
                "image_url": product.get("product_main_image_url", ""),
                "video_url": product.get("product_video_url", ""),
                "product_url": f"https://www.aliexpress.com/item/{product.get('product_id')}.html",
                "affiliate_url": "",  # 링크 변환 후 업데이트
                "vendor": "알리익스프레스",
                "shop_id": product.get("shop_id"),
                "shop_url": product.get("shop_url"),
                "category": product.get("first_level_category_name"),
                "subcategory": product.get("second_level_category_name"),
                "rating": rating_display,
                "review_count": volume_display,
                "commission": product.get("30days_commission", "수수료 정보 없음"),
                "commission_rate": product.get("relevant_market_commission_rate", "수수료율 정보 없음"),
                "is_plus": product.get("plus_product", False),
                "original_data": product
            }
            formatted_products.append(formatted_product)
        
        return formatted_products
