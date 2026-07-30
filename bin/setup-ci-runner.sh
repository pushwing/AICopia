#!/usr/bin/env bash
#
# self-hosted GitHub Actions 러너를 이 Mac에 등록한다 (.github/workflows/ci.yml의
# runs-on: [self-hosted, macOS, ARM64] 잡을 실행하기 위한 1회성 셋업).
#
# 동작:
#   1. gh CLI로 저장소 등록 토큰을 자동 발급(실패 시 수동 입력으로 폴백)
#   2. GitHub 공식 릴리스에서 최신 러너 패키지(macOS/ARM64) 다운로드
#   3. ~/actions-runners/<repo>에 설치 + launchd 서비스로 등록·기동
#
# 요구사항: macOS, curl, tar. gh CLI(선택, 없으면 토큰을 직접 입력받음)
# 재실행: 이미 등록돼 있으면 아무것도 하지 않고 상태만 출력한다.
# 삭제하려면: bin/teardown-ci-runner.sh 참고(또는 아래 "제거" 안내 출력 참고)
#
# 사용법: bin/setup-ci-runner.sh
set -euo pipefail

REPO_OWNER="pushwing"
REPO_NAME="AICopia"
RUNNER_DIR="${RUNNER_DIR:-$HOME/actions-runners/${REPO_NAME}}"
RUNNER_NAME="${RUNNER_NAME:-$(hostname -s 2>/dev/null || echo mac)-$(echo "$REPO_NAME" | tr '[:upper:]' '[:lower:]')}"
LABELS="self-hosted,macOS,ARM64"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[0;33m'; NC='\033[0m'
info() { echo -e "${YELLOW}▶ $1${NC}"; }
ok()   { echo -e "${GREEN}✓ $1${NC}"; }
die()  { echo -e "${RED}✗ $1${NC}" >&2; exit 1; }

[ "$(uname -s)" = "Darwin" ] || die "이 스크립트는 macOS 전용입니다. (ci.yml이 self-hosted macOS 러너를 기대함)"

case "$(uname -m)" in
  arm64)  RUNNER_ARCH="arm64" ;;
  x86_64) RUNNER_ARCH="x64" ;;
  *)      die "지원하지 않는 아키텍처: $(uname -m)" ;;
esac

# 이미 등록된 러너가 있으면 상태만 보여주고 종료 (중복 등록 방지)
if [ -f "$RUNNER_DIR/.runner" ]; then
  ok "이미 등록된 러너가 있습니다: $RUNNER_DIR"
  (cd "$RUNNER_DIR" && ./svc.sh status) || true
  echo "재등록하려면 먼저 제거하세요:"
  echo "  cd \"$RUNNER_DIR\" && ./svc.sh uninstall && ./config.sh remove --token <제거토큰(저장소 Settings에서 발급)>"
  exit 0
fi

command -v curl >/dev/null 2>&1 || die "curl이 필요합니다."
command -v tar  >/dev/null 2>&1 || die "tar가 필요합니다."

# 1) 등록 토큰 발급 — gh CLI가 있으면 자동, 없으면 수동 입력
REG_TOKEN=""
if command -v gh >/dev/null 2>&1 && gh auth status >/dev/null 2>&1; then
  info "gh CLI로 등록 토큰 발급 중..."
  REG_TOKEN="$(gh api -X POST "repos/${REPO_OWNER}/${REPO_NAME}/actions/runners/registration-token" --jq .token 2>/dev/null || true)"
fi
if [ -z "$REG_TOKEN" ]; then
  echo "gh CLI로 토큰 발급에 실패했거나 gh가 없습니다."
  echo "GitHub → https://github.com/${REPO_OWNER}/${REPO_NAME}/settings/actions/runners/new 에서"
  echo "'New self-hosted runner' 페이지를 열어 발급된 토큰을 아래에 붙여넣으세요(유효시간 짧음)."
  read -r -s -p "등록 토큰: " REG_TOKEN
  echo
fi
[ -n "$REG_TOKEN" ] || die "등록 토큰이 비어 있습니다."

# 2) 최신 러너 패키지 조회 및 다운로드
info "최신 러너 버전 조회 중..."
LATEST_TAG="$(curl -fsSL https://api.github.com/repos/actions/runner/releases/latest | grep -m1 '"tag_name"' | sed -E 's/.*"v([^"]+)".*/\1/')"
[ -n "$LATEST_TAG" ] || die "최신 러너 버전을 조회하지 못했습니다."
PKG="actions-runner-osx-${RUNNER_ARCH}-${LATEST_TAG}.tar.gz"
URL="https://github.com/actions/runner/releases/download/v${LATEST_TAG}/${PKG}"

mkdir -p "$RUNNER_DIR"
info "다운로드: $URL"
curl -fsSL -o "${RUNNER_DIR}/${PKG}" "$URL"

info "압축 해제..."
tar xzf "${RUNNER_DIR}/${PKG}" -C "$RUNNER_DIR"
rm -f "${RUNNER_DIR}/${PKG}"

# 3) 등록 + launchd 서비스로 상시화
info "러너 등록 (${RUNNER_NAME}, labels=${LABELS})..."
(
  cd "$RUNNER_DIR"
  ./config.sh --unattended \
    --url "https://github.com/${REPO_OWNER}/${REPO_NAME}" \
    --token "$REG_TOKEN" \
    --name "$RUNNER_NAME" \
    --labels "$LABELS" \
    --work "_work" \
    --replace

  info "launchd 서비스로 설치·기동..."
  ./svc.sh install
  ./svc.sh start
)

ok "완료. Mac이 켜져 있으면 자동으로 CI 잡을 리스닝합니다."
echo "  상태 확인: (cd \"$RUNNER_DIR\" && ./svc.sh status)"
echo "  중지:      (cd \"$RUNNER_DIR\" && ./svc.sh stop)"
echo "  제거:      (cd \"$RUNNER_DIR\" && ./svc.sh uninstall && ./config.sh remove --token <제거토큰>)"
