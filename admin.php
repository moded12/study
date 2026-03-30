<?php
// /zaka/study/admin.php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/admin/bootstrap.php';
require_once __DIR__ . '/includes/functions_ai.php';
require_once __DIR__ . '/includes/questions_ai.php';

requireLogin();

$selectedTab = trim((string)($_GET['tab'] ?? 'dashboard'));
$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$linkFilter = trim((string)($_GET['link_status'] ?? ''));
$responseCategoryFilter = trim((string)($_GET['response_category'] ?? ''));
$committeeFilter = trim((string)($_GET['committee_status'] ?? ''));
$suspicionFilter = trim((string)($_GET['suspicion_level'] ?? ''));

$allowedTabs = ['dashboard', 'import', 'candidates', 'links', 'responses'];
if (!in_array($selectedTab, $allowedTabs, true)) {
    $selectedTab = 'dashboard';
}

$stats = getStudyStats();
$candidates = getStudyCandidates(500);
$links = getStudyLinks(500);
$submissions = getStudySubmissions(500);

$submissionDetails = [];
foreach ($submissions as $row) {
    $full = getSubmission((int)$row['id']);
    if (!$full) {
        $full = $row;
    }
    $submissionDetails[(int)$row['id']] = $full;
}

if ($search !== '') {
    $candidates = array_values(array_filter($candidates, function ($row) use ($search) {
        return mb_stripos((string)$row['full_name'], $search) !== false
            || mb_stripos((string)$row['phone'], $search) !== false;
    }));

    $links = array_values(array_filter($links, function ($row) use ($search) {
        return mb_stripos((string)$row['full_name'], $search) !== false
            || mb_stripos((string)$row['phone'], $search) !== false
            || mb_stripos((string)$row['token'], $search) !== false;
    }));

    $submissions = array_values(array_filter($submissions, function ($row) use ($search) {
        return mb_stripos((string)$row['full_name'], $search) !== false
            || mb_stripos((string)$row['phone'], $search) !== false
            || mb_stripos((string)$row['id'], $search) !== false;
    }));
}

if ($statusFilter !== '') {
    $candidates = array_values(array_filter($candidates, fn($row) => (string)$row['status'] === $statusFilter));
}

if ($linkFilter !== '') {
    if ($linkFilter === 'used') {
        $links = array_values(array_filter($links, fn($row) => (int)$row['is_used'] === 1));
    } elseif ($linkFilter === 'active') {
        $links = array_values(array_filter($links, fn($row) => (int)$row['is_used'] === 0));
    }
}

if ($responseCategoryFilter !== '') {
    $submissions = array_values(array_filter($submissions, fn($row) => (string)$row['category'] === $responseCategoryFilter));
}

if ($committeeFilter !== '') {
    $submissions = array_values(array_filter($submissions, fn($row) => (string)$row['committee_status'] === $committeeFilter));
}

if ($suspicionFilter !== '') {
    $submissions = array_values(array_filter($submissions, function ($row) use ($submissionDetails, $suspicionFilter) {
        $full = $submissionDetails[(int)$row['id']] ?? $row;
        $score = (int)($full['suspicion_score'] ?? 0);
        return match ($suspicionFilter) {
            'high' => $score >= 35,
            'medium' => $score >= 20 && $score < 35,
            'low' => $score < 20,
            default => true,
        };
    }));
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function calcDashboardMetrics(array $submissions, array $submissionDetails): array
{
    $count = count($submissions);
    $avgPoverty = 0.0;
    $avgTrust = 0.0;
    $highNeedCount = 0;
    $reviewCount = 0;
    $highSuspicionCount = 0;
    $printableReadyCount = 0;

    foreach ($submissions as $row) {
        $full = $submissionDetails[(int)$row['id']] ?? $row;
        $avgPoverty += (float)($full['poverty_percent'] ?? 0);
        $avgTrust += (float)($full['trust_score'] ?? 0);

        if ((string)($full['category'] ?? '') === 'high') {
            $highNeedCount++;
        }
        if ((string)($full['category'] ?? '') === 'review' || (string)($full['committee_status'] ?? '') === 'review') {
            $reviewCount++;
        }
        if ((int)($full['suspicion_score'] ?? 0) >= 35) {
            $highSuspicionCount++;
        }
        if ((string)($full['committee_status'] ?? '') === 'pending' || (string)($full['committee_status'] ?? '') === 'approved') {
            $printableReadyCount++;
        }
    }

    if ($count > 0) {
        $avgPoverty /= $count;
        $avgTrust /= $count;
    }

    return [
        'avg_poverty' => round($avgPoverty, 2),
        'avg_trust' => round($avgTrust, 2),
        'high_need_count' => $highNeedCount,
        'review_count' => $reviewCount,
        'high_suspicion_count' => $highSuspicionCount,
        'printable_ready_count' => $printableReadyCount,
    ];
}

function recentTopSubmissions(array $submissions, array $submissionDetails, int $limit = 5): array
{
    usort($submissions, function ($a, $b) use ($submissionDetails) {
        $aFull = $submissionDetails[(int)$a['id']] ?? $a;
        $bFull = $submissionDetails[(int)$b['id']] ?? $b;

        $aScore = (float)($aFull['poverty_percent'] ?? 0) + ((int)($aFull['suspicion_score'] ?? 0) / 5);
        $bScore = (float)($bFull['poverty_percent'] ?? 0) + ((int)($bFull['suspicion_score'] ?? 0) / 5);

        return $bScore <=> $aScore;
    });

    return array_slice($submissions, 0, $limit);
}

function badgeClassByStatus(string $type): string
{
    return match ($type) {
        'new' => 'badge-secondary',
        'sent' => 'badge-primary',
        'answered' => 'badge-success',
        'used' => 'badge-success',
        'active' => 'badge-primary',
        'pending' => 'badge-warning',
        'approved' => 'badge-success',
        'rejected' => 'badge-danger',
        'review' => 'badge-purple',
        'high' => 'badge-danger',
        'medium' => 'badge-warning',
        'low' => 'badge-secondary',
        default => 'badge-secondary',
    };
}

function responseRiskLabel(array $full): array
{
    $suspicion = (int)($full['suspicion_score'] ?? 0);
    $poverty = (float)($full['poverty_percent'] ?? 0);

    if ($suspicion >= 35) {
        return ['label' => 'اشتباه مرتفع', 'class' => 'badge-danger'];
    }
    if ($poverty >= 78) {
        return ['label' => 'أولوية قصوى', 'class' => 'badge-danger'];
    }
    if ($poverty >= 58) {
        return ['label' => 'أولوية مرتفعة', 'class' => 'badge-warning'];
    }
    return ['label' => 'مستقر نسبيًا', 'class' => 'badge-secondary'];
}

$dashboardMetrics = calcDashboardMetrics($submissions, $submissionDetails);
$prioritySubmissions = recentTopSubmissions($submissions, $submissionDetails, 6);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>إدارة دراسة الحالة - لوحة التحكم</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --success: #059669;
            --warning: #d97706;
            --danger: #dc2626;
            --purple: #7c3aed;
            --bg-body: #f8fafc;
            --sidebar-bg: #0f172a;
            --card-bg: #ffffff;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --radius: 16px;
            --shadow: 0 10px 25px rgba(15, 23, 42, .06);
        }

        * { box-sizing: border-box; outline: none; }
        body {
            margin: 0;
            font-family: 'IBM Plex Sans Arabic', sans-serif;
            background: var(--bg-body);
            color: var(--text-main);
            line-height: 1.65;
        }

        a { text-decoration: none; color: inherit; }

        .layout { display: grid; grid-template-columns: 290px 1fr; min-height: 100vh; }

        .sidebar {
            background: linear-gradient(180deg, #0f172a, #111827);
            color: #fff;
            padding: 2rem 1.25rem;
            position: sticky;
            top: 0;
            height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .brand { font-size: 1.5rem; font-weight: 800; color: #fff; margin-bottom: .35rem; display: flex; align-items: center; gap: 10px; }
        .brand-sub { font-size: .88rem; color: #94a3b8; margin-bottom: 2rem; }

        .nav { display: flex; flex-direction: column; gap: .5rem; flex-grow: 1; }
        .nav a {
            display: flex;
            align-items: center;
            gap: .7rem;
            padding: .9rem 1rem;
            border-radius: 14px;
            color: #cbd5e1;
            transition: all .25s ease;
            font-size: .96rem;
            font-weight: 500;
        }
        .nav a:hover { background: rgba(255,255,255,.05); color: #fff; }
        .nav a.active { background: var(--primary); color: #fff; box-shadow: 0 12px 24px rgba(37,99,235,.28); }

        .sidebar-card {
            background: rgba(255,255,255,.04);
            border: 1px solid rgba(255,255,255,.08);
            padding: 1rem;
            border-radius: 14px;
            margin-top: .8rem;
        }
        .sidebar-card .title { font-size: .76rem; color: #94a3b8; text-transform: uppercase; }
        .sidebar-card .value { font-size: 1.25rem; font-weight: 800; color: #fff; margin-top: .2rem; }

        .content { padding: 2rem; overflow-x: hidden; }
        .page-header {
            display: flex; justify-content: space-between; align-items: flex-end;
            margin-bottom: 1.5rem; gap: 1rem; flex-wrap: wrap;
        }
        .page-title { font-size: 1.95rem; font-weight: 800; margin: 0; color: #0f172a; }
        .page-subtitle { color: var(--text-muted); font-size: .98rem; margin-top: .35rem; }

        .section {
            background: var(--card-bg);
            border-radius: var(--radius);
            padding: 1.35rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
        }
        .section-header {
            display: flex; justify-content: space-between; align-items: center;
            gap: 1rem; flex-wrap: wrap;
            margin-bottom: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: .85rem;
        }
        .section-header h2 { font-size: 1.2rem; margin: 0; font-weight: 800; }
        .section-note { color: var(--text-muted); font-size: .9rem; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px,1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .stat-card {
            background: linear-gradient(180deg, #fff, #fbfdff);
            padding: 1.25rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            transition: transform .2s ease;
        }
        .stat-card:hover { transform: translateY(-4px); }
        .stat-label { color: var(--text-muted); font-size: .9rem; font-weight: 600; }
        .stat-value { font-size: 2rem; font-weight: 800; color: var(--primary); display: block; margin-top: .3rem; }
        .stat-sub { color: var(--text-muted); font-size: .8rem; margin-top: .35rem; }

        .hero-grid { display: grid; grid-template-columns: 1.4fr .9fr; gap: 1rem; }
        .mini-grid { display: grid; grid-template-columns: repeat(2,1fr); gap: .85rem; }
        .mini-card {
            background: #f8fafc; border: 1px solid var(--border-color);
            border-radius: 14px; padding: 1rem;
        }

        .quick-actions { display: grid; grid-template-columns: repeat(3,1fr); gap: 1rem; }
        .quick-item {
            background: linear-gradient(180deg, #f8fafc, #ffffff);
            border: 1px dashed var(--border-color);
            padding: 1.25rem;
            border-radius: var(--radius);
            text-align: center;
        }
        .quick-item h4 { margin: 0 0 .45rem; font-size: 1rem; }
        .quick-item p { margin: 0 0 1rem; color: var(--text-muted); font-size: .9rem; }

        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: .45rem;
            padding: .68rem 1rem; border-radius: 12px; font-weight: 700; font-size: .9rem;
            cursor: pointer; transition: all .2s ease; border: none;
        }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-success { background: var(--success); color: #fff; }
        .btn-warning { background: var(--warning); color: #fff; }
        .btn-danger { background: var(--danger); color: #fff; }
        .btn-light { background: #f1f5f9; color: #475569; }
        .btn-light:hover { background: #e2e8f0; }

        input, select, textarea {
            width: 100%; padding: .8rem 1rem; border: 1px solid var(--border-color);
            border-radius: 12px; font-family: inherit; background: #fff; transition: border .2s ease;
        }
        input:focus, select:focus, textarea:focus { border-color: var(--primary); box-shadow: 0 0 0 4px rgba(37,99,235,.08); }

        .filters-row { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: .9rem; }
        .filters-row.responses { grid-template-columns: 2fr 1fr 1fr 1fr; }

        .badge {
            padding: 5px 12px; border-radius: 999px; font-size: .76rem; font-weight: 800; display: inline-block;
        }
        .badge-primary { background: #dbeafe; color: #1e40af; }
        .badge-success { background: #dcfce7; color: #166534; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-secondary { background: #e2e8f0; color: #334155; }
        .badge-purple { background: #ede9fe; color: #6d28d9; }

        .copy-box {
            font-family: monospace; background: #f8fafc; padding: .6rem .75rem; border-radius: 10px;
            font-size: .8rem; color: #475569; max-width: 260px; overflow: hidden;
            text-overflow: ellipsis; white-space: nowrap; border: 1px solid var(--border-color);
        }

        .table-container { overflow-x: auto; margin: 0 -1.35rem; padding: 0 1.35rem; }
        table { width: 100%; border-collapse: collapse; min-width: 950px; }
        th {
            background: #f8fafc; padding: 1rem; text-align: right; font-size: .84rem;
            color: var(--text-muted); font-weight: 700; border-bottom: 2px solid var(--border-color);
        }
        td { padding: 1rem; font-size: .94rem; border-bottom: 1px solid var(--border-color); vertical-align: middle; }
        tr:hover td { background: #fcfdfe; }

        .response-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px,1fr)); gap: 1rem; }
        .response-card {
            background: #fff; border: 1px solid var(--border-color); border-radius: var(--radius);
            padding: 1.2rem; transition: all .25s ease; box-shadow: var(--shadow);
        }
        .response-card:hover { transform: translateY(-3px); }
        .response-card h3 { margin: 0 0 .5rem; font-size: 1.06rem; }
        .response-meta { color: var(--text-muted); font-size: .9rem; margin-bottom: 1rem; }
        .response-actions { display: grid; grid-template-columns: 1fr 1fr; gap: .65rem; }
        .response-kpis { display: grid; grid-template-columns: repeat(2,1fr); gap: .65rem; margin: .8rem 0 1rem; }
        .response-kpi {
            background: #f8fafc; border: 1px solid var(--border-color);
            border-radius: 12px; padding: .8rem;
        }
        .response-kpi .k { color: var(--text-muted); font-size: .78rem; }
        .response-kpi .v { font-size: 1rem; font-weight: 800; margin-top: .25rem; }

        .priority-list { display: grid; gap: .9rem; }
        .priority-item {
            display: grid; grid-template-columns: 1fr auto; gap: 1rem; align-items: center;
            padding: 1rem; border: 1px solid var(--border-color); border-radius: 14px; background: #fff;
        }

        .muted { color: var(--text-muted); }
        .flash-messages { margin-bottom: 1rem; }

        @media (max-width: 1200px) {
            .hero-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 1024px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar { display: none; }
            .content { padding: 1rem; }
            .quick-actions { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .filters-row, .filters-row.responses { grid-template-columns: 1fr; }
            .stats-grid, .mini-grid, .response-grid, .response-actions, .response-kpis { grid-template-columns: 1fr; }
            .page-header { align-items: flex-start; }
        }
    </style>
    <script>
        let searchTimer = null;
        function autoSubmitFilters() { document.getElementById('filters-form')?.submit(); }
        function debounceSearch() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(autoSubmitFilters, 350);
        }
        function toggleAll(source, name) {
            const items = document.querySelectorAll('input[name="' + name + '[]"]');
            items.forEach(item => item.checked = source.checked);
        }
        function copyText(text) {
            navigator.clipboard.writeText(text).then(() => {
                alert('✅ تم نسخ الرابط بنجاح');
            }).catch(() => {
                alert('تعذر النسخ تلقائياً');
            });
        }
    </script>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="brand">🛡️ دراسة الحالة</div>
        <div class="brand-sub">لوحة إدارة الاحتياج الذكي</div>

        <nav class="nav">
            <a href="/zaka/admin/dashboard.php">
                <span>🏠</span> الرئيسية
            </a>
            <a href="admin.php?tab=dashboard" class="<?= $selectedTab === 'dashboard' ? 'active' : '' ?>">
                <span>📊</span> لوحة التحكم
            </a>
            <a href="admin.php?tab=import" class="<?= $selectedTab === 'import' ? 'active' : '' ?>">
                <span>📥</span> استيراد البيانات
            </a>
            <a href="admin.php?tab=candidates" class="<?= $selectedTab === 'candidates' ? 'active' : '' ?>">
                <span>👥</span> المرشحون
            </a>
            <a href="admin.php?tab=links" class="<?= $selectedTab === 'links' ? 'active' : '' ?>">
                <span>🔗</span> الروابط
            </a>
            <a href="admin.php?tab=responses" class="<?= $selectedTab === 'responses' ? 'active' : '' ?>">
                <span>📝</span> الردود والقرارات
            </a>
        </nav>

        <div class="sidebar-card">
            <div class="title">إجمالي المرشحين</div>
            <div class="value"><?= h((string)$stats['candidates']) ?></div>
        </div>
        <div class="sidebar-card">
            <div class="title">الردود المكتملة</div>
            <div class="value"><?= h((string)$stats['submissions']) ?></div>
        </div>
        <div class="sidebar-card">
            <div class="title">متوسط الثقة</div>
            <div class="value"><?= h(number_format((float)$dashboardMetrics['avg_trust'], 1)) ?>%</div>
        </div>
    </aside>

    <main class="content">
        <header class="page-header">
            <div>
                <h1 class="page-title">لوحة إدارة دراسة الحالة</h1>
                <div class="page-subtitle">Dashboard عصري في الأعلى + جداول عملية في الأسفل</div>
            </div>
            <div class="btn btn-light" style="cursor:default;">📍 <?= h(date('Y-m-d')) ?></div>
        </header>

        <div class="flash-messages"><?= renderFlash() ?></div>

        <section class="section">
            <div class="section-header">
                <h2>مرشحات وبحث ذكي</h2>
                <div class="section-note">البحث يبدأ من أول حرف أو أي جزء من الاسم/الهاتف/التوكن</div>
            </div>
            <form method="get" id="filters-form">
                <input type="hidden" name="tab" value="<?= h($selectedTab) ?>">

                <?php if ($selectedTab === 'responses'): ?>
                    <div class="filters-row responses">
                        <input type="text" name="search" value="<?= h($search) ?>" placeholder="🔍 ابحث بالاسم أو الهاتف أو رقم الرد..." oninput="debounceSearch()">
                        <select name="response_category" onchange="autoSubmitFilters()">
                            <option value="">كل مستويات الاحتياج</option>
                            <option value="high" <?= $responseCategoryFilter === 'high' ? 'selected' : '' ?>>احتياج مرتفع</option>
                            <option value="medium" <?= $responseCategoryFilter === 'medium' ? 'selected' : '' ?>>احتياج متوسط</option>
                            <option value="low" <?= $responseCategoryFilter === 'low' ? 'selected' : '' ?>>احتياج منخفض</option>
                            <option value="review" <?= $responseCategoryFilter === 'review' ? 'selected' : '' ?>>بحاجة مراجعة</option>
                        </select>
                        <select name="committee_status" onchange="autoSubmitFilters()">
                            <option value="">كل قرارات اللجنة</option>
                            <option value="pending" <?= $committeeFilter === 'pending' ? 'selected' : '' ?>>بانتظار المراجعة</option>
                            <option value="review" <?= $committeeFilter === 'review' ? 'selected' : '' ?>>قيد الدراسة</option>
                            <option value="approved" <?= $committeeFilter === 'approved' ? 'selected' : '' ?>>قبول</option>
                            <option value="rejected" <?= $committeeFilter === 'rejected' ? 'selected' : '' ?>>رفض</option>
                        </select>
                        <select name="suspicion_level" onchange="autoSubmitFilters()">
                            <option value="">كل درجات الاشتباه</option>
                            <option value="high" <?= $suspicionFilter === 'high' ? 'selected' : '' ?>>اشتباه مرتفع</option>
                            <option value="medium" <?= $suspicionFilter === 'medium' ? 'selected' : '' ?>>اشتباه متوسط</option>
                            <option value="low" <?= $suspicionFilter === 'low' ? 'selected' : '' ?>>اشتباه منخفض</option>
                        </select>
                    </div>
                <?php else: ?>
                    <div class="filters-row">
                        <input type="text" name="search" value="<?= h($search) ?>" placeholder="🔍 ابحث بالاسم، الهاتف أو التوكن..." oninput="debounceSearch()">

                        <select name="status" onchange="autoSubmitFilters()">
                            <option value="">📂 كل حالات المرشحين</option>
                            <option value="new" <?= $statusFilter === 'new' ? 'selected' : '' ?>>جديد</option>
                            <option value="sent" <?= $statusFilter === 'sent' ? 'selected' : '' ?>>تم إنشاء رابط</option>
                            <option value="answered" <?= $statusFilter === 'answered' ? 'selected' : '' ?>>تم الرد</option>
                        </select>

                        <select name="link_status" onchange="autoSubmitFilters()">
                            <option value="">🔗 كل حالات الروابط</option>
                            <option value="active" <?= $linkFilter === 'active' ? 'selected' : '' ?>>روابط نشطة</option>
                            <option value="used" <?= $linkFilter === 'used' ? 'selected' : '' ?>>روابط مستخدمة</option>
                        </select>
                    </div>
                <?php endif; ?>
            </form>
        </section>

        <?php if ($selectedTab === 'dashboard'): ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <span class="stat-label">👥 المرشحون</span>
                    <span class="stat-value"><?= h((string)$stats['candidates']) ?></span>
                    <div class="stat-sub">إجمالي السجلات المسجلة</div>
                </div>
                <div class="stat-card">
                    <span class="stat-label">📝 الردود المكتملة</span>
                    <span class="stat-value"><?= h((string)$stats['submissions']) ?></span>
                    <div class="stat-sub">عدد الحالات التي أكملت النموذج</div>
                </div>
                <div class="stat-card">
                    <span class="stat-label">📉 متوسط الاحتياج</span>
                    <span class="stat-value"><?= h(number_format((float)$dashboardMetrics['avg_poverty'], 1)) ?>%</span>
                    <div class="stat-sub">متوسط نسبة الفقر المحسوبة</div>
                </div>
                <div class="stat-card">
                    <span class="stat-label">🔒 متوسط الثقة</span>
                    <span class="stat-value"><?= h(number_format((float)$dashboardMetrics['avg_trust'], 1)) ?>%</span>
                    <div class="stat-sub">موثوقية البيانات المدخلة</div>
                </div>
                <div class="stat-card">
                    <span class="stat-label">🚨 حالات مشتبه بها</span>
                    <span class="stat-value"><?= h((string)$dashboardMetrics['high_suspicion_count']) ?></span>
                    <div class="stat-sub">اشتباه مرتفع 35% فأكثر</div>
                </div>
                <div class="stat-card">
                    <span class="stat-label">⚡ أولوية مرتفعة</span>
                    <span class="stat-value"><?= h((string)$dashboardMetrics['high_need_count']) ?></span>
                    <div class="stat-sub">الحالات المصنفة high</div>
                </div>
            </div>

            <div class="hero-grid">
                <section class="section">
                    <div class="section-header">
                        <h2>أعلى الحالات أولوية الآن</h2>
                        <div class="section-note">مرتبطة بنسبة الاحتياج + الاشتباه</div>
                    </div>
                    <div class="priority-list">
                        <?php foreach ($prioritySubmissions as $row): $full = $submissionDetails[(int)$row['id']] ?? $row; $risk = responseRiskLabel($full); ?>
                            <div class="priority-item">
                                <div>
                                    <div style="font-weight:800;"><?= h((string)$row['full_name']) ?></div>
                                    <div class="muted"><?= h((string)$row['phone']) ?></div>
                                    <div style="margin-top:.45rem;">
                                        <span class="badge <?= h($risk['class']) ?>"><?= h($risk['label']) ?></span>
                                        <span class="badge <?= h(badgeClassByStatus((string)($full['committee_status'] ?? 'pending'))) ?>">
                                            <?= h(committeeStatusLabel((string)($full['committee_status'] ?? 'pending'))) ?>
                                        </span>
                                    </div>
                                </div>
                                <div style="text-align:left;">
                                    <div style="font-weight:800;font-size:1.25rem;"><?= h(number_format((float)($full['poverty_percent'] ?? 0), 1)) ?>%</div>
                                    <div class="muted">احتياج</div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$prioritySubmissions): ?>
                            <div class="muted">لا توجد بيانات كافية بعد.</div>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="section">
                    <div class="section-header">
                        <h2>نظرة تنفيذية سريعة</h2>
                    </div>
                    <div class="mini-grid">
                        <div class="mini-card">
                            <div class="muted">بحاجة مراجعة</div>
                            <div style="font-size:1.6rem;font-weight:800;"><?= h((string)$dashboardMetrics['review_count']) ?></div>
                        </div>
                        <div class="mini-card">
                            <div class="muted">جاهزة للطباعة</div>
                            <div style="font-size:1.6rem;font-weight:800;"><?= h((string)$dashboardMetrics['printable_ready_count']) ?></div>
                        </div>
                        <div class="mini-card">
                            <div class="muted">الروابط النشطة</div>
                            <div style="font-size:1.6rem;font-weight:800;"><?= h((string)max(0, $stats['links'] - $stats['used_links'])) ?></div>
                        </div>
                        <div class="mini-card">
                            <div class="muted">الروابط المستخدمة</div>
                            <div style="font-size:1.6rem;font-weight:800;"><?= h((string)$stats['used_links']) ?></div>
                        </div>
                    </div>
                </section>
            </div>

            <section class="section">
                <div class="section-header"><h2>وصول سريع للعمليات</h2></div>
                <div class="quick-actions">
                    <div class="quick-item">
                        <h4>الاستيراد الجماعي</h4>
                        <p>إضافة مرشحين من نص أو ملف TXT بشكل سريع.</p>
                        <a href="admin.php?tab=import" class="btn btn-primary">ابدأ الآن</a>
                    </div>
                    <div class="quick-item">
                        <h4>توليد الروابط</h4>
                        <p>إنشاء روابط جديدة لغير المسجلين وإعادة التنظيم.</p>
                        <a href="admin.php?tab=candidates" class="btn btn-success">فتح القائمة</a>
                    </div>
                    <div class="quick-item">
                        <h4>مراجعة الردود</h4>
                        <p>فلترة الحالات وفتح التقرير الكامل والطباعة.</p>
                        <a href="admin.php?tab=responses" class="btn btn-warning">الردود</a>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($selectedTab === 'import'): ?>
            <section class="section">
                <div class="section-header"><h2>استيراد من نص مباشر</h2></div>
                <form method="post" action="actions.php?action=import_candidates">
                    <?= csrfField() ?>
                    <textarea name="bulk_candidates" style="height:160px;margin-bottom:1rem;" placeholder="الاسم | الهاتف&#10;مثال: أحمد محمد | 777000000"></textarea>
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

        <?php if ($selectedTab === 'candidates'): ?>
            <section class="section">
                <div class="section-header"><h2>إضافة مرشح جديد</h2></div>
                <form method="post" action="actions.php?action=add_candidate">
                    <?= csrfField() ?>
                    <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:1rem;">
                        <input type="text" name="full_name" placeholder="الاسم الرباعي" required>
                        <input type="text" name="phone" placeholder="رقم الهاتف" required>
                        <button type="submit" class="btn btn-primary">إضافة</button>
                    </div>
                </form>
            </section>

            <section class="section">
                <div class="section-header"><h2>قائمة المرشحين</h2></div>
                <form method="post">
                    <?= csrfField() ?>
                    <div style="display:flex;gap:.6rem;margin-bottom:1.2rem;flex-wrap:wrap;">
                        <button type="submit" formaction="actions.php?action=generate_links_selected" class="btn btn-success">✨ توليد روابط للمحدد</button>
                        <button type="submit" formaction="actions.php?action=delete_candidates_selected" class="btn btn-danger" onclick="return confirm('حذف المحددين؟');">🗑️ حذف المحدد</button>
                    </div>

                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width:46px;"><input type="checkbox" onclick="toggleAll(this, 'candidate_ids')"></th>
                                    <th>#ID</th>
                                    <th>الاسم</th>
                                    <th>الهاتف</th>
                                    <th>الحالة</th>
                                    <th>عدد الروابط</th>
                                    <th>إجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($candidates as $row): ?>
                                <tr>
                                    <td><input type="checkbox" name="candidate_ids[]" value="<?= h((string)$row['id']) ?>"></td>
                                    <td><span class="muted">#<?= h((string)$row['id']) ?></span></td>
                                    <td><strong><?= h((string)$row['full_name']) ?></strong></td>
                                    <td><?= h((string)$row['phone']) ?></td>
                                    <td>
                                        <span class="badge <?= h(badgeClassByStatus((string)$row['status'])) ?>">
                                            <?= h(candidateStatusLabel((string)$row['status'])) ?>
                                        </span>
                                    </td>
                                    <td><?= h((string)($row['links_count'] ?? 0)) ?></td>
                                    <td>
                                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                            <form method="post" action="actions.php?action=generate_single_link">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="candidate_id" value="<?= h((string)$row['id']) ?>">
                                                <button class="btn btn-light" style="padding:.45rem .8rem;">🔗 رابط</button>
                                            </form>
                                            <form method="post" action="actions.php?action=delete_candidate" onsubmit="return confirm('حذف هذا المرشح؟');">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="candidate_id" value="<?= h((string)$row['id']) ?>">
                                                <button class="btn btn-danger" style="padding:.45rem .8rem;">🗑️ حذف</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$candidates): ?>
                                <tr><td colspan="7" class="muted">لا توجد نتائج مطابقة.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($selectedTab === 'links'): ?>
            <section class="section">
                <div class="section-header">
                    <h2>إدارة الروابط</h2>
                    <div class="section-note">نسخ سريع + حالة الاستخدام</div>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>المرشح</th>
                                <th>الحالة</th>
                                <th>الرابط</th>
                                <th>إجراءات</th>
                                <th>تاريخ الإنشاء</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($links as $row): $fullLink = buildStudyLink((string)$row['token']); ?>
                            <tr>
                                <td>
                                    <strong><?= h((string)$row['full_name']) ?></strong><br>
                                    <span class="muted"><?= h((string)$row['phone']) ?></span>
                                </td>
                                <td>
                                    <?php if ((int)$row['is_used'] === 1): ?>
                                        <span class="badge badge-success">مستخدم</span>
                                    <?php else: ?>
                                        <span class="badge badge-primary">نشط</span>
                                    <?php endif; ?>
                                </td>
                                <td><div class="copy-box"><?= h($fullLink) ?></div></td>
                                <td>
                                    <button class="btn btn-light" onclick="copyText('<?= h($fullLink) ?>')">نسخ</button>
                                </td>
                                <td><span class="muted"><?= h((string)$row['created_at']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$links): ?>
                            <tr><td colspan="5" class="muted">لا توجد روابط مطابقة.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($selectedTab === 'responses'): ?>
            <section class="section">
                <div class="section-header">
                    <h2>الردود والقرارات</h2>
                    <div class="section-note">عرض بطاقات + مؤشرات + فتح التقرير الكامل</div>
                </div>

                <div class="response-grid">
                    <?php foreach ($submissions as $row): $full = $submissionDetails[(int)$row['id']] ?? $row; $risk = responseRiskLabel($full); ?>
                        <div class="response-card">
                            <h3><?= h((string)$row['full_name']) ?></h3>
                            <div class="response-meta">
                                <div>📱 <?= h((string)$row['phone']) ?></div>
                                <div>🆔 رد رقم #<?= h((string)$row['id']) ?></div>
                            </div>

                            <div style="display:flex;gap:.45rem;flex-wrap:wrap;margin-bottom:.8rem;">
                                <span class="badge <?= h($risk['class']) ?>"><?= h($risk['label']) ?></span>
                                <span class="badge <?= h(badgeClassByStatus((string)($full['committee_status'] ?? 'pending'))) ?>">
                                    <?= h(committeeStatusLabel((string)($full['committee_status'] ?? 'pending'))) ?>
                                </span>
                                <span class="badge <?= h(badgeClassByStatus((string)($full['category'] ?? 'low'))) ?>">
                                    <?= h(studyCategoryLabel((string)($full['category'] ?? 'low'))) ?>
                                </span>
                            </div>

                            <div class="response-kpis">
                                <div class="response-kpi">
                                    <div class="k">نسبة الاحتياج</div>
                                    <div class="v"><?= h(number_format((float)($full['poverty_percent'] ?? 0), 1)) ?>%</div>
                                </div>
                                <div class="response-kpi">
                                    <div class="k">درجة الثقة</div>
                                    <div class="v"><?= h((string)((int)($full['trust_score'] ?? 0))) ?>%</div>
                                </div>
                                <div class="response-kpi">
                                    <div class="k">الاشتباه</div>
                                    <div class="v"><?= h((string)((int)($full['suspicion_score'] ?? 0))) ?>%</div>
                                </div>
                                <div class="response-kpi">
                                    <div class="k">الإشارات الحرجة</div>
                                    <div class="v"><?= h((string)((int)($full['critical_flags_count'] ?? 0))) ?></div>
                                </div>
                            </div>

                            <div class="response-actions">
                                <a href="result_ai.php?id=<?= h((string)$row['id']) ?>" class="btn btn-primary">عرض</a>
                                <a href="result_ai.php?id=<?= h((string)$row['id']) ?>&print=1" target="_blank" class="btn btn-light">🖨️ طباعة</a>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if (!$submissions): ?>
                        <div class="muted">لا توجد ردود مطابقة لهذه المرشحات.</div>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
