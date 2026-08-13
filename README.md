# Tamsaek Profit

애드센스 수익($)과 광고비(₩)를 매일 입력하면 어제 / 이번 달 / 저번 달 / 올해 누적 순수익을 보여주는 워드프레스 관리자 전용 대시보드 플러그인.

## 주요 기능

- 어제 / 이번 달 / 저번 달 / 올해 누적 순수익 카드 (광고비 비중 %, RPM 포함)
- 환율 자동 조회: ECB 공식 API → KITA 매매기준율(KB 고시) 순
- 페이지 RPM 입력 → 페이지뷰 역산 → 월간 가중평균 RPM 자동 계산
- 입력 내역 페이징 목록, 행별 수정(날짜 이동 지원)·삭제
- 어제까지만 입력 가능 (확정치 기준)
- GitHub Release 기반 자동 업데이트

## 설치

[Releases](https://github.com/azit4u/Tamsaek-profit/releases)에서 최신 `tamsaek-profit.zip`을 받아
워드프레스 관리자 → 플러그인 → 새로 추가 → 플러그인 업로드로 설치.

## 업데이트 배포 방법

1. `tamsaek-profit.php`의 `Version`과 `TAMSAEK_PROFIT_VERSION`을 올린다 (예: 1.1)
2. `tamsaek-profit/` 폴더를 `tamsaek-profit.zip`으로 압축한다 (zip 안에 폴더가 포함되어야 함)
3. 새 Release를 만들고 태그를 `v1.1`로 지정, zip을 첨부하고 변경사항을 설명글에 적는다
4. 각 사이트의 플러그인 목록에서 업데이트 알림이 뜬다 (최대 6시간 캐시)

## 데이터

- 전용 테이블 `wp_tamsaek_profit_daily`에만 저장, 외부 전송 없음
- 구버전(apd-dashboard)의 `wp_apd_daily` 테이블이 있으면 활성화 시 자동 승계
- 관리자(`manage_options`)만 접근 가능
