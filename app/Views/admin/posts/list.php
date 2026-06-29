<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '전체 게시물 관리' ?>

<?= $this->section('content') ?>

<div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
    <input id="quickFilter" type="text" class="form-control form-control-sm"
           placeholder="제목 / 작성자 검색" style="width:220px">
    <select id="boardFilter" class="form-select form-select-sm" style="width:160px">
        <option value="">전체 게시판</option>
        <?php foreach ($boards as $b): ?>
        <option value="<?= esc($b['name']) ?>"><?= esc($b['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <div id="rowCount" class="ms-auto text-muted small"></div>
</div>

<div id="postsGrid" class="ag-theme-alpine" style="height:620px"></div>

<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ag-grid-community@31.3.4/styles/ag-grid.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ag-grid-community@31.3.4/styles/ag-theme-alpine.css">
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script src="https://cdn.jsdelivr.net/npm/ag-grid-community@31.3.4/dist/ag-grid-community.noStyle.min.js"></script>
<script>
(function () {
    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    var boardFilterVal = '';
    var csrf = {
        name:  document.querySelector('meta[name="csrf-name"]').content,
        token: document.querySelector('meta[name="csrf-token"]').content,
    };

    var grid = agGrid.createGrid(document.getElementById('postsGrid'), {
        columnDefs: [
            { headerName: 'ID', field: 'id', width: 80,
              cellRenderer: function(p) { return '<span class="text-muted small">' + p.value + '</span>'; }},
            { headerName: '게시판', field: 'board_name', width: 130,
              cellRenderer: function(p) {
                  return '<span class="badge bg-light text-dark border">' + esc(p.value || '') + '</span>';
              }},
            { headerName: '제목', field: 'title', flex: 1, minWidth: 250,
              cellRenderer: function(p) {
                  var badges = '';
                  if (p.data.is_notice) badges += '<span class="badge bg-warning text-dark me-1">공지</span>';
                  if (p.data.is_secret) badges += '<span class="badge bg-secondary me-1">비밀</span>';
                  var url = '/board/' + esc(p.data.board_slug) + '/' + p.data.id;
                  return badges + '<a href="' + url + '" target="_blank" '
                       + 'class="text-decoration-none text-dark small">' + esc(p.value) + '</a>';
              }},
            { headerName: '작성자', field: 'author', width: 120,
              cellRenderer: function(p) { return '<span class="small">' + esc(p.value || '') + '</span>'; }},
            { headerName: '조회', field: 'views', width: 80, type: 'numericColumn',
              cellRenderer: function(p) {
                  return '<span class="small text-muted">' + p.value.toLocaleString() + '</span>';
              }},
            { headerName: '작성일', field: 'created_at', width: 110,
              cellRenderer: function(p) {
                  if (!p.value) return '';
                  return '<span class="small text-muted">'
                       + new Date(p.value.replace(' ', 'T')).toLocaleDateString('ko-KR') + '</span>';
              },
              comparator: function(a, b) { return a < b ? -1 : a > b ? 1 : 0; }},
            { headerName: '', width: 90, sortable: false, filter: false, resizable: false,
              cellStyle: { display: 'flex', alignItems: 'center', justifyContent: 'center' },
              cellRenderer: function(p) {
                  return '<button class="btn btn-sm btn-outline-danger" onclick="doDelete(' + p.data.id + ')">삭제</button>';
              }},
        ],
        defaultColDef: { sortable: true, filter: true, resizable: true },
        rowHeight: 46,
        pagination: true,
        paginationPageSize: 20,
        paginationPageSizeSelector: [20, 50, 100],
        isExternalFilterPresent: function() { return boardFilterVal !== ''; },
        doesExternalFilterPass: function(node) { return node.data.board_name === boardFilterVal; },
        onModelUpdated: function(e) {
            document.getElementById('rowCount').textContent = '총 ' + e.api.getDisplayedRowCount().toLocaleString() + '건';
        },
        onGridReady: function(e) {
            fetch('/admin/posts/json')
                .then(function(r) { return r.json(); })
                .then(function(d) { e.api.setGridOption('rowData', d.data); });
        },
        localeText: { noRowsToShow: '게시물이 없습니다.' },
    });

    document.getElementById('quickFilter').addEventListener('input', function() {
        grid.setGridOption('quickFilterText', this.value);
    });

    document.getElementById('boardFilter').addEventListener('change', function() {
        boardFilterVal = this.value;
        grid.onFilterChanged();
    });

    window.doDelete = function(id) {
        if (!confirm('정말 삭제하시겠습니까?')) return;
        fetch('/admin/posts/' + id + '/delete', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
            body: csrf.name + '=' + encodeURIComponent(csrf.token),
        }).then(function() { location.reload(); });
    };
}());
</script>
<?= $this->endSection() ?>
