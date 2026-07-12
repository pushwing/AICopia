# Git 워크플로우

**브랜치 모델: `feature/* → dev → main`.**

- **`main`** — 운영/릴리스 브랜치. `dev`에서 올라온 PR로만 갱신.
- **`dev`** — 통합 브랜치. **`dev`는 절대 삭제 금지.** 모든 기능 작업은 먼저 여기로 머지.
- **`feature/xxx`** — 짧게 쓰는 작업 브랜치. 항상 **`dev`에서 분기**.

모든 변경의 표준 흐름:
```bash
git checkout dev && git pull origin dev
git checkout -b feature/<짧은-이름>      # dev에서 분기
# ...작업 후 커밋...
git push -u origin feature/<짧은-이름>
gh pr create --base dev --head feature/<짧은-이름>   # dev로 PR
# 리뷰/머지 후 feature 브랜치만 삭제 (dev 아님)
```
릴리스: 별도로 `dev → main` PR을 올립니다 (`gh pr create --base main --head dev`).

**규칙**
- `main`·`dev`에 직접 커밋 금지. 항상 `feature/*` 브랜치 + PR을 거칠 것. (단, 아래 **문서만 변경** 예외 참고.)
- `dev` 브랜치는 절대 삭제하지 말 것.
- `feature/*` 브랜치는 PR 머지 후에만 삭제.
- 커밋 메시지: 한국어 + 변경 내용에 맞는 이모지 접두사(프로젝트 규칙). 논리적 작업 1개 = 커밋 1개.

**예외 — 문서만 변경한 경우**
- `CLAUDE.md`·`.claude/rules/`·`docs/`·`README` 등 **문서/주석만 수정**하고 애플리케이션 코드(`app/`, `public/`, 마이그레이션, 설정 등) 변경이 전혀 없으면, feature 브랜치·PR 없이 **`dev`에 직접 커밋**해도 된다.
- 이 경우에도 커밋 메시지 규칙(한국어 + 이모지 접두사)은 동일하게 지킨다.
- **`main` 직접 커밋은 문서만 변경이라도 여전히 금지** — 릴리스는 항상 `dev → main` PR로만.
