// Bootstrap form validation
(function () {
    'use strict';
    document.querySelectorAll('.needs-validation').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!form.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });
})();

// 카카오(구 다음) 우편번호 서비스 — 버튼 클릭 시점에 CDN 스크립트를 지연 로드한다.
// 스크립트를 <script> 태그로 미리 심어두면 로드가 실패했을 때(광고 차단 확장·네트워크 오류 등)
// 클릭 시 `kakao is not defined` ReferenceError 만 나고 주소 입력이 완전히 막힌다.
//
// 도메인·네임스페이스는 카카오 이관에 맞춰 t1.kakaocdn.net · kakao.Postcode 를 쓴다.
// 번들이 window.kakao 와 window.daum 을 서로 alias 하므로 구 네임스페이스도 동작하지만,
// 레거시 다음 도메인은 순차 종료 중이라 신규 경로로 통일한다.
(function () {
    'use strict';

    var POSTCODE_SRC = 'https://t1.kakaocdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js';
    var loadingPromise = null;

    // 스크립트를 한 번만 로드하고 Promise 를 캐시한다. 실패 시 캐시를 비워 다음 클릭에서 재시도한다.
    function loadPostcodeScript() {
        if (window.kakao && window.kakao.Postcode) {
            return Promise.resolve();
        }
        if (loadingPromise) {
            return loadingPromise;
        }

        loadingPromise = new Promise(function (resolve, reject) {
            var script = document.createElement('script');
            script.src   = POSTCODE_SRC;
            script.async = true;
            script.onload = function () {
                if (window.kakao && window.kakao.Postcode) {
                    resolve();
                    return;
                }
                reject(new Error('스크립트는 로드됐지만 kakao.Postcode 가 없습니다.'));
            };
            script.onerror = function () {
                reject(new Error('우편번호 스크립트 로드 실패: ' + POSTCODE_SRC));
            };
            document.head.appendChild(script);
        })['catch'](function (err) {
            loadingPromise = null;
            throw err;
        });

        return loadingPromise;
    }

    // 검색 서비스를 못 쓰는 상황에서도 주문이 막히지 않도록 주소 입력란의 readonly 를 해제한다.
    function enableManualAddressInput() {
        ['zipcode', 'address1'].forEach(function (id) {
            var field = document.getElementById(id);
            if (field) {
                field.readOnly = false;
            }
        });
    }

    /**
     * 우편번호 검색 팝업을 연다.
     * @param {function(Object): void} oncomplete 주소 선택 시 호출되는 콜백
     */
    window.openPostcode = function (oncomplete) {
        loadPostcodeScript().then(function () {
            new window.kakao.Postcode({ oncomplete: oncomplete }).open();
        })['catch'](function (err) {
            console.error('[postcode]', err);
            enableManualAddressInput();
            alert('주소 검색 서비스를 불러오지 못했습니다.\n네트워크 상태나 광고 차단 확장 프로그램을 확인해 주세요.\n우선 우편번호와 주소를 직접 입력하실 수 있습니다.');
        });
    };
})();

// 상품 이미지 로드 실패(404 등) 시 플레이스홀더 아이콘으로 폴백한다.
// 상품 URL 은 있으나 파일이 없는 경우(데모 데이터 등) 깨진 이미지 대신,
// primary_image 가 비었을 때와 동일한 bi-image 플레이스홀더를 보여준다.
// 목록·홈·상세·추천·장바구니 등 .product-card 가 쓰이는 모든 화면에 적용된다.
(function () {
    'use strict';

    function toPlaceholder(img) {
        if (img.dataset.imgFallback) {
            return;
        }
        img.dataset.imgFallback = '1';
        var ph = document.createElement('div');
        ph.className = 'd-flex align-items-center justify-content-center h-100 text-muted';
        var icon = document.createElement('i');
        icon.className = 'bi bi-image fs-1';
        ph.appendChild(icon);
        if (img.parentNode) {
            img.parentNode.replaceChild(ph, img);
        }
    }

    document.querySelectorAll('.product-card img').forEach(function (img) {
        img.addEventListener('error', function () {
            toPlaceholder(img);
        });
        // 스크립트 실행 전 이미 로드에 실패한 이미지도 처리한다.
        if (img.complete && img.naturalWidth === 0) {
            toPlaceholder(img);
        }
    });
})();

// ─── 공용 토스트 (네이티브 alert 대체) ──────────────────────────────────────
// window.toast(message, type) — type: success | error/danger | warning | info(기본)
// 우하단 컨테이너(#toastContainer)에 Bootstrap Toast 를 띄운다.
// 메시지는 textContent 로 넣어 XSS 안전. 컨테이너/Bootstrap 이 없으면 alert 폴백.
window.toast = function (message, type) {
    var container = document.getElementById('toastContainer');
    if (! container || typeof bootstrap === 'undefined') { alert(message); return; }

    var clsMap = {
        success: 'text-bg-success',
        error:   'text-bg-danger',
        danger:  'text-bg-danger',
        warning: 'text-bg-warning',
        info:    'text-bg-dark'
    };
    var cls = clsMap[type] || 'text-bg-dark';

    var el = document.createElement('div');
    el.className = 'toast align-items-center border-0 ' + cls;
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');
    el.setAttribute('aria-atomic', 'true');

    var flex = document.createElement('div');
    flex.className = 'd-flex';

    var body = document.createElement('div');
    body.className = 'toast-body';
    body.textContent = message;

    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn-close me-2 m-auto' + (cls === 'text-bg-warning' ? '' : ' btn-close-white');
    close.setAttribute('data-bs-dismiss', 'toast');
    close.setAttribute('aria-label', '닫기');

    flex.appendChild(body);
    flex.appendChild(close);
    el.appendChild(flex);
    container.appendChild(el);

    var t = new bootstrap.Toast(el, { delay: 3000 });
    el.addEventListener('hidden.bs.toast', function () { el.remove(); });
    t.show();
};

// ─── 공용 확인 모달 (네이티브 confirm 대체, Promise<boolean> 반환) ────────────
// window.confirmDialog({ title, message, confirmText, cancelText, danger }).then(ok => ...)
// #confirmModal 스켈레톤(레이아웃)을 재사용. 없으면 window.confirm 폴백.
window.confirmDialog = function (opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
        var modalEl = document.getElementById('confirmModal');
        if (! modalEl || typeof bootstrap === 'undefined') {
            resolve(window.confirm(opts.message || '진행하시겠습니까?'));
            return;
        }

        modalEl.querySelector('[data-confirm-title]').textContent   = opts.title   || '확인';
        modalEl.querySelector('[data-confirm-message]').textContent = opts.message || '';

        var okBtn     = modalEl.querySelector('[data-confirm-ok]');
        var cancelBtn = modalEl.querySelector('[data-confirm-cancel]');
        okBtn.textContent     = opts.confirmText || '확인';
        cancelBtn.textContent = opts.cancelText || '취소';
        okBtn.className = 'btn ' + (opts.danger ? 'btn-danger' : 'btn-primary');

        var modal   = bootstrap.Modal.getOrCreateInstance(modalEl);
        var settled = false;

        function finish(result) {
            if (settled) return;
            settled = true;
            okBtn.removeEventListener('click', onOk);
            modalEl.removeEventListener('hidden.bs.modal', onHidden);
            resolve(result);
        }
        function onOk()     { modal.hide(); finish(true); }
        function onHidden() { finish(false); }

        okBtn.addEventListener('click', onOk);
        modalEl.addEventListener('hidden.bs.modal', onHidden);
        modal.show();
    });
};

// ─── data-confirm 폼: 제출 전 공용 확인 모달로 게이트 ──────────────────────────
// <form data-confirm="메시지" [data-confirm-title="제목"] [data-confirm-danger]> 를
// confirmDialog 로 가로챈다. 네이티브 onsubmit="return confirm(...)" 대체.
document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        confirmDialog({
            title:   form.dataset.confirmTitle || '확인',
            message: form.dataset.confirm,
            danger:  form.hasAttribute('data-confirm-danger')
        }).then(function (ok) {
            // form.submit() 은 submit 이벤트를 다시 발생시키지 않아 재진입이 없다.
            if (ok) form.submit();
        });
    });
});
