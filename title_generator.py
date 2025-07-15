#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
노바센트 어필리에이트 상품 글 제목 생성 스크립트
auto_post_overseas.py와 동일한 방식으로 구현
메모리 최적화 버전
"""

import os
import sys
import json
import gc
import google.generativeai as genai
from dotenv import load_dotenv
from datetime import datetime

def load_configuration():
    """환경변수 로드 (웹 환경에서는 로그 출력 제거)"""
    
    # .env 파일 경로를 명시적으로 지정
    env_path = '/home/novacents/.env'
    load_dotenv(env_path)
    
    config = {
        "gemini_api_key": os.getenv("GEMINI_API_KEY"),
    }
    if not all(config.values()):
         return None
    try:
        genai.configure(api_key=config["gemini_api_key"])
    except Exception as e:
        return None
    return config

def generate_titles_with_gemini(keywords):
    """
    Gemini AI로 제목 생성 (여러 키워드 지원)
    """
    try:
        model = genai.GenerativeModel('gemini-2.5-pro')
        
        # 현재 년도 정보 포함
        from datetime import datetime
        current_year = datetime.now().year
        
        # 키워드가 리스트인 경우 문자열로 변환
        if isinstance(keywords, list):
            keywords_str = ', '.join(keywords)
        else:
            keywords_str = keywords
        
        prompt = f"""당신은 SEO 전문가이자 노바센트 '스마트 리빙' 카테고리 전문 카피라이터입니다. 다음 키워드들을 모두 포함하여 한국 사용자들의 클릭을 유도하는 매력적인 블로그 제목 5개를 생성해주세요.

**키워드: {keywords_str}**
**중요: 현재는 {current_year}년입니다. 반드시 {current_year}년 기준으로 제목을 생성해주세요.**
**필수: 제공된 모든 키워드를 제목에 자연스럽게 포함해야 합니다.**

제목 유형을 다양하게 조합해서 생성해주세요:
1. 클릭 유도형: '{current_year}년 꼭 써봐야 할', '지금 당장', '놓치면 후회하는' 등
2. 문제 해결형: '○○ 고민 해결', '이런 불편함 없애는', '효과적인 해결책' 등  
3. 가이드형: '완벽 가이드', '총정리', '추천 BEST' 등

요구사항:
- 각 제목은 15-50자 사이
- 제공된 모든 키워드를 자연스럽게 포함
- 스마트 리빙 카테고리에 적합한 실용적 톤
- 구매 욕구를 자극하는 표현 사용
- 년도는 반드시 {current_year}년으로 표시

결과는 제목만 5개를 번호 없이 줄바꿈으로 구분해서 출력해주세요."""

        response = model.generate_content(prompt)
        
        if response and response.text:
            # 생성된 텍스트를 줄바꿈으로 분리하여 제목 목록 생성
            titles = [title.strip() for title in response.text.strip().split('\n') if title.strip()]
            
            # 빈 제목이나 너무 짧은 제목 필터링
            filtered_titles = [title for title in titles if len(title) >= 10]
            
            return {
                'success': True,
                'titles': filtered_titles[:5]  # 최대 5개만 반환
            }
        else:
            return {
                'success': False,
                'message': 'Gemini API 응답이 비어있습니다.'
            }
            
    except Exception as e:
        return {
            'success': False,
            'message': f'Gemini 제목 생성 중 오류: {str(e)}'
        }

def main():
    """메인 실행 함수 - 메모리 최적화"""
    
    try:
        # 명령행 인수 확인
        if len(sys.argv) != 2:
            result = {
                'success': False,
                'message': 'Usage: python title_generator.py <keyword>'
            }
            print(json.dumps(result, ensure_ascii=False))
            sys.exit(1)
        
        keywords_input = sys.argv[1]
        
        if not keywords_input or len(keywords_input.strip()) == 0:
            result = {
                'success': False,
                'message': '키워드가 비어있습니다.'
            }
            print(json.dumps(result, ensure_ascii=False))
            sys.exit(1)
        
        # 콤마로 구분된 키워드들을 배열로 변환
        keywords = [kw.strip() for kw in keywords_input.split(',') if kw.strip()]
        
        if not keywords:
            result = {
                'success': False,
                'message': '유효한 키워드가 없습니다.'
            }
            print(json.dumps(result, ensure_ascii=False))
            sys.exit(1)
        
        # 설정 로드
        config = load_configuration()
        if not config:
            result = {
                'success': False,
                'message': 'Gemini API 설정을 로드할 수 없습니다.'
            }
            print(json.dumps(result, ensure_ascii=False))
            sys.exit(1)
        
        # 제목 생성
        result = generate_titles_with_gemini(keywords)
        
        # JSON 형태로 결과 출력
        print(json.dumps(result, ensure_ascii=False))
        
    finally:
        # 메모리 정리
        try:
            # 로컬 변수 정리
            if 'keywords' in locals():
                del keywords
            if 'config' in locals():
                del config
            if 'result' in locals():
                del result
            if 'keywords_input' in locals():
                del keywords_input
            
            # 가비지 컬렉션 강제 실행
            gc.collect()
            
        except:
            pass  # 정리 과정에서 오류가 발생해도 무시

if __name__ == "__main__":
    main()
