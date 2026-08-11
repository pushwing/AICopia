# 장바구니 본품 해제 시 애드온 자동 해제·잠금 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 장바구니에서 본품 체크박스를 해제하면 연결된 애드온(추가구성상품) 체크박스도 자동으로 해제·잠기고, 본품을 다시 체크하면 애드온이 잠금 해제되며 잠기기 직전 선택 상태로 복원되게 한다.

**Architecture:** 서버 변경 없이 `app/Views/shop/cart.php` 한 파일만 수정한다. `AddonGrouping::order()`가 이미 각 애드온 항목 배열에 `parent_product_id`를 채워주므로, 템플릿에서 이 값을 애드온 카드의 `data-parent-product-id` 속성으로 노출하고, 인라인 스크립트가 본품 체크박스의 `change` 이벤트(및 페이지 로드 시 1회)에서 같은 `data-product-id`를 가진 본품과 `data-parent-product-id`가 일치하는 애드온 카드를 찾아 체크박스의 `checked`/`disabled`를 동기화한다. 품절로 이미 `disabled`인 애드온(`data-soldout="1"`로 표시)은 이 동기화 대상에서 제외해 기존 품절 처리와 충돌하지 않게 한다.

**Tech Stack:** CodeIgniter 4 뷰(네이티브 PHP 템플릿) + 바닐라 JS(인라인 `<script>`). 이 저장소에는 JS 테스트 프레임워크가 없어(`package.json`에 `devDependencies`로 `tinymce`만 있음) 자동화 테스트 대상이 아니며, 브라우저로 직접 동작을 검증한다.

**설계 스펙:** `docs/superpowers/specs/2026-08-11-cart-addon-auto-deselect-design.md`

## Global Constraints

- 주석·커밋 메시지는 한국어. 커밋 = 이모지 + Conventional Commits 접두어 + 한국어 설명(이번 변경은 `✨ feat:`).
- 뷰의 모든 PHP 출력은 `esc()` 또는 `(int)` 캐스팅으로 안전하게 — `data-parent-product-id`는 이미 DB에서 온 정수값이므로 `(int)` 캐스팅으로 충분(문자열 이스케이프 불필요).
- 서버(컨트롤러/모델/DB/마이그레이션) 변경 없음 — `app/Views/shop/cart.php` 단일 파일만 수정.
- 기존 동작 회귀 금지: "전체 선택" 토글, 개별 수량 변경, 결제 버튼 활성화, 품절 상품 처리, 고아 애드온(본품 없는 애드온) 독립 선택은 그대로 유지되어야 한다.
- 브랜치: 현재 워크트리 브랜치(`claude/cart-addon-purchase-process-a977aa`, `dev`에서 분기됨)에서 계속 작업. 새 `feature/*` 브랜치를 별도로 만들지 않는다.

---

### Task 1: `cart.php`에 애드온 잠금 로직 구현

**Files:**
- Modify: `app/Views/shop/cart.php:61-64` (카드 data 속성)
- Modify: `app/Views/shop/cart.php:71-72` (체크박스에 품절 마커 추가)
- Modify: `app/Views/shop/cart.php:257-268` (전체선택/개별체크 리스너에 잠금 동기화 연결)
- Modify: `app/Views/shop/cart.php:329-330` (로드 시 초기 동기화)

**Interfaces:**
- Consumes: `$item['parent_product_id']`(이미 `AddonGrouping::order()`가 채워 넣는 값, 애드온이 아니면 접근하지 않음), `$isAddon`(기존 로컬 변수), `$isSoldOut`(기존 로컬 변수)
- Produces: 이 태스크가 마지막 태스크이므로 이후 태스크에서 소비하는 인터페이스는 없음. (검증은 Task 2에서 브라우저로 수행)

- [ ] **Step 1: 애드온 카드에 `data-parent-product-id` 속성 추가**

`app/Views/shop/cart.php:61-64`의 카드 컨테이너를 아래와 같이 수정한다(기존 4개 속성 줄 사이에 조건부 속성 한 줄 추가):

```php
            <div class="card mb-2 cart-item <?= $isSoldOut ? 'opacity-75' : '' ?> <?= $isAddon ? 'ps-4 border-start border-3' : '' ?>"
                 data-cart-id="<?= (int) $item['id'] ?>"
                 data-product-id="<?= (int) $item['product_id'] ?>"
                 data-sku-id="<?= (int) ($item['sku_id'] ?? 0) ?>"
                 data-price="<?= (int) $item['display_price'] ?>"
                 <?php if ($isAddon): ?>data-parent-product-id="<?= (int) $item['parent_product_id'] ?>"<?php endif; ?>>
```

- [ ] **Step 2: 품절 체크박스에 `data-soldout` 마커 추가**

`app/Views/shop/cart.php:71-72`의 체크박스를 아래와 같이 수정한다(잠금 로직이 품절로 인해 이미 `disabled`인 체크박스를 건드리지 않도록 구분자를 남긴다):

```php
                        <div class="pt-1 flex-shrink-0">
                            <input type="checkbox" class="form-check-input item-check"
                                   <?= $isSoldOut ? 'disabled data-soldout="1"' : 'checked' ?>>
                        </div>
```

- [ ] **Step 3: 잠금 동기화 함수 추가 + 기존 체크 리스너에 연결**

`app/Views/shop/cart.php:257-268`(전체 선택 토글 + 개별 체크 리스너 블록)을 아래로 통째로 교체한다:

```javascript
    // 본품 체크 상태에 따라 연결된 애드온 체크박스를 잠그거나 푼다.
    // - 본품 미체크: 애드온을 체크 해제하고 잠근다(직전 선택 상태는 기억해둔다).
    // - 본품 체크: 애드온 잠금을 풀고, 잠기기 전 선택 상태로 복원한다.
    // 품절로 이미 disabled된 애드온(data-soldout="1")은 건드리지 않는다.
    function syncAddonLock(parentCheckbox) {
        const parentCard = parentCheckbox.closest('.cart-item');
        if (! parentCard) return;
        const parentProductId = parentCard.dataset.productId;

        document.querySelectorAll('.cart-item[data-parent-product-id="' + parentProductId + '"]').forEach(function (addonCard) {
            const addonCheck = addonCard.querySelector('.item-check');
            if (! addonCheck || addonCheck.dataset.soldout === '1') return;

            if (parentCheckbox.checked) {
                if (addonCheck.disabled) {
                    addonCheck.disabled = false;
                    addonCheck.checked  = addonCheck.dataset.prevChecked === '1';
                }
            } else if (! addonCheck.disabled) {
                addonCheck.dataset.prevChecked = addonCheck.checked ? '1' : '0';
                addonCheck.checked  = false;
                addonCheck.disabled = true;
            }
        });
    }

    // 전체 선택 토글
    document.getElementById('selectAll')?.addEventListener('change', function () {
        document.querySelectorAll('.item-check:not([disabled])').forEach(function (c) {
            c.checked = this.checked;
        }, this);
        document.querySelectorAll('.item-check').forEach(syncAddonLock);
        updateSummary();
    });

    // 개별 체크 → 합계 갱신 + 연결된 애드온 잠금 동기화
    document.querySelectorAll('.item-check').forEach(function (c) {
        c.addEventListener('change', function () {
            syncAddonLock(c);
            updateSummary();
        });
    });
```

- [ ] **Step 4: 페이지 로드 시 초기 잠금 상태 동기화**

`app/Views/shop/cart.php:329-330`(파일 맨 끝, `updateSummary()` 단독 호출 지점)을 아래로 교체한다:

```javascript
    // 로드 시점에 본품 체크 상태에 따라 애드온 잠금 상태를 먼저 맞추고,
    // 기본 선택된 상품 수/합계를 요약에 반영한다.
    document.querySelectorAll('.item-check').forEach(syncAddonLock);
    updateSummary();
```

- [ ] **Step 5: PHP 구문 검사**

Run: `php -l app/Views/shop/cart.php`
Expected: `No syntax errors detected in app/Views/shop/cart.php`

- [ ] **Step 6: 코드 스타일 검사**

Run: `composer cs`
Expected: 종료 코드 0(뷰 파일은 PHP-CS-Fixer 대상에서 보통 제외되지만, 혹시 대상이면 위반 없이 통과해야 한다). 위반이 나오면 `composer cs-fix`로 자동 수정 후 재확인.

- [ ] **Step 7: 커밋**

```bash
git add app/Views/shop/cart.php
git commit -m "$(cat <<'EOF'
✨ feat: 장바구니 본품 해제 시 애드온 자동 해제·잠금

EOF
)"
```

---

### Task 2: 브라우저로 동작 검증

**Files:** 없음(코드 변경 없이 Task 1 결과를 검증만 한다)

**Interfaces:**
- Consumes: Task 1에서 구현한 `syncAddonLock()` 동작, `data-parent-product-id`/`data-soldout` 속성
- Produces: 없음(검증 태스크)

이 저장소에는 JS 단위 테스트가 없으므로(설계 스펙 "테스트" 절 참고), 실제 개발 서버를 띄우고 브라우저로 아래 시나리오를 확인한다. 관리자로 애드온을 연결하고, 구매자 화면에서 본품+애드온을 함께 담은 뒤 장바구니에서 체크박스 동작을 확인하는 순서다.

- [ ] **Step 1: 개발 서버 기동 및 테스트 데이터 확인**

Run: `php spark serve --port 8303`

애드온이 연결된 상품이 없다면 관리자로 `/admin/products/{본품id}/edit`에 접속해 "추가구성상품" 카드에서 애드온 상품을 하나 연결하고 저장한다(연결 UI는 `app/Views/admin/products/form.php`의 `#addonCard`, 검색 후 클릭하면 추가됨).

- [ ] **Step 2: 본품+애드온을 함께 장바구니에 담기**

`/shop/{본품-slug}` 상품 상세 페이지에서 애드온 섹션(`#addonSection`)의 "선택" 버튼으로 방금 연결한 애드온을 고르고, "장바구니 담기"를 눌러 `/cart/add-bundle`을 통해 본품+애드온이 함께 담기도록 한다.

- [ ] **Step 3: 시나리오 1 — 본품 해제 시 애드온 자동 해제·잠금**

`/cart`에서:
1. 본품 카드는 처음에 체크되어 있고, 그 아래 "추가구성" 배지가 붙은 애드온 카드도 체크되어 있는지 확인.
2. 본품 체크박스를 클릭해 해제.
3. **기대 결과:** 애드온 체크박스도 자동으로 해제되고, 회색으로 비활성화(잠김)되어 클릭해도 반응이 없다. 주문 요약(`#selectedCount`/`#selectedTotal`)에서 두 항목 모두 합계에서 빠진다.

- [ ] **Step 4: 시나리오 2 — 본품 재체크 시 애드온 잠금 해제 + 상태 복원**

이어서:
1. 본품 체크박스를 다시 클릭해 체크.
2. **기대 결과:** 애드온 체크박스가 잠금 해제(활성화)되고, Step 3에서 해제되기 직전 상태(체크됨)로 자동 복원되어 다시 체크된 채로 표시된다. 주문 요약에 두 항목이 다시 합산된다.
3. 애드온만 따로 체크 해제 → 본품은 그대로 두고 애드온만 빠지는지(정상적인 "본품만 구매" 케이스가 계속 가능한지) 확인.
4. 본품을 다시 한번 해제했다가 체크 → 방금 애드온만 해제해둔 상태(체크 해제됨)가 그대로 복원되는지 확인(마지막 상태를 기억하는지 재검증).

- [ ] **Step 5: 시나리오 3 — 품절 본품의 애드온 초기 잠금**

관리자에서 본품 재고를 0으로 바꾸거나(또는 이미 품절인 본품+애드온 조합이 있다면 그것으로) 해당 장바구니를 새로고침.
**기대 결과:** 본품 카드가 "품절 상품 — 결제 불가" 문구와 함께 처음부터 비활성 상태이고, 그 애드온도 페이지 로드 시점부터 잠긴(비활성) 상태로 표시된다.

- [ ] **Step 6: 시나리오 4 — 고아 애드온 회귀 확인**

본품이 장바구니에서 삭제된 채 애드온만 남아있는 상태(또는 `parent_product_id`가 매칭되지 않는 상태)를 만들어, 해당 애드온이 "추가구성" 배지 없이 일반 상품처럼 완전히 독립적으로 체크/해제되는지 확인(`AddonGrouping`이 `is_addon = false`로 처리하므로 `data-parent-product-id`가 렌더링되지 않아야 한다).

- [ ] **Step 7: 시나리오 5 — 기존 기능 회귀 확인**

- "전체 선택" 체크박스로 전체 체크/해제 시 잠긴 애드온이 전체선택 대상에서 자동으로 빠지는지, 잠금 해제된 애드온은 정상적으로 함께 선택되는지.
- 수량 +/−/직접입력, "수정" 버튼(Ajax) 동작이 기존처럼 정상인지.
- 결제 버튼이 선택 항목 0개일 때 비활성화되고, 1개 이상일 때 활성화되는지.

모든 시나리오가 기대대로 동작하면 Task 2 완료. 실패하는 시나리오가 있으면 Task 1로 돌아가 `syncAddonLock()` 로직을 수정한다(코드 변경이 있었다면 Task 1의 Step 5~7을 다시 수행).
