<style>
  :root{
    --brand:#2563EB;
    --brand-hover:#1D4ED8;
    --accent:#6D5EF6;
    --text-strong:#0F172A;
    --text-body:#334155;
    --text-muted:#64748B;
    --border-default:#E2E8F0;
    --font-sans:'Noto Sans KR',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
    --font-display:'Space Grotesk','Noto Sans KR',sans-serif;
  }
  *{box-sizing:border-box}
  html,body{height:100%}
  body{
    margin:0;
    display:flex;
    align-items:center;
    justify-content:center;
    font-family:var(--font-sans);
    color:var(--text-body);
    background:linear-gradient(165deg,#ffffff 0%,#eff5ff 55%,#e6e4fd 100%);
    -webkit-font-smoothing:antialiased;
  }
  a{text-decoration:none}

  .error-page{width:100%;padding:24px}
  .error-card{
    max-width:520px;
    margin:0 auto;
    text-align:center;
  }
  .error-logo{display:inline-flex;align-items:center;gap:8px;margin-bottom:40px}
  .error-logo span{font-family:var(--font-display);font-size:18px;font-weight:700;color:var(--text-strong)}
  .error-logo span b{color:var(--brand);font-weight:700}

  .error-code{
    font-family:var(--font-display);
    font-size:clamp(64px,14vw,104px);
    font-weight:700;
    line-height:1;
    letter-spacing:-.03em;
    margin:0 0 16px;
    background:linear-gradient(135deg,var(--brand) 0%,var(--accent) 100%);
    -webkit-background-clip:text;
    background-clip:text;
    color:transparent;
  }
  .error-heading{
    font-family:var(--font-display);
    font-size:clamp(20px,3.4vw,26px);
    font-weight:700;
    color:var(--text-strong);
    letter-spacing:-.02em;
    margin:0 0 12px;
  }
  .error-message{
    font-size:15px;
    line-height:1.65;
    color:var(--text-muted);
    margin:0 0 32px;
  }

  .error-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
  .btn{
    display:inline-flex;align-items:center;gap:8px;
    font-family:var(--font-sans);font-weight:700;font-size:15px;
    padding:12px 22px;border-radius:6px;border:1px solid transparent;
    cursor:pointer;transition:background .15s,color .15s,border-color .15s;
  }
  .btn-primary{background:var(--brand);color:#fff}
  .btn-primary:hover{background:var(--brand-hover)}
  .btn-outline{background:#fff;color:var(--brand);border-color:var(--border-default)}
  .btn-outline:hover{background:var(--brand);color:#fff;border-color:var(--brand)}

  .error-footer{margin-top:48px;font-size:12px;color:var(--text-muted)}
</style>
