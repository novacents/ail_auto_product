#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
알리익스프레스 전용 어필리에이트 상품 자동 등록 시스템 (4가지 프롬프트 템플릿 시스템 + 즉시 발행 지원)
키워드 입력 → 알리익스프레스 API → AI 콘텐츠 생성 → 워드프레스 자동 발행

작성자: Claude AI
날짜: 2025-07-30
버전: v5.8 (즉시 발행 출력 메시지 수정 - keyword_processor.php 패턴과 일치)
"""

import os
import sys
import json
import time
import requests
import traceback
import argparse
import re
import gc  # 가비지 컬렉션 추가
import subprocess
import glob
import google.generativeai as genai
from datetime import datetime
from dotenv import load_dotenv
from prompt_templates import PromptTemplates

# 🔧 AliExpress SDK 로그 경로 수정 (import 전에 환경변수 설정)
os.environ['IOP_LOG_PATH'] = '/var/www/logs'
os.makedirs('/var/www/logs', exist_ok=True)

# 알리익스프레스 SDK 경로 추가
sys.path.append('/home/novacents/aliexpress-sdk')
import iop

# ##############################################################################
# 사용자 설정
# ##############################################################################
MAX_POSTS_PER_RUN = 1
QUEUE_FILE = "/var/www/product_queue.json"  # 레거시 큐 파일 (백업용)
QUEUES_DIR = "/var/www/queues"  # 새로운 분할 큐 디렉토리
LOG_FILE = "/var/www/auto_post_products.log"
PUBLISHED_LOG_FILE = "/var/www/published_log.txt"
POST_DELAY_SECONDS = 30
# ##############################################################################

def load_aliexpress_keyword_links():
    """알리익스프레스 키워드 링크 매핑 파일 로드"""
    keyword_links_path = '/var/www/novacents/tools/aliexpress_keyword_links.json'
    try:
        with open(keyword_links_path, 'r', encoding='utf-8') as f:
            return json.load(f)
    except Exception as e:
        print(f"키워드 링크 파일 로드 실패: {e}")
        return {}

def log_message(message):
    """로그 메시지 출력 및 파일 저장"""
    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    log_entry = f"[{timestamp}] {message}"
    print(log_entry)
    
    try:
        with open(LOG_FILE, 'a', encoding='utf-8') as f:
            f.write(log_entry + '\n')
    except Exception as e:
        print(f"로그 파일 쓰기 실패: {e}")

def load_env():
    """환경 변수 로드"""
    load_dotenv('/home/novacents/.env')

def get_aliexpress_client():
    """알리익스프레스 API 클라이언트 생성"""
    client = iop.IopClient('https://api-sg.aliexpress.com/sync', 
                          os.getenv('ALIEXPRESS_API_KEY'), 
                          os.getenv('ALIEXPRESS_API_SECRET'))
    return client

def call_aliexpress_api(client, keyword, page_no=1, page_size=20):
    """알리익스프레스 API 호출"""
    try:
        log_message(f"🔍 알리익스프레스 API 호출: {keyword} (페이지: {page_no})")
        
        request = iop.IopRequest('/aliexpress/affiliate/product/query')
        request.add_api_param('app_signature', os.getenv('ALIEXPRESS_APP_SIGNATURE'))
        request.add_api_param('keywords', keyword)
        request.add_api_param('category_ids', '')
        request.add_api_param('page_no', str(page_no))
        request.add_api_param('page_size', str(page_size))
        request.add_api_param('platform_product_type', 'ALL')
        request.add_api_param('ship_to_country', 'KR')
        request.add_api_param('sort', 'SALE_PRICE_ASC') 
        request.add_api_param('target_currency', 'KRW')
        request.add_api_param('target_language', 'ko')
        request.add_api_param('tracking_id', os.getenv('ALIEXPRESS_TRACKING_ID'))
        
        response = client.execute(request, os.getenv('ALIEXPRESS_ACCESS_TOKEN'))
        return response
    except Exception as e:
        log_message(f"❌ 알리익스프레스 API 호출 실패: {str(e)}")
        return None

def generate_ai_content(product_data, keyword, template_type="standard"):
    """AI 콘텐츠 생성"""
    try:
        genai.configure(api_key=os.getenv('GEMINI_API_KEY'))
        
        # 프롬프트 템플릿 로드
        templates = PromptTemplates()
        
        # 평점 정보 추출 및 형식화
        rating_info = ""
        if product_data.get('evaluate_rate'):
            rating_percentage = float(product_data['evaluate_rate']) * 100
            if rating_percentage >= 90:
                rating_info = f"⭐⭐⭐⭐⭐ ({rating_percentage:.1f}%)"
            elif rating_percentage >= 70:
                rating_info = f"⭐⭐⭐⭐ ({rating_percentage:.1f}%)"
            elif rating_percentage >= 50:
                rating_info = f"⭐⭐⭐ ({rating_percentage:.1f}%)"
            else:
                rating_info = f"⭐⭐ ({rating_percentage:.1f}%)"
        else:
            rating_info = "평점 정보 없음"
        
        # 상품 정보 준비
        product_title = product_data.get('product_title', '제목 없음')
        original_price = product_data.get('original_price', '0')
        sale_price = product_data.get('sale_price', '0')
        lastest_volume = product_data.get('lastest_volume', '0')
        
        # 템플릿 타입에 따른 프롬프트 선택
        if template_type == "review":
            prompt_text = templates.get_review_template()
        elif template_type == "comparison":
            prompt_text = templates.get_comparison_template()
        elif template_type == "guide":
            prompt_text = templates.get_guide_template()
        else:  # standard
            prompt_text = templates.get_standard_template()
        
        # 프롬프트에 실제 데이터 삽입
        full_prompt = prompt_text.format(
            keyword=keyword,
            product_title=product_title,
            original_price=original_price,
            sale_price=sale_price,
            rating_info=rating_info,
            volume=lastest_volume
        )
        
        log_message(f"🤖 AI 콘텐츠 생성 시작 (템플릿: {template_type})")
        
        model = genai.GenerativeModel('gemini-1.5-flash')
        response = model.generate_content(full_prompt)
        
        content = response.text.strip()
        log_message(f"✅ AI 콘텐츠 생성 완료 ({len(content)}자)")
        
        return content
        
    except Exception as e:
        log_message(f"❌ AI 콘텐츠 생성 실패: {str(e)}")
        return f"# {keyword}\n\n{product_data.get('product_title', '제품명 없음')}에 대한 상세한 정보를 제공합니다."

def create_wordpress_post(title, content, category_id, tags, product_info):
    """워드프레스 포스트 생성"""
    try:
        # YoastSEO 메타 설정
        yoast_meta = {
            '_yoast_wpseo_focuskw': product_info.get('keyword', ''),
            '_yoast_wpseo_metadesc': f"{product_info.get('keyword', '')}에 대한 상세한 정보와 구매 가이드를 제공합니다. 최저가 상품을 찾아보세요.",
            '_yoast_wpseo_title': f"{title} - 노바센트",
            '_yoast_wpseo_canonical': '',
            '_yoast_wpseo_bctitle': '',
            '_yoast_wpseo_opengraph_description': f"{product_info.get('keyword', '')} 구매를 위한 완벽한 가이드",
            '_yoast_wpseo_twitter_description': f"{product_info.get('keyword', '')} 구매를 위한 완벽한 가이드"
        }
        
        # FIFU (Featured Image from URL) 설정
        fifu_meta = {
            'fifu_image_url': product_info.get('image_url', ''),
            'fifu_image_alt': title[:100]  # alt 텍스트는 100자 제한
        }
        
        # 모든 메타 데이터 통합
        all_meta = {**yoast_meta, **fifu_meta}
        
        post_data = {
            'title': title,
            'content': content,
            'status': 'publish',
            'categories': [category_id],
            'tags': [tag['id'] for tag in tags] if tags else [],
            'meta': all_meta,
            'slug': ''  # 빈 slug로 설정하면 WordPress가 자동 생성
        }
        
        # API 요청
        url = f"{os.getenv('NOVACENTS_WP_API_BASE')}/posts"
        headers = {
            'Authorization': f"Basic {os.getenv('NOVACENTS_WP_AUTH_HEADER')}",
            'Content-Type': 'application/json'
        }
        
        response = requests.post(url, json=post_data, headers=headers)
        
        if response.status_code == 201:
            post_response = response.json()
            log_message(f"✅ 워드프레스 포스트 발행 성공: {post_response['link']}")
            return post_response
        else:
            log_message(f"❌ 워드프레스 포스트 발행 실패: {response.status_code} - {response.text}")
            return None
            
    except Exception as e:
        log_message(f"❌ 워드프레스 포스트 생성 중 오류: {str(e)}")
        return None

def create_or_get_tag(tag_name):
    """태그 생성 또는 기존 태그 가져오기"""
    try:
        # 기존 태그 검색
        url = f"{os.getenv('NOVACENTS_WP_API_BASE')}/tags"
        headers = {
            'Authorization': f"Basic {os.getenv('NOVACENTS_WP_AUTH_HEADER')}",
            'Content-Type': 'application/json'
        }
        
        params = {'search': tag_name}
        response = requests.get(url, headers=headers, params=params)
        
        if response.status_code == 200:
            tags = response.json()
            if tags:
                return tags[0]  # 첫 번째 일치 태그 반환
        
        # 태그가 없으면 새로 생성
        tag_data = {'name': tag_name}
        response = requests.post(url, json=tag_data, headers=headers)
        
        if response.status_code == 201:
            return response.json()
        else:
            log_message(f"❌ 태그 생성 실패: {tag_name}")
            return None
            
    except Exception as e:
        log_message(f"❌ 태그 처리 중 오류: {str(e)}")
        return None

def get_or_create_category_by_name(category_name, parent_id=0):
    """카테고리 이름으로 ID 찾기 또는 생성"""
    try:
        # 기존 카테고리 검색
        url = f"{os.getenv('NOVACENTS_WP_API_BASE')}/categories"
        headers = {
            'Authorization': f"Basic {os.getenv('NOVACENTS_WP_AUTH_HEADER')}",
            'Content-Type': 'application/json'
        }
        
        params = {'search': category_name, 'per_page': 100}
        response = requests.get(url, headers=headers, params=params)
        
        if response.status_code == 200:
            categories = response.json()
            for cat in categories:
                if cat['name'] == category_name:
                    return cat['id']
        
        # 카테고리가 없으면 새로 생성
        category_data = {
            'name': category_name,
            'parent': parent_id
        }
        
        response = requests.post(url, json=category_data, headers=headers)
        
        if response.status_code == 201:
            new_category = response.json()
            log_message(f"✅ 새 카테고리 생성: {category_name} (ID: {new_category['id']})")
            return new_category['id']
        else:
            log_message(f"❌ 카테고리 생성 실패: {category_name}")
            return 12  # 기본 카테고리 ID
            
    except Exception as e:
        log_message(f"❌ 카테고리 처리 중 오류: {str(e)}")
        return 12  # 기본 카테고리 ID

def load_product_queue():
    """상품 큐 로드 (분할 큐 지원)"""
    try:
        # 새로운 분할 큐 시스템 확인
        if os.path.exists(QUEUES_DIR):
            # 가장 최근의 큐 파일 찾기
            queue_files = glob.glob(os.path.join(QUEUES_DIR, "queue_*.json"))
            if queue_files:
                # 파일명의 타임스탬프로 정렬
                queue_files.sort(reverse=True)
                latest_queue = queue_files[0]
                log_message(f"📁 분할 큐 파일 로드: {latest_queue}")
                
                with open(latest_queue, 'r', encoding='utf-8') as f:
                    return json.load(f)
        
        # 레거시 큐 파일 확인
        if os.path.exists(QUEUE_FILE):
            log_message(f"📁 레거시 큐 파일 로드: {QUEUE_FILE}")
            with open(QUEUE_FILE, 'r', encoding='utf-8') as f:
                return json.load(f)
        
        log_message("⚠️ 큐 파일을 찾을 수 없습니다.")
        return []
        
    except Exception as e:
        log_message(f"❌ 큐 파일 로드 실패: {str(e)}")
        return []

def save_product_queue(queue, queue_file_path=None):
    """상품 큐 저장"""
    try:
        # 큐 파일 경로 결정
        if queue_file_path:
            file_path = queue_file_path
        else:
            # 분할 큐 파일이 있으면 해당 파일 사용, 없으면 레거시 파일 사용
            if os.path.exists(QUEUES_DIR):
                queue_files = glob.glob(os.path.join(QUEUES_DIR, "queue_*.json"))
                if queue_files:
                    queue_files.sort(reverse=True)
                    file_path = queue_files[0]
                else:
                    file_path = QUEUE_FILE
            else:
                file_path = QUEUE_FILE
        
        with open(file_path, 'w', encoding='utf-8') as f:
            json.dump(queue, f, ensure_ascii=False, indent=2)
        
        log_message(f"💾 큐 파일 저장 완료: {file_path}")
        
    except Exception as e:
        log_message(f"❌ 큐 파일 저장 실패: {str(e)}")

def log_published_product(product_info, wordpress_url):
    """발행된 상품 로그 기록"""
    try:
        timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        log_entry = f"[{timestamp}] {product_info.get('keyword', 'Unknown')} - {wordpress_url}\n"
        
        with open(PUBLISHED_LOG_FILE, 'a', encoding='utf-8') as f:
            f.write(log_entry)
            
    except Exception as e:
        log_message(f"❌ 발행 로그 기록 실패: {str(e)}")

def extract_product_keywords(title):
    """상품 제목에서 키워드 추출"""
    # 한글, 영문, 숫자만 추출하고 특수문자 제거
    import re
    keywords = re.findall(r'[가-힣a-zA-Z0-9]+', title)
    # 2글자 이상인 키워드만 선택
    meaningful_keywords = [k for k in keywords if len(k) >= 2]
    return meaningful_keywords[:5]  # 최대 5개

def clean_title_for_wordpress(title):
    """워드프레스용 제목 정리"""
    # 특수문자 제거 및 길이 제한
    import re
    # 불필요한 특수문자 제거
    cleaned = re.sub(r'[^\w\s가-힣-]', '', title)
    # 연속 공백 제거
    cleaned = re.sub(r'\s+', ' ', cleaned).strip()
    # 길이 제한 (70자)
    if len(cleaned) > 70:
        cleaned = cleaned[:67] + "..."
    return cleaned

def format_rating_display(evaluate_rate):
    """평점 정보를 별점 형식으로 변환"""
    try:
        if not evaluate_rate or str(evaluate_rate) in ['0', '0.0', '']:
            return "평점 정보 없음"
        
        rating_float = float(evaluate_rate) * 100
        
        if rating_float >= 90:
            return f"⭐⭐⭐⭐⭐ ({rating_float:.1f}%)"
        elif rating_float >= 70:
            return f"⭐⭐⭐⭐ ({rating_float:.1f}%)"
        elif rating_float >= 50:
            return f"⭐⭐⭐ ({rating_float:.1f}%)"
        else:
            return f"⭐⭐ ({rating_float:.1f}%)"
    except:
        log_message("❌ 평점 변환 중 오류 발생")
        return "평점 정보 없음"

def enhance_product_data_with_rating(product):
    """상품 데이터에 평점 정보 강화"""
    try:
        # 원본 평점 데이터 보존
        rating_raw = product.get('evaluate_rate', 0)
        
        # 평점이 문자열로 된 경우 처리
        if isinstance(rating_raw, str):
            try:
                rating_raw = float(rating_raw)
            except:
                rating_raw = 0
                
        product['rating_raw'] = rating_raw
        
        # 평점 백분율 계산
        if rating_raw and rating_raw > 0:
            if rating_raw <= 1:  # 0~1 범위인 경우 (예: 0.75)
                rating_float = rating_raw * 100
            else:  # 이미 백분율인 경우 (예: 75)
                rating_float = rating_raw
        else:
            rating_float = 0
            
        product['rating_float'] = rating_float
        
        # 별점 표시 생성
        if rating_float > 0:
            if rating_float >= 90:
                rating_display = f"⭐⭐⭐⭐⭐ ({rating_float:.1f}%)"
            elif rating_float >= 70:
                rating_display = f"⭐⭐⭐⭐ ({rating_float:.1f}%)"
            elif rating_float >= 50:
                rating_display = f"⭐⭐⭐ ({rating_float:.1f}%)"
            else:
                rating_display = f"⭐⭐ ({rating_float:.1f}%)"
        else:
            rating_display = "평점 정보 없음"
            
        product['rating_display'] = rating_display
        
        # 디버그 로그
        log_message(f"📊 평점 정보 처리: {rating_raw} → {rating_float}% → {rating_display}")
        
        return product
        
    except Exception as e:
        log_message(f"❌ 평점 정보 처리 중 오류: {str(e)}")
        product['rating_display'] = "평점 정보 없음"
        return product

def process_queue():
    """큐 처리 메인 함수"""
    try:
        log_message("🚀 상품 큐 처리 시작")
        
        # 환경 변수 로드
        load_env()
        
        # 큐 로드
        queue = load_product_queue()
        if not queue:
            log_message("📭 처리할 상품이 없습니다.")
            return
        
        log_message(f"📋 큐에서 {len(queue)}개 상품 발견")
        
        # 발행할 상품 선택 (큐에서 첫 번째)
        products_to_publish = queue[:MAX_POSTS_PER_RUN]
        remaining_queue = queue[MAX_POSTS_PER_RUN:]
        
        # 키워드 링크 매핑 로드
        keyword_links = load_aliexpress_keyword_links()
        
        for product in products_to_publish:
            try:
                log_message(f"📦 상품 처리 시작: {product.get('keyword', 'Unknown')}")
                
                # 상품 데이터 평점 정보 강화
                product = enhance_product_data_with_rating(product)
                
                # AI 콘텐츠 생성
                template_type = product.get('template_type', 'standard')
                content = generate_ai_content(product, product['keyword'], template_type)
                
                # 제목 정리
                original_title = product.get('product_title', product['keyword'])
                clean_title = clean_title_for_wordpress(original_title)
                
                # 카테고리 처리
                category_name = product.get('category', '우리잇템')
                if category_name in ['Today\'s Pick', 'today\'s pick', 'Today Pick']:
                    category_id = 354
                elif category_name in ['기발한 잡화점', '기발한잡화점']:
                    category_id = 355
                elif category_name in ['스마트 리빙', '스마트리빙']:
                    category_id = 356
                else:
                    category_id = 12  # 기본 '우리잇템' 카테고리
                
                # 태그 생성
                tag_keywords = extract_product_keywords(original_title)
                tags = []
                for tag_name in tag_keywords:
                    tag = create_or_get_tag(tag_name)
                    if tag:
                        tags.append(tag)
                
                # 어필리에이트 링크 처리
                affiliate_url = product.get('affiliate_url', '')
                if not affiliate_url and product.get('keyword') in keyword_links:
                    affiliate_url = keyword_links[product['keyword']]
                    product['affiliate_url'] = affiliate_url
                    log_message(f"🔗 키워드 링크 매핑 적용: {product['keyword']}")
                
                # 워드프레스 포스트 생성
                post_result = create_wordpress_post(
                    title=clean_title,
                    content=content,
                    category_id=category_id,
                    tags=tags,
                    product_info=product
                )
                
                if post_result:
                    # 발행 성공 로그
                    log_published_product(product, post_result['link'])
                    log_message(f"✅ 상품 발행 완료: {post_result['link']}")
                    
                    # 메모리 정리
                    del content
                    gc.collect()
                    
                    # 발행 간격 대기
                    if len(products_to_publish) > 1:
                        log_message(f"⏳ {POST_DELAY_SECONDS}초 대기 중...")
                        time.sleep(POST_DELAY_SECONDS)
                else:
                    log_message(f"❌ 상품 발행 실패: {product.get('keyword', 'Unknown')}")
                    # 실패한 상품은 큐 뒤로 이동
                    remaining_queue.append(product)
                    
            except Exception as e:
                log_message(f"❌ 상품 처리 중 오류: {str(e)}")
                log_message(f"상품 정보: {product.get('keyword', 'Unknown')}")
                # 오류 발생한 상품도 큐 뒤로 이동
                remaining_queue.append(product)
        
        # 큐 업데이트
        save_product_queue(remaining_queue)
        log_message(f"📋 남은 큐 항목: {len(remaining_queue)}개")
        
        log_message("🎉 상품 큐 처리 완료")
        
    except Exception as e:
        log_message(f"❌ 큐 처리 중 치명적 오류: {str(e)}")
        log_message(f"오류 세부사항: {traceback.format_exc()}")

def load_immediate_job(temp_file):
    """즉시 발행용 임시 파일 로드"""
    try:
        with open(temp_file, 'r', encoding='utf-8') as f:
            data = json.load(f)
        
        # 임시 파일 삭제
        try:
            os.remove(temp_file)
            log_message(f"🗑️ 임시 파일 정리 완료: {temp_file}")
        except:
            pass
            
        return data.get('job_data', {})
    except Exception as e:
        log_message(f"❌ 임시 파일 로드 실패: {str(e)}")
        return None

def process_immediate_publish(queue_data):
    """즉시 발행 처리 함수"""
    try:
        log_message("🚀 즉시 발행 처리 시작")
        
        # 환경 변수 로드
        load_env()
        
        # 키워드 링크 매핑 로드
        keyword_links = load_aliexpress_keyword_links()
        
        published_results = []
        
        for product in queue_data:
            try:
                log_message(f"📦 즉시 발행 상품 처리: {product.get('keyword', 'Unknown')}")
                
                # 상품 데이터 평점 정보 강화
                product = enhance_product_data_with_rating(product)
                
                # AI 콘텐츠 생성
                template_type = product.get('template_type', 'standard')
                content = generate_ai_content(product, product['keyword'], template_type)
                
                # 제목 정리
                original_title = product.get('product_title', product['keyword'])
                clean_title = clean_title_for_wordpress(original_title)
                
                # 카테고리 처리
                category_name = product.get('category', '우리잇템')
                if category_name in ['Today\'s Pick', 'today\'s pick', 'Today Pick']:
                    category_id = 354
                elif category_name in ['기발한 잡화점', '기발한잡화점']:
                    category_id = 355
                elif category_name in ['스마트 리빙', '스마트리빙']:
                    category_id = 356
                else:
                    category_id = 12  # 기본 '우리잇템' 카테고리
                
                # 태그 생성
                tag_keywords = extract_product_keywords(original_title)
                tags = []
                for tag_name in tag_keywords:
                    tag = create_or_get_tag(tag_name)
                    if tag:
                        tags.append(tag)
                
                # 어필리에이트 링크 처리
                affiliate_url = product.get('affiliate_url', '')
                if not affiliate_url and product.get('keyword') in keyword_links:
                    affiliate_url = keyword_links[product['keyword']]
                    product['affiliate_url'] = affiliate_url
                    log_message(f"🔗 키워드 링크 매핑 적용: {product['keyword']}")
                
                # 워드프레스 포스트 생성
                post_result = create_wordpress_post(
                    title=clean_title,
                    content=content,
                    category_id=category_id,
                    tags=tags,
                    product_info=product
                )
                
                if post_result:
                    # 발행 성공
                    published_results.append({
                        'success': True,
                        'keyword': product.get('keyword', 'Unknown'),
                        'url': post_result['link'],
                        'title': clean_title
                    })
                    
                    # 발행 성공 로그
                    log_published_product(product, post_result['link'])
                    log_message(f"✅ 즉시 발행 완료: {post_result['link']}")
                    
                    # 🔧 keyword_processor.php가 인식할 수 있는 패턴으로 출력
                    print(f"워드프레스 발행 성공: {post_result['link']}")
                    
                else:
                    # 발행 실패
                    published_results.append({
                        'success': False,
                        'keyword': product.get('keyword', 'Unknown'),
                        'error': '워드프레스 포스트 생성 실패'
                    })
                    log_message(f"❌ 즉시 발행 실패: {product.get('keyword', 'Unknown')}")
                
                # 메모리 정리
                del content
                gc.collect()
                
            except Exception as e:
                # 개별 상품 처리 오류
                published_results.append({
                    'success': False,
                    'keyword': product.get('keyword', 'Unknown'),
                    'error': str(e)
                })
                log_message(f"❌ 즉시 발행 상품 처리 오류: {str(e)}")
        
        log_message("🎉 즉시 발행 처리 완료")
        return published_results
        
    except Exception as e:
        log_message(f"❌ 즉시 발행 처리 중 치명적 오류: {str(e)}")
        return [{'success': False, 'error': f'치명적 오류: {str(e)}'}]

def run_immediate_mode(temp_file):
    """즉시 발행 모드 실행"""
    try:
        log_message(f"🚀 즉시 발행 모드 시작: {temp_file}")
        
        # 임시 파일에서 작업 데이터 로드
        job_data = load_immediate_job(temp_file)
        if not job_data:
            log_message("❌ 작업 데이터 로드 실패")
            return False
        
        # 키워드 목록에서 상품 데이터 추출
        products_to_publish = []
        if job_data.get('keywords'):
            for keyword_data in job_data['keywords']:
                if keyword_data.get('products_data'):
                    for product_data in keyword_data['products_data']:
                        if product_data.get('analysis_data'):
                            # 상품 데이터를 발행 가능한 형태로 변환
                            product_for_publish = product_data['analysis_data'].copy()
                            product_for_publish['keyword'] = keyword_data['name']
                            product_for_publish['category'] = job_data.get('category_name', '우리잇템')
                            product_for_publish['template_type'] = job_data.get('prompt_type', 'standard')
                            products_to_publish.append(product_for_publish)
        
        if not products_to_publish:
            log_message("❌ 발행할 상품 데이터가 없습니다")
            return False
        
        log_message(f"📦 즉시 발행할 상품 수: {len(products_to_publish)}")
        
        # 즉시 발행 처리
        results = process_immediate_publish(products_to_publish)
        
        # 결과 출력 (JSON 형태)
        success_count = sum(1 for r in results if r.get('success'))
        
        if success_count > 0:
            return True
        else:
            print("❌ 모든 상품 발행 실패")
            for result in results:
                if not result.get('success'):
                    print(f"❌ 오류: {result.get('error', '알 수 없는 오류')}")
            return False
            
    except Exception as e:
        log_message(f"❌ 즉시 발행 모드 실행 오류: {str(e)}")
        print(f"❌ 즉시 발행 오류: {str(e)}")
        return False

def generate_product_html(product):
    """상품 정보를 HTML로 변환 (발행용)"""
    try:
        # 상품 기본 정보
        title = product.get('product_title', '제목 없음')
        image_url = product.get('product_main_image_url', product.get('image_url', ''))
        price = product.get('target_sale_price', product.get('sale_price', '0'))
        affiliate_url = product.get('affiliate_url', '#')
        
        # 가격 정보 처리
        try:
            if isinstance(price, str):
                price_display = f"₩ {int(float(price.replace(',', '').replace('₩', '').strip())):,}"
            else:
                price_display = f"₩ {int(price):,}"
        except:
            price_display = "가격 문의"
        
        # 썸네일 이미지 HTML
        thumbnail_html = ""
        if image_url:
            thumbnail_html = f'''
            <div style="text-align: center; margin-bottom: 15px;">
                <img src="{product['image_url']}" alt="{product['title']}" style="max-width: 400px; height: auto; border-radius: 8px; border: 1px solid #ddd;">
            </div>'''
        
        # 🔧 평점 표시 개선 - 큐 파일에 저장된 rating_display를 우선 사용
        rating_display = product.get('rating_display', '⭐⭐⭐⭐ (75%)')
        
        # rating_display가 이미 정확히 저장되어 있다면 그대로 사용
        # 만약 rating_display가 기본값이거나 없다면 계산 로직 사용
        if rating_display == '⭐⭐⭐⭐ (75%)' or not rating_display or rating_display == '⭐⭐⭐⭐ (75.0%)':
            if product.get('rating_raw') and str(product.get('rating_raw')) != '0':
                try:
                    rating_float = float(product.get('rating_float', 75.0))
                    if rating_float >= 90:
                        rating_display = f"⭐⭐⭐⭐⭐ ({rating_float}%)"
                    elif rating_float >= 70:
                        rating_display = f"⭐⭐⭐⭐ ({rating_float}%)"
                    elif rating_float >= 50:
                        rating_display = f"⭐⭐⭐ ({rating_float}%)"
                    else:
                        rating_display = f"⭐⭐⭐⭐ (75%)"
                except:
                    rating_display = "⭐⭐⭐⭐ (75%)"
        
        # 어필리에이트 버튼 HTML (반응형 - 1600px 기준)
        button_html = f'''
        <div class="affiliate-button-container" style="width: 100%; max-width: 800px; margin: 15px auto; text-align: center;">
            <a href="{product['affiliate_url']}" target="_blank" rel="noopener" style="display: inline-block; width: 100%;">
                <picture>
                    <source media="(min-width: 1600px)" srcset="https://novacents.com/tools/images/aliexpress-button-pc.png">
                    <img src="https://novacents.com/tools/images/aliexpress-button-mobile.png" alt="알리익스프레스에서 {product.get('keyword', '상품')} 구매하기" style="width: 100%; height: auto; max-width: 800px; border-radius: 8px;">
                </picture>
            </a>
        </div>'''
        
        # 상품 카드 HTML 생성 (새로운 디자인)
        product_html = f'''
<div style="display:flex;justify-content:center;margin:25px 0;">
<div style="border:2px solid #eee;padding:30px;border-radius:15px;background:#f9f9f9;box-shadow:0 4px 8px rgba(0,0,0,0.1);max-width:1000px;width:100%;">

<div style="display:grid;grid-template-columns:400px 1fr;gap:30px;align-items:start;margin-bottom:25px;">
<div style="text-align:center;">
<img src="{image_url}" alt="{title}" style="width:100%;max-width:400px;border-radius:12px;box-shadow:0 6px 20px rgba(0,0,0,0.15);">
</div>

<div style="display:flex;flex-direction:column;gap:20px;">
<div style="margin-bottom:15px;text-align:center;">
<img src="https://novacents.com/tools/images/Ali_black_logo.webp" alt="AliExpress" style="width:250px;height:60px;object-fit:contain;"/>
</div>

<h3 style="color:#1c1c1c;margin:0 0 20px 0;font-size:21px;font-weight:600;line-height:1.4;word-break:keep-all;overflow-wrap:break-word;text-align:center;">{title}</h3>

<div style="background:linear-gradient(135deg,#e62e04 0%,#ff9900 100%);color:white;padding:14px 30px;border-radius:10px;font-size:40px;font-weight:700;text-align:center;margin-bottom:20px;box-shadow:0 4px 15px rgba(230,46,4,0.3);">
<strong>{price_display}</strong>
</div>

<div style="color:#1c1c1c;font-size:20px;display:flex;align-items:center;gap:10px;margin-bottom:15px;justify-content:center;flex-wrap:nowrap;">
<span style="color:#ff9900;">{rating_display.split('(')[0].strip()}</span>
<span>({rating_display.split('(')[1] if '(' in rating_display else '만족도 정보 없음'}</span>
</div>

<p style="color:#1c1c1c;font-size:18px;margin:0 0 15px 0;text-align:center;">
<strong>📦 판매량:</strong> {product.get('lastest_volume', '0')}개 판매
</p>

</div>
</div>

<div style="text-align:center;margin-top:30px;width:100%;">
<a href="{affiliate_url}" target="_blank" rel="nofollow" style="text-decoration:none;">
<picture>
<source media="(max-width:1600px)" srcset="https://novacents.com/tools/images/aliexpress-button-mobile.png">
<img src="https://novacents.com/tools/images/aliexpress-button-pc.png" alt="알리익스프레스에서 구매하기" style="max-width:100%;height:auto;cursor:pointer;">
</picture>
</a>
</div>

</div>
</div>

<style>
@media(max-width:1600px){{
div[style*="grid-template-columns:400px 1fr"]{{display:block!important;grid-template-columns:none!important;gap:15px!important;}}
img[style*="max-width:400px"]{{width:95%!important;max-width:none!important;margin-bottom:30px!important;}}
div[style*="gap:20px"]{{gap:10px!important;}}
div[style*="text-align:center"] img[alt="AliExpress"]{{display:block;margin:0!important;}}
div[style*="text-align:center"]:has(img[alt="AliExpress"]){{text-align:left!important;margin-bottom:10px!important;}}
h3[style*="text-align:center"]{{text-align:left!important;font-size:18px!important;margin-bottom:10px!important;}}
div[style*="font-size:40px"]{{font-size:28px!important;padding:12px 20px!important;margin-bottom:10px!important;}}
div[style*="justify-content:center"][style*="flex-wrap:nowrap"]{{justify-content:flex-start!important;font-size:16px!important;margin-bottom:10px!important;gap:8px!important;}}
p[style*="text-align:center"]{{text-align:left!important;font-size:16px!important;margin-bottom:10px!important;}}
div[style*="margin-top:30px"]{{margin-top:15px!important;}}
}}

@media(max-width:480px){{
img[style*="width:95%"]{{width:100%!important;}}
h3[style*="font-size:18px"]{{font-size:16px!important;}}
div[style*="font-size:28px"]{{font-size:24px!important;}}
}}
</style>'''
        
        return product_html
        
    except Exception as e:
        log_message(f"❌ 상품 HTML 생성 실패: {str(e)}")
        return f"<p>상품 정보를 불러올 수 없습니다: {product.get('product_title', 'Unknown')}</p>"

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description='알리익스프레스 어필리에이트 상품 자동 발행')
    parser.add_argument('--immediate-file', help='즉시 발행용 임시 파일 경로')
    
    args = parser.parse_args()
    
    if args.immediate_file:
        # 즉시 발행 모드
        success = run_immediate_mode(args.immediate_file)
        sys.exit(0 if success else 1)
    else:
        # 일반 큐 처리 모드
        process_queue()