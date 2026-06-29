(function () {
    'use strict';

    function getCookie(name) {
        const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : null;
    }

    function setCookie(name, value, hours) {
        const expires = new Date(Date.now() + hours * 3600 * 1000).toUTCString();
        document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + expires + '; path=/';
    }

    function closePopup(popup, id) {
        const cb = popup.querySelector('.popup-hide-today');
        if (cb && cb.checked) {
            setCookie('popup_hidden_' + id, '1', 24);
        }
        popup.style.display = 'none';
    }

    document.querySelectorAll('.site-popup').forEach(function (popup) {
        const id = popup.dataset.id;

        if (getCookie('popup_hidden_' + id)) {
            popup.style.display = 'none';
            return;
        }

        popup.style.display = 'block';

        // X 버튼 닫기
        popup.querySelector('.site-popup-close').addEventListener('click', function () {
            closePopup(popup, id);
        });

        // 오늘 하루 보지 않기 체크 시 즉시 닫기
        const cb = popup.querySelector('.popup-hide-today');
        if (cb) {
            cb.addEventListener('change', function () {
                if (this.checked) closePopup(popup, id);
            });
        }
    });
}());
