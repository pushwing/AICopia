<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '주문 관리' ?>

<?= $this->section('content') ?>

<!-- 필터 바 -->
<div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
    <input id="quickFilter" type="text" class="form-control form-control-sm" style="width:240px"
           placeholder="주문번호 / 수취인 / 이메일 검색">
    <select id="statusFilter" class="form-select form-select-sm" style="width:150px">
        <option value="">전체 상태</option>
        <?php foreach ($statusLabels as $val => $label): ?>
        <option value="<?= esc($val) ?>"><?= esc($label) ?></option>
        <?php endforeach; ?>
    </select>
    <div class="ms-auto d-flex gap-2">
        <a href="/admin/orders/anomalies" class="btn btn-outline-danger btn-sm">
            <i class="bi bi-shield-exclamation me-1"></i>이상 주문 탐지
        </a>
        <a href="/admin/orders/tracking-upload" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-truck me-1"></i>송장 일괄 등록
        </a>
        <a href="/admin/orders/export" class="btn btn-success btn-sm">
            <i class="bi bi-file-earmark-excel me-1"></i>엑셀 다운로드
        </a>
    </div>
</div>

<!-- 일괄 변경 액션 바 -->
<div id="bulkBar" class="d-none bg-light border rounded p-2 mb-3 d-flex align-items-center gap-2 flex-wrap">
    <span class="small fw-semibold"><span id="selectedCount">0</span>개 선택됨</span>
    <select id="bulkStatus" class="form-select form-select-sm" style="width:150px">
        <option value="">→ 상태 선택</option>
        <?php foreach ($statusLabels as $val => $label): ?>
        <option value="<?= esc($val) ?>"><?= esc($label) ?></option>
        <?php endforeach; ?>
    </select>
    <button id="btnBulkUpdate" class="btn btn-sm btn-primary">일괄 변경</button>
    <button id="btnClearSel" class="btn btn-sm btn-outline-secondary">선택 해제</button>
    <span class="text-muted small">※ 허용되지 않는 전환은 자동 건너뜁니다</span>
</div>

<div id="ordersGrid" class="ag-theme-alpine" style="height:620px"></div>
<div id="rowCount" class="text-muted small mt-2"></div>

<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ag-grid-community@31.3.4/styles/ag-grid.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ag-grid-community@31.3.4/styles/ag-theme-alpine.css">
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script src="https://cdn.jsdelivr.net/npm/ag-grid-community@31.3.4/dist/ag-grid-community.noStyle.min.js"></script>
<script>
(function () {
    var STATUS_BADGE = {
        pending:             'secondary',
        awaiting_payment:    'primary',
        paid:                'success',
        preparing:           'info',
        shipped:             'warning',
        delivered:           'success',
        cancelled:           'danger',
        expired:             'secondary',
        refund_requested:    'warning',
        refunded:            'dark',
        return_requested:    'warning',
        return_approved:     'info',
        exchange_requested:  'warning',
        exchange_approved:   'info',
        exchange_completed:  'success',
    };
    var PG_LABELS = {
        bank_transfer: '무통장', toss: '토스', inicis: '이니시스',
        nicepay: '나이스페이', kakaopay: '카카오페이', naverpay: '네이버페이', payco: 'PAYCO',
    };

    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    var statusFilterVal = '';

    var grid = agGrid.createGrid(document.getElementById('ordersGrid'), {
        columnDefs: [
            { headerName: '', checkboxSelection: true, headerCheckboxSelection: true,
              width: 40, pinned: 'left', sortable: false, filter: false, resizable: false },
            { headerName: '주문번호', field: 'order_number', width: 190,
              cellRenderer: function(p) {
                  var memo = p.data.memo_count > 0
                      ? ' <i class="bi bi-sticky text-warning" title="메모 있음"></i>' : '';
                  return '<span class="fw-semibold small">' + esc(p.value) + '</span>' + memo;
              }},
            { headerName: '주문일시', field: 'created_at', width: 150,
              cellRenderer: function(p) {
                  if (!p.value) return '';
                  var d = new Date(p.value.replace(' ', 'T'));
                  return '<span class="small text-muted">'
                       + d.toLocaleDateString('ko-KR') + '<br>'
                       + d.toLocaleTimeString('ko-KR', {hour: '2-digit', minute: '2-digit'})
                       + '</span>';
              },
              comparator: function(a, b) { return a < b ? -1 : a > b ? 1 : 0; }},
            { headerName: '회원', width: 170,
              valueGetter: function(p) { return (p.data.user_nickname || '') + ' ' + (p.data.user_email || ''); },
              cellRenderer: function(p) {
                  return '<span class="small">' + esc(p.data.user_nickname || '—') + '</span>'
                       + (p.data.user_email
                           ? '<br><span class="text-muted" style="font-size:.75rem">' + esc(p.data.user_email) + '</span>'
                           : '');
              }},
            { headerName: '수취인', field: 'receiver_name', width: 100,
              cellRenderer: function(p) { return '<span class="small">' + esc(p.value || '') + '</span>'; }},
            { headerName: '결제수단', width: 130,
              valueGetter: function(p) { return PG_LABELS[p.data.pg_provider] || p.data.pg_provider || ''; },
              cellRenderer: function(p) {
                  if (!p.data.pg_provider) return '<span class="text-muted">—</span>';
                  return '<span class="badge bg-light text-dark border">'
                       + esc(PG_LABELS[p.data.pg_provider] || p.data.pg_provider) + '</span>'
                       + (p.data.payment_method
                           ? '<br><span class="text-muted" style="font-size:.7rem">' + esc(p.data.payment_method) + '</span>'
                           : '');
              }},
            { headerName: '결제금액', field: 'total_amount', width: 110, type: 'numericColumn',
              cellRenderer: function(p) { return '<span class="small">' + p.value.toLocaleString() + '원</span>'; }},
            { headerName: '상태', field: 'status', width: 115,
              cellRenderer: function(p) {
                  var cls = STATUS_BADGE[p.value] || 'secondary';
                  return '<span class="badge bg-' + cls + '">' + esc(p.data.status_label) + '</span>';
              }},
            { headerName: '', width: 90, sortable: false, filter: false, resizable: false,
              cellStyle: { display: 'flex', alignItems: 'center', justifyContent: 'center' },
              cellRenderer: function(p) {
                  return '<a href="/admin/orders/' + p.data.id + '" class="btn btn-sm btn-outline-secondary">상세</a>';
              }},
        ],
        defaultColDef: { sortable: true, filter: true, resizable: true },
        rowSelection: 'multiple',
        rowHeight: 54,
        pagination: true,
        paginationPageSize: 20,
        paginationPageSizeSelector: [20, 50, 100],
        suppressRowClickSelection: true,
        isExternalFilterPresent: function() { return statusFilterVal !== ''; },
        doesExternalFilterPass: function(node) { return node.data.status === statusFilterVal; },
        onSelectionChanged: function(e) {
            var sel = e.api.getSelectedRows();
            document.getElementById('bulkBar').classList.toggle('d-none', sel.length === 0);
            document.getElementById('selectedCount').textContent = sel.length;
        },
        onModelUpdated: function(e) {
            document.getElementById('rowCount').textContent = '총 ' + e.api.getDisplayedRowCount().toLocaleString() + '건';
        },
        onGridReady: function(e) {
            fetch('/admin/orders/json')
                .then(function(r) { return r.json(); })
                .then(function(d) { e.api.setGridOption('rowData', d.data); });
        },
        localeText: { noRowsToShow: '주문이 없습니다.' },
    });

    document.getElementById('quickFilter').addEventListener('input', function() {
        grid.setGridOption('quickFilterText', this.value);
    });

    document.getElementById('statusFilter').addEventListener('change', function() {
        statusFilterVal = this.value;
        grid.onFilterChanged();
    });

    document.getElementById('btnClearSel').addEventListener('click', function() {
        grid.deselectAll();
    });

    document.getElementById('btnBulkUpdate').addEventListener('click', function() {
        var status = document.getElementById('bulkStatus').value;
        if (!status) { alert('변경할 상태를 선택해주세요.'); return; }
        var ids = grid.getSelectedRows().map(function(r) { return r.id; });
        if (!ids.length) return;
        if (!confirm(ids.length + '개 주문 상태를 변경하시겠습니까?')) return;

        var csrfName  = document.querySelector('meta[name="csrf-name"]').content;
        var csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        var body = csrfName + '=' + encodeURIComponent(csrfToken)
                 + '&status=' + encodeURIComponent(status)
                 + ids.map(function(id) { return '&order_ids[]=' + id; }).join('');

        fetch('/admin/orders/bulk-status', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
            body: body,
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            alert(data.message || (data.success ? '처리 완료' : '처리 실패'));
            if (data.success) location.reload();
        })
        .catch(function() { alert('오류가 발생했습니다.'); });
    });
}());
</script>
<?= $this->endSection() ?>
