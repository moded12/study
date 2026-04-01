<?php
// /zaka/study/admin.php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/admin/bootstrap.php';
require_once __DIR__ . '/includes/functions_ai.php';
require_once __DIR__ . '/includes/questions_ai.php';

requireLogin();

// ─── Input ───────────────────────────────────────────────────────────────────
$selectedTab     = trim((string)($_GET['tab']               ?? 'dashboard'));
$search          = trim((string)($_GET['search']            ?? ''));
$statusFilter    = trim((string)($_GET['status']            ?? ''));
$linkFilter      = trim((string)($_GET['link_status']       ?? ''));
$resCatFilter    = trim((string)($_GET['response_category'] ?? ''));
$committeeFilter = trim((string)($_GET['committee_status']  ?? ''));
$suspicionFilter = trim((string)($_GET['suspicion_level']   ?? ''));
$responseView    = trim((string)($_GET['response_view']     ?? 'all'));

$allowedTabs  = ['dashboard','import','candidates','links','responses'];
$allowedViews = ['all','approved','rejected','reserve','waiting','suspicious'];
if (!in_array($selectedTab,  $allowedTabs,  true)) $selectedTab  = 'dashboard';
if (!in_array($responseView, $allowedViews, true)) $responseView = 'all';

// ─── Data ─────────────────────────────────────────────────────────────────────
$stats       = getStudyStats();
$candidates  = getStudyCandidates(500);
$links       = getStudyLinks(500);
$submissions = getStudySubmissions(500);

$submissionDetails = [];
foreach ($submissions as $row) {
    $submissionDetails[(int)$row['id']] = getSubmission((int)$row['id']) ?: $row;
}

// ─── Helpers ──────────────────────────────────────────────────────────────────
function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

function normalizeWaPhoneLocal(string $phone): string
{
    $phone = preg_replace('/\D+/', '', $phone);
    if ($phone === '') return '';
    if (str_starts_with($phone, '00'))   return substr($phone, 2);
    if (str_starts_with($phone, '0'))    return '962' . substr($phone, 1);
    if (!str_starts_with($phone, '962')) return '962' . $phone;
    return $phone;
}

function buildStudyWhatsAppMessageLocal(string $name, string $link): string
{
    return "مرحبًا {$name}\nهذا رابط نموذج دراسة الحالة:\n{$link}";
}

// Unified badge resolver — replaces 4 separate functions
function badgeClass(string $type, string $value): string
{
    return match ($type) {
        'committee' => match ($value) {
            'approved'        => 'badge-success',
            'rejected'        => 'badge-danger',
            'reserve'         => 'badge-purple',
            'waiting','review'=> 'badge-warning',
            default           => 'badge-secondary',
        },
        'category' => match ($value) {
            'high'   => 'badge-danger',
            'medium' => 'badge-warning',
            'review' => 'badge-purple',
            default  => 'badge-secondary',
        },
        'candidate' => match ($value) {
            'sent'     => 'badge-primary',
            'answered' => 'badge-success',
            default    => 'badge-secondary',
        },
        'link'  => $value === 'used' ? 'badge-success' : 'badge-primary',
        default => 'badge-secondary',
    };
}

function committeeLabelLocal(string $s): string
{
    return match ($s) {
        'approved' => 'موافقة', 'rejected' => 'رفض',
        'reserve'  => 'احتياط', 'waiting'  => 'انتظار',
        'review'   => 'مراجعة', 'pending'  => 'بانتظار الفرز',
        default    => 'غير محدد',
    };
}

function responseRiskInfoLocal(array $full): array
{
    $sus = (int)($full['suspicion_score'] ?? 0);
    $pov = (float)($full['poverty_percent'] ?? 0);
    if ($sus >= 35) return ['label' => 'اشتباه مرتفع',  'class' => 'badge-danger'];
    if ($sus >= 20) return ['label' => 'اشتباه متوسط',  'class' => 'badge-warning'];
    if ($pov >= 78) return ['label' => 'أولوية قصوى',   'class' => 'badge-danger'];
    if ($pov >= 58) return ['label' => 'أولوية مرتفعة', 'class' => 'badge-warning'];
    return ['label' => 'مستقر نسبيًا', 'class' => 'badge-secondary'];
}

// Single-loop metrics (replaces two separate loops)
function calcDashboardMetrics(array $submissions, array $details): array
{
    $n = count($submissions);
    $m = [
        'avg_poverty' => 0.0, 'avg_trust' => 0.0, 'avg_suspicion' => 0.0,
        'high_need_count' => 0, 'review_count' => 0, 'high_suspicion_count' => 0,
        'approved_count' => 0, 'rejected_count' => 0, 'reserve_count' => 0, 'waiting_count' => 0,
    ];
    foreach ($submissions as $row) {
        $f   = $details[(int)$row['id']] ?? $row;
        $cat = (string)($f['category']         ?? '');
        $com = (string)($f['committee_status'] ?? 'pending');
        $sus = (int)($f['suspicion_score']     ?? 0);
        $m['avg_poverty']   += (float)($f['poverty_percent'] ?? 0);
        $m['avg_trust']     += (float)($f['trust_score']     ?? 0);
        $m['avg_suspicion'] += (float)($f['suspicion_score'] ?? 0);
        if ($cat === 'high') $m['high_need_count']++;
        if ($cat === 'review' || in_array($com, ['review','waiting'], true)) $m['review_count']++;
        if ($sus >= 35) $m['high_suspicion_count']++;
        match ($com) {
            'approved' => $m['approved_count']++,
            'rejected' => $m['rejected_count']++,
            'reserve'  => $m['reserve_count']++,
            default    => $m['waiting_count']++,
        };
    }
    if ($n > 0) {
        $m['avg_poverty']   = round($m['avg_poverty']   / $n, 2);
        $m['avg_trust']     = round($m['avg_trust']     / $n, 2);
        $m['avg_suspicion'] = round($m['avg_suspicion'] / $n, 2);
    }
    return $m;
}

function recentTopSubmissions(array $submissions, array $details, int $limit = 6): array
{
    usort($submissions, function ($a, $b) use ($details) {
        $aF = $details[(int)$a['id']] ?? $a;
        $bF = $details[(int)$b['id']] ?? $b;
        $aS = (float)($aF['poverty_percent'] ?? 0) + ((int)($aF['suspicion_score'] ?? 0) / 5);
        $bS = (float)($bF['poverty_percent'] ?? 0) + ((int)($bF['suspicion_score'] ?? 0) / 5);
        return $bS <=> $aS;
    });
    return array_slice($submissions, 0, $limit);
}

function responseMatchesViewLocal(array $row, string $view): bool
{
    $com = (string)($row['committee_status'] ?? 'pending');
    $sus = (int)($row['suspicion_score']     ?? 0);
    return match ($view) {
        'approved'   => $com === 'approved',
        'rejected'   => $com === 'rejected',
        'reserve'    => $com === 'reserve',
        'waiting'    => in_array($com, ['pending','review','waiting'], true),
        'suspicious' => $sus >= 35,
        default      => true,
    };
}

function countByView(array $subs, array $details, string $view): int
{
    $c = 0;
    foreach ($subs as $row) {
        if (responseMatchesViewLocal($details[(int)$row['id']] ?? $row, $view)) $c++;
    }
    return $c;
}

// ─── Filtering ────────────────────────────────────────────────────────────────
if ($search !== '') {
    $candidates  = array_values(array_filter($candidates, fn($r) =>
        mb_stripos((string)$r['full_name'], $search) !== false ||
        mb_stripos((string)$r['phone'],     $search) !== false));
    $links = array_values(array_filter($links, fn($r) =>
        mb_stripos((string)$r['full_name'], $search) !== false ||
        mb_stripos((string)$r['phone'],     $search) !== false ||
        mb_stripos((string)$r['token'],     $search) !== false));
    $submissions = array_values(array_filter($submissions, fn($r) =>
        mb_stripos((string)$r['full_name'], $search) !== false ||
        mb_stripos((string)$r['phone'],     $search) !== false ||
        mb_stripos((string)$r['id'],        $search) !== false));
}

if ($statusFilter    !== '') $candidates  = array_values(array_filter($candidates,  fn($r) => (string)$r['status'] === $statusFilter));
if ($linkFilter === 'used')   $links = array_values(array_filter($links, fn($r) => (int)$r['is_used'] === 1));
if ($linkFilter === 'active') $links = array_values(array_filter($links, fn($r) => (int)$r['is_used'] === 0));
if ($resCatFilter    !== '') $submissions = array_values(array_filter($submissions, fn($r) => (string)$r['category'] === $resCatFilter));
if ($committeeFilter !== '') $submissions = array_values(array_filter($submissions, fn($r) => (string)$r['committee_status'] === $committeeFilter));

if ($suspicionFilter !== '') {
    $submissions = array_values(array_filter($submissions, function ($r) use ($submissionDetails, $suspicionFilter) {
        $s = (int)(($submissionDetails[(int)$r['id']] ?? $r)['suspicion_score'] ?? 0);
        return match ($suspicionFilter) {
            'high'   => $s >= 35,
            'medium' => $s >= 20 && $s < 35,
            'low'    => $s < 20,
            default  => true,
        };
    }));
}

$submissions = array_values(array_filter($submissions, fn($r) =>
    responseMatchesViewLocal($submissionDetails[(int)$r['id']] ?? $r, $responseView)));

// ─── Computed ─────────────────────────────────────────────────────────────────
$dashboardMetrics    = calcDashboardMetrics($submissions, $submissionDetails);
$prioritySubmissions = recentTopSubmissions($submissions, $submissionDetails, 6);

// View counts — one clean pass, no duplicate block
$allRaw     = getStudySubmissions(500);
$allDetails = [];
foreach ($allRaw as $r) $allDetails[(int)$r['id']] = getSubmission((int)$r['id']) ?: $r;

$viewCounts = [];
foreach (['all','approved','rejected','reserve','waiting','suspicious'] as $v) {
    $viewCounts[$v] = countByView($allRaw, $allDetails, $v);
}

// Progress bar %
$progressPct = $stats['candidates'] > 0
    ? min(100, round(($stats['submissions'] / $stats['candidates']) * 100))
    : 0;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>إدارة دراسة الحالة</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ── Variables ── */
:root {
    --primary:#2563eb; --primary-hover:#1d4ed8;
    --success:#059669; --warning:#d97706;
    --danger:#dc2626;  --purple:#7c3aed;
    --bg-body:#f1f5f9; --card-bg:#ffffff;
    --text-main:#1e293b; --text-muted:#64748b;
    --border-color:#e2e8f0;
    --radius:16px;
    --shadow:0 4px 20px rgba(15,23,42,.07);
    --sidebar-w:270px;
}
@media(prefers-color-scheme:dark){
    :root{
        --bg-body:#0f172a; --card-bg:#1e293b;
        --text-main:#f1f5f9; --text-muted:#94a3b8;
        --border-color:#334155;
        --shadow:0 4px 20px rgba(0,0,0,.3);
    }
    .sidebar{background:linear-gradient(180deg,#020617,#0f172a)!important;}
    .stat-card,.section,.response-card,.priority-item,.quick-item{background:#1e293b!important;}
    th{background:#0f172a!important;}
    .mini-card,.response-kpi,.copy-box{background:#0f172a!important;}
    .response-view-tab:not(.active){background:#1e293b!important;color:#94a3b8!important;}
    tr:nth-child(even) td{background:rgba(255,255,255,.02)!important;}
    tr:hover td{background:rgba(37,99,235,.08)!important;}
    input,select,textarea{background:#0f172a!important;color:var(--text-main)!important;}
    .btn-light{background:#334155!important;color:#cbd5e1!important;}
    .export-btn{background:#334155!important;color:#cbd5e1!important;border-color:#475569!important;}
}

/* ── Reset ── */
*{box-sizing:border-box;outline:none;}
body{margin:0;font-family:'IBM Plex Sans Arabic',sans-serif;background:var(--bg-body);color:var(--text-main);line-height:1.65;}
a{text-decoration:none;color:inherit;}

/* ── Layout ── */
.layout{display:grid;grid-template-columns:var(--sidebar-w) 1fr;min-height:100vh;}
.content{padding:2rem;overflow-x:hidden;}

/* ── Sidebar ── */
.sidebar{
    background:linear-gradient(180deg,#0f172a,#111827);
    color:#fff;padding:1.75rem 1.1rem;
    position:sticky;top:0;height:100vh;
    display:flex;flex-direction:column;overflow-y:auto;
}
.brand{font-size:1.35rem;font-weight:800;color:#fff;margin-bottom:.25rem;display:flex;align-items:center;gap:8px;}
.brand-sub{font-size:.8rem;color:#94a3b8;margin-bottom:1.5rem;}
.nav{display:flex;flex-direction:column;gap:.4rem;flex-grow:1;}
.nav a{display:flex;align-items:center;gap:.65rem;padding:.8rem .95rem;border-radius:12px;color:#cbd5e1;transition:all .2s;font-size:.9rem;font-weight:500;}
.nav a:hover{background:rgba(255,255,255,.06);color:#fff;}
.nav a.active{background:var(--primary);color:#fff;box-shadow:0 8px 20px rgba(37,99,235,.3);}
.sidebar-card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);padding:.85rem;border-radius:12px;margin-top:.7rem;}
.sidebar-card .title{font-size:.7rem;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;}
.sidebar-card .value{font-size:1.15rem;font-weight:800;color:#fff;margin-top:.15rem;}

/* ── Page header ── */
.page-header{display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:1.5rem;gap:1rem;flex-wrap:wrap;}
.page-title{font-size:1.8rem;font-weight:800;margin:0;}
.page-subtitle{color:var(--text-muted);font-size:.93rem;margin-top:.3rem;}

/* ── Section ── */
.section{background:var(--card-bg);border-radius:var(--radius);padding:1.25rem;margin-bottom:1.4rem;box-shadow:var(--shadow);border:1px solid var(--border-color);}
.section-header{display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;border-bottom:1px solid var(--border-color);padding-bottom:.8rem;}
.section-header h2{font-size:1.1rem;margin:0;font-weight:800;}
.section-note{color:var(--text-muted);font-size:.85rem;}

/* ── Stats ── */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:.9rem;margin-bottom:1.4rem;}
.stat-card{background:var(--card-bg);padding:1.15rem;border-radius:var(--radius);box-shadow:var(--shadow);border:1px solid var(--border-color);transition:transform .2s;}
.stat-card:hover{transform:translateY(-3px);}
.stat-label{color:var(--text-muted);font-size:.85rem;font-weight:600;}
.stat-value{font-size:1.9rem;font-weight:800;color:var(--primary);display:block;margin-top:.2rem;}
.stat-sub{color:var(--text-muted);font-size:.78rem;margin-top:.3rem;}

/* ── Progress bar ── */
.progress-bar-bg{background:var(--border-color);border-radius:999px;height:8px;overflow:hidden;}
.progress-bar-fill{height:100%;border-radius:999px;background:linear-gradient(90deg,var(--primary),#60a5fa);transition:width .7s ease;}

/* ── Hero/mini ── */
.hero-grid{display:grid;grid-template-columns:1.4fr .9fr;gap:1rem;}
.mini-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:.8rem;}
.mini-card{background:var(--bg-body);border:1px solid var(--border-color);border-radius:12px;padding:.9rem;}

/* ── Quick actions ── */
.quick-actions{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;}
.quick-item{background:var(--card-bg);border:1px dashed var(--border-color);padding:1.2rem;border-radius:var(--radius);text-align:center;}
.quick-item h4{margin:0 0 .4rem;font-size:.95rem;}
.quick-item p{margin:0 0 .9rem;color:var(--text-muted);font-size:.85rem;}

/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;padding:.65rem .95rem;border-radius:11px;font-weight:700;font-size:.88rem;cursor:pointer;transition:all .2s;border:none;font-family:inherit;}
.btn:disabled{opacity:.55;cursor:not-allowed;}
.btn-primary{background:var(--primary);color:#fff;} .btn-primary:hover{background:var(--primary-hover);}
.btn-success{background:var(--success);color:#fff;}
.btn-warning{background:var(--warning);color:#fff;}
.btn-danger{background:var(--danger);color:#fff;}
.btn-purple{background:var(--purple);color:#fff;}
.btn-light{background:#f1f5f9;color:#475569;} .btn-light:hover{background:#e2e8f0;}
.btn-whatsapp{background:#25D366;color:#fff;} .btn-whatsapp:hover{background:#1ebe5d;}
.btn-xs{padding:.45rem .65rem;font-size:.78rem;border-radius:9px;}

/* Loading spinner inside btn */
.btn .spinner{display:none;width:13px;height:13px;border:2px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:spin .55s linear infinite;flex-shrink:0;}
.btn.loading .spinner{display:inline-block;}
.btn.loading .btn-label{opacity:0;}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── Inputs ── */
input,select,textarea{width:100%;padding:.75rem .95rem;border:1px solid var(--border-color);border-radius:11px;font-family:inherit;background:var(--card-bg);color:var(--text-main);transition:border .2s;}
input:focus,select:focus,textarea:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(37,99,235,.1);}

/* ── Filters ── */
.filters-row{display:grid;grid-template-columns:2fr 1fr 1fr;gap:.85rem;}
.filters-row.responses{grid-template-columns:2fr 1fr 1fr 1fr;}

/* ── View tabs ── */
.response-view-tabs{display:flex;gap:.6rem;flex-wrap:wrap;margin-bottom:1rem;}
.response-view-tab{display:inline-flex;align-items:center;gap:.4rem;padding:.65rem .9rem;border-radius:999px;background:#f1f5f9;color:#334155;font-weight:700;font-size:.83rem;border:1px solid var(--border-color);transition:all .2s;}
.response-view-tab.active{background:var(--primary);color:#fff;border-color:var(--primary);}

/* ── Badges ── */
.badge{padding:4px 11px;border-radius:999px;font-size:.73rem;font-weight:800;display:inline-block;}
.badge-primary{background:#dbeafe;color:#1e40af;}
.badge-success{background:#dcfce7;color:#166534;}
.badge-warning{background:#fef3c7;color:#92400e;}
.badge-danger{background:#fee2e2;color:#991b1b;}
.badge-secondary{background:#e2e8f0;color:#334155;}
.badge-purple{background:#ede9fe;color:#6d28d9;}

/* ── Copy box ── */
.copy-box{font-family:monospace;background:var(--bg-body);padding:.55rem .7rem;border-radius:9px;font-size:.78rem;color:#475569;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;border:1px solid var(--border-color);}

/* ── Table ── */
.table-container{overflow-x:auto;margin:0 -1.25rem;padding:0 1.25rem;}
table{width:100%;border-collapse:collapse;min-width:900px;}
th{background:var(--bg-body);padding:.9rem;text-align:right;font-size:.8rem;color:var(--text-muted);font-weight:700;border-bottom:2px solid var(--border-color);position:sticky;top:0;z-index:1;}
th.sortable{cursor:pointer;user-select:none;}
th.sortable:hover{color:var(--primary);}
th.sortable::after{content:' ⇅';font-size:.68rem;opacity:.45;}
th.sort-asc::after{content:' ▲';opacity:1;color:var(--primary);}
th.sort-desc::after{content:' ▼';opacity:1;color:var(--primary);}
td{padding:.9rem;font-size:.9rem;border-bottom:1px solid var(--border-color);vertical-align:middle;}
tr:nth-child(even) td{background:rgba(241,245,249,.6);}
tr:hover td{background:rgba(37,99,235,.04);}

/* ── Response cards ── */
.response-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:1rem;}
.response-card{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);padding:1.15rem;transition:all .2s;box-shadow:var(--shadow);position:relative;}
.response-card:hover{transform:translateY(-2px);box-shadow:0 8px 30px rgba(15,23,42,.1);}
.response-card.is-suspicious{border-color:rgba(220,38,38,.3);box-shadow:0 6px 24px rgba(220,38,38,.1);}
.suspect-ribbon{position:absolute;top:12px;left:12px;background:#fee2e2;color:#991b1b;padding:3px 9px;border-radius:999px;font-size:.7rem;font-weight:800;}
.response-card h3{margin:0 0 .4rem;font-size:1rem;}
.response-meta{color:var(--text-muted);font-size:.85rem;margin-bottom:.85rem;}
.response-kpis{display:grid;grid-template-columns:repeat(2,1fr);gap:.6rem;margin:.75rem 0 .9rem;}
.response-kpi{background:var(--bg-body);border:1px solid var(--border-color);border-radius:11px;padding:.7rem;}
.response-kpi .k{color:var(--text-muted);font-size:.74rem;}
.response-kpi .v{font-size:.95rem;font-weight:800;margin-top:.2rem;}
.response-actions{display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin-bottom:.75rem;}
.decision-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:.5rem;}
.decision-form{margin:0;}

/* ── Priority list ── */
.priority-list{display:grid;gap:.85rem;}
.priority-item{display:grid;grid-template-columns:1fr auto;gap:1rem;align-items:center;padding:.95rem;border:1px solid var(--border-color);border-radius:13px;background:var(--card-bg);}

/* ── Toast ── */
#toast-container{position:fixed;bottom:1.6rem;left:50%;transform:translateX(-50%);z-index:9999;display:flex;flex-direction:column;gap:.5rem;align-items:center;pointer-events:none;}
.toast{background:#1e293b;color:#fff;padding:.7rem 1.5rem;border-radius:999px;font-size:.87rem;font-weight:600;opacity:0;transform:translateY(10px);transition:all .3s;box-shadow:0 4px 18px rgba(0,0,0,.2);pointer-events:none;}
.toast.show{opacity:1;transform:translateY(0);}
.toast.toast-success{background:#059669;}
.toast.toast-error{background:#dc2626;}

/* ── Export btn ── */
.export-btn{display:inline-flex;align-items:center;gap:.4rem;padding:.48rem .9rem;border-radius:10px;background:#f1f5f9;color:#475569;font-size:.82rem;font-weight:700;border:1px solid var(--border-color);cursor:pointer;transition:all .2s;font-family:inherit;}
.export-btn:hover{background:#e2e8f0;}

.muted{color:var(--text-muted);}
.flash-messages{margin-bottom:1rem;}

/* ── Responsive ── */
@media(max-width:1200px){.hero-grid{grid-template-columns:1fr;}}
@media(max-width:1024px){
    .layout{grid-template-columns:1fr;}
    .sidebar{display:none;}
    .content{padding:1rem;}
    .quick-actions{grid-template-columns:1fr;}
}
@media(max-width:768px){
    .filters-row,.filters-row.responses,
    .stats-grid,.mini-grid,.response-grid,
    .response-actions,.response-kpis,.decision-grid{grid-template-columns:1fr;}
    .page-header{align-items:flex-start;}
    .response-view-tabs{flex-direction:column;}
    .response-view-tab{width:100%;justify-content:space-between;}
}
</style>
</head>
<body>

<div id="toast-container"></div>

<div class="layout">

<!-- ═══ Sidebar ═══ -->
<aside class="sidebar">
    <div class="brand">🛡️ دراسة الحالة</div>
    <div class="brand-sub">لوحة إدارة الاحتياج الذكي</div>
    <nav class="nav">
        <a href="/zaka/admin/dashboard.php"><span>🏠</span> الرئيسية</a>
        <a href="admin.php?tab=dashboard"  class="<?= $selectedTab==='dashboard'  ?'active':'' ?>"><span>📊</span> لوحة التحكم</a>
        <a href="admin.php?tab=import"     class="<?= $selectedTab==='import'     ?'active':'' ?>"><span>📥</span> استيراد البيانات</a>
        <a href="admin.php?tab=candidates" class="<?= $selectedTab==='candidates' ?'active':'' ?>"><span>👥</span> المرشحون</a>
        <a href="admin.php?tab=links"      class="<?= $selectedTab==='links'      ?'active':'' ?>"><span>🔗</span> الروابط</a>
        <a href="admin.php?tab=responses"  class="<?= $selectedTab==='responses'  ?'active':'' ?>"><span>📝</span> الردود والقرارات</a>
    </nav>
    <div class="sidebar-card"><div class="title">المرشحون</div><div class="value"><?= h((string)$stats['candidates']) ?></div></div>
    <div class="sidebar-card"><div class="title">الردود المكتملة</div><div class="value"><?= h((string)$stats['submissions']) ?></div></div>
    <div class="sidebar-card"><div class="title">متوسط الثقة</div><div class="value"><?= h(number_format((float)$dashboardMetrics['avg_trust'],1)) ?>%</div></div>
    <div class="sidebar-card"><div class="title">مشتبه بها</div><div class="value"><?= h((string)$dashboardMetrics['high_suspicion_count']) ?></div></div>
</aside>

<!-- ═══ Main ═══ -->
<main class="content">

    <header class="page-header">
        <div>
            <h1 class="page-title">لوحة إدارة دراسة الحالة</h1>
            <div class="page-subtitle">إحصائيات ذكية · إدارة المرشحين · الروابط · القرارات</div>
        </div>
        <div class="btn btn-light" style="cursor:default;">📍 <?= h(date('Y-m-d')) ?></div>
    </header>

    <div class="flash-messages"><?= renderFlash() ?></div>

    <!-- ── Filters ── -->
    <section class="section">
        <div class="section-header">
            <h2>بحث ومرشحات</h2>
            <div class="section-note">اسم · هاتف · توكن — يعمل من أول حرف</div>
        </div>
        <form method="get" id="filters-form">
            <input type="hidden" name="tab" value="<?= h($selectedTab) ?>">
            <?php if ($selectedTab === 'responses'): ?>
                <input type="hidden" name="response_view" value="<?= h($responseView) ?>">
                <div class="filters-row responses">
                    <input type="text" name="search" value="<?= h($search) ?>" placeholder="🔍 ابحث بالاسم أو الهاتف أو رقم الرد..." oninput="debounceSearch()">
                    <select name="response_category" onchange="this.form.submit()">
                        <option value="">كل مستويات الاحتياج</option>
                        <option value="high"   <?= $resCatFilter==='high'   ?'selected':'' ?>>احتياج مرتفع</option>
                        <option value="medium" <?= $resCatFilter==='medium' ?'selected':'' ?>>احتياج متوسط</option>
                        <option value="low"    <?= $resCatFilter==='low'    ?'selected':'' ?>>احتياج منخفض</option>
                        <option value="review" <?= $resCatFilter==='review' ?'selected':'' ?>>بحاجة مراجعة</option>
                    </select>
                    <select name="committee_status" onchange="this.form.submit()">
                        <option value="">كل قرارات اللجنة</option>
                        <option value="pending"  <?= $committeeFilter==='pending'  ?'selected':'' ?>>بانتظار الفرز</option>
                        <option value="waiting"  <?= $committeeFilter==='waiting'  ?'selected':'' ?>>انتظار</option>
                        <option value="review"   <?= $committeeFilter==='review'   ?'selected':'' ?>>مراجعة</option>
                        <option value="reserve"  <?= $committeeFilter==='reserve'  ?'selected':'' ?>>احتياط</option>
                        <option value="approved" <?= $committeeFilter==='approved' ?'selected':'' ?>>موافقة</option>
                        <option value="rejected" <?= $committeeFilter==='rejected' ?'selected':'' ?>>رفض</option>
                    </select>
                    <select name="suspicion_level" onchange="this.form.submit()">
                        <option value="">كل درجات الاشتباه</option>
                        <option value="high"   <?= $suspicionFilter==='high'   ?'selected':'' ?>>اشتباه مرتفع</option>
                        <option value="medium" <?= $suspicionFilter==='medium' ?'selected':'' ?>>اشتباه متوسط</option>
                        <option value="low"    <?= $suspicionFilter==='low'    ?'selected':'' ?>>اشتباه منخفض</option>
                    </select>
                </div>
            <?php else: ?>
                <div class="filters-row">
                    <input type="text" name="search" value="<?= h($search) ?>" placeholder="🔍 ابحث بالاسم أو الهاتف أو التوكن..." oninput="debounceSearch()">
                    <select name="status" onchange="this.form.submit()">
                        <option value="">📂 كل حالات المرشحين</option>
                        <option value="new"      <?= $statusFilter==='new'      ?'selected':'' ?>>جديد</option>
                        <option value="sent"     <?= $statusFilter==='sent'     ?'selected':'' ?>>تم إنشاء رابط</option>
                        <option value="answered" <?= $statusFilter==='answered' ?'selected':'' ?>>تم الرد</option>
                    </select>
                    <select name="link_status" onchange="this.form.submit()">
                        <option value="">🔗 كل حالات الروابط</option>
                        <option value="active" <?= $linkFilter==='active' ?'selected':'' ?>>روابط نشطة</option>
                        <option value="used"   <?= $linkFilter==='used'   ?'selected':'' ?>>روابط مستخدمة</option>
                    </select>
                </div>
            <?php endif; ?>
        </form>
    </section>

    <?php /* ═══ DASHBOARD ═══ */ if ($selectedTab === 'dashboard'): ?>

        <!-- Progress -->
        <section class="section" style="padding:1rem 1.25rem;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem;">
                <span style="font-weight:700;font-size:.9rem;">نسبة الاستجابة الكلية</span>
                <span style="font-weight:800;color:var(--primary);"><?= $progressPct ?>%</span>
            </div>
            <div class="progress-bar-bg"><div class="progress-bar-fill" style="width:<?= $progressPct ?>%;"></div></div>
            <div style="margin-top:.4rem;font-size:.78rem;color:var(--text-muted);"><?= h((string)$stats['submissions']) ?> من <?= h((string)$stats['candidates']) ?> مرشح أجابوا على النموذج</div>
        </section>

        <div class="stats-grid">
            <?php
            $statCards = [
                ['👥 المرشحون',       $stats['candidates'],                                         'إجمالي السجلات'],
                ['📝 الردود',          $stats['submissions'],                                        'أكملوا النموذج'],
                ['📉 متوسط الاحتياج', number_format((float)$dashboardMetrics['avg_poverty'],1).'%', 'نسبة الفقر المحسوبة'],
                ['🔒 متوسط الثقة',    number_format((float)$dashboardMetrics['avg_trust'],1).'%',   'موثوقية البيانات'],
                ['🚨 مشتبه بها',       $dashboardMetrics['high_suspicion_count'],                    'اشتباه 35%+'],
                ['⚡ أولوية مرتفعة',  $dashboardMetrics['high_need_count'],                         'مصنفة high'],
                ['✅ موافقات',         $dashboardMetrics['approved_count'],                          'قرار اللجنة'],
                ['⏳ انتظار/مراجعة',  $dashboardMetrics['waiting_count'],                           'بانتظار قرار'],
            ];
            foreach ($statCards as [$lbl, $val, $sub]):
            ?>
            <div class="stat-card">
                <span class="stat-label"><?= $lbl ?></span>
                <span class="stat-value"><?= h((string)$val) ?></span>
                <div class="stat-sub"><?= $sub ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="hero-grid">
            <section class="section">
                <div class="section-header"><h2>أعلى الحالات أولوية</h2><div class="section-note">مرتبة بالاحتياج + الاشتباه</div></div>
                <div class="priority-list">
                    <?php foreach ($prioritySubmissions as $row):
                        $full = $submissionDetails[(int)$row['id']] ?? $row;
                        $risk = responseRiskInfoLocal($full);
                    ?>
                    <div class="priority-item">
                        <div>
                            <div style="font-weight:800;"><?= h((string)$row['full_name']) ?></div>
                            <div class="muted"><?= h((string)$row['phone']) ?></div>
                            <div style="margin-top:.4rem;">
                                <span class="badge <?= h($risk['class']) ?>"><?= h($risk['label']) ?></span>
                                <span class="badge <?= h(badgeClass('committee',(string)($full['committee_status']??'pending'))) ?>">
                                    <?= h(committeeLabelLocal((string)($full['committee_status']??'pending'))) ?>
                                </span>
                            </div>
                        </div>
                        <div style="text-align:left;">
                            <div style="font-weight:800;font-size:1.2rem;"><?= h(number_format((float)($full['poverty_percent']??0),1)) ?>%</div>
                            <div class="muted">احتياج</div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (!$prioritySubmissions): ?><div class="muted">لا توجد بيانات كافية بعد.</div><?php endif; ?>
                </div>
            </section>

            <section class="section">
                <div class="section-header"><h2>نظرة تنفيذية</h2></div>
                <div class="mini-grid">
                    <div class="mini-card"><div class="muted">متوسط الاشتباه</div><div style="font-size:1.5rem;font-weight:800;"><?= h(number_format((float)$dashboardMetrics['avg_suspicion'],1)) ?>%</div></div>
                    <div class="mini-card"><div class="muted">بحاجة مراجعة</div><div style="font-size:1.5rem;font-weight:800;"><?= h((string)$dashboardMetrics['review_count']) ?></div></div>
                    <div class="mini-card"><div class="muted">روابط نشطة</div><div style="font-size:1.5rem;font-weight:800;"><?= h((string)max(0,$stats['links']-$stats['used_links'])) ?></div></div>
                    <div class="mini-card"><div class="muted">روابط مستخدمة</div><div style="font-size:1.5rem;font-weight:800;"><?= h((string)$stats['used_links']) ?></div></div>
                </div>
            </section>
        </div>

        <section class="section">
            <div class="section-header"><h2>وصول سريع</h2></div>
            <div class="quick-actions">
                <div class="quick-item"><h4>استيراد جماعي</h4><p>إضافة مرشحين من نص أو ملف TXT</p><a href="admin.php?tab=import" class="btn btn-primary">ابدأ الآن</a></div>
                <div class="quick-item"><h4>توليد الروابط</h4><p>إنشاء روابط لغير المسجلين</p><a href="admin.php?tab=candidates" class="btn btn-success">فتح القائمة</a></div>
                <div class="quick-item"><h4>مراجعة الردود</h4><p>فلترة الحالات واتخاذ القرار</p><a href="admin.php?tab=responses" class="btn btn-warning">الردود</a></div>
            </div>
        </section>

    <?php endif; ?>

    <?php /* ═══ IMPORT ═══ */ if ($selectedTab === 'import'): ?>

        <section class="section">
            <div class="section-header"><h2>استيراد من نص مباشر</h2></div>
            <form method="post" action="actions.php?action=import_candidates">
                <?= csrfField() ?>
                <textarea name="bulk_candidates" style="height:150px;margin-bottom:1rem;" placeholder="الاسم ثم مسافة ثم رقم الهاتف&#10;مثال: أحمد محمد 777000000"></textarea>
                <button type="submit" class="btn btn-primary">تنفيذ الاستيراد</button>
            </form>
        </section>

        <section class="section">
            <div class="section-header"><h2>استيراد ملف TXT</h2></div>
            <form method="post" action="actions.php?action=import_candidates_file" enctype="multipart/form-data">
                <?= csrfField() ?>
                <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;">
                    <input type="file" name="txt_file" accept=".txt" required>
                    <button type="submit" class="btn btn-success">رفع الملف</button>
                </div>
            </form>
        </section>

    <?php endif; ?>

    <?php /* ═══ CANDIDATES ═══ */ if ($selectedTab === 'candidates'): ?>

        <section class="section">
            <div class="section-header"><h2>إضافة مرشح جديد</h2></div>
            <form method="post" action="actions.php?action=add_candidate">
                <?= csrfField() ?>
                <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:1rem;">
                    <input type="text" name="full_name" placeholder="الاسم الرباعي" required>
                    <input type="text" name="phone"     placeholder="رقم الهاتف"    required>
                    <button type="submit" class="btn btn-primary">إضافة</button>
                </div>
            </form>
        </section>

        <section class="section">
            <div class="section-header">
                <h2>قائمة المرشحين</h2>
                <button class="export-btn" onclick="exportTableCSV('tbl-candidates','candidates')">⬇ تصدير CSV</button>
            </div>
            <form method="post">
                <?= csrfField() ?>
                <div style="display:flex;gap:.6rem;margin-bottom:1.1rem;flex-wrap:wrap;">
                    <button type="submit" formaction="actions.php?action=generate_links_selected" class="btn btn-success">✨ توليد روابط للمحدد</button>
                    <button type="submit" formaction="actions.php?action=delete_candidates_selected" class="btn btn-danger" onclick="return confirm('حذف المحددين؟');">🗑️ حذف المحدد</button>
                </div>
                <div class="table-container">
                    <table id="tbl-candidates">
                        <thead>
                            <tr>
                                <th style="width:42px;"><input type="checkbox" onclick="toggleAll(this,'candidate_ids')"></th>
                                <th class="sortable" data-col="1">#ID</th>
                                <th class="sortable" data-col="2">الاسم</th>
                                <th class="sortable" data-col="3">الهاتف</th>
                                <th>الحالة</th>
                                <th class="sortable" data-col="5">الروابط</th>
                                <th>إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($candidates as $row): ?>
                            <tr>
                                <td><input type="checkbox" name="candidate_ids[]" value="<?= h((string)$row['id']) ?>"></td>
                                <td class="muted">#<?= h((string)$row['id']) ?></td>
                                <td><strong><?= h((string)$row['full_name']) ?></strong></td>
                                <td><?= h((string)$row['phone']) ?></td>
                                <td><span class="badge <?= h(badgeClass('candidate',(string)$row['status'])) ?>"><?= h(candidateStatusLabel((string)$row['status'])) ?></span></td>
                                <td><?= h((string)($row['links_count']??0)) ?></td>
                                <td>
                                    <div style="display:flex;gap:5px;flex-wrap:wrap;">
                                        <form method="post" action="actions.php?action=generate_single_link">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="candidate_id" value="<?= h((string)$row['id']) ?>">
                                            <button class="btn btn-light btn-xs">🔗 رابط</button>
                                        </form>
                                        <form method="post" action="actions.php?action=delete_candidate" onsubmit="return confirm('حذف هذا المرشح؟');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="candidate_id" value="<?= h((string)$row['id']) ?>">
                                            <button class="btn btn-danger btn-xs">🗑️</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$candidates): ?><tr><td colspan="7" class="muted">لا توجد نتائج مطابقة.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </section>

    <?php endif; ?>

    <?php /* ═══ LINKS ═══ */ if ($selectedTab === 'links'): ?>

        <section class="section">
            <div class="section-header">
                <h2>إدارة الروابط</h2>
                <button class="export-btn" onclick="exportTableCSV('tbl-links','links')">⬇ تصدير CSV</button>
            </div>
            <div class="table-container">
                <table id="tbl-links">
                    <thead>
                        <tr>
                            <th class="sortable" data-col="0">المرشح</th>
                            <th>الحالة</th>
                            <th>الرابط</th>
                            <th>إجراءات</th>
                            <th class="sortable" data-col="4">تاريخ الإنشاء</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($links as $row):
                        $fullLink  = buildStudyLink((string)$row['token']);
                        $waPhone   = normalizeWaPhoneLocal((string)$row['phone']);
                        $waMessage = buildStudyWhatsAppMessageLocal((string)$row['full_name'], $fullLink);
                    ?>
                        <tr>
                            <td><strong><?= h((string)$row['full_name']) ?></strong><br><span class="muted"><?= h((string)$row['phone']) ?></span></td>
                            <td><span class="badge <?= h(badgeClass('link',(int)$row['is_used']===1?'used':'active')) ?>"><?= (int)$row['is_used']===1?'مستخدم':'نشط' ?></span></td>
                            <td><div class="copy-box"><?= h($fullLink) ?></div></td>
                            <td>
                                <div style="display:flex;gap:5px;flex-wrap:wrap;">
                                    <button type="button" class="btn btn-light btn-xs" onclick="copyText('<?= h($fullLink) ?>')">نسخ</button>
                                    <button type="button" class="btn btn-whatsapp btn-xs wa-btn"
                                            data-phone="<?= h($waPhone) ?>"
                                            data-message="<?= h($waMessage) ?>">واتساب</button>
                                </div>
                            </td>
                            <td class="muted"><?= h((string)$row['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$links): ?><tr><td colspan="5" class="muted">لا توجد روابط مطابقة.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

    <?php endif; ?>

    <?php /* ═══ RESPONSES ═══ */ if ($selectedTab === 'responses'): ?>

        <section class="section">
            <div class="section-header">
                <h2>الردود والقرارات</h2>
                <div class="section-note">بطاقات عملية · فرز · إبراز المشتبه بها · قرارات اللجنة</div>
            </div>

            <div class="response-view-tabs">
                <?php
                $viewTabs = [
                    'all'        => ['الكل',            'badge-secondary'],
                    'approved'   => ['موافق عليه',      'badge-success'],
                    'rejected'   => ['مرفوض',            'badge-danger'],
                    'reserve'    => ['احتياط',           'badge-purple'],
                    'waiting'    => ['انتظار / مراجعة', 'badge-warning'],
                    'suspicious' => ['مشتبه بها',        'badge-danger'],
                ];
                foreach ($viewTabs as $key => [$label, $cls]): ?>
                <a class="response-view-tab <?= $responseView===$key?'active':'' ?>"
                   href="admin.php?tab=responses&response_view=<?= $key ?>">
                    <span><?= $label ?></span>
                    <span class="badge <?= $cls ?>"><?= h((string)$viewCounts[$key]) ?></span>
                </a>
                <?php endforeach; ?>
            </div>

            <div class="stats-grid" style="margin-top:0;">
                <div class="stat-card"><span class="stat-label">الردود المعروضة</span><span class="stat-value"><?= h((string)count($submissions)) ?></span><div class="stat-sub">حسب التبويب والمرشحات</div></div>
                <div class="stat-card"><span class="stat-label">متوسط الثقة</span><span class="stat-value"><?= h(number_format((float)$dashboardMetrics['avg_trust'],1)) ?>%</span><div class="stat-sub">للعناصر المعروضة</div></div>
                <div class="stat-card"><span class="stat-label">متوسط الاشتباه</span><span class="stat-value"><?= h(number_format((float)$dashboardMetrics['avg_suspicion'],1)) ?>%</span><div class="stat-sub">للعناصر المعروضة</div></div>
                <div class="stat-card"><span class="stat-label">احتياج مرتفع</span><span class="stat-value"><?= h((string)$dashboardMetrics['high_need_count']) ?></span><div class="stat-sub">داخل النتائج</div></div>
            </div>

            <div class="response-grid">
                <?php foreach ($submissions as $row):
                    $full            = $submissionDetails[(int)$row['id']] ?? $row;
                    $risk            = responseRiskInfoLocal($full);
                    $committeeStatus = (string)($full['committee_status'] ?? 'pending');
                    $category        = (string)($full['category']         ?? 'low');
                    $isSuspicious    = (int)($full['suspicion_score']     ?? 0) >= 35;
                ?>
                <div class="response-card <?= $isSuspicious?'is-suspicious':'' ?>">
                    <?php if ($isSuspicious): ?><div class="suspect-ribbon">⚠ مشتبه بها</div><?php endif; ?>

                    <h3><?= h((string)$row['full_name']) ?></h3>
                    <div class="response-meta">
                        <div>📱 <?= h((string)$row['phone']) ?></div>
                        <div>🆔 رد #<?= h((string)$row['id']) ?></div>
                    </div>

                    <div style="display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:.75rem;">
                        <span class="badge <?= h($risk['class']) ?>"><?= h($risk['label']) ?></span>
                        <span class="badge <?= h(badgeClass('committee',$committeeStatus)) ?>"><?= h(committeeLabelLocal($committeeStatus)) ?></span>
                        <span class="badge <?= h(badgeClass('category',$category)) ?>"><?= h(studyCategoryLabel($category)) ?></span>
                    </div>

                    <div class="response-kpis">
                        <div class="response-kpi"><div class="k">نسبة الاحتياج</div><div class="v"><?= h(number_format((float)($full['poverty_percent']??0),1)) ?>%</div></div>
                        <div class="response-kpi"><div class="k">درجة الثقة</div><div class="v"><?= h((string)((int)($full['trust_score']??0))) ?>%</div></div>
                        <div class="response-kpi"><div class="k">الاشتباه</div><div class="v"><?= h((string)((int)($full['suspicion_score']??0))) ?>%</div></div>
                        <div class="response-kpi"><div class="k">إشارات حرجة</div><div class="v"><?= h((string)((int)($full['critical_flags_count']??0))) ?></div></div>
                    </div>

                    <div class="response-actions">
                        <a href="result_ai.php?id=<?= h((string)$row['id']) ?>" class="btn btn-primary">عرض</a>
                        <a href="result_ai.php?id=<?= h((string)$row['id']) ?>&print=1" target="_blank" class="btn btn-light">🖨️ طباعة</a>
                    </div>

                    <div class="decision-grid">
                        <?php foreach ([
                            ['approved','btn-success','✔ موافقة'],
                            ['rejected','btn-danger', '✖ رفض'],
                            ['reserve', 'btn-purple', '⏳ احتياط'],
                            ['waiting', 'btn-warning','🔍 انتظار'],
                        ] as [$val,$cls,$lbl]): ?>
                        <form method="post" action="actions.php?action=set_committee_status" class="decision-form"
                              onsubmit="return handleDecision(this,'<?= h($lbl) ?>')">
                            <?= csrfField() ?>
                            <input type="hidden" name="submission_id"    value="<?= h((string)$row['id']) ?>">
                            <input type="hidden" name="committee_status" value="<?= $val ?>">
                            <button type="submit" class="btn <?= $cls ?> btn-xs" style="width:100%;">
                                <span class="btn-label"><?= $lbl ?></span>
                                <span class="spinner"></span>
                            </button>
                        </form>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (!$submissions): ?><div class="muted">لا توجد ردود مطابقة لهذه المرشحات.</div><?php endif; ?>
            </div>
        </section>

    <?php endif; ?>

</main>
</div>

<script>
// ── Debounce search ──────────────────────────────────────────────────────────
let _st = null;
function debounceSearch() {
    clearTimeout(_st);
    _st = setTimeout(() => document.getElementById('filters-form')?.submit(), 380);
}

// ── Checkbox toggle ──────────────────────────────────────────────────────────
function toggleAll(src, name) {
    document.querySelectorAll(`input[name="${name}[]"]`).forEach(el => el.checked = src.checked);
}

// ── Toast notifications ──────────────────────────────────────────────────────
function toast(msg, type = 'success') {
    const el = document.createElement('div');
    el.className = `toast toast-${type}`;
    el.textContent = msg;
    document.getElementById('toast-container').appendChild(el);
    requestAnimationFrame(() => requestAnimationFrame(() => el.classList.add('show')));
    setTimeout(() => { el.classList.remove('show'); setTimeout(() => el.remove(), 350); }, 2800);
}

// ── Copy link ────────────────────────────────────────────────────────────────
function copyText(text) {
    navigator.clipboard.writeText(text)
        .then(() => toast('✅ تم نسخ الرابط بنجاح'))
        .catch(() => toast('تعذّر النسخ', 'error'));
}

// ── WhatsApp ─────────────────────────────────────────────────────────────────
function openWhatsApp(phone, message) {
    const appUrl = 'whatsapp://send?phone=' + encodeURIComponent(phone) + '&text=' + encodeURIComponent(message);
    const webUrl = 'https://wa.me/' + encodeURIComponent(phone) + '?text=' + encodeURIComponent(message);
    const start = Date.now();
    window.location.href = appUrl;
    setTimeout(() => { if (Date.now() - start < 1800) window.open(webUrl, '_blank'); }, 1200);
}

// ── Decision with loading spinner ────────────────────────────────────────────
function handleDecision(form, label) {
    if (!confirm('تأكيد القرار: ' + label + ' ؟')) return false;
    const btn = form.querySelector('button[type="submit"]');
    if (btn) btn.classList.add('loading');
    return true;
}

// ── CSV Export ───────────────────────────────────────────────────────────────
function exportTableCSV(tableId, filename) {
    const table = document.getElementById(tableId);
    if (!table) return;
    const csv = Array.from(table.querySelectorAll('tr')).map(row =>
        Array.from(row.querySelectorAll('th,td'))
            .map(cell => '"' + cell.innerText.replace(/"/g, '""').replace(/\n/g, ' ') + '"')
            .join(',')
    ).join('\n');
    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    const a = Object.assign(document.createElement('a'), {
        href: URL.createObjectURL(blob),
        download: filename + '_' + new Date().toISOString().slice(0,10) + '.csv'
    });
    a.click();
    URL.revokeObjectURL(a.href);
    toast('📥 تم تصدير الملف بنجاح');
}

// ── Sortable tables ──────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    // WhatsApp buttons
    document.querySelectorAll('.wa-btn').forEach(btn =>
        btn.addEventListener('click', () => openWhatsApp(btn.dataset.phone, btn.dataset.message))
    );

    // Column sorting
    document.querySelectorAll('th.sortable').forEach(th => {
        th.addEventListener('click', () => {
            const tbody = th.closest('table').querySelector('tbody');
            const col   = parseInt(th.dataset.col);
            const asc   = !th.classList.contains('sort-asc');

            th.closest('thead').querySelectorAll('th').forEach(t => t.classList.remove('sort-asc','sort-desc'));
            th.classList.add(asc ? 'sort-asc' : 'sort-desc');

            Array.from(tbody.querySelectorAll('tr'))
                .sort((a, b) => {
                    const aT = (a.cells[col]?.innerText || '').trim();
                    const bT = (b.cells[col]?.innerText || '').trim();
                    const aN = parseFloat(aT.replace(/[^\d.]/g, ''));
                    const bN = parseFloat(bT.replace(/[^\d.]/g, ''));
                    if (!isNaN(aN) && !isNaN(bN)) return asc ? aN - bN : bN - aN;
                    return asc ? aT.localeCompare(bT, 'ar') : bT.localeCompare(aT, 'ar');
                })
                .forEach(r => tbody.appendChild(r));
        });
    });
});
</script>
</body>
</html>