<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '회원 관리' ?>

<?= $this->section('content') ?>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <input id="quickFilter" type="text" class="form-control form-control-sm" style="max-width:220px"
           placeholder="닉네임 / 이메일 / 휴대폰 검색">
    <select id="roleFilter" class="form-select form-select-sm" style="width:auto">
        <option value="">전체 역할</option>
        <option value="admin">관리자</option>
        <option value="member">일반회원</option>
    </select>
    <select id="gradeFilter" class="form-select form-select-sm" style="width:auto">
        <option value="">전체 등급</option>
        <option value="bronze">브론즈</option>
        <option value="silver">실버</option>
        <option value="gold">골드</option>
        <option value="platinum">플래티넘</option>
    </select>
    <select id="statusFilter" class="form-select form-select-sm" style="width:auto">
        <option value="">전체 상태</option>
        <option value="active">활성</option>
        <option value="unverified">이메일 미인증</option>
        <option value="inactive">비활성</option>
    </select>
    <input id="fromFilter" type="date" class="form-control form-control-sm" style="width:auto" title="가입일 시작">
    <input id="toFilter"   type="date" class="form-control form-control-sm" style="width:auto" title="가입일 종료">
    <button id="resetBtn" class="btn btn-outline-secondary btn-sm">초기화</button>
    <button id="exportBtn" class="btn btn-outline-success btn-sm ms-auto">
        <i class="bi bi-file-earmark-excel me-1"></i>엑셀 내보내기
    </button>
    <span id="rowCount" class="text-muted small">불러오는 중…</span>
</div>

<div id="userGrid" class="ag-theme-alpine rounded border" style="height:640px"></div>

<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ag-grid-community@31.3.4/styles/ag-grid.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ag-grid-community@31.3.4/styles/ag-theme-alpine.css">
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script src="https://cdn.jsdelivr.net/npm/ag-grid-community@31.3.4/dist/ag-grid-community.min.js"></script>

<style>
.ag-theme-alpine {
    --ag-font-size: 13px;
    --ag-row-height: 44px;
    --ag-header-height: 40px;
    --ag-header-background-color: #f8f9fa;
    --ag-row-hover-color: #f0f4f8;
    --ag-border-color: #dee2e6;
}
.ag-theme-alpine .ag-paging-panel { font-size: 13px; }
.ag-theme-alpine .ag-cell { display: flex; align-items: center; }
</style>

<script>
const CSRF_NAME  = document.querySelector('meta[name="csrf-name"]').content;
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;

const GRADES = {
    bronze:   { cls: 'badge-bronze',         icon: 'bi-award',      label: '브론즈' },
    silver:   { cls: 'badge-silver',         icon: 'bi-award-fill', label: '실버' },
    gold:     { cls: 'bg-warning text-dark', icon: 'bi-trophy',     label: '골드' },
    platinum: { cls: 'bg-primary',           icon: 'bi-gem',        label: '플래티넘' },
};

const columnDefs = [
    {
        field: 'id', headerName: 'ID', width: 70,
        filter: 'agNumberColumnFilter', sortingOrder: ['desc', 'asc'],
    },
    { field: 'nickname', headerName: '닉네임', flex: 1,   minWidth: 100, filter: 'agTextColumnFilter' },
    { field: 'email',    headerName: '이메일',  flex: 2,   minWidth: 160, filter: 'agTextColumnFilter' },
    {
        field: 'phone', headerName: '휴대폰', width: 140,
        filter: 'agTextColumnFilter',
        valueFormatter: p => p.value || '-',
    },
    {
        field: 'role', headerName: '역할', width: 150,
        filter: false,
        cellRenderer: p => {
            const badge = p.data.role === 'admin'
                ? '<span class="badge bg-danger">관리자</span>'
                : '<span class="badge bg-secondary">일반</span>';
            const social = p.data.social_provider
                ? ` <span class="badge bg-info text-dark">${p.data.social_provider}</span>`
                : '';
            return badge + social;
        },
    },
    {
        field: 'grade', headerName: '등급', width: 110,
        filter: false,
        cellRenderer: p => {
            const g = GRADES[p.value] || GRADES.bronze;
            return `<span class="badge ${g.cls}"><i class="bi ${g.icon} me-1"></i>${g.label}</span>`;
        },
    },
    {
        field: 'is_active', headerName: '상태', width: 130,
        filter: false,
        cellRenderer: p => {
            if (p.data.is_active)          return '<span class="badge bg-success">활성</span>';
            if (p.data.email_verify_token) return '<span class="badge bg-warning text-dark">이메일 미인증</span>';
            return '<span class="badge bg-secondary">비활성</span>';
        },
    },
    {
        field: 'created_at', headerName: '가입일', width: 100,
        filter: 'agDateColumnFilter',
        valueFormatter: p => p.value ? p.value.substring(0, 10) : '-',
    },
    {
        field: 'last_login', headerName: '최근 로그인', width: 115,
        filter: 'agDateColumnFilter',
        valueFormatter: p => p.value ? p.value.substring(0, 10) : '-',
    },
    {
        headerName: '관리', width: 195, sortable: false, filter: false, resizable: false,
        cellRenderer: p => {
            const id  = p.data.id;
            const unv = !p.data.is_active && p.data.email_verify_token;
            let html  = `<a href="/admin/users/${id}/edit" class="btn btn-sm btn-outline-secondary py-0">수정</a> `;
            if (unv) html += `<button class="btn btn-sm btn-outline-warning py-0" onclick="doResend(${id})">재발송</button> `;
            html += `<button class="btn btn-sm btn-outline-danger py-0" onclick="doDelete(${id})">삭제</button>`;
            return html;
        },
    },
];

let gridApi;

const gridOptions = {
    columnDefs,
    rowData: [],
    pagination: true,
    paginationPageSize: 20,
    paginationPageSizeSelector: [20, 50, 100],
    defaultColDef: { sortable: true, resizable: true, filter: true },
    rowHeight: 44,
    suppressMovableColumns: false,
    isExternalFilterPresent: () =>
        document.getElementById('roleFilter').value   !== '' ||
        document.getElementById('gradeFilter').value  !== '' ||
        document.getElementById('statusFilter').value !== '' ||
        document.getElementById('fromFilter').value   !== '' ||
        document.getElementById('toFilter').value     !== '',
    doesExternalFilterPass: node => {
        const role   = document.getElementById('roleFilter').value;
        const grade  = document.getElementById('gradeFilter').value;
        const status = document.getElementById('statusFilter').value;
        const from   = document.getElementById('fromFilter').value;
        const to     = document.getElementById('toFilter').value;
        const d = node.data;
        if (role  && d.role  !== role)  return false;
        if (grade && d.grade !== grade) return false;
        if (status === 'active'     && !d.is_active)                           return false;
        if (status === 'unverified' && (d.is_active || !d.email_verify_token)) return false;
        if (status === 'inactive'   && (d.is_active ||  d.email_verify_token)) return false;
        if (from && d.created_at && d.created_at.substring(0, 10) < from) return false;
        if (to   && d.created_at && d.created_at.substring(0, 10) > to)   return false;
        return true;
    },
    onGridReady: params => {
        gridApi = params.api;
        loadData();
    },
    onFilterChanged:     updateCount,
    onPaginationChanged: updateCount,
};

agGrid.createGrid(document.getElementById('userGrid'), gridOptions);

function loadData() {
    fetch('/admin/users/json')
        .then(r => r.json())
        .then(({ data }) => {
            gridApi.setGridOption('rowData', data);
            updateCount();
        });
}

function updateCount() {
    if (!gridApi) return;
    const n = gridApi.getDisplayedRowCount();
    document.getElementById('rowCount').textContent = '총 ' + n.toLocaleString() + '명';
}

document.getElementById('quickFilter').addEventListener('input',  e => gridApi.setGridOption('quickFilterText', e.target.value));
document.getElementById('roleFilter').addEventListener('change',   () => gridApi.onFilterChanged());
document.getElementById('gradeFilter').addEventListener('change',  () => gridApi.onFilterChanged());
document.getElementById('statusFilter').addEventListener('change', () => gridApi.onFilterChanged());
document.getElementById('fromFilter').addEventListener('change',   () => gridApi.onFilterChanged());
document.getElementById('toFilter').addEventListener('change',     () => gridApi.onFilterChanged());

document.getElementById('resetBtn').addEventListener('click', () => {
    ['quickFilter','roleFilter','gradeFilter','statusFilter','fromFilter','toFilter']
        .forEach(id => document.getElementById(id).value = '');
    gridApi.setGridOption('quickFilterText', '');
    gridApi.setFilterModel(null);
    gridApi.onFilterChanged();
});

document.getElementById('exportBtn').addEventListener('click', () => {
    const params = new URLSearchParams({
        q:      document.getElementById('quickFilter').value,
        role:   document.getElementById('roleFilter').value,
        grade:  document.getElementById('gradeFilter').value,
        status: document.getElementById('statusFilter').value,
        from:   document.getElementById('fromFilter').value,
        to:     document.getElementById('toFilter').value,
    });
    window.location.href = '/admin/users/export?' + params.toString();
});

async function doDelete(id) {
    if (!confirm('정말 삭제하시겠습니까?')) return;
    await fetch(`/admin/users/${id}/delete`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `${encodeURIComponent(CSRF_NAME)}=${encodeURIComponent(CSRF_TOKEN)}`,
    });
    window.location.reload();
}

async function doResend(id) {
    await fetch(`/admin/users/${id}/resend-verify`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `${encodeURIComponent(CSRF_NAME)}=${encodeURIComponent(CSRF_TOKEN)}`,
    });
    alert('인증 메일을 재발송했습니다.');
}
</script>
<?= $this->endSection() ?>
