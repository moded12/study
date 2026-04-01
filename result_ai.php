<?php
// /zaka/study/result_ai.php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/functions_ai.php';
require_once __DIR__ . '/includes/questions_ai.php';
require_once __DIR__ . '/config/study_texts.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function readSubmissionDetails(int $submissionId): array|false
{
    $stmt = db()->prepare(
        "SELECT
            s.*,
            c.full_name,
            c.phone,
            l.token
        FROM study_submissions s
        INNER JOIN study_candidates c ON c.id = s.candidate_id
        INNER JOIN study_links l ON l.id = s.link_id
        WHERE s.id = ?
        LIMIT 1"
    );
    $stmt->execute([$submissionId]);
    return $stmt->fetch();
}

function decodeJsonArray(mixed $value): array
{
    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function resultBadgeStyle(string $category): string
{
    return match ($category) {
        'high' => 'background:#fee2e2;color:#991b1b;',
        'medium' => 'background:#fef3c7;color:#92400e;',
        'review' => 'background:#ede9fe;color:#6d28d9;',
        default => 'background:#e5e7eb;color:#374151;',
    };
}

function boolTextFromMeta(string $value, string $yes = 'نعم', string $no = 'لا'): string
{
    return match (strtolower(trim($value))) {
        'yes', '1', 'true' => $yes,
        'no', '0', 'false' => $no,
        default => '-',
    };
}

function committeeLabelLocal(string $status): string
{
    return match ($status) {
        'approved' => 'موافقة',
        'rejected' => 'رفض',
        'reserve' => 'احتياط',
        'waiting' => 'انتظار',
        'review' => 'مراجعة',
        'pending' => 'بانتظار الفرز',
        default => committeeStatusLabel($status),
    };
}

function riskBadgeStyleLocal(int $suspicionScore, float $povertyPercent): array
{
    if ($suspicionScore >= 35) {
        return ['label' => '⚠ حالة مشتبه بها', 'style' => 'background:#fee2e2;color:#991b1b;'];
    }
    if ($povertyPercent >= 78) {
        return ['label' => 'أولوية قصوى', 'style' => 'background:#fee2e2;color:#991b1b;'];
    }
    if ($povertyPercent >= 58) {
        return ['label' => 'أولوية مرتفعة', 'style' => 'background:#fef3c7;color:#92400e;'];
    }
    return ['label' => 'مستقرة نسبيًا', 'style' => 'background:#e5e7eb;color:#374151;'];
}

$submissionId = max(0, (int)($_GET['id'] ?? 0));
$submitted = isset($_GET['submitted']) && (string)$_GET['submitted'] === '1';
$isPrint = isset($_GET['print']) && (string)$_GET['print'] === '1';

$submission = $submissionId > 0 ? readSubmissionDetails($submissionId) : false;
$answersRows = $submission ? getSubmissionAnswers($submissionId) : [];
$answers = normalizeAnswersInput($answersRows);
$flags = $submission ? decodeJsonArray($submission['flags_json'] ?? '') : [];
$notes = $submission ? decodeJsonArray($submission['notes_json'] ?? '') : [];
$snapshot = $submission ? decodeJsonArray($submission['answers_snapshot_json'] ?? '') : [];
$meta = is_array($snapshot['meta'] ?? null) ? $snapshot['meta'] : [];
$storedAnswers = is_array($snapshot['answers'] ?? null) ? normalizeAnswersInput($snapshot['answers']) : [];

if ($storedAnswers) {
    $answers = $storedAnswers;
}

$oathText = boolTextFromMeta((string)($meta['oath_confirmation'] ?? ''));
$locationConsentText = boolTextFromMeta((string)($meta['location_consent'] ?? ''));
$gpsValue = trim((string)($meta['gps'] ?? ''));
$addressText = trim((string)($meta['address_text'] ?? ''));
$mapsLink = trim((string)($meta['maps_link'] ?? ''));
$expectedAmount = trim((string)($meta['expected_amount'] ?? ''));
$areaValue = trim((string)($meta['area'] ?? ''));

$suspicionScore = (int)($submission['suspicion_score'] ?? 0);
$povertyPercent = (float)($submission['poverty_percent'] ?? 0);
$riskInfo = riskBadgeStyleLocal($suspicionScore, $povertyPercent);
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $submitted ? e(STUDY_RECEIVED_TITLE) : 'نتيجة الدراسة الذكية' ?></title>
    <style>
        :root{
            --bg:#f5f7fb;
            --card:#ffffff;
            --text:#1f2937;
            --muted:#6b7280;
            --line:#e5e7eb;
            --primary:#2563eb;
            --success:#16a34a;
            --warning:#d97706;
            --danger:#dc2626;
            --purple:#7c3aed;
            --shadow:0 8px 30px rgba(0,0,0,.08);
        }

        *{box-sizing:border-box}
        body{
            font-family:Tahoma,Arial,sans-serif;
            background:var(--bg);
            color:var(--text);
            margin:0;
            padding:0;
        }

        .wrap{
            max-width:1120px;
            margin:30px auto;
            padding:20px;
        }

        .card{
            background:#fff;
            border-radius:16px;
            box-shadow:var(--shadow);
            padding:22px;
            margin-bottom:18px;
        }

        .hero{
            background:linear-gradient(135deg,#0f172a,#1d4ed8);
            color:#fff;
        }

        .hero .muted{
            color:rgba(255,255,255,.82);
        }

        .muted{
            color:var(--muted);
            font-size:14px;
        }

        .grid{
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
            gap:14px;
        }

        .metric{
            background:#fafafa;
            border:1px solid var(--line);
            border-radius:14px;
            padding:16px;
        }

        .metric .label{
            font-size:13px;
            color:var(--muted);
            margin-bottom:8px;
        }

        .metric .value{
            font-size:28px;
            font-weight:700;
        }

        .metric .value.small{
            font-size:16px;
            line-height:1.8;
            word-break:break-word;
        }

        .badge{
            display:inline-block;
            padding:6px 12px;
            border-radius:999px;
            font-size:13px;
            font-weight:700;
        }

        .bar{
            height:10px;
            background:#e5e7eb;
            border-radius:999px;
            overflow:hidden;
            margin-top:10px;
        }

        .bar > span{
            display:block;
            height:100%;
            background:#111827;
        }

        table{
            width:100%;
            border-collapse:collapse;
        }

        th,td{
            padding:12px;
            border-bottom:1px solid var(--line);
            text-align:right;
            vertical-align:top;
        }

        th{
            background:#f8fafc;
            font-size:14px;
        }

        .empty{
            background:#fff7ed;
            color:#9a3412;
            padding:14px 16px;
            border-radius:12px;
        }

        .top-actions{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
        }

        .btn{
            display:inline-block;
            text-decoration:none;
            padding:10px 16px;
            border-radius:12px;
            background:#111827;
            color:#fff;
        }

        .flag{
            padding:10px 12px;
            border-radius:12px;
            margin-bottom:8px;
        }

        .flag-danger{background:#fee2e2;color:#991b1b}
        .flag-note{background:#eff6ff;color:#1d4ed8}
        .flag-neutral{background:#f3f4f6;color:#374151}

        .section-title{
            margin:0 0 14px 0;
        }

        .summary-table-wrap{
            overflow-x:auto;
        }

        @media print{
            body{background:#fff}
            .wrap{max-width:none;margin:0;padding:0}
            .card{box-shadow:none;border:1px solid #ddd}
            .no-print{display:none!important}
        }
    </style>
    <?php if ($isPrint): ?>
    <script>window.onload=function(){window.print();};</script>
    <?php endif; ?>
</head>
<body>
<div class="wrap">
    <?php if (!$submission): ?>
        <div class="card">
            <div class="empty">لم يتم العثور على النتيجة المطلوبة.</div>
        </div>

    <?php elseif ($submitted): ?>
        <div class="card" style="text-align:center;padding:70px 22px;">
            <h1><?= e(STUDY_RECEIVED_TITLE) ?></h1>
            <p style="font-size:18px;margin-top:20px;line-height:1.9;"><?= e(STUDY_RECEIVED_MESSAGE) ?></p>
        </div>

    <?php else: ?>
        <div class="card hero">
            <div class="top-actions no-print" style="margin-bottom:16px;">
                <a class="btn" href="?id=<?= (int)$submissionId ?>&print=1" target="_blank">طباعة</a>
            </div>

            <h1 class="section-title">نتيجة الدراسة الذكية</h1>
            <p class="muted">
                الاسم: <strong><?= e((string)$submission['full_name']) ?></strong>
                — الهاتف: <strong><?= e((string)$submission['phone']) ?></strong>
            </p>

            <p style="line-height:2;">
                <span class="badge" style="<?= e(resultBadgeStyle((string)$submission['category'])) ?>">
                    <?= e(studyCategoryLabel((string)$submission['category'])) ?>
                </span>
                &nbsp;
                <span class="badge" style="background:#dbeafe;color:#1d4ed8;">
                    <?= e((string)($submission['eligibility'] ?? '-')) ?>
                </span>
                &nbsp;
                <span class="badge" style="background:#dcfce7;color:#166534;">
                    قرار اللجنة: <?= e(committeeLabelLocal((string)($submission['committee_status'] ?? 'pending'))) ?>
                </span>
                &nbsp;
                <span class="badge" style="<?= e($riskInfo['style']) ?>">
                    <?= e($riskInfo['label']) ?>
                </span>
            </p>

            <?php if (!empty($submission['recommendation'])): ?>
                <p style="margin:14px 0 0;line-height:1.9;"><?= e((string)$submission['recommendation']) ?></p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2 class="section-title">المؤشرات الرئيسية</h2>
            <div class="grid">
                <div class="metric">
                    <div class="label">درجة الفقر النهائية</div>
                    <div class="value"><?= e(number_format((float)$submission['poverty_percent'], 2)) ?>%</div>
                </div>

                <div class="metric">
                    <div class="label">درجة الثقة</div>
                    <div class="value"><?= e((string)((int)$submission['trust_score'])) ?>%</div>
                </div>

                <div class="metric">
                    <div class="label">النتيجة قبل خصم الثقة</div>
                    <div class="value"><?= e(number_format((float)$submission['smart_score_before_trust'], 2)) ?></div>
                </div>

                <div class="metric">
                    <div class="label">النتيجة بعد خصم الثقة</div>
                    <div class="value"><?= e(number_format((float)$submission['trust_adjusted_score'], 2)) ?></div>
                </div>

                <div class="metric">
                    <div class="label">الاشتباه الأولي</div>
                    <div class="value"><?= e((string)$suspicionScore) ?>%</div>
                </div>

                <div class="metric">
                    <div class="label">الإشارات الحرجة</div>
                    <div class="value"><?= e((string)((int)($submission['critical_flags_count'] ?? 0))) ?></div>
                </div>

                <div class="metric">
                    <div class="label">الإشارات المتوسطة</div>
                    <div class="value"><?= e((string)((int)($submission['moderate_flags_count'] ?? 0))) ?></div>
                </div>

                <div class="metric">
                    <div class="label">إشارات الاتساق</div>
                    <div class="value"><?= e((string)((int)($submission['consistency_signals'] ?? 0))) ?></div>
                </div>
            </div>
        </div>

        <div class="card">
            <h2 class="section-title">المحاور الفرعية</h2>
            <?php
            $axes = [
                'الضغط المالي' => (float)($submission['financial_stress_percent'] ?? 0),
                'الضغط السكني' => (float)($submission['housing_stress_percent'] ?? 0),
                'الضغط الصحي' => (float)($submission['health_stress_percent'] ?? 0),
                'ضعف الدعم' => (float)($submission['support_weakness_percent'] ?? 0),
                'العبء الأسري' => (float)($submission['family_pressure_percent'] ?? 0),
            ];
            ?>
            <div class="grid">
                <?php foreach ($axes as $label => $percent): ?>
                    <div class="metric">
                        <div class="label"><?= e($label) ?></div>
                        <div class="value" style="font-size:22px"><?= e(number_format($percent, 2)) ?>%</div>
                        <div class="bar"><span style="width:<?= e((string)max(0, min(100, $percent))) ?>%"></span></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card">
            <h2 class="section-title">الإقرار والموقع والبيانات الإضافية</h2>
            <div class="grid">
                <div class="metric">
                    <div class="label">هل أقسم بصحة المعلومات؟</div>
                    <div class="value small"><?= e($oathText) ?></div>
                </div>

                <div class="metric">
                    <div class="label">موافقة مشاركة الموقع</div>
                    <div class="value small"><?= e($locationConsentText) ?></div>
                </div>

                <div class="metric">
                    <div class="label">إحداثيات GPS</div>
                    <div class="value small"><?= e($gpsValue !== '' ? $gpsValue : '-') ?></div>
                </div>

                <div class="metric">
                    <div class="label">العنوان النصي</div>
                    <div class="value small"><?= e($addressText !== '' ? $addressText : '-') ?></div>
                </div>

                <div class="metric">
                    <div class="label">رابط الخرائط</div>
                    <div class="value small">
                        <?= e($mapsLink !== '' ? $mapsLink : '-') ?>
                    </div>
                </div>

                <div class="metric">
                    <div class="label">المنطقة</div>
                    <div class="value small"><?= e($areaValue !== '' ? $areaValue : '-') ?></div>
                </div>

                <div class="metric">
                    <div class="label">المبلغ المتوقع</div>
                    <div class="value small"><?= e($expectedAmount !== '' ? $expectedAmount . ' دينار' : '-') ?></div>
                </div>

                <div class="metric">
                    <div class="label">التدقيق العشوائي</div>
                    <div class="value small"><?= !empty($submission['random_audit_triggered']) ? 'نعم' : 'لا' ?></div>
                </div>
            </div>
        </div>

        <div class="card">
            <h2 class="section-title">الإشارات والملاحظات</h2>

            <?php if ($flags): ?>
                <h3>Flags</h3>
                <?php foreach ($flags as $flag): ?>
                    <?php $flagClass = str_contains((string)$flag, '⚠️') ? 'flag-danger' : 'flag-neutral'; ?>
                    <div class="flag <?= $flagClass ?>"><?= e((string)$flag) ?></div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($notes): ?>
                <h3>Notes</h3>
                <?php foreach ($notes as $note): ?>
                    <div class="flag flag-note"><?= e((string)$note) ?></div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if (!$flags && !$notes): ?>
                <div class="muted">لا توجد إشارات مسجلة.</div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2 class="section-title">ملخص الإجابات</h2>
            <div class="summary-table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>رقم</th>
                        <th>السؤال</th>
                        <th>الإجابة</th>
                        <th>النوع</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($studyQuestions as $number => $question): ?>
                        <?php
                        $hasAnswer = array_key_exists($number, $answers);
                        $answerValue = $hasAnswer ? (int)$answers[$number] : null;
                        $answerLabel = $hasAnswer
                            ? (string)(($question['options'][$answerValue]['label'] ?? '-'))
                            : '-';
                        ?>
                        <tr>
                            <td><?= (int)$number ?></td>
                            <td><?= e((string)$question['text']) ?></td>
                            <td><?= e($answerLabel) ?></td>
                            <td><?= !empty($question['validation']) ? 'تحقق' : 'أساسي' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>