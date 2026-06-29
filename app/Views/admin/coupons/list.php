<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '쿠폰 관리' ?>
<?php use App\Libraries\GradeService; ?>

<?= $this->section('content') ?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <div class="d-flex gap-2">
        <input id="quickFilter" type="text" class="form-control form-control-sm"
               placeholder="쿠폰명, 코드 검색" style="width:220px">
        <select id="activeFilter" class="form-select form-select-sm" style="width:110px">
            <option value="">전체 상태</option>
            <option value="1">활성</option>
            <option value="0">비활성</option>
        </select>
    </div>
    <a href="/admin/coupons/create" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>쿠폰 등록
    </a>
</div>

<div id="couponsGrid" class="ag-theme-alpine" style="height:620px"></div>
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
    var GRADE_LABELS = <?= json_encode(GradeService::LABELS) ?>;
    var GRADE_BADGES = <?= json_encode(GradeService::BADGE_CLASSES) ?>;

    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function formatDate(s) {
        if (!s) return '';
        return new Date(s.replace(' ', 'T')).toLocaleDateString('ko-KR');
    }

    function discountLabel(row) {
        if (row.type === 'free_shipping') return '—';
        if (row.type === 'fixed') return row.discount_value.toLocaleString() + '원';
        var s = row.discount_value + '%';
        if (row.max_discount_amount > 0) s += ' (최대 ' + row.max_discount_amount.toLocaleString() + '원)';
        return s;
    }

    var activeFilterVal = '';
    var csrf = {
        name:  document.querySelector('meta[name="csrf-name"]').content,
        token: document.querySelector('meta[name="csrf-token"]').content,
    };

    var grid = agGrid.createGrid(document.getElementById('couponsGrid'), {
        columnDefs: [
            { headerName: '쿠폰명', field: 'name', flex: 1, minWidth: 160,
              cellRenderer: function(p) {
                  return '<span class="fw-semibold small">' + esc(p.value) + '</span>';
              }},
            { headerName: '코드', field: 'code', width: 150,
              cellRenderer: function(p) {
                  return '<span class="font-monospace small">' + esc(p.value) + '</span>';
              }},
            { headerName: '종류', field: 'type_label', width: 100,
              cellRenderer: function(p) { return '<span class="small">' + esc(p.value) + '</span>'; }},
            { headerName: '대상 등급', field: 'target_grade', width: 150,
              cellRenderer: function(p) {
                  if (!p.value) return '<span class="text-muted small">전체</span>';
                  return p.value.split(',').map(function(g) {
                      g = g.trim();
                      var cls = GRADE_BADGES[g] || 'bg-secondary';
                      return '<span class="badge ' + cls + '">' + esc(GRADE_LABELS[g] || g) + '</span>';
                  }).join(' ');
              }},
            { headerName: '할인값', width: 150,
              valueGetter: function(p) { return discountLabel(p.data); },
              cellRenderer: function(p) {
                  return '<span class="small">' + esc(p.value) + '</span>';
              }},
            { headerName: '최소주문', field: 'min_order_amount', width: 100, type: 'numericColumn',
              cellRenderer: function(p) {
                  return '<span class="small">' + (p.value > 0 ? p.value.toLocaleString() + '원' : '—') + '</span>';
              }},
            { headerName: '수량', width: 100,
              valueGetter: function(p) {
                  return p.data.total_qty !== null
                      ? p.data.used_count + '/' + p.data.total_qty
                      : '무제한';
              },
              cellRenderer: function(p) {
                  return '<span class="small text-center d-block">' + esc(p.value) + '</span>';
              }},
            { headerName: '유효기간', width: 180,
              valueGetter: function(p) { return (p.data.starts_at || '') + ' ~ ' + (p.data.expires_at || ''); },
              cellRenderer: function(p) {
                  var s = formatDate(p.data.starts_at);
                  var e = formatDate(p.data.expires_at);
                  if (!s && !e) return '<span class="text-muted small">—</span>';
                  if (s && e) return '<span class="small text-muted">' + s + ' ~ ' + e + '</span>';
                  return '<span class="small text-muted">' + (s ? s + ' ~' : '~ ' + e) + '</span>';
              }},
            { headerName: '상태', field: 'is_active', width: 80,
              cellRenderer: function(p) {
                  return p.value
                      ? '<span class="badge bg-success">활성</span>'
                      : '<span class="badge bg-secondary">비활성</span>';
              }},
            { headerName: '', width: 205, sortable: false, filter: false, resizable: false,
              cellStyle: { display: 'flex', alignItems: 'center', gap: '4px' },
              cellRenderer: function(p) {
                  return '<a href="/admin/coupons/' + p.data.id + '/issue" class="btn btn-sm btn-outline-info">발급</a>'
                       + '<a href="/admin/coupons/' + p.data.id + '/edit" class="btn btn-sm btn-outline-secondary">수정</a>'
                       + '<button class="btn btn-sm btn-outline-danger" onclick="doDeactivate(' + p.data.id + ')">비활성화</button>';
              }},
        ],
        defaultColDef: { sortable: true, filter: true, resizable: true },
        rowHeight: 50,
        pagination: true,
        paginationPageSize: 20,
        paginationPageSizeSelector: [20, 50, 100],
        isExternalFilterPresent: function() { return activeFilterVal !== ''; },
        doesExternalFilterPass: function(node) {
            return String(node.data.is_active) === activeFilterVal;
        },
        onModelUpdated: function(e) {
            document.getElementById('rowCount').textContent = '총 ' + e.api.getDisplayedRowCount().toLocaleString() + '개';
        },
        onGridReady: function(e) {
            fetch('/admin/coupons/json')
                .then(function(r) { return r.json(); })
                .then(function(d) { e.api.setGridOption('rowData', d.data); });
        },
        localeText: { noRowsToShow: '등록된 쿠폰이 없습니다.' },
    });

    document.getElementById('quickFilter').addEventListener('input', function() {
        grid.setGridOption('quickFilterText', this.value);
    });

    document.getElementById('activeFilter').addEventListener('change', function() {
        activeFilterVal = this.value;
        grid.onFilterChanged();
    });

    window.doDeactivate = function(id) {
        if (!confirm('비활성화하시겠습니까?')) return;
        fetch('/admin/coupons/' + id + '/delete', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
            body: csrf.name + '=' + encodeURIComponent(csrf.token),
        }).then(function() { location.reload(); });
    };
}());
</script>
<?= $this->endSection() ?>
