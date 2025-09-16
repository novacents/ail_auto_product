#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
4가지 프롬프트 템플릿 시스템 (SEO 최적화 + 구매 전환율 최적화)
- 필수템형: 특정 상황의 필수 아이템들 (다중 상품)
- 친구 추천형: 개인 경험 기반 솔직한 후기 (소수 상품)
- 전문 분석형: 기술적 분석 + 객관적 평가 (고가 상품)
- 놀라움 발견형: 혁신적 상품의 호기심 자극 (독특한 상품)

작성자: Claude AI
날짜: 2025-07-17
버전: v1.0
"""

class PromptTemplates:
    def _load_aliexpress_links(self):
        """AliExpress 키워드 링크 JSON 파일을 로드합니다."""
        try:
            json_path = "/var/www/novacents/tools/aliexpress_keyword_links.json"
            with open(json_path, 'r', encoding='utf-8') as f:
                return json.load(f)
        except FileNotFoundError:
            logging.warning(f"AliExpress links file not found: {json_path}")
            return {}
        except json.JSONDecodeError:
            logging.error(f"Invalid JSON in AliExpress links file: {json_path}")
            return {}

    def _generate_keyword_sections(self, keywords, aliexpress_links):
        """키워드별 상세 섹션을 생성합니다."""
        sections = []

        # keywords 처리
        if isinstance(keywords, list):
            # 딕셔너리 배열인 경우 (실제 큐 데이터 형태)
            if keywords and isinstance(keywords[0], dict) and 'name' in keywords[0]:
                keyword_names = [kw.get('name', '') for kw in keywords if kw.get('name')]
            else:
                # 문자열 배열인 경우
                keyword_names = [kw for kw in keywords if kw]
        else:
            keyword_names = [str(keywords)] if keywords else []

        for keyword in keyword_names:
            # 해당 키워드의 AliExpress 링크 찾기
            keyword_link = aliexpress_links.get(keyword, '')

            section = f"""
<h2>{keyword} - 왜 필수일까요?</h2>
<p><strong>선택 이유:</strong> {keyword}는 이런 상황에서 꼭 필요한 아이템입니다.</p>
<p><strong>핵심 기능:</strong> 실제 사용해보니 이런 점들이 가장 만족스러웠습니다.</p>
<p><strong>구매 포인트:</strong> 선택할 때 이런 기준으로 고르시면 실패하지 않습니다.</p>
<p><strong>주의사항:</strong> 다만 이런 점들은 미리 확인하고 구매하시길 추천합니다.</p>"""

            if keyword_link:
                section += f"""
<p><strong>추천 상품:</strong> <a href="{keyword_link}" target="_blank" rel="noopener">{keyword} 바로 확인하기</a></p>"""

            sections.append(section)

        return '\n'.join(sections)

    def _create_more_products_button(self, keywords):
        """관련 상품 더보기 버튼을 생성합니다."""
        # keywords 처리
        if isinstance(keywords, list):
            # 딕셔너리 배열인 경우 (실제 큐 데이터 형태)
            if keywords and isinstance(keywords[0], dict) and 'name' in keywords[0]:
                first_keyword = keywords[0].get('name', '') if keywords else ''
            else:
                # 문자열 배열인 경우
                first_keyword = keywords[0] if keywords else ''
        else:
            first_keyword = str(keywords) if keywords else ''

        if first_keyword:
            return f"""
<div style="text-align: center; margin: 30px 0; padding: 20px; background: #f8f9fa; border-radius: 10px;">
    <h3>🛒 더 많은 {first_keyword} 상품이 궁금하다면?</h3>
    <p>품질 좋은 다양한 상품들을 더 확인해보세요!</p>
    <a href="https://s.click.aliexpress.com/e/_DlgOQmR" target="_blank" rel="noopener"
       style="display: inline-block; background: #ff6b35; color: white; padding: 12px 24px;
              text-decoration: none; border-radius: 5px; font-weight: bold;">
        👉 관련 상품 더 보기
    </a>
</div>"""
        else:
            return """
<div style="text-align: center; margin: 30px 0; padding: 20px; background: #f8f9fa; border-radius: 10px;">
    <h3>🛒 더 많은 상품이 궁금하다면?</h3>
    <p>품질 좋은 다양한 상품들을 더 확인해보세요!</p>
    <a href="https://s.click.aliexpress.com/e/_DlgOQmR" target="_blank" rel="noopener"
       style="display: inline-block; background: #ff6b35; color: white; padding: 12px 24px;
              text-decoration: none; border-radius: 5px; font-weight: bold;">
        👉 관련 상품 더 보기
    </a>
</div>"""

    def _create_structured_prompt(self, title, keywords, user_details, keyword_sections, more_products_button):
        """최종 구조화된 프롬프트를 생성합니다."""
        user_details_formatted = PromptTemplates._format_user_details_for_prompt(user_details)

        # keywords 리스트 문자열 생성
        if isinstance(keywords, list):
            if keywords and isinstance(keywords[0], dict) and 'name' in keywords[0]:
                keywords_list = ', '.join([kw.get('name', '') for kw in keywords if kw.get('name')])
            else:
                keywords_list = ', '.join([kw for kw in keywords if kw])
        else:
            keywords_list = str(keywords) if keywords else ''

        return f"""당신은 특정 상황/활동의 필수 아이템을 추천하는 전문 가이드입니다.
15년 이상의 온라인 쇼핑 경험과 5,000건 이상의 상품 리뷰 경험을 보유하고 있습니다.

아래 제공되는 정보를 바탕으로, https://novacents.com/전기자전거-추천아이템정리/ 와 유사한 구조화된 상품 가이드를 작성해주세요.

### 📋 제공된 정보 ###
**글 제목:** {title}
**핵심 키워드:** {keywords_list}

**사용자 상세 정보:**
{user_details_formatted}

### ✅ 구조화된 가이드 작성 요구사항 ###

**1. 🎯 도입부 (200-250자):**
   - 상황 공감: "{keywords_list.split(', ')[0] if keywords_list else '상품'} 선택할 때 이런 고민 있으시죠?"
   - 문제 해결 약속: "이 가이드로 완벽하게 해결해드리겠습니다"
   - 키워드 자연스럽게 2회 언급

**2. 📋 키워드별 필수 아이템 분석:**
{keyword_sections}

**3. 💡 구매 가이드 (300-400자):**
   <h2>💡 스마트한 구매 방법</h2>
   <p><strong>브랜드 선택 기준:</strong> 이런 브랜드들이 품질과 A/S에서 좋은 평가를 받고 있습니다.</p>
   <p><strong>가격대별 추천:</strong></p>
   <ul>
   <li>입문용 (5-10만원): 기본 기능 충실한 제품</li>
   <li>실속형 (10-20만원): 가성비 최고의 선택</li>
   <li>프리미엄 (20만원 이상): 모든 기능이 완벽한 제품</li>
   </ul>
   <p><strong>구매 시 주의사항:</strong> 이런 점들을 반드시 확인하고 구매하세요.</p>

**4. 🛒 관련 상품 더보기:**
{more_products_button}

**5. ✅ 최종 정리 (200-250자):**
   <h2>✅ 마무리하며</h2>
   <p>이 가이드로 {keywords_list.split(', ')[0] if keywords_list else '상품'} 선택 고민이 해결되셨길 바랍니다.</p>
   <p><strong>핵심 포인트 다시 한 번:</strong></p>
   <ul>
   <li>용도에 맞는 제품 선택이 가장 중요</li>
   <li>브랜드보다는 실제 기능과 품질 우선</li>
   <li>구매 후기와 평점을 꼼꼼히 확인</li>
   </ul>
   <p>좋은 제품으로 만족스러운 경험 하시길 바랍니다! 🎉</p>

### ⚠️ 작성 원칙 ###
- **톤앤매너**: 친근하면서도 전문적, "완벽한 선택", "추천드려요"
- **HTML 구조**: H2, H3, p, ul, li 태그 적극 활용
- **키워드 최적화**: 자연스럽게 5-7회 배치
- **E-E-A-T 강화**: 개인 경험담 + 객관적 근거 제시
- **구매 전환율 최적화**: 구체적 행동 유도 문구 포함

참고 사이트(https://novacents.com/전기자전거-추천아이템정리/)와 같은 체계적이고 실용적인 가이드를 작성해주세요."""

    @staticmethod
    def get_prompt_by_type(prompt_type, title, keywords, user_details):
        """프롬프트 타입에 따라 적절한 프롬프트 반환"""

        # PromptTemplates 인스턴스 생성 (essential_items_prompt에서 필요)
        instance = PromptTemplates()

        if prompt_type == "essential_items":
            return instance._essential_items_prompt(title, keywords, user_details)
        elif prompt_type == "friend_review":
            return PromptTemplates._friend_review_prompt(title, keywords, user_details)
        elif prompt_type == "professional_analysis":
            return PromptTemplates._professional_analysis_prompt(title, keywords, user_details)
        elif prompt_type == "amazing_discovery":
            return PromptTemplates._amazing_discovery_prompt(title, keywords, user_details)
        else:
            # 기본값: 필수템형
            return instance._essential_items_prompt(title, keywords, user_details)
    
    @staticmethod
    def _format_user_details_for_prompt(user_details):
        """사용자 상세 정보를 프롬프트용으로 포맷팅"""
        if not user_details:
            return "사용자 상세 정보: 제공되지 않음"
        
        formatted_sections = []
        
        # 기능 및 스펙
        if 'specs' in user_details and user_details['specs']:
            specs_text = "**기능 및 스펙:**\n"
            for key, value in user_details['specs'].items():
                if key == 'main_function':
                    specs_text += f"- 주요 기능: {value}\n"
                elif key == 'size_capacity':
                    specs_text += f"- 크기/용량: {value}\n"
                elif key == 'color':
                    specs_text += f"- 색상: {value}\n"
                elif key == 'material':
                    specs_text += f"- 재질/소재: {value}\n"
                elif key == 'power_battery':
                    specs_text += f"- 전원/배터리: {value}\n"
            formatted_sections.append(specs_text)
        
        # 효율성 분석
        if 'efficiency' in user_details and user_details['efficiency']:
            efficiency_text = "**효율성 분석:**\n"
            for key, value in user_details['efficiency'].items():
                if key == 'problem_solving':
                    efficiency_text += f"- 해결하는 문제: {value}\n"
                elif key == 'time_saving':
                    efficiency_text += f"- 시간 절약 효과: {value}\n"
                elif key == 'space_efficiency':
                    efficiency_text += f"- 공간 활용: {value}\n"
                elif key == 'cost_saving':
                    efficiency_text += f"- 비용 절감: {value}\n"
            formatted_sections.append(efficiency_text)
        
        # 사용 시나리오
        if 'usage' in user_details and user_details['usage']:
            usage_text = "**사용 시나리오:**\n"
            for key, value in user_details['usage'].items():
                if key == 'usage_location':
                    usage_text += f"- 주요 사용 장소: {value}\n"
                elif key == 'usage_frequency':
                    usage_text += f"- 사용 빈도: {value}\n"
                elif key == 'target_users':
                    usage_text += f"- 적합한 사용자: {value}\n"
                elif key == 'usage_method':
                    usage_text += f"- 사용법 요약: {value}\n"
            formatted_sections.append(usage_text)
        
        # 장점 및 주의사항
        if 'benefits' in user_details and user_details['benefits']:
            benefits_text = "**장점 및 주의사항:**\n"
            if 'advantages' in user_details['benefits'] and user_details['benefits']['advantages']:
                benefits_text += "- 핵심 장점:\n"
                for i, advantage in enumerate(user_details['benefits']['advantages'], 1):
                    benefits_text += f"  {i}. {advantage}\n"
            if 'precautions' in user_details['benefits']:
                benefits_text += f"- 주의사항: {user_details['benefits']['precautions']}\n"
            formatted_sections.append(benefits_text)
        
        return "\n".join(formatted_sections) if formatted_sections else "사용자 상세 정보: 제공되지 않음"
    
    def _essential_items_prompt(self, title, keywords, user_details):
        """필수템형 프롬프트 🎯 - 구조화된 상품 가이드 생성"""

        # 1. AliExpress 링크 데이터 로드
        aliexpress_links = self._load_aliexpress_links()

        # 2. 키워드별 상세 섹션 생성
        keyword_sections = self._generate_keyword_sections(keywords, aliexpress_links)

        # 3. 관련 상품 더보기 버튼 생성
        more_products_button = self._create_more_products_button(keywords)

        # 4. 최종 구조화된 프롬프트 생성
        return self._create_structured_prompt(title, keywords, user_details, keyword_sections, more_products_button)

    @staticmethod
    def _friend_review_prompt(title, keywords, user_details):
        """친구 추천형 프롬프트 👫 - 개인 경험 기반 솔직한 후기"""
        
        user_details_formatted = PromptTemplates._format_user_details_for_prompt(user_details)
        # keywords 데이터 타입에 따른 적절한 처리
        if isinstance(keywords, list) and keywords:
            # 딕셔너리 배열인 경우 (실제 큐 데이터 형태)
            if isinstance(keywords[0], dict) and 'name' in keywords[0]:
                keywords_list = ', '.join([kw.get('name', '') for kw in keywords if kw.get('name')])
            else:
                # 문자열 배열인 경우 (기존 방식 호환)
                keywords_list = ', '.join(keywords)
        else:
            keywords_list = str(keywords) if keywords else ''
        
        return f"""당신은 실제 상품을 사용해본 친구처럼 솔직하고 진실된 후기를 전하는 리뷰어입니다.
3년 이상 알리익스프레스를 활용해온 경험이 있으며, 100개 이상의 상품을 직접 구매하여 사용해본 경험이 있습니다.

아래 제공되는 정보를 바탕으로, 친근하고 솔직한 개인 후기를 작성해주세요.

### 📋 제공된 정보 ###
**글 제목:** {title}
**핵심 키워드:** {keywords_list}

**사용자 상세 정보:**
{user_details_formatted}

### ✅ 친구 추천형 작성 요구사항 ###

**E-E-A-T 필수 요소:**
- Experience: "3개월 동안 매일 사용했습니다", "실제로 써보니"
- Expertise: "이전에 사용한 제품과 비교하면", "비교 경험상"
- Authoritativeness: 실제 데이터 + 사진 언급 "실제 측정해보니"
- Trustworthiness: "아쉬운 점도 있어요", "솔직히 말하면"

**글 구조 (총 2000-2500자):**

1. **👫 도입부 (200-250자):**
   - 개인 경험담: "솔직히 말하면 처음엔 이런 생각이었어요..."
   - 구매 계기: 사용자 제공 '해결하는 문제' 정보 활용
   - 친근한 톤: "여러분도 그런 경험 있으시죠?"

2. **🔥 각 키워드별 실제 사용 후기 (키워드당 500-600자):**
   - H2 태그: "[키워드명] 한 달 사용 후기"
   - 구매 계기: "이런 이유로 구매했어요"
   - 첫 사용 느낌: "처음 써보니..." (사용자 제공 스펙 정보 활용)
   - 장점 3가지: "특히 좋았던 점" (사용자 제공 장점 활용)
   - 단점/아쉬운 점: "다만 이런 점은..." (사용자 제공 주의사항 활용)
   - 추천 대상: "이런 분들께 추천해요" (사용자 제공 타겟 사용자 활용)

3. **💭 솔직한 총평 (200-300자):**
   - 개인적 만족도: "정말 만족하고 있어요"
   - 재구매 의사: "다시 살 거예요"
   - 지인 추천: "친구들에게도 추천했어요"

4. **✅ 마무리 (150-200자):**
   - 솔직한 결론: "진짜 추천할게요!"
   - 개인적 CTA: "저처럼 만족하실 거예요"

### ⚠️ 작성 원칙 ###
- 톤앤매너: 친근하고 솔직한, "개인적으로는", "제 경험상"
- 감정 표현: 풍부한 감정 표현, 대화하듯 자연스럽게
- HTML 태그: H2, H3, p 태그 사용 (마크다운 금지)
- 사용자 정보를 개인 경험담으로 자연스럽게 녹여내기

### 🎯 구매 전환율 최적화 ###
- 개인적 만족도: "정말 만족하고 있어요"
- 재구매 의사: "다시 살 거예요"
- 지인 추천: "친구들에게도 추천했어요"
- 솔직한 CTA: "저처럼 만족하실 거예요"

핵심 키워드 '{keywords_list.split(", ")[0] if isinstance(keywords_list, str) else keywords_list[0]}'에 대한 솔직하고 친근한 개인 후기를 작성해주세요."""

    @staticmethod
    def _professional_analysis_prompt(title, keywords, user_details):
        """전문 분석형 프롬프트 📊 - 기술적 분석 + 객관적 평가"""
        
        user_details_formatted = PromptTemplates._format_user_details_for_prompt(user_details)
        # keywords 데이터 타입에 따른 적절한 처리
        if isinstance(keywords, list) and keywords:
            # 딕셔너리 배열인 경우 (실제 큐 데이터 형태)
            if isinstance(keywords[0], dict) and 'name' in keywords[0]:
                keywords_list = ', '.join([kw.get('name', '') for kw in keywords if kw.get('name')])
            else:
                # 문자열 배열인 경우 (기존 방식 호환)
                keywords_list = ', '.join(keywords)
        else:
            keywords_list = str(keywords) if keywords else ''
        
        return f"""당신은 상품의 기술적 특징을 분석하고 객관적 평가를 제공하는 전문 분석가입니다.
10년 이상의 제품 분석 경험과 500개 이상의 제품 테스트 경험을 보유하고 있습니다.

아래 제공되는 정보를 바탕으로, 전문적이고 객관적인 상품 분석을 작성해주세요.

### 📋 제공된 정보 ###
**글 제목:** {title}
**핵심 키워드:** {keywords_list}

**사용자 상세 정보:**
{user_details_formatted}

### ✅ 전문 분석형 작성 요구사항 ###

**E-E-A-T 필수 요소:**
- Experience: "수십 개 제품을 분석한 결과", "테스트 경험에 따르면"
- Expertise: "핵심 기술을 분석하면", "기술적 관점에서"
- Authoritativeness: 객관적 데이터 + 성능 지표 활용
- Trustworthiness: "객관적으로 분석하면", "공정한 평가"

**글 구조 (총 2500-3000자):**

1. **📊 도입부 (200-250자):**
   - 시장 현황: "2025년 ○○○ 시장 분석"
   - 분석 목적: "전문가 관점에서 객관적 분석"
   - 키워드 중심 문제 정의

2. **🔍 각 키워드별 심층 분석 (키워드당 500-600자):**
   - H2 태그: "[키워드명] 전문 분석"
   - 핵심 기술/기능: "이 제품의 핵심은" (사용자 제공 스펙 정보 활용)
   - 성능 데이터 분석: "테스트 결과" (사용자 제공 효율성 분석 활용)
   - 경쟁 제품 대비 장점: "다른 제품과 비교하면"
   - 적정 가격대 분석: "가격 대비 성능"
   - 구매 적합성 판단: "이런 용도에 최적" (사용자 제공 사용 시나리오 활용)

3. **📈 종합 성능 평가 (300-400자):**
   - 객관적 우수성: "분석 결과 최고 성능"
   - 투자 가치: "이 가격에 이 성능이면"
   - 시장 내 위치: "업계 평균 대비"

4. **✅ 전문가 결론 (200-250자):**
   - 종합 평가: "전문가 관점에서 추천"
   - 구매 가이드: "이런 기준으로 선택하세요"
   - 전문적 CTA: "분석 근거를 바탕으로 권장"

### ⚠️ 작성 원칙 ###
- 톤앤매너: 객관적이고 전문적, "분석 결과", "데이터 기준"
- 논리적 구조: 체계적이고 논리적인 설명
- HTML 태그: H2, H3, p 태그 사용 (마크다운 금지)
- 사용자 정보를 기술적 분석 근거로 활용

### 🎯 구매 전환율 최적화 ###
- 전문적 근거: "분석 결과 최고 성능"
- 객관적 우수성: "테스트에서 1위"
- 투자 가치: "이 가격에 이 성능이면"
- 전문가 CTA: "전문가 관점에서 추천"

핵심 키워드 '{keywords_list.split(", ")[0] if isinstance(keywords_list, str) else keywords_list[0]}'에 대한 전문적이고 객관적인 분석을 작성해주세요."""

    @staticmethod
    def _amazing_discovery_prompt(title, keywords, user_details):
        """놀라움 발견형 프롬프트 ✨ - 혁신적 상품의 호기심 자극"""
        
        user_details_formatted = PromptTemplates._format_user_details_for_prompt(user_details)
        # keywords 데이터 타입에 따른 적절한 처리
        if isinstance(keywords, list) and keywords:
            # 딕셔너리 배열인 경우 (실제 큐 데이터 형태)
            if isinstance(keywords[0], dict) and 'name' in keywords[0]:
                keywords_list = ', '.join([kw.get('name', '') for kw in keywords if kw.get('name')])
            else:
                # 문자열 배열인 경우 (기존 방식 호환)
                keywords_list = ', '.join(keywords)
        else:
            keywords_list = str(keywords) if keywords else ''
        
        return f"""당신은 혁신적이고 독특한 상품의 놀라운 가치를 발견하고 전달하는 트렌드 큐레이터입니다.
5년 이상 최신 트렌드를 분석하고 1,000개 이상의 혁신 제품을 발굴한 경험이 있습니다.

아래 제공되는 정보를 바탕으로, 호기심을 자극하는 혁신 제품 소개를 작성해주세요.

### 📋 제공된 정보 ###
**글 제목:** {title}
**핵심 키워드:** {keywords_list}

**사용자 상세 정보:**
{user_details_formatted}

### ✅ 놀라움 발견형 작성 요구사항 ###

**E-E-A-T 필수 요소:**
- Experience: "이런 경험은 처음입니다", "직접 체험해보니"
- Expertise: "최신 기술 동향을 보면", "트렌드 분석 결과"
- Authoritativeness: 혁신성 근거 + 특허 정보 언급
- Trustworthiness: "물론 완벽하지는 않지만", "현실적 한계"

**글 구조 (총 2000-2500자):**

1. **✨ 도입부 (200-250자):**
   - 문제 제기: "이런 불편함 한 번쯤 경험해보셨죠?"
   - 놀라운 해결책 예고: "하지만 이제는 다릅니다"
   - 호기심 자극: "믿을 수 없겠지만 사실입니다"

2. **🚀 각 키워드별 혁신적 해결책 (키워드당 500-600자):**
   - H2 태그: "[키워드명] - 이런 게 가능하다고?"
   - 기존 방식의 한계: "지금까지는 이랬죠" (사용자 제공 문제 해결 정보 활용)
   - 혁신적 해결 방식: "하지만 이 제품은" (사용자 제공 스펙 정보 활용)
   - 놀라운 효과/결과: "결과는 정말 놀랍습니다" (사용자 제공 효율성 분석 활용)
   - 실제 사용 사례: "이런 식으로 활용하면" (사용자 제공 사용 시나리오 활용)
   - 미래 가능성: "앞으로 더 발전할 것"

3. **🔮 미래 전망 (200-300자):**
   - 기술 발전 방향: "이런 기술이 더 발전하면"
   - 라이프스타일 변화: "우리 생활이 어떻게 바뀔지"
   - 선점 효과: "아직 모르는 사람이 많아요"

4. **✅ 얼리어답터 추천 (150-200자):**
   - 혁신 참여: "새로운 변화에 동참하세요"
   - 발견자 특권: "당신도 이 놀라움을 경험해보세요"
   - 미래지향적 CTA: "미래를 먼저 경험하세요"

### ⚠️ 작성 원칙 ###
- 톤앤매너: 호기심 자극적, "믿을 수 없겠지만", "세상에 이런 게"
- 흥미진진한 표현: 역동적이고 미래지향적
- HTML 태그: H2, H3, p 태그 사용 (마크다운 금지)
- 사용자 정보를 혁신적 기능 설명에 활용

### 🎯 구매 전환율 최적화 ###
- 호기심 자극: "믿을 수 없겠지만 사실입니다"
- 선점 효과: "아직 모르는 사람이 많아요"
- 혁신 참여: "새로운 변화에 동참하세요"
- 발견자 CTA: "당신도 이 놀라움을 경험해보세요"

핵심 키워드 '{keywords_list.split(", ")[0] if isinstance(keywords_list, str) else keywords_list[0]}'의 혁신적 가치를 호기심 자극적으로 소개해주세요."""
