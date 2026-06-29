<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '상품 관리' ?>

<?= $this->section('content') ?>

<!-- 일괄 처리용 히든 폼 -->
<form id="bulkForm" method="post" action="/admin/products/bulk">
    <?= csrf_field() ?>
    <input type="hidden" name="action"         id="bulkAction">
    <input type="hidden" name="status"         id="bulkStatusVal">
    <input type="hidden" name="stock"          id="bulkStockVal">
    <input type="hidden" name="discount_type"  id="bulkDiscountType">
    <input type="hidden" name="discount_value" id="bulkDiscountVal">
</form>

<div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
    <input id="quickFilter" type="text" class="form-control form-control-sm" style="width:180px"
           placeholder="상품명 검색">
    <select id="statusFilter" class="form-select form-select-sm" style="width:120px">
        <option value="">전체 상태</option>
        <?php foreach ($statuses as $val => $label): ?>
        <option value="<?= esc($val) ?>"><?= esc($label) ?></option>
        <?php endforeach; ?>
    </select>
    <button id="btnLowStock" class="btn btn-sm btn-outline-danger">
        <i class="bi bi-exclamation-triangle me-1"></i>재고 부족
        <?php if (($lowStockCount ?? 0) > 0): ?>
        <span class="badge bg-danger ms-1"><?= number_format($lowStockCount) ?></span>
        <?php endif; ?>
    </button>
    <div class="ms-auto d-flex gap-2">
        <a href="/admin/products/unassigned" class="btn btn-sm btn-outline-warning">
            <i class="bi bi-folder-x me-1"></i>미분류
            <?php if (($unassignedCount ?? 0) > 0): ?>
            <span class="badge bg-warning text-dark ms-1"><?= number_format($unassignedCount) ?></span>
            <?php endif; ?>
        </a>
        <a href="/admin/products/categories" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-tags me-1"></i>카테고리
        </a>
        <a href="/admin/products/import" class="btn btn-sm btn-outline-success">
            <i class="bi bi-file-earmark-excel me-1"></i>엑셀 일괄 등록
        </a>
        <a href="/admin/products/create" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg me-1"></i>상품 등록
        </a>
    </div>
</div>

<!-- 일괄 편집 액션 바 -->
<div id="bulkBar" class="card mb-3 border-primary d-none">
    <div class="card-body py-2 px-3 d-flex align-items-center flex-wrap gap-3">
        <span class="fw-semibold text-primary small"><span id="bulkCount">0</span>개 선택됨</span>
        <div class="vr"></div>
        <div class="d-flex align-items-center gap-1">
            <span class="small text-muted">상태:</span>
            <select id="bulkStatusSel" class="form-select form-select-sm" style="width:100px">
                <?php foreach ($statuses as $val => $label): ?>
                <option value="<?= esc($val) ?>"><?= esc($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-outline-primary" onclick="submitBulk('status')">변경</button>
        </div>
        <div class="vr"></div>
        <div class="d-flex align-items-center gap-1">
            <span class="small text-muted">재고:</span>
            <input id="bulkStockInput" type="number" min="0" class="form-control form-control-sm"
                   style="width:80px" placeholder="0">
            <button class="btn btn-sm btn-outline-primary" onclick="submitBulk('stock')">설정</button>
        </div>
        <div class="vr"></div>
        <div class="d-flex align-items-center gap-1">
            <span class="small text-muted">할인:</span>
            <select id="discountTypeSel" class="form-select form-select-sm" style="width:80px">
                <option value="percent">%</option>
                <option value="fixed">정액</option>
                <option value="clear">해제</option>
            </select>
            <input id="discountValueInput" type="number" min="0" class="form-control form-control-sm"
                   style="width:70px" placeholder="0">
            <button class="btn btn-sm btn-outline-warning" onclick="submitBulk('price_discount')">적용</button>
        </div>
        <div class="vr"></div>
        <button class="btn btn-sm btn-outline-danger" onclick="submitBulk('delete')">
            <i class="bi bi-trash me-1"></i>삭제
        </button>
        <button class="btn btn-sm btn-link text-muted ms-auto p-0" onclick="clearSelection()">선택 해제</button>
    </div>
</div>

<div id="productsGrid" class="ag-theme-alpine" style="height:620px"></div>
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
    var STATUSES    = { <?php foreach ($statuses as $v => $l): ?>'<?= $v ?>': '<?= esc($l) ?>', <?php endforeach; ?> };
    var STATUS_BADGE = { on_sale: 'success', sold_out: 'warning', hidden: 'secondary' };
    var stockThreshold = <?= (int) ($lowStockThreshold ?? 5) ?>;
    var csrf = {
        name:  document.querySelector('meta[name="csrf-name"]').content,
        token: document.querySelector('meta[name="csrf-token"]').content,
    };

    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    var statusFilterVal = '';
    var lowStockOnly    = false;

    var grid = agGrid.createGrid(document.getElementById('productsGrid'), {
        columnDefs: [
            { headerName: '', checkboxSelection: true, headerCheckboxSelection: true,
              width: 40, pinned: 'left', sortable: false, filter: false, resizable: false },
            { headerName: '이미지', width: 72, sortable: false, filter: false, resizable: false,
              cellRenderer: function(p) {
                  if (p.data.primary_image) {
                      return '<img src="' + esc(p.data.primary_image) + '" alt="" '
                           + 'style="width:48px;height:48px;object-fit:cover;border-radius:4px;margin:4px 0">';
                  }
                  return '<div style="width:48px;height:48px;background:#f1f3f5;border-radius:4px;'
                       + 'display:flex;align-items:center;justify-content:center;margin:4px 0">'
                       + '<i class="bi bi-image text-muted"></i></div>';
              }},
            { headerName: '상품명', field: 'name', flex: 1, minWidth: 180,
              cellRenderer: function(p) {
                  return '<div class="fw-semibold small">' + esc(p.value) + '</div>'
                       + '<div class="text-muted" style="font-size:.75rem">/shop/' + esc(p.data.slug) + '</div>';
              }},
            { headerName: '카테고리', field: 'category_name', width: 120,
              cellRenderer: function(p) {
                  return '<span class="small text-muted">' + esc(p.value || '—') + '</span>';
              }},
            { headerName: '가격', width: 130,
              valueGetter: function(p) { return p.data.discount_price || p.data.price; },
              cellRenderer: function(p) {
                  if (p.data.discount_price) {
                      return '<span class="text-decoration-line-through text-muted small">'
                           + p.data.price.toLocaleString() + '원</span><br>'
                           + '<span class="text-danger fw-semibold small">'
                           + p.data.discount_price.toLocaleString() + '원</span>';
                  }
                  return '<span class="small">' + p.data.price.toLocaleString() + '원</span>';
              }},
            { headerName: '재고', field: 'stock', width: 100, editable: true, singleClickEdit: true,
              cellStyle: function(p) {
                  return p.data.is_low_stock ? {color: '#dc3545', fontWeight: '600'} : {};
              },
              valueFormatter: function(p) { return typeof p.value === 'number' ? p.value.toLocaleString() : p.value; },
              valueSetter: function(p) {
                  var newStock = parseInt(p.newValue, 10);
                  if (isNaN(newStock) || newStock < 0) return false;
                  var oldStock       = p.data.stock;
                  var oldLow         = p.data.is_low_stock;
                  p.data.stock       = newStock;
                  p.data.is_low_stock = newStock <= stockThreshold ? 1 : 0;
                  fetch('/admin/products/' + p.data.id + '/stock', {
                      method: 'POST',
                      headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
                      body: csrf.name + '=' + encodeURIComponent(csrf.token) + '&stock=' + newStock,
                  })
                  .then(function(r) { return r.json(); })
                  .then(function(data) {
                      if (data.success) {
                          p.data.stock        = data.stock;
                          p.data.is_low_stock = data.stock <= stockThreshold ? 1 : 0;
                          if (data.csrf_hash) csrf.token = data.csrf_hash;
                      } else {
                          p.data.stock        = oldStock;
                          p.data.is_low_stock = oldLow;
                      }
                      p.api.refreshCells({ rowNodes: [p.node], columns: ['stock'], force: true });
                  });
                  return true;
              }},
            { headerName: '상태', field: 'status', width: 100,
              cellRenderer: function(p) {
                  var cls = STATUS_BADGE[p.value] || 'secondary';
                  return '<span class="badge bg-' + cls + '">' + esc(STATUSES[p.value] || p.value) + '</span>';
              }},
            { headerName: '등록일', field: 'created_at', width: 110,
              cellRenderer: function(p) {
                  if (!p.value) return '';
                  return '<span class="small text-muted">'
                       + new Date(p.value.replace(' ', 'T')).toLocaleDateString('ko-KR') + '</span>';
              },
              comparator: function(a, b) { return a < b ? -1 : a > b ? 1 : 0; }},
            { headerName: '기획전', field: 'is_featured', width: 90, sortable: true, filter: false, resizable: false,
              cellStyle: { display: 'flex', alignItems: 'center', justifyContent: 'center' },
              cellRenderer: function(p) {
                  var on = p.value == 1;
                  return '<button class="btn btn-sm ' + (on ? 'btn-danger' : 'btn-outline-secondary') + '"'
                       + ' onclick="toggleFeatured(' + p.data.id + ', this)" title="기획전 토글">'
                       + (on ? '<i class=\'bi bi-star-fill\'></i>' : '<i class=\'bi bi-star\'></i>') + '</button>';
              }},
            { headerName: '', width: 155, sortable: false, filter: false, resizable: false,
              cellStyle: { display: 'flex', alignItems: 'center', gap: '4px' },
              cellRenderer: function(p) {
                  return '<a href="/admin/products/' + p.data.id + '/edit" class="btn btn-sm btn-outline-secondary">수정</a>'
                       + '<button class="btn btn-sm btn-outline-danger" onclick="doDelete(' + p.data.id + ')">삭제</button>';
              }},
        ],
        defaultColDef: { sortable: true, filter: true, resizable: true },
        rowSelection: 'multiple',
        rowHeight: 60,
        pagination: true,
        paginationPageSize: 20,
        paginationPageSizeSelector: [20, 50, 100],
        suppressRowClickSelection: true,
        isExternalFilterPresent: function() { return statusFilterVal !== '' || lowStockOnly; },
        doesExternalFilterPass: function(node) {
            if (statusFilterVal && node.data.status !== statusFilterVal) return false;
            if (lowStockOnly && !node.data.is_low_stock) return false;
            return true;
        },
        onSelectionChanged: function(e) {
            var sel = e.api.getSelectedRows();
            document.getElementById('bulkBar').classList.toggle('d-none', sel.length === 0);
            document.getElementById('bulkCount').textContent = sel.length;
        },
        onModelUpdated: function(e) {
            document.getElementById('rowCount').textContent = '총 ' + e.api.getDisplayedRowCount().toLocaleString() + '개 상품';
        },
        onGridReady: function(e) {
            fetch('/admin/products/json')
                .then(function(r) { return r.json(); })
                .then(function(d) { e.api.setGridOption('rowData', d.data); });
        },
        localeText: { noRowsToShow: '등록된 상품이 없습니다.' },
    });

    document.getElementById('quickFilter').addEventListener('input', function() {
        grid.setGridOption('quickFilterText', this.value);
    });

    document.getElementById('statusFilter').addEventListener('change', function() {
        statusFilterVal = this.value;
        grid.onFilterChanged();
    });

    document.getElementById('btnLowStock').addEventListener('click', function() {
        lowStockOnly = !lowStockOnly;
        this.classList.toggle('btn-danger', lowStockOnly);
        this.classList.toggle('btn-outline-danger', !lowStockOnly);
        grid.onFilterChanged();
    });

    window.doDelete = function(id) {
        if (!confirm('상품을 삭제하시겠습니까?')) return;
        fetch('/admin/products/' + id + '/delete', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
            body: csrf.name + '=' + encodeURIComponent(csrf.token),
        }).then(function() { location.reload(); });
    };

    window.toggleFeatured = function(id, btn) {
        fetch('/admin/products/' + id + '/featured', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
            body: csrf.name + '=' + encodeURIComponent(csrf.token),
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) return;
            var on = data.is_featured == 1;
            btn.className = 'btn btn-sm ' + (on ? 'btn-danger' : 'btn-outline-secondary');
            btn.innerHTML = on ? '<i class="bi bi-star-fill"></i>' : '<i class="bi bi-star"></i>';
        });
    };

    window.clearSelection = function() { grid.deselectAll(); };

    window.submitBulk = function(action) {
        var selected = grid.getSelectedRows();
        if (!selected.length) { alert('상품을 선택해주세요.'); return; }

        if (action === 'status') {
            document.getElementById('bulkStatusVal').value = document.getElementById('bulkStatusSel').value;
        }
        if (action === 'stock') {
            var s = document.getElementById('bulkStockInput').value.trim();
            if (s === '' || parseInt(s, 10) < 0) { alert('재고 수량을 올바르게 입력해주세요.'); return; }
            document.getElementById('bulkStockVal').value = s;
        }
        if (action === 'price_discount') {
            var dtype = document.getElementById('discountTypeSel').value;
            document.getElementById('bulkDiscountType').value = dtype;
            if (dtype !== 'clear') {
                var dval = document.getElementById('discountValueInput').value.trim();
                if (dval === '' || parseInt(dval, 10) < 0) { alert('할인 값을 입력해주세요.'); return; }
                document.getElementById('bulkDiscountVal').value = dval;
            }
            if (!confirm(selected.length + '개 상품 할인가를 ' + (dtype === 'clear' ? '초기화' : '변경') + '하시겠습니까?')) return;
        }
        if (action === 'delete') {
            if (!confirm(selected.length + '개 상품을 삭제하시겠습니까?')) return;
        }

        var form = document.getElementById('bulkForm');
        document.getElementById('bulkAction').value = action;
        form.querySelectorAll('input[name="ids[]"]').forEach(function(el) { el.remove(); });
        selected.forEach(function(row) {
            var inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = row.id;
            form.appendChild(inp);
        });
        form.submit();
    };
}());
</script>
<?= $this->endSection() ?>
