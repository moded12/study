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

$allowedTabs = ['dashboard', 'import', 'candidates', 'links', 'responses'];
if (!in_array($selectedTab, $allowedTabs, true)) {
    $selectedTab = 'dashboard';
}

$stats = getStudyStats();
$candidates = getStudyCandidates(500);
$links = getStudyLinks(500);
$submissions = getStudySubmissions(500);

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
            || mb_stripos((string)$row['phone'], $search) !== false;
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
            --bg-body: #f8fafc;
            --sidebar-bg: #0f172a;
            --card-bg: #ffffff;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --radius: 12px;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        * { box-sizing: border-box; outline: none; }
        body { 
            margin: 0; 
            font-family: 'IBM Plex Sans Arabic', sans-serif; 
            background: var(--bg-body); 
            color: var(--text-main);
            line-height: 1.6;
        }

        /* Layout Structure */
        .layout { display: grid; grid-template-columns: 280px 1fr; min-height: 100vh; }

        /* Sidebar */
        .sidebar { 
            background: var(--sidebar-bg); 
            color: #fff; 
            padding: 2rem 1.5rem; 
            position: sticky; 
            top: 0; 
            height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .brand { font-size: 1.5rem; font-weight: 700; color: #fff; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 10px; }
        .brand-sub { font-size: 0.85rem; color: #94a3b8; margin-bottom: 2.5rem; }

        .nav { display: flex; flex-direction: column; gap: 0.5rem; flex-grow: 1; }
        .nav a { 
            display: flex; 
            align-items: center; 
            padding: 0.8rem 1rem; 
            border-radius: var(--radius); 
            color: #cbd5e1; 
            transition: all 0.3s ease;
            font-size: 0.95rem;
            text-decoration: none;
        }
        .nav a:hover { background: rgba(255,255,255,0.05); color: #fff; }
        .nav a.active { background: var(--primary); color: #fff; box-shadow: 0 10px 15px -3px rgba(37, 99, 235, 0.3); }

        .sidebar-footer { border-top: 1px solid rgba(255,255,255,0.1); pt: 1.5rem; margin-top: auto; }
        .sidebar-card { 
            background: rgba(255,255,255,0.03); 
            border: 1px solid rgba(255,255,255,0.05);
            padding: 1rem; 
            border-radius: var(--radius);
            margin-bottom: 0.8rem;
        }
        .sidebar-card .title { font-size: 0.75rem; color: #94a3b8; text-transform: uppercase; }
        .sidebar-card .value { font-size: 1.25rem; font-weight: 700; color: #fff; }

        /* Main Content */
        .content { padding: 2.5rem; overflow-x: hidden; }
        .page-header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 2.5rem; }
        .page-title { font-size: 1.8rem; font-weight: 700; margin: 0; color: #0f172a; }
        .page-subtitle { color: var(--text-muted); font-size: 1rem; }

        /* Stats Grid */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem; margin-bottom: 2.5rem; }
        .stat-card { 
            background: var(--card-bg); 
            padding: 1.5rem; 
            border-radius: var(--radius); 
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            transition: transform 0.2s;
        }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-label { color: var(--text-muted); font-size: 0.9rem; font-weight: 500; }
        .stat-value { font-size: 2rem; font-weight: 800; color: var(--primary); display: block; margin-top: 0.5rem; }

        /* Sections & Components */
        .section { 
            background: var(--card-bg); 
            border-radius: var(--radius); 
            padding: 1.5rem; 
            margin-bottom: 2rem; 
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
        }
        .section-header { margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem; }
        .section-header h2 { font-size: 1.25rem; margin: 0; font-weight: 700; }

        /* Buttons */
        .btn { 
            display: inline-flex; 
            align-items: center; 
            justify-content: center; 
            padding: 0.6rem 1.2rem; 
            border-radius: 8px; 
            font-weight: 600; 
            font-size: 0.9rem; 
            cursor: pointer; 
            transition: all 0.2s; 
            border: none;
            gap: 8px;
            text-decoration: none;
        }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-success { background: #10b981; color: #fff; }
        .btn-warning { background: #f59e0b; color: #fff; }
        .btn-danger { background: #ef4444; color: #fff; }
        .btn-light { background: #f1f5f9; color: #475569; }
        .btn-light:hover { background: #e2e8f0; }

        /* Tables */
        .table-container { 
            overflow-x: auto; 
            margin: 0 -1.5rem; 
            padding: 0 1.5rem;
        }
        table { width: 100%; border-collapse: collapse; min-width: 900px; }
        th { background: #f8fafc; padding: 1rem; text-align: right; font-size: 0.85rem; color: var(--text-muted); font-weight: 600; border-bottom: 2px solid var(--border-color); }
        td { padding: 1rem; font-size: 0.95rem; border-bottom: 1px solid var(--border-color); vertical-align: middle; }
        tr:hover td { background: #fcfdfe; }

        /* Form Controls */
        input, select, textarea { 
            width: 100%; 
            padding: 0.75rem 1rem; 
            border: 1px solid var(--border-color); 
            border-radius: 8px; 
            font-family: inherit; 
            background: #fdfdfd;
            transition: border 0.2s;
        }
        input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1); }

        .filters-row { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 1rem; margin-bottom: 1rem; }

        /* Badges */
        .badge { padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; display: inline-block; }
        .badge-new { background: #dcfce7; color: #166534; }
        .badge-sent { background: #dbeafe; color: #1e40af; }
        .badge-used { background: #fef3c7; color: #92400e; }

        .copy-box { 
            font-family: monospace; 
            background: #f1f5f9; 
            padding: 0.5rem; 
            border-radius: 6px; 
            font-size: 0.8rem; 
            color: #475569;
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Response Cards */
        .response-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.5rem; }
        .response-card { 
            background: #fff; 
            border: 1px solid var(--border-color); 
            border-radius: var(--radius); 
            padding: 1.5rem;
            transition: all 0.3s;
        }
        .response-card:hover { box-shadow: 0 10px 20px rgba(0,0,0,0.05); }

        /* Quick Grid */
        .quick-actions { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem; }
        .quick-item { 
            background: #f8fafc; 
            border: 1px dashed var(--border-color); 
            padding: 1.5rem; 
            border-radius: var(--radius);
            text-align: center;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar { display: none; } /* يمكن تحويلها لـ Off-canvas لاحقاً */
            .content { padding: 1.5rem; }
            .quick-actions { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .filters-row { grid-template-columns: 1fr; }
            .page-header { flex-direction: column; align-items: flex-start; gap: 1rem; }
            .stats-grid { grid-template-columns: 1fr; }
        }
    </style>
    <script>
        let searchTimer = null;
        function autoSubmitFilters() { document.getElementById('filters-form').submit(); }
        function debounceSearch() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(autoSubmitFilters, 500);
        }
        function toggleAll(source, name) {
            const items = document.querySelectorAll('input[name="' + name + '[]"]');
            items.forEach(item => item.checked = source.checked);
        }
        function copyText(text) {
            navigator.clipboard.writeText(text).then(() => alert('✅ تم نسخ الرابط بنجاح'));
        }
    </script>
</head>
<body>

<div class="layout">
    <aside class="sidebar">
        <div class="brand">
            <span>🛡️ دراسة الحالة</span>
        </div>
        <div class="brand-sub">نظام تقييم الاحتياج الذكي</div>

        <nav class="nav">
            <a href="/zaka/admin/dashboard.php" style="border: 1px solid rgba(255,255,255,0.1); margin-bottom: 1rem;">
                <span style="margin-left: 10px;">🏠</span> الرئيسية
            </a>
            
            <a href="admin.php?tab=dashboard" class="<?= $selectedTab === 'dashboard' ? 'active' : '' ?>">
                <span style="margin-left: 10px;">📊</span> لوحة التحكم
            </a>
            <a href="admin.php?tab=import" class="<?= $selectedTab === 'import' ? 'active' : '' ?>">
                <span style="margin-left: 10px;">📥</span> استيراد البيانات
            </a>
            <a href="admin.php?tab=candidates" class="<?= $selectedTab === 'candidates' ? 'active' : '' ?>">
                <span style="margin-left: 10px;">👥</span> إدارة المرشحين
            </a>
            <a href="admin.php?tab=links" class="<?= $selectedTab === 'links' ? 'active' : '' ?>">
                <span style="margin-left: 10px;">🔗</span> الروابط المرسلة
            </a>
            <a href="admin.php?tab=responses" class="<?= $selectedTab === 'responses' ? 'active' : '' ?>">
                <span style="margin-left: 10px;">📝</span> الردود والقرارات
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-card">
                <div class="title">إجمالي المرشحين</div>
                <div class="value"><?= e((string)$stats['candidates']) ?></div>
            </div>
            <div class="sidebar-card">
                <div class="title">الردود المكتملة</div>
                <div class="value"><?= e((string)$stats['submissions']) ?></div>
            </div>
        </div>
    </aside>

    <main class="content">
        <header class="page-header">
            <div>
                <h1 class="page-title">مرحباً بك في نظام إدارة الدراسة</h1>
                <p class="page-subtitle">أنت تشاهد الآن: <?= e(ucfirst($selectedTab)) ?></p>
            </div>
            <div class="btn btn-light" style="font-size: 0.8rem; cursor: default;">
                📍 <?= e(date('Y-m-d')) ?>
            </div>
        </header>

        <div class="flash-messages"><?= renderFlash() ?></div>

        <section class="section">
            <form method="get" id="filters-form">
                <input type="hidden" name="tab" value="<?= e($selectedTab) ?>">
                <div class="filters-row">
                    <input type="text" name="search" value="<?= e($search) ?>" placeholder="🔍 ابحث بالاسم، الهاتف أو التوكن..." oninput="debounceSearch()">
                    
                    <select name="status" onchange="autoSubmitFilters()">
                        <option value="">📂 كل الحالات</option>
                        <option value="new" <?= $statusFilter === 'new' ? 'selected' : '' ?>>جديد</option>
                        <option value="sent" <?= $statusFilter === 'sent' ? 'selected' : '' ?>>تم الإرسال</option>
                        <option value="answered" <?= $statusFilter === 'answered' ? 'selected' : '' ?>>تم الرد</option>
                    </select>

                    <select name="link_status" onchange="autoSubmitFilters()">
                        <option value="">🔗 حالة الرابط</option>
                        <option value="active" <?= $linkFilter === 'active' ? 'selected' : '' ?>>روابط نشطة</option>
                        <option value="used" <?= $linkFilter === 'used' ? 'selected' : '' ?>>روابط مستخدمة</option>
                    </select>
                </div>
            </form>
        </section>

        <?php if ($selectedTab === 'dashboard'): ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <span class="stat-label">👥 المرشحون</span>
                    <span class="stat-value"><?= e((string)$stats['candidates']) ?></span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">🔗 الروابط</span>
                    <span class="stat-value"><?= e((string)$stats['links']) ?></span>
                </div>
                <div class="stat-card" style="border-right: 4px solid #10b981;">
                    <span class="stat-label">✅ روابط مستخدمة</span>
                    <span class="stat-value"><?= e((string)$stats['used_links']) ?></span>
                </div>
                <div class="stat-card" style="border-right: 4px solid #f59e0b;">
                    <span class="stat-label">📩 الردود</span>
                    <span class="stat-value"><?= e((string)$stats['submissions']) ?></span>
                </div>
            </div>

            <div class="section">
                <div class="section-header"><h2>🚀 وصول سريع للعمليات</h2></div>
                <div class="quick-actions">
                    <div class="quick-item">
                        <h4>الاستيراد الجماعي</h4>
                        <p class="muted small">إضافة مرشحين من ملفات نصية</p>
                        <a href="admin.php?tab=import" class="btn btn-primary">ابدأ الآن</a>
                    </div>
                    <div class="quick-item">
                        <h4>توليد الروابط</h4>
                        <p class="muted small">إنشاء روابط جديدة لغير المسجلين</p>
                        <a href="admin.php?tab=candidates" class="btn btn-success">فتح القائمة</a>
                    </div>
                    <div class="quick-item">
                        <h4>مراجعة القرارات</h4>
                        <p class="muted small">عرض الردود واتخاذ القرار</p>
                        <a href="admin.php?tab=responses" class="btn btn-warning">الردود</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($selectedTab === 'import'): ?>
            <div class="section">
                <div class="section-header"><h2>📥 استيراد من نص مباشر</h2></div>
                <form method="post" action="actions.php?action=import_candidates">
                    <?= csrfField() ?>
                    <textarea name="bulk_candidates" style="height: 150px; margin-bottom: 1rem;" placeholder="الاسم | الهاتف ... مثال: أحمد محمد | 777000000"></textarea>
                    <button type="submit" class="btn btn-primary">تأفيذ الاستيراد</button>
                </form>
            </div>

            <div class="section">
                <div class="section-header"><h2>📄 استيراد ملف TXT</h2></div>
                <form method="post" action="actions.php?action=import_candidates_file" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <div style="display: flex; gap: 1rem; align-items: center;">
                        <input type="file" name="txt_file" accept=".txt" required>
                        <button type="submit" class="btn btn-success">رفع الملف</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($selectedTab === 'candidates'): ?>
            <div class="section">
                <div class="section-header"><h2>👤 إضافة مرشح جديد</h2></div>
                <form method="post" action="actions.php?action=add_candidate">
                    <?= csrfField() ?>
                    <div style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 1rem;">
                        <input type="text" name="full_name" placeholder="الاسم الرباعي" required>
                        <input type="text" name="phone" placeholder="رقم الهاتف" required>
                        <button type="submit" class="btn btn-primary">إضافة</button>
                    </div>
                </form>
            </div>

            <div class="section">
                <form method="post">
                    <?= csrfField() ?>
                    <div style="display: flex; gap: 0.5rem; margin-bottom: 1.5rem; flex-wrap: wrap;">
                        <button type="submit" formaction="actions.php?action=generate_links_selected" class="btn btn-success">✨ توليد روابط للمحدد</button>
                        <button type="submit" formaction="actions.php?action=delete_candidates_selected" class="btn btn-danger" onclick="return confirm('حذف المحددين؟');">🗑️ حذف المحدد</button>
                    </div>

                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width: 40px;"><input type="checkbox" onclick="toggleAll(this, 'candidate_ids')"></th>
                                    <th>#ID</th>
                                    <th>الاسم</th>
                                    <th>الهاتف</th>
                                    <th>الحالة</th>
                                    <th>إجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($candidates as $row): ?>
                                <tr>
                                    <td><input type="checkbox" name="candidate_ids[]" value="<?= e((string)$row['id']) ?>"></td>
                                    <td><span class="muted">#<?= e((string)$row['id']) ?></span></td>
                                    <td><strong><?= e((string)$row['full_name']) ?></strong></td>
                                    <td><?= e((string)$row['phone']) ?></td>
                                    <td>
                                        <span class="badge badge-<?= e($row['status'] === 'new' ? 'new' : 'sent') ?>">
                                            <?= e(candidateStatusLabel((string)$row['status'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 5px;">
                                            <form method="post" action="actions.php?action=generate_single_link">
                                                <?= csrfField() ?><input type="hidden" name="candidate_id" value="<?= e((string)$row['id']) ?>">
                                                <button class="btn btn-light" style="padding: 5px 10px;">🔗 رابط</button>
                                            </form>
                                            <form method="post" action="actions.php?action=delete_candidate" onsubmit="return confirm('حذف؟')">
                                                <?= csrfField() ?><input type="hidden" name="candidate_id" value="<?= e((string)$row['id']) ?>">
                                                <button class="btn btn-danger" style="padding: 5px 10px;">🗑️</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($selectedTab === 'links'): ?>
            <div class="section">
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
                                <td><strong><?= e((string)$row['full_name']) ?></strong><br><small><?= e((string)$row['phone']) ?></small></td>
                                <td><?= (int)$row['is_used'] === 1 ? '<span class="badge badge-used">مستخدم</span>' : '<span class="badge badge-sent">نشط</span>' ?></td>
                                <td><div class="copy-box"><?= e($fullLink) ?></div></td>
                                <td>
                                    <button class="btn btn-light" onclick="copyText('<?= e($fullLink) ?>')">نسخ</button>
                                </td>
                                <td><span class="muted"><?= e((string)$row['created_at']) ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($selectedTab === 'responses'): ?>
            <div class="response-grid">
                <?php foreach ($submissions as $row): ?>
                <div class="response-card">
                    <h3 style="margin-top: 0;"><?= e((string)$row['full_name']) ?></h3>
                    <div style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 1rem;">
                        <div>📱 <?= e((string)$row['phone']) ?></div>
                        <div>📉 نسبة الاحتياج: <strong><?= e(number_format((float)$row['poverty_percent'], 2)) ?>%</strong></div>
                        <div>⚖️ القرار: <?= e(committeeStatusLabel((string)$row['committee_status'])) ?></div>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                        <a href="result_ai.php?id=<?= e((string)$row['id']) ?>" class="btn btn-primary">عرض</a>
                        <a href="result_ai.php?id=<?= e((string)$row['id']) ?>&print=1" target="_blank" class="btn btn-light">🖨️ طباعة</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </main>
</div>

</body>
</html>