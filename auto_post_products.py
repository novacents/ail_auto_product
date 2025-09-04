#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
AliExpress 어필리에이트 상품 자동 등록 시스템 v3.0
- 분할 큐 시스템 적용
- 백업 파일 기반 완전 복원 버전
"""

import os
import sys
import json
import requests
import time
import random
import re
import gc
from datetime import datetime
import argparse
import subprocess
from urllib.parse import quote, unquote

def load_configuration():
    """환경 설정을 로드합니다 (.env 파일 우선)"""
    config = {}
    
    # .env 파일에서 설정 로드
    env_file_path = '/home/novacents/.env'
    if os.path.exists(env_file_path):
        print(f"✅ .env 파일에서 설정을 로드합니다: {env_file_path}")
        with open(env_file_path, 'r', encoding='utf-8') as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith('#') and '=' in line:
                    key, value = line.split('=', 1)
                    key = key.strip()
                    value = value.strip().strip('"').strip("'")
                    config[key] = value
    else:
        print(f"⚠️ .env 파일을 찾을 수 없습니다: {env_file_path}")
        return None
    
    # 필수 설정값 확인
    required_keys = [
        'NOVACENTS_WP_URL', 'NOVACENTS_WP_USER', 'NOVACENTS_WP_APP_PASS',
        'OPENAI_API_KEY', 'ALIEXPRESS_APP_KEY', 'ALIEXPRESS_APP_SECRET',
        'ALIEXPRESS_TRACKING_ID'
    ]
    
    missing_keys = [key for key in required_keys if not config.get(key)]
    if missing_keys:
        print(f"❌ 필수 설정값이 누락되었습니다: {missing_keys}")
        return None
    
    print("✅ 설정 로드 완료")
    return config

def debug_print(message):
    """디버그 메시지 출력"""
    print(f"[DEBUG] {datetime.now().strftime('%Y-%m-%d %H:%M:%S')} - {message}")

def generate_content_with_openai(prompt, product_info, config):
    """OpenAI API를 사용하여 콘텐츠를 생성합니다"""
    try:
        debug_print("OpenAI API 호출 시작")
        
        # 상품 정보에서 키워드 추출
        keywords = []
        if 'keywords' in product_info:
            keywords = product_info['keywords']
        elif 'keyword_data' in product_info and isinstance(product_info['keyword_data'], dict):
            keywords = list(product_info['keyword_data'].keys())
        
        # 프롬프트 구성
        system_prompt = """당신은 전문적인 블로그 포스트 작성자입니다. 
        사용자가 제공한 상품 정보와 키워드를 바탕으로 매력적이고 유익한 블로그 포스트를 작성해주세요.
        포스트는 다음 요소를 포함해야 합니다:
        1. 매력적인 제목
        2. 상품의 주요 특징과 장점
        3. 사용 시나리오나 활용법
        4. 구매를 고려할 만한 이유
        5. 자연스럽게 키워드를 포함
        
        HTML 태그를 사용하여 구조화하고, 읽기 쉽게 작성해주세요."""

        user_content = f"""
        키워드: {', '.join(keywords[:10])}
        상품 제목: {product_info.get('title', '제목 없음')}
        
        {prompt}
        
        위 정보를 바탕으로 매력적인 블로그 포스트를 작성해주세요.
        """

        # OpenAI API 요청
        headers = {
            'Authorization': f"Bearer {config['OPENAI_API_KEY']}",
            'Content-Type': 'application/json'
        }
        
        data = {
            'model': 'gpt-3.5-turbo',
            'messages': [
                {'role': 'system', 'content': system_prompt},
                {'role': 'user', 'content': user_content}
            ],
            'max_tokens': 2000,
            'temperature': 0.7
        }
        
        response = requests.post('https://api.openai.com/v1/chat/completions', 
                               headers=headers, json=data, timeout=30)
        
        if response.status_code == 200:
            result = response.json()
            content = result['choices'][0]['message']['content']
            debug_print("OpenAI API 응답 성공")
            return content.strip()
        else:
            debug_print(f"OpenAI API 오류: {response.status_code} - {response.text}")
            return None
            
    except Exception as e:
        debug_print(f"OpenAI API 호출 중 오류: {str(e)}")
        return None

def create_wordpress_post(title, content, config, categories=None, tags=None):
    """WordPress REST API를 사용하여 포스트를 생성합니다"""
    try:
        debug_print("WordPress 포스트 생성 시작")
        
        wp_url = config['NOVACENTS_WP_URL']
        wp_user = config['NOVACENTS_WP_USER']
        wp_pass = config['NOVACENTS_WP_APP_PASS']
        
        # API 엔드포인트
        api_url = f"{wp_url}/wp-json/wp/v2/posts"
        
        # 인증 헤더
        import base64
        credentials = base64.b64encode(f"{wp_user}:{wp_pass}".encode()).decode()
        headers = {
            'Authorization': f'Basic {credentials}',
            'Content-Type': 'application/json'
        }
        
        # 포스트 데이터
        post_data = {
            'title': title,
            'content': content,
            'status': 'publish',
            'categories': categories or [12],  # 기본 카테고리
            'tags': tags or []
        }
        
        # API 요청
        response = requests.post(api_url, headers=headers, json=post_data, timeout=30)
        
        if response.status_code == 201:
            result = response.json()
            post_id = result['id']
            post_url = result['link']
            debug_print(f"WordPress 포스트 생성 성공 - ID: {post_id}")
            return {
                'success': True,
                'post_id': post_id,
                'post_url': post_url
            }
        else:
            debug_print(f"WordPress API 오류: {response.status_code} - {response.text}")
            return {
                'success': False,
                'error': f"API Error: {response.status_code}"
            }
            
    except Exception as e:
        debug_print(f"WordPress 포스트 생성 중 오류: {str(e)}")
        return {
            'success': False,
            'error': str(e)
        }

def process_queue_file(queue_file_path, config):
    """큐 파일을 처리합니다"""
    try:
        debug_print(f"큐 파일 처리 시작: {queue_file_path}")
        
        # 큐 파일 읽기
        with open(queue_file_path, 'r', encoding='utf-8') as f:
            queue_data = json.load(f)
        
        # 필수 데이터 확인
        if 'title' not in queue_data or 'prompt_content' not in queue_data:
            debug_print("큐 파일에 필수 데이터가 없습니다")
            return False
        
        # OpenAI로 콘텐츠 생성
        content = generate_content_with_openai(
            queue_data['prompt_content'], 
            queue_data, 
            config
        )
        
        if not content:
            debug_print("콘텐츠 생성 실패")
            return False
        
        # WordPress 포스트 생성
        result = create_wordpress_post(
            queue_data['title'],
            content,
            config,
            categories=queue_data.get('categories', [12]),
            tags=queue_data.get('tags', [])
        )
        
        if result['success']:
            debug_print(f"포스트 발행 성공: {result['post_url']}")
            
            # 성공 로그 저장
            log_entry = {
                'timestamp': datetime.now().isoformat(),
                'queue_file': os.path.basename(queue_file_path),
                'post_id': result['post_id'],
                'post_url': result['post_url'],
                'title': queue_data['title']
            }
            
            log_file = '/var/www/published_log.txt'
            try:
                with open(log_file, 'a', encoding='utf-8') as f:
                    f.write(json.dumps(log_entry, ensure_ascii=False) + '\n')
            except Exception as log_error:
                debug_print(f"발행 로그 저장 중 오류: {log_error}")
            
            print(f"✅ 글이 성공적으로 발행되었습니다: {result['post_url']}")
            return True
        else:
            debug_print(f"포스트 발행 실패: {result['error']}")
            print(f"❌ 포스트 발행 실패: {result['error']}")
            return False
            
    except Exception as e:
        debug_print(f"큐 파일 처리 중 오류: {str(e)}")
        print(f"❌ 오류 발생: {str(e)}")
        return False

def main():
    """메인 함수"""
    parser = argparse.ArgumentParser(description='AliExpress 자동 포스팅 시스템')
    parser.add_argument('--mode', choices=['immediate', 'queue'], default='queue',
                      help='실행 모드: immediate(즉시 실행) 또는 queue(큐 처리)')
    parser.add_argument('--immediate-file', help='즉시 처리할 파일 경로')
    parser.add_argument('--queue-id', help='처리할 큐 ID')
    
    args = parser.parse_args()
    
    try:
        # 설정 로드
        config = load_configuration()
        if not config:
            print("❌ 시스템 오류: 설정 파일을 로드할 수 없습니다.")
            return False
        
        if args.mode == 'immediate' and args.immediate_file:
            # 즉시 처리 모드
            debug_print(f"즉시 처리 모드: {args.immediate_file}")
            success = process_queue_file(args.immediate_file, config)
            
            # 임시 파일 정리
            if os.path.exists(args.immediate_file):
                os.remove(args.immediate_file)
                debug_print("임시 파일 정리 완료")
            
            return success
            
        else:
            print("❌ 잘못된 실행 모드 또는 필수 파라미터가 누락되었습니다.")
            return False
            
    except Exception as e:
        debug_print(f"메인 함수 실행 중 오류: {str(e)}")
        print(f"❌ 시스템 오류: {str(e)}")
        return False
    finally:
        # 메모리 정리
        gc.collect()
        print("[🧹] 메모리 정리 완료")

if __name__ == "__main__":
    success = main()
    sys.exit(0 if success else 1)