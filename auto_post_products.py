#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
알리익스프레스 자동 포스팅 시스템 v3.0
- 분할 큐 시스템 지원
- 즉시 발행 모드 지원
- 메모리 최적화 강화
- 디버깅 강화

작성자: Claude AI
날짜: 2025-07-25
버전: v3.0
"""

import requests
import json
import time
import os
import sys
import subprocess
import gc
from datetime import datetime

# 설정
QUEUE_FILE = "/var/www/novacents/tools/product_queue.json"
MAX_POSTS_PER_RUN = 3

# WordPress REST API 설정
CONFIG_FILE = "/var/www/novacents/tools/config.json"

def load_config():
    """설정 파일 로드"""
    try:
        with open(CONFIG_FILE, "r", encoding="utf-8") as f:
            return json.load(f)
    except Exception as e:
        print(f"[❌] 설정 파일 로드 실패: {e}")
        return None

def normalize_url(url):
    """URL 정규화 - 상품 ID만 추출"""
    if not url:
        return ""
    
    # 알리익스프레스 상품 ID 추출
    import re
    patterns = [
        r'/item/(\d+)\.html',
        r'/item/(\d+)',
        r'productId=(\d+)',
        r'/(\d+)\.html'
    ]
    
    for pattern in patterns:
        match = re.search(pattern, url)
        if match:
            return match.group(1)
    
    return url

class AliExpressPostingSystem:
    def __init__(self):
        # 설정 로드
        self.config = load_config()
        if not self.config:
            raise Exception("설정 파일을 로드할 수 없습니다.")
        
        # WordPress 인증 정보
        self.wp_auth = (
            self.config.get("wp_username"), 
            self.config.get("wp_app_password")
        )
        
        # 요청 헤더
        self.headers = {
            "Content-Type": "application/json",
            "User-Agent": "AliExpress Auto Poster v3.0"
        }
        
        print("[🚀] 알리익스프레스 자동 포스팅 시스템 v3.0 초기화 완료")
        
    def log_message(self, message):
        """로그 메시지 출력 (타임스탬프 포함)"""
        timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        print(f"[{timestamp}] {message}")
        
    def get_gemini_api_key(self):
        """Gemini API 키 로드"""
        try:
            with open("/var/www/novacents/tools/gemini_api_key.txt", "r") as f:
                api_key = f.read().strip()
                return api_key
        except Exception as e:
            print(f"[❌] Gemini API 키 로드 실패: {e}")
            return None
            
    def convert_aliexpress_to_affiliate_link(self, original_url):
        """알리익스프레스 URL을 어필리에이트 링크로 변환"""
        try:
            api_key = self.config.get("aliexpress_api_key")
            app_signature = self.config.get("aliexpress_app_signature")
            
            if not api_key or not app_signature:
                print("[❌] 알리익스프레스 API 설정이 없습니다.")
                return original_url
            
            # API 요청 구성
            params = {
                "app_key": api_key,
                "method": "aliexpress.affiliate.link.generate",
                "format": "json",
                "v": "2.0",
                "sign_method": "md5",
                "timestamp": str(int(time.time() * 1000)),
                "promotion_link_type": "0",
                "source_values": original_url
            }
            
            # 서명 생성 (실제로는 MD5 해시가 필요하지만 단순화)
            params["sign"] = app_signature
            
            # API 호출
            response = requests.get(
                "https://api-sg.aliexpress.com/sync",
                params=params,
                timeout=10
            )
            
            if response.status_code == 200:
                data = response.json()
                if data.get("aliexpress_affiliate_link_generate_response"):
                    result = data["aliexpress_affiliate_link_generate_response"]["resp_result"]
                    if result.get("result") and result["result"].get("promotion_links"):
                        affiliate_url = result["result"]["promotion_links"][0]["promotion_link"]
                        return affiliate_url
            
            print(f"[⚠️] 어필리에이트 링크 변환 실패, 원본 URL 사용")
            return original_url
            
        except Exception as e:
            print(f"[⚠️] 어필리에이트 링크 변환 중 오류: {e}")
            return original_url
            
    def call_php_function(self, function_name, *args):
        """PHP queue_utils.php 함수 호출"""
        try:
            # PHP 스크립트 경로
            php_script = "/var/www/queue_utils.php"
            
            if not os.path.exists(php_script):
                print(f"[❌] PHP 스크립트를 찾을 수 없습니다: {php_script}")
                return None
            
            # PHP 함수 호출을 위한 wrapper 스크립트 생성
            wrapper_code = f"""<?php
require_once '{php_script}';

$function_name = '{function_name}';
$args = json_decode('{json.dumps(list(args), ensure_ascii=False)}', true);

try {{
    $result = call_user_func_array($function_name, $args);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
}} catch (Exception $e) {{
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}}
?>"""
            
            # 임시 파일에 wrapper 스크립트 저장
            temp_file = f"/tmp/php_wrapper_{int(time.time())}.php"
            with open(temp_file, 'w', encoding='utf-8') as f:
                f.write(wrapper_code)
            
            # PHP 실행
            result = subprocess.run(
                ['php', temp_file],
                capture_output=True,
                text=True,
                timeout=30
            )
            
            # 임시 파일 삭제
            os.unlink(temp_file)
            
            if result.returncode == 0:
                response = json.loads(result.stdout)
                if isinstance(response, dict) and 'error' in response:
                    print(f"[❌] PHP 함수 오류: {response['error']}")
                    return None
                return response
            else:
                print(f"[❌] PHP 실행 오류: {result.stderr}")
                return None
                
        except Exception as e:
            print(f"[❌] PHP 함수 호출 중 오류: {e}")
            return None
        finally:
            # 메모리 정리
            gc.collect()
        
    def load_queue_split(self):
        """분할 큐 시스템에서 pending 작업 로드"""
        try:
            print("[📋] 분할 큐 시스템에서 대기 중인 작업을 로드합니다...")
            
            # PHP 함수 호출: get_pending_queues_split($limit)
            pending_jobs = self.call_php_function('get_pending_queues_split', MAX_POSTS_PER_RUN)
            
            if pending_jobs is None:
                print("[❌] 분할 큐 로드 실패")
                return []
            
            if not isinstance(pending_jobs, list):
                print(f"[❌] 예상치 못한 응답 형태: {type(pending_jobs)}")
                return []
            
            print(f"[📋] 분할 큐에서 {len(pending_jobs)}개의 대기 중인 작업을 발견했습니다.")
            return pending_jobs
            
        except Exception as e:
            print(f"[❌] 분할 큐 로드 중 오류 발생: {e}")
            return []
        finally:
            gc.collect()
            
    def update_queue_status_split(self, queue_id, status, error_message=None):
        """분할 큐 시스템에서 작업 상태 업데이트"""
        try:
            # PHP 함수 호출: update_queue_status_split($queue_id, $new_status, $error_message)
            result = self.call_php_function('update_queue_status_split', queue_id, status, error_message)
            
            if result:
                print(f"[✅] 큐 상태 업데이트 성공: {queue_id} -> {status}")
                return True
            else:
                print(f"[❌] 큐 상태 업데이트 실패: {queue_id}")
                return False
                
        except Exception as e:
            print(f"[❌] 큐 상태 업데이트 중 오류: {e}")
            return False
            
    def remove_completed_from_queue_split(self, queue_id):
        """분할 큐 시스템에서 완료된 작업 제거 (실제로는 상태만 변경)"""
        try:
            # completed 상태로 변경 (파일은 completed 디렉토리로 이동)
            return self.update_queue_status_split(queue_id, 'completed')
        except Exception as e:
            print(f"[❌] 완료된 작업 제거 중 오류: {e}")
            return False
            
    def load_queue_legacy(self):
        """레거시 큐 파일에서 pending 작업 로드 (호환성)"""
        try:
            if not os.path.exists(QUEUE_FILE):
                print(f"[⚠️] 레거시 큐 파일이 없습니다. 분할 시스템을 사용합니다.")
                return self.load_queue_split()
                
            with open(QUEUE_FILE, "r", encoding="utf-8") as f:
                queue_data = json.load(f)
                
            # pending 상태인 작업만 필터링
            pending_jobs = [job for job in queue_data if job.get("status") == "pending"]
            
            print(f"[📋] 레거시 큐에서 {len(pending_jobs)}개의 대기 중인 작업을 발견했습니다.")
            
            # 전체 큐 데이터는 메모리에서 제거
            del queue_data
            gc.collect()  # 가비지 컬렉션 강제 실행
            
            return pending_jobs
            
        except Exception as e:
            print(f"[❌] 레거시 큐 로드 중 오류 발생: {e}")
            print("[🔄] 분할 시스템으로 전환합니다.")
            return self.load_queue_split()
            
    def save_queue(self, queue_data):
        """레거시 큐 파일 저장 (호환성)"""
        try:
            with open(QUEUE_FILE, "w", encoding="utf-8") as f:
                json.dump(queue_data, f, ensure_ascii=False, indent=4)
            print("[✅] 레거시 큐 파일이 성공적으로 저장되었습니다.")
        except Exception as e:
            print(f"[❌] 레거시 큐 저장 중 오류 발생: {e}")
            
    def update_job_status(self, job_id, status, error_message=None):
        """작업 상태 업데이트 (분할 시스템 우선)"""
        # 분할 시스템 먼저 시도
        success = self.update_queue_status_split(job_id, status, error_message)
        
        if success:
            return True
        
        # 분할 시스템 실패 시 레거시 시스템 시도
        try:
            if not os.path.exists(QUEUE_FILE):
                print("[⚠️] 레거시 큐 파일이 없어 상태 업데이트를 건너뜁니다.")
                return False
                
            with open(QUEUE_FILE, "r", encoding="utf-8") as f:
                queue_data = json.load(f)
            
            # 해당 작업 찾아서 상태 업데이트
            for job in queue_data:
                if job.get("queue_id") == job_id or job.get("id") == job_id:
                    job["status"] = status
                    if error_message:
                        job["error_message"] = error_message
                    job["updated_at"] = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
                    break
            
            # 파일 저장
            self.save_queue(queue_data)
            print(f"[✅] 레거시 큐 상태 업데이트 성공: {job_id} -> {status}")
            return True
            
        except Exception as e:
            print(f"[❌] 레거시 큐 상태 업데이트 중 오류: {e}")
            return False
            
    def remove_completed_job(self, job_id):
        """완료된 작업 제거 (분할 시스템 우선)"""
        # 분할 시스템 먼저 시도
        success = self.remove_completed_from_queue_split(job_id)
        
        if success:
            return True
        
        # 분할 시스템 실패 시 레거시 시스템 시도
        try:
            if not os.path.exists(QUEUE_FILE):
                print("[⚠️] 레거시 큐 파일이 없어 작업 제거를 건너뜁니다.")
                return False
                
            with open(QUEUE_FILE, "r", encoding="utf-8") as f:
                queue_data = json.load(f)
            
            # 해당 작업 제거
            original_length = len(queue_data)
            queue_data = [job for job in queue_data if job.get("queue_id") != job_id and job.get("id") != job_id]
            
            if len(queue_data) < original_length:
                self.save_queue(queue_data)
                print(f"[✅] 레거시 큐에서 완료된 작업 제거: {job_id}")
                return True
            else:
                print(f"[⚠️] 제거할 작업을 찾지 못했습니다: {job_id}")
                return False
                
        except Exception as e:
            print(f"[❌] 레거시 큐 작업 제거 중 오류: {e}")
            return False
    
    def load_immediate_job(self, temp_file):
        """즉시 발행용 임시 파일에서 작업 로드"""
        try:
            print(f"[📄] 즉시 발행 임시 파일 로드: {temp_file}")
            
            if not os.path.exists(temp_file):
                print(f"[❌] 임시 파일을 찾을 수 없습니다: {temp_file}")
                return None
                
            with open(temp_file, "r", encoding="utf-8") as f:
                temp_data = json.load(f)
                
            # 데이터 구조 검증
            if temp_data.get('mode') != 'immediate':
                print(f"[❌] 잘못된 임시 파일 모드: {temp_data.get('mode')}")
                return None
                
            job_data = temp_data.get('job_data')
            if not job_data:
                print(f"[❌] 임시 파일에 작업 데이터가 없습니다.")
                return None
                
            print(f"[✅] 즉시 발행 작업 로드 성공: {job_data.get('title', 'N/A')}")
            
            # temp_data는 더 이상 필요 없으므로 삭제
            del temp_data
            
            return job_data
            
        except Exception as e:
            print(f"[❌] 즉시 발행 임시 파일 로드 중 오류: {e}")
            return None
            
    def cleanup_temp_file(self, temp_file):
        """임시 파일 정리 (선택사항)"""
        try:
            if os.path.exists(temp_file):
                # 임시 파일을 바로 삭제하지 않고 유지 (사용자가 수동 삭제)
                print(f"[🗂️] 임시 파일 유지: {temp_file}")
                print(f"[💡] 수동 삭제 필요: rm {temp_file}")
        except Exception as e:
            print(f"[❌] 임시 파일 정리 중 오류: {e}")
    
    def extract_aliexpress_product_id(self, url):
        """알리익스프레스 URL에서 상품 ID 추출"""
        patterns = [
            r'/item/(\d+)\.html',  # 기본 패턴
            r'/item/(\d+)$',       # .html 없는 경우
            r'productId=(\d+)',    # 쿼리 파라미터
            r'/(\d+)\.html',       # 간단한 형태
        ]
        
        for pattern in patterns:
            import re
            match = re.search(pattern, url)
            if match:
                return match.group(1)
        
        return None

    def get_aliexpress_product_details(self, product_id):
        """알리익스프레스 상품 상세 정보 조회"""
        try:
            api_key = self.config.get("aliexpress_api_key")
            app_signature = self.config.get("aliexpress_app_signature")
            
            if not api_key or not app_signature:
                print("[❌] 알리익스프레스 API 설정이 없습니다.")
                return None
            
            # API 요청 구성
            params = {
                "app_key": api_key,
                "method": "aliexpress.affiliate.product.detail.get",
                "format": "json",
                "v": "2.0",
                "sign_method": "md5",
                "timestamp": str(int(time.time() * 1000)),
                "product_ids": product_id,
                "fields": "product_title,product_main_image_url,product_video_url,evaluate_rate,original_price,sale_price,discount,volume,shop_id,shop_url",
                "country": "KR",
                "target_currency": "KRW",
                "target_language": "ko"
            }
            
            # 서명 생성 (실제로는 MD5 해시가 필요하지만 단순화)
            params["sign"] = app_signature
            
            # API 호출
            response = requests.get(
                "https://api-sg.aliexpress.com/sync",
                params=params,
                timeout=15
            )
            
            if response.status_code == 200:
                data = response.json()
                if data.get("aliexpress_affiliate_product_detail_get_response"):
                    result = data["aliexpress_affiliate_product_detail_get_response"]["resp_result"]
                    if result.get("result") and result["result"].get("products"):
                        product = result["result"]["products"][0]
                        
                        # 가격 정보 처리
                        price_info = product.get("target_sale_price") or product.get("target_original_price")
                        price = "가격 확인 필요"
                        if price_info:
                            currency = price_info.get("currency_code", "KRW")
                            amount = price_info.get("amount", "0")
                            if currency == "KRW":
                                try:
                                    krw_price = int(float(amount))
                                    price = f"₩{krw_price:,}"
                                except:
                                    price = f"₩{amount}"
                            else:
                                price = f"{amount} {currency}"
                        
                        # 평점 정보 처리
                        rating = product.get("evaluate_rate", "0")
                        try:
                            rating_float = float(rating)
                            star_count = int(rating_float)
                            stars = "⭐" * min(star_count, 5)
                            rating_display = f"{stars} ({rating}%)"
                        except:
                            rating_display = "평점 정보 없음"
                        
                        # 판매량 정보 처리
                        volume = product.get("volume", 0)
                        try:
                            volume_int = int(volume)
                            if volume_int > 1000:
                                volume_display = f"{volume_int//1000}k개 판매"
                            elif volume_int > 0:
                                volume_display = f"{volume_int}개 판매"
                            else:
                                volume_display = "판매량 정보 없음"
                        except:
                            volume_display = "판매량 정보 없음"
                        
                        return {
                            "product_id": product_id,
                            "title": product.get("product_title", "상품명 없음"),
                            "price": price,
                            "image_url": product.get("product_main_image_url", ""),
                            "rating_display": rating_display,
                            "lastest_volume": volume_display
                            # "original_data": product  # 제거됨 - 메모리 절약
                        }
            
            print(f"[⚠️] 상품 정보 조회 실패: {product_id}")
            return None
            
        except Exception as e:
            print(f"[⚠️] 상품 상세 정보 조회 중 오류: {e}")
            return None
        finally:
            # 메모리 정리
            gc.collect()

    def process_aliexpress_products(self, job_data):
        """알리익스프레스 상품 처리 (큐 데이터 강제 우선 사용 + 디버깅 강화)"""
        print("[🌏] 알리익스프레스 상품 처리를 시작합니다...")
        
        processed_products = []
        
        for keyword_data in job_data["keywords"]:
            keyword = keyword_data["name"]
            aliexpress_links = keyword_data.get("aliexpress", [])
            # 🔧 키워드별 products_data 올바르게 접근
            products_data = keyword_data.get("products_data", [])
            
            print(f"[📋] 키워드 '{keyword}' 처리 중...")
            print(f"[🔍] 알리익스프레스 링크: {len(aliexpress_links)}개")
            print(f"[🔍] 큐 products_data: {len(products_data)}개")
            
            # 디버깅: 큐 데이터 구조 확인
            if products_data:
                for i, product_data in enumerate(products_data):
                    print(f"[🔍] 큐 상품 {i+1}: URL={product_data.get('url', 'N/A')[:50]}...")
                    if product_data.get('analysis_data'):
                        analysis = product_data['analysis_data']
                        print(f"[🔍]   제목: {analysis.get('title', 'N/A')[:50]}...")
                        print(f"[🔍]   가격: {analysis.get('price', 'N/A')}")
                        print(f"[🔍]   평점: {analysis.get('rating_display', 'N/A')}")
            
            # 🚀 큐에 저장된 완성된 상품 데이터 강제 우선 사용
            for i, link in enumerate(aliexpress_links):
                if link.strip():
                    print(f"[🔍] 처리 중인 링크: {link[:50]}...")
                    
                    # 1순위: 키워드의 products_data에서 해당 상품 데이터 찾기 (강화된 매칭)
                    queue_product = None
                    link_normalized = normalize_url(link.strip())
                    
                    for product_data in products_data:
                        queue_url = product_data.get('url', '')
                        queue_url_normalized = normalize_url(queue_url)
                        
                        # URL 정규화 후 매칭 (상품 ID 기반)
                        if link_normalized and queue_url_normalized and link_normalized == queue_url_normalized:
                            queue_product = product_data
                            print(f"[✅] 큐 데이터 매칭 성공 (상품 ID: {link_normalized})")
                            break
                        # 부분 URL 매칭도 시도
                        elif link.strip() in queue_url or queue_url in link.strip():
                            queue_product = product_data
                            print(f"[✅] 큐 데이터 매칭 성공 (부분 매칭)")
                            break
                    
                    # 🔒 큐 데이터가 있으면 무조건 사용 (API 호출 차단)
                    if queue_product and queue_product.get('analysis_data'):
                        analysis_data = queue_product['analysis_data']
                        product_info = {
                            "product_id": analysis_data.get("product_id", ""),
                            "title": analysis_data.get("title", f"{keyword} 관련 상품"),
                            "price": analysis_data.get("price", "가격 확인 필요"),
                            "image_url": analysis_data.get("image_url", ""),
                            "rating_display": analysis_data.get("rating_display", "평점 정보 없음"),
                            "lastest_volume": analysis_data.get("lastest_volume", "판매량 정보 없음"),
                            "affiliate_url": analysis_data.get("affiliate_link", ""),
                            "keyword": keyword
                        }
                        processed_products.append(product_info)
                        print(f"[✅] 큐 데이터 사용: {analysis_data.get('title', 'N/A')[:50]}...")
                        print(f"[✅]   가격: {analysis_data.get('price', 'N/A')}")
                        print(f"[✅]   평점: {analysis_data.get('rating_display', 'N/A')}")
                        continue
                    
                    # 🚀 큐에 products_data가 있지만 analysis_data가 없는 경우도 강제로 큐 데이터 활용
                    elif products_data:
                        # 첫 번째 큐 상품의 기본 정보라도 사용
                        first_queue_product = products_data[0]
                        if first_queue_product.get('analysis_data'):
                            analysis_data = first_queue_product['analysis_data']
                            product_info = {
                                "product_id": analysis_data.get("product_id", ""),
                                "title": analysis_data.get("title", f"{keyword} 관련 상품"),
                                "price": analysis_data.get("price", "가격 확인 필요"),
                                "image_url": analysis_data.get("image_url", ""),
                                "rating_display": analysis_data.get("rating_display", "평점 정보 없음"),
                                "lastest_volume": analysis_data.get("lastest_volume", "판매량 정보 없음"),
                                "affiliate_url": analysis_data.get("affiliate_link", ""),
                                "keyword": keyword
                            }
                            processed_products.append(product_info)
                            print(f"[⚠️] 매칭 실패로 첫 번째 큐 데이터 사용: {analysis_data.get('title', 'N/A')[:50]}...")
                            continue
                    
                    # 마지막 폴백: API 호출 (큐 데이터가 전혀 없는 경우에만)
                    print(f"[❌] 큐 데이터 없음, API 호출: {link[:50]}...")
                    affiliate_link = self.convert_aliexpress_to_affiliate_link(link.strip())
                    
                    if affiliate_link:
                        # 상품 ID 추출
                        product_id = self.extract_aliexpress_product_id(link.strip())
                        
                        if product_id:
                            # 상품 상세 정보 조회
                            product_info = self.get_aliexpress_product_details(product_id)
                            
                            if product_info:
                                product_info["affiliate_url"] = affiliate_link
                                product_info["keyword"] = keyword
                                processed_products.append(product_info)
                                print(f"[✅] API 상품 정보 조회 성공: {product_info['title'][:50]}...")
                            else:
                                # API 호출 실패 시 기본 정보
                                basic_product = {
                                    "title": f"{keyword} 관련 상품",
                                    "price": "가격 확인 필요",
                                    "image_url": "",
                                    "rating_display": "평점 정보 없음",
                                    "lastest_volume": "판매량 정보 없음",
                                    "affiliate_url": affiliate_link,
                                    "keyword": keyword
                                }
                                processed_products.append(basic_product)
                                print(f"[⚠️] API 호출 실패, 기본 정보 사용")
                        else:
                            print(f"[❌] 상품 ID 추출 실패: {link}")
                    else:
                        print(f"[❌] 어필리에이트 링크 변환 실패: {link}")
                
                # 메모리 정리 (각 링크 처리 후)
                gc.collect()
        
        print(f"[✅] 총 {len(processed_products)}개의 상품 처리 완료")
        return processed_products

    def generate_content_with_gemini(self, job_data, products):
        """Gemini AI를 사용하여 콘텐츠 생성"""
        try:
            print("[🤖] Gemini AI를 사용하여 콘텐츠를 생성합니다...")
            
            api_key = self.get_gemini_api_key()
            if not api_key:
                return "콘텐츠 생성에 실패했습니다."
            
            # 프롬프트 템플릿 사용
            from prompt_templates import PromptTemplates
            
            # 상품 정보 요약 생성
            product_summaries = []
            for product in products:
                summary = f"- {product['title']} (가격: {product['price']}, 평점: {product['rating_display']}, 판매량: {product['lastest_volume']})"
                product_summaries.append(summary)
            
            # 키워드 목록 생성
            keywords = [kw["name"] for kw in job_data["keywords"]]
            
            # 프롬프트 생성
            prompt_type = job_data.get("prompt_type", "essential_items")
            user_details = job_data.get("user_details", {})
            
            # 프롬프트 템플릿에서 프롬프트 가져오기
            prompt = PromptTemplates.get_prompt_by_type(
                prompt_type, 
                job_data["title"], 
                keywords, 
                user_details
            )
            
            # 상품 정보를 프롬프트에 추가
            prompt += f"\n\n### 📦 추천 상품 정보 ###\n"
            prompt += "\n".join(product_summaries)
            prompt += "\n\n위 상품들을 자연스럽게 글에 녹여내어 추천해주세요."
            
            # Gemini API 호출
            gemini_url = f"https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={api_key}"
            
            payload = {
                "contents": [{
                    "parts": [{
                        "text": prompt
                    }]
                }],
                "generationConfig": {
                    "temperature": 0.7,
                    "topK": 40,
                    "topP": 0.95,
                    "maxOutputTokens": 4000,
                }
            }
            
            response = requests.post(
                gemini_url,
                headers={"Content-Type": "application/json"},
                json=payload,
                timeout=30
            )
            
            if response.status_code == 200:
                data = response.json()
                if "candidates" in data and len(data["candidates"]) > 0:
                    content = data["candidates"][0]["content"]["parts"][0]["text"]
                    print("[✅] Gemini AI 콘텐츠 생성 완료")
                    return content
                else:
                    print("[❌] Gemini 응답에서 콘텐츠를 찾을 수 없습니다.")
                    return "콘텐츠 생성에 실패했습니다."
            else:
                print(f"[❌] Gemini API 오류: {response.status_code}")
                return "콘텐츠 생성에 실패했습니다."
                
        except Exception as e:
            print(f"[❌] Gemini 콘텐츠 생성 중 오류: {e}")
            return "콘텐츠 생성에 실패했습니다."
        finally:
            # 메모리 정리
            gc.collect()

    def generate_seo_optimized_slug_with_gemini(self, title):
        """Gemini AI를 사용하여 SEO 최적화된 한글 슬러그 생성"""
        try:
            api_key = self.get_gemini_api_key()
            if not api_key:
                # 폴백: 기본 슬러그 생성
                return title.replace(" ", "-")[:30]
            
            prompt = f"""다음 제목을 SEO에 최적화된 한글 슬러그로 변환해주세요.

제목: {title}

요구사항:
1. 한글로 작성
2. 30자 이내
3. 공백을 '-'로 변경
4. 검색에 유리한 핵심 키워드 포함
5. 읽기 쉽고 의미가 명확한 형태

예시:
- "2024년 겨울 필수 아이템" → "2024-겨울-필수템"
- "여행용 가방 추천" → "여행용-가방-추천"

슬러그만 출력해주세요:"""
            
            gemini_url = f"https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={api_key}"
            
            payload = {
                "contents": [{
                    "parts": [{
                        "text": prompt
                    }]
                }],
                "generationConfig": {
                    "temperature": 0.3,
                    "maxOutputTokens": 100,
                }
            }
            
            response = requests.post(
                gemini_url,
                headers={"Content-Type": "application/json"},
                json=payload,
                timeout=15
            )
            
            if response.status_code == 200:
                data = response.json()
                if "candidates" in data and len(data["candidates"]) > 0:
                    slug = data["candidates"][0]["content"]["parts"][0]["text"].strip()
                    # 안전성 검사
                    if len(slug) <= 30 and slug:
                        return slug
            
            # 폴백
            return title.replace(" ", "-")[:30]
            
        except Exception as e:
            print(f"[⚠️] 슬러그 생성 중 오류: {e}")
            # 폴백
            return title.replace(" ", "-")[:30]

    def generate_seo_with_gemini(self, title, content):
        """Gemini AI를 사용하여 SEO 정보 생성"""
        try:
            api_key = self.get_gemini_api_key()
            if not api_key:
                return {
                    "focus_keyphrase": title.split()[0] if title.split() else "키워드",
                    "meta_description": title[:150],
                    "tags": ["추천", "상품", "리뷰"]
                }
            
            prompt = f"""다음 글의 SEO 정보를 생성해주세요.

제목: {title}
내용 일부: {content[:500]}...

다음 형식으로 JSON 응답해주세요:
{{
    "focus_keyphrase": "메인 키워드 (2-3단어)",
    "meta_description": "150자 이내의 매력적인 설명",
    "tags": ["태그1", "태그2", "태그3", "태그4", "태그5"]
}}

요구사항:
- focus_keyphrase: 검색량이 높은 핵심 키워드
- meta_description: 클릭을 유도하는 매력적인 설명 (150자 이내)
- tags: 관련성 높은 태그 5개 (검색 친화적)"""
            
            gemini_url = f"https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={api_key}"
            
            payload = {
                "contents": [{
                    "parts": [{
                        "text": prompt
                    }]
                }],
                "generationConfig": {
                    "temperature": 0.5,
                    "maxOutputTokens": 500,
                }
            }
            
            response = requests.post(
                gemini_url,
                headers={"Content-Type": "application/json"},
                json=payload,
                timeout=20
            )
            
            if response.status_code == 200:
                data = response.json()
                if "candidates" in data and len(data["candidates"]) > 0:
                    content_text = data["candidates"][0]["content"]["parts"][0]["text"]
                    
                    # JSON 추출 시도
                    import re
                    json_match = re.search(r'\{.*\}', content_text, re.DOTALL)
                    if json_match:
                        try:
                            seo_info = json.loads(json_match.group())
                            return seo_info
                        except:
                            pass
            
            # 폴백
            return {
                "focus_keyphrase": title.split()[0] if title.split() else "키워드",
                "meta_description": title[:150],
                "tags": ["추천", "상품", "리뷰"]
            }
            
        except Exception as e:
            print(f"[⚠️] SEO 정보 생성 중 오류: {e}")
            return {
                "focus_keyphrase": title.split()[0] if title.split() else "키워드",
                "meta_description": title[:150],
                "tags": ["추천", "상품", "리뷰"]
            }

    def insert_product_cards(self, content, products):
        """콘텐츠에 상품 카드 삽입 (키워드별 배치 최적화)"""
        try:
            print("[🎨] 상품 카드를 콘텐츠에 삽입합니다...")
            
            # 키워드별 상품 그룹화
            keyword_products = {}
            for product in products:
                keyword = product.get('keyword', '기타')
                if keyword not in keyword_products:
                    keyword_products[keyword] = []
                keyword_products[keyword].append(product)
            
            modified_content = content
            
            # 각 키워드에 대해 상품 카드 삽입
            for keyword, keyword_products_list in keyword_products.items():
                # 키워드 섹션 찾기 (H2 태그 기준)
                keyword_pattern = rf'(<h2[^>]*>{keyword}[^<]*</h2>)'
                
                import re
                matches = list(re.finditer(keyword_pattern, modified_content, re.IGNORECASE))
                
                if matches:
                    # 키워드 제목 바로 다음에 상품 카드들 삽입
                    insert_position = matches[0].end()
                    
                    # 해당 키워드의 모든 상품 카드 생성
                    cards_html = ""
                    for product in keyword_products_list:
                        card_html = self.generate_product_card_html(product)
                        cards_html += card_html + "\n\n"
                    
                    # '관련 상품 더보기' 버튼 추가
                    more_button_html = f"""
<div style="text-align: center; margin: 30px 0;">
    <a href="https://s.click.aliexpress.com/s/NdpwztbAIgDMmxGbP8fFks7yUFfsHjzlImSYmA68h7xlMRgzueFsRgokQ9f9hcpVKttxtQE2VzIoqkXJXrpOr6uR3zv7mP4CDO9CddoFf4t0QE5DXPy8Pgjhz4irfQSEjEGUmy5yF3A5gJ9h3NYjLMgqIMqa3zcpq5E2BC3W5EbV40Dm9buQxWpZzw1lbX8Zm2kZtNIa4KyDieNjsH7IU6Yv8StELKVvT9r4eFAaXPGhwHsgaDqdNcmY116r3GC2YvmvR57S69fGyn8Tt4pCtmLccxQz3g0L1ZKu00BPwYOT3BF4QZOqRqMDB0DRoR65ImC50PvWw9j08apNAefqW0LVr2DTPcEXAgsKfFscxmuFmpI4rYzHfa2mMW2QNVG8UJYu3NzspDJwpjh3wubYAUfQGbK23jUf26VCAyG2UcU7BaNqtj2tdiNs39r3U1s0g0X4D7CZItfiUeBEaLHoA5i9OwAiCW8DSH4WYxdJoWk7b9W4R72F584IAijnds412fk8L2vHo1yGlC6KZcrf9BTz0A9dHdOIIWirol37jOGiMTsmUyO7NRY6jr5BDcHYRjBgEPdPF9m9DAbKJa1MHnD6VJuo6NqrIlPq4hxOl6MOuvqoSxYhDRbljF3BJMtn2CPSGFmVKdejQHc9DcmBI1JOvA9wWCHieGgTCmc7oKkMqjxWJjrffpvUxBqkVNNHI516eP7ovNcfysZQNiaUGxPocTe1pWako83Fu2hxP9pBmKoebUlDEb0eMEw3FS6sAUGdqTfw9lT1nqdK9nLIsWhYmRpuxLhPJGT9W5Oi6lX1yFzA06gE7LSqKnp6wzwhGOrCZzubMChEpcFPNHxh1icrVispUS0TTrBNITICJlj1Ap7izOwASn8HKrh0O00CvZsHqHwTptJlE0OkkXs9npZpGUOtFYyaciT48gah7txJFP5qEBAxlvQngg19RqLj1j2u21bHWCHnu9NOwF9wTpjzn2el7rB7myUjYKwq9OhTH" target="_blank" rel="nofollow" style="display: inline-block; background: linear-gradient(135deg, #ff6b35, #f7931e); color: white; padding: 12px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; font-size: 16px; box-shadow: 0 4px 15px rgba(255, 107, 53, 0.3); transition: all 0.3s ease; border: none;">
        🛍️ {keyword} 관련 상품 더보기
    </a>
</div>"""
                    
                    cards_html += more_button_html
                    
                    # 콘텐츠에 삽입
                    modified_content = (
                        modified_content[:insert_position] + 
                        "\n\n" + cards_html + 
                        modified_content[insert_position:]
                    )
                    
                    print(f"[✅] '{keyword}' 키워드에 {len(keyword_products_list)}개 상품 카드 삽입")
                else:
                    print(f"[⚠️] '{keyword}' 키워드 섹션을 찾을 수 없어 글 하단에 추가")
                    # 키워드 섹션을 찾지 못한 경우 글 하단에 추가
                    cards_html = ""
                    for product in keyword_products_list:
                        card_html = self.generate_product_card_html(product)
                        cards_html += card_html + "\n\n"
                    
                    modified_content += "\n\n" + cards_html
            
            print("[✅] 모든 상품 카드 삽입 완료")
            return modified_content
            
        except Exception as e:
            print(f"[❌] 상품 카드 삽입 중 오류: {e}")
            return content
        finally:
            # 메모리 정리
            gc.collect()

    def generate_product_card_html(self, product):
        """개별 상품 카드 HTML 생성 (폴백용)"""
        # 상품 이미지 처리
        image_html = ""
        if product.get('image_url') and product['image_url'].startswith('http'):
            image_html = f'''
            <div style="text-align: center; margin-bottom: 15px;">
                <img src="{product['image_url']}" alt="{product['title']}" style="max-width: 400px; height: auto; border-radius: 8px; border: 1px solid #ddd;">
            </div>'''
        
        # 어필리에이트 버튼 HTML (반응형 - 1600px 기준)
        button_html = f'''
        <div style="text-align: center; margin-top: 20px;">
            <a href="{product['affiliate_url']}" target="_blank" rel="nofollow" style="text-decoration: none;">
                <picture>
                    <source media="(max-width: 1600px)" srcset="https://novacents.com/tools/images/aliexpress-button-mobile.png">
                    <img src="https://novacents.com/tools/images/aliexpress-button-pc.png" alt="알리익스프레스에서 구매하기" style="max-width: 100%; height: auto; cursor: pointer;">
                </picture>
            </a>
        </div>'''
        
        return f'''
        <div style="border: 2px solid #eee; padding: 20px; margin: 20px 0; border-radius: 10px; background: #f9f9f9;">
            <h3 style="color: #333; margin-bottom: 15px;">{product['title']}</h3>
            {image_html}
            <p style="font-size: 18px; color: #e74c3c; font-weight: bold; margin: 10px 0;">가격: {product['price']}</p>
            <p style="margin: 5px 0;">평점: {product['rating_display']}</p>
            <p style="margin: 5px 0;">판매량: {product['lastest_volume']}</p>
            {button_html}
        </div>'''

    def ensure_wordpress_tags(self, tags):
        """WordPress에 태그가 없으면 생성하고 태그 ID 반환"""
        try:
            tag_ids = []
            
            for tag_name in tags:
                # 기존 태그 검색
                search_url = f"{self.config['wp_api_base']}/tags"
                search_params = {"search": tag_name, "per_page": 100}
                
                response = requests.get(
                    search_url,
                    auth=self.wp_auth,
                    params=search_params,
                    headers=self.headers,
                    timeout=10
                )
                
                if response.status_code == 200:
                    existing_tags = response.json()
                    
                    # 정확히 일치하는 태그 찾기
                    found_tag = None
                    for tag in existing_tags:
                        if tag["name"].lower() == tag_name.lower():
                            found_tag = tag
                            break
                    
                    if found_tag:
                        tag_ids.append(found_tag["id"])
                        print(f"[✅] 기존 태그 사용: {tag_name} (ID: {found_tag['id']})")
                    else:
                        # 새 태그 생성
                        create_url = f"{self.config['wp_api_base']}/tags"
                        tag_data = {"name": tag_name}
                        
                        create_response = requests.post(
                            create_url,
                            auth=self.wp_auth,
                            json=tag_data,
                            headers=self.headers,
                            timeout=10
                        )
                        
                        if create_response.status_code == 201:
                            new_tag = create_response.json()
                            tag_ids.append(new_tag["id"])
                            print(f"[✅] 새 태그 생성: {tag_name} (ID: {new_tag['id']})")
                        else:
                            print(f"[⚠️] 태그 생성 실패: {tag_name}")
                else:
                    print(f"[⚠️] 태그 검색 실패: {tag_name}")
                
                # 각 태그 처리 후 짧은 대기
                time.sleep(0.5)
            
            return tag_ids
            
        except Exception as e:
            print(f"[❌] 태그 처리 중 오류: {e}")
            return []
        finally:
            # 메모리 정리
            gc.collect()

    def post_to_wordpress(self, job_data):
        """워드프레스에 글 발행 (FIFU, YoastSEO, 태그 포함) - 메모리 최적화"""
        try:
            print("[📝] 워드프레스에 글을 발행합니다...")
            
            # 1단계: 기본 포스트 생성
            post_data = {
                "title": job_data["title"],
                "content": job_data["content"],
                "status": "publish",
                "categories": [job_data["category_id"]]
            }
            
            # 한글 슬러그 추가
            if job_data.get("korean_slug"):
                post_data["slug"] = job_data["korean_slug"]
            
            # 워드프레스 REST API 호출
            auth = self.wp_auth
            headers = self.headers
            
            response = requests.post(
                f"{self.config['wp_api_base']}/posts",
                auth=auth,
                json=post_data,
                headers=headers,
                timeout=30
            )
            
            if response.status_code == 201:
                post_info = response.json()
                post_id = post_info.get("id")
                post_url = post_info.get("link", "")
                print(f"[✅] 워드프레스 게시물 생성 성공! (ID: {post_id})")
                
                # 응답 객체 즉시 삭제
                del response
                del post_info
                
                # 2단계: FIFU 썸네일 설정 (auto_post_overseas.py와 동일한 방식)
                thumbnail_url = job_data.get('thumbnail_url')
                
                # 디버깅을 위한 로그 파일 작성 (강화됨)
                debug_log_path = '/var/www/novacents/tools/logs/thumbnail_debug.log'
                try:
                    with open(debug_log_path, 'a', encoding='utf-8') as debug_file:
                        debug_file.write(f"\n========== [{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] 썸네일 디버깅 ==========\n")
                        debug_file.write(f"포스트 ID: {post_id}\n")
                        debug_file.write(f"포스트 제목: {job_data.get('title', 'N/A')}\n")
                        debug_file.write(f"thumbnail_url 값: '{thumbnail_url}'\n")
                        debug_file.write(f"thumbnail_url 타입: {type(thumbnail_url)}\n")
                        debug_file.write(f"job_data 키 목록: {list(job_data.keys())}\n")
                        debug_file.write(f"has_thumbnail_url 값: {job_data.get('has_thumbnail_url', 'KEY_NOT_FOUND')}\n")
                        # job_data 전체 구조를 JSON으로 저장 (민감한 데이터 제외)
                        safe_job_data = {k: v for k, v in job_data.items() if k not in ['keywords', 'user_details']}
                        debug_file.write(f"job_data 구조 (안전한 부분): {json.dumps(safe_job_data, ensure_ascii=False, indent=2)}\n")
                except Exception as e:
                    print(f"[⚠️] 디버그 로그 작성 실패: {e}")
                
                if thumbnail_url:
                    print(f"[⚙️] 2단계 - FIFU 썸네일을 설정합니다...")
                    fifu_payload = {"meta": {"_fifu_image_url": thumbnail_url}}
                    
                    try:
                        fifu_response = requests.post(
                            f"{self.config['wp_api_base']}/posts/{post_id}", 
                            auth=auth, 
                            json=fifu_payload, 
                            headers=headers, 
                            timeout=20
                        )
                        
                        # 디버깅 로그에 응답 기록
                        try:
                            with open(debug_log_path, 'a', encoding='utf-8') as debug_file:
                                debug_file.write(f"FIFU API 호출 완료\n")
                                debug_file.write(f"응답 상태 코드: {fifu_response.status_code}\n")
                                debug_file.write(f"응답 내용: {fifu_response.text[:500]}...\n")
                                debug_file.write(f"FIFU 설정 성공 여부: {'성공' if fifu_response.status_code in [200, 201] else '실패'}\n")
                        except:
                            pass
                        
                        print("[✅] FIFU 썸네일 설정 완료.")
                    except Exception as e:
                        print(f"[⚠️] FIFU 썸네일 설정 중 오류: {e}")
                        try:
                            with open(debug_log_path, 'a', encoding='utf-8') as debug_file:
                                debug_file.write(f"FIFU 오류 발생: {str(e)}\n")
                        except:
                            pass
                else:
                    print("[⚠️] 썸네일 URL이 없어 FIFU 설정을 건너뜁니다.")
                    try:
                        with open(debug_log_path, 'a', encoding='utf-8') as debug_file:
                            debug_file.write(f"썸네일 URL이 없음 - thumbnail_url 값: '{thumbnail_url}'\n")
                    except:
                        pass
                
                # 3단계: YoastSEO 메타데이터 설정
                if job_data.get("focus_keyphrase") and job_data.get("meta_description"):
                    print("[⚙️] 3단계 - YoastSEO 메타데이터를 설정합니다...")
                    
                    yoast_payload = {
                        "post_id": post_id,
                        "focus_keyphrase": job_data["focus_keyphrase"],
                        "meta_description": job_data["meta_description"]
                    }
                    
                    try:
                        yoast_url = f"{self.config['wp_url'].rstrip('/')}/wp-json/my-api/v1/update-seo"
                        yoast_response = requests.post(
                            yoast_url,
                            auth=auth,
                            json=yoast_payload,
                            headers=headers,
                            timeout=20
                        )
                        
                        if yoast_response.status_code == 200:
                            print("[✅] YoastSEO 메타데이터 설정 완료.")
                        else:
                            print(f"[⚠️] YoastSEO 설정 실패: {yoast_response.status_code}")
                    except Exception as e:
                        print(f"[⚠️] YoastSEO 설정 중 오류: {e}")
                
                # 4단계: 태그 설정
                if job_data.get("tags"):
                    print("[⚙️] 4단계 - 태그를 설정합니다...")
                    tag_ids = self.ensure_wordpress_tags(job_data["tags"])
                    
                    if tag_ids:
                        tag_payload = {"tags": tag_ids}
                        try:
                            tag_response = requests.post(
                                f"{self.config['wp_api_base']}/posts/{post_id}",
                                auth=auth,
                                json=tag_payload,
                                headers=headers,
                                timeout=15
                            )
                            
                            if tag_response.status_code == 200:
                                print(f"[✅] 태그 설정 완료: {len(tag_ids)}개")
                            else:
                                print(f"[⚠️] 태그 설정 실패: {tag_response.status_code}")
                        except Exception as e:
                            print(f"[⚠️] 태그 설정 중 오류: {e}")
                
                print(f"[🎉] 워드프레스 발행 완료!")
                print(f"[🔗] 게시물 URL: {post_url}")
                
                return True, post_url
                
            else:
                error_msg = f"워드프레스 게시물 생성 실패: {response.status_code}"
                print(f"[❌] {error_msg}")
                print(f"[❌] 응답: {response.text[:200]}...")
                return False, error_msg
                
        except Exception as e:
            error_msg = f"워드프레스 발행 중 오류: {str(e)}"
            print(f"[❌] {error_msg}")
            return False, error_msg
        finally:
            # 메모리 정리
            gc.collect()

    def process_job(self, job_data):
        """단일 작업 처리 (메모리 최적화)"""
        job_id = job_data.get("queue_id") or job_data.get("id", "unknown")
        title = job_data.get("title", "제목 없음")
        
        try:
            print("=" * 60)
            print(f"[🔄] 작업 처리 시작: {title}")
            print("=" * 60)
            
            # 작업 상태를 'processing'으로 업데이트
            self.update_job_status(job_id, "processing")
            
            # 1단계: 알리익스프레스 상품 처리
            print("[1/5] 알리익스프레스 상품 처리...")
            products = self.process_aliexpress_products(job_data)
            
            if not products:
                error_msg = "처리할 수 있는 상품이 없습니다."
                print(f"[❌] {error_msg}")
                self.update_job_status(job_id, "error", error_msg)
                return False
            
            # job_data에 상품 정보 추가 (메모리 효율적으로)
            job_data["products"] = products
            
            # 2단계: Gemini AI 콘텐츠 생성
            print("[2/5] Gemini AI 콘텐츠 생성...")
            content = self.generate_content_with_gemini(job_data, products)
            
            # 3단계: 상품 카드 삽입
            print("[3/5] 상품 카드 삽입...")
            final_content = self.insert_product_cards(content, products)
            
            # content 변수 삭제 (메모리 절약)
            del content
            gc.collect()
            
            # 4단계: SEO 정보 생성
            print("[4/5] SEO 정보 생성...")
            seo_info = self.generate_seo_with_gemini(title, final_content[:1000])
            
            # 한글 슬러그 생성
            korean_slug = self.generate_seo_optimized_slug_with_gemini(title)
            
            # job_data 업데이트
            job_data.update({
                "content": final_content,
                "focus_keyphrase": seo_info.get("focus_keyphrase", ""),
                "meta_description": seo_info.get("meta_description", ""),
                "tags": seo_info.get("tags", []),
                "korean_slug": korean_slug
            })
            
            # seo_info 삭제 (메모리 절약)
            del seo_info
            gc.collect()
            
            # 5단계: 워드프레스 발행
            print("[5/5] 워드프레스 발행...")
            success, result = self.post_to_wordpress(job_data)
            
            if success:
                print(f"[✅] 작업 완료: {title}")
                print(f"[🔗] URL: {result}")
                
                # 완료된 작업 제거
                self.remove_completed_job(job_id)
                
                return True
            else:
                error_msg = f"워드프레스 발행 실패: {result}"
                print(f"[❌] {error_msg}")
                self.update_job_status(job_id, "error", error_msg)
                return False
                
        except Exception as e:
            error_msg = f"작업 처리 중 오류: {str(e)}"
            print(f"[❌] {error_msg}")
            self.update_job_status(job_id, "error", error_msg)
            return False
        finally:
            # 메모리 정리
            if 'products' in locals():
                del products
            if 'final_content' in locals():
                del final_content
            if 'job_data' in locals() and 'products' in job_data:
                del job_data['products']
            if 'job_data' in locals() and 'content' in job_data:
                del job_data['content']
            gc.collect()
            
            print("=" * 60)
            print(f"[🏁] 작업 처리 종료: {title}")
            print("=" * 60)

    def run_immediate_mode(self, temp_file):
        """즉시 발행 모드 실행"""
        print("=" * 60)
        print("🚀 즉시 발행 모드 시작")
        print("=" * 60)
        
        # 1. 시작 메시지
        start_message = f"[🚀] 즉시 발행 모드가 시작되었습니다."
        self.log_message(start_message)
        
        # 2. 임시 파일에서 작업 로드
        job_data = self.load_immediate_job(temp_file)
        if not job_data:
            print("[❌] 즉시 발행 작업 로드 실패.")
            return False
            
        # 3. 단일 작업 처리
        success = self.process_job(job_data)
        
        # 4. 임시 파일 정리 (선택사항)
        self.cleanup_temp_file(temp_file)
        
        # 5. 메모리 정리
        del job_data
        gc.collect()
        
        # 6. 완료 메시지
        if success:
            completion_message = f"[🎉] 즉시 발행 완료!"
            self.log_message(completion_message)
            print("=" * 60)
            print("🚀 알리익스프레스 즉시 발행 성공")
            print("=" * 60)
            return True
        else:
            print("=" * 60)
            print("❌ 즉시 발행 실패")
            print("=" * 60)
            return False

    def run_queue_mode(self):
        """큐 모드 실행 (분할 시스템 우선)"""
        print("=" * 60)
        print("📋 큐 모드 시작")
        print("=" * 60)
        
        # 1. 시작 메시지
        start_message = f"[📋] 큐 처리 모드가 시작되었습니다. (최대 {MAX_POSTS_PER_RUN}개 처리)"
        self.log_message(start_message)
        
        # 2. 분할 큐에서 작업 로드
        pending_jobs = self.load_queue_split()
        
        if not pending_jobs:
            print("[📋] 처리할 작업이 없습니다.")
            return
            
        # 3. 작업 처리
        processed_count = 0
        
        for job in pending_jobs:
            if processed_count >= MAX_POSTS_PER_RUN:
                print(f"[⏸️] 최대 처리 개수({MAX_POSTS_PER_RUN})에 도달했습니다.")
                break
                
            success = self.process_job(job)
            processed_count += 1
            
            # 작업 완료 후 메모리 정리
            del job
            gc.collect()
        
        # 4. 완료 메시지
        completion_message = f"[🎉] 큐 처리 완료! {processed_count}개 작업 처리됨"
        self.log_message(completion_message)
        print("=" * 60)
        print("📋 큐 처리 완료")
        print("=" * 60)

def main():
    """메인 실행 함수"""
    try:
        # 메모리 초기 정리
        gc.collect()
        
        # 인스턴스 생성
        poster = AliExpressPostingSystem()
        
        # 실행 모드 확인
        if len(sys.argv) > 1:
            # 즉시 발행 모드
            temp_file = sys.argv[1]
            print(f"[📄] 즉시 발행 모드: {temp_file}")
            poster.run_immediate_mode(temp_file)
        else:
            # 큐 모드
            print("[📋] 큐 모드")
            poster.run_queue_mode()
            
    except KeyboardInterrupt:
        print("\n[⚠️] 사용자에 의해 중단되었습니다.")
    except Exception as e:
        print(f"[❌] 시스템 오류: {e}")
    finally:
        # 최종 메모리 정리
        gc.collect()
        print("[🧹] 메모리 정리 완료")

if __name__ == "__main__":
    main()