<?php
// /zaka/study/form_ai.php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/functions_ai.php';
require_once __DIR__ . '/includes/questions_ai.php';
require_once __DIR__ . '/config/study_texts.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function studyQuestionLabel(array $question, int $number): string
{
    return (!empty($question['validation']) ? 'سؤال تحقق' : 'سؤال أساسي') . ' #' . $number;
}

function getQuestionNumberByKeyLocal(string $wantedKey): ?int
{
    global $studyQuestions;

    foreach ($studyQuestions as $number => $question) {
        if (($question['key'] ?? null) === $wantedKey) {
            return (int)$number;
        }
    }

    return null;
}

function getValidationQuestionNumbersLocal(): array
{
    global $studyQuestions;

    $numbers = [];
    foreach ($studyQuestions as $number => $question) {
        if (!empty($question['validation'])) {
            $numbers[] = (int)$number;
        }
    }

    return $numbers;
}

function normalizeCaseTypeSelectionLocal(?int $answerValue, ?int $questionNumber = null): ?string
{
    global $studyQuestions;

    if ($answerValue === null || $questionNumber === null || !isset($studyQuestions[$questionNumber])) {
        return null;
    }

    $options = $studyQuestions[$questionNumber]['options'] ?? [];
    if (!isset($options[$answerValue])) {
        return null;
    }

    $option = $options[$answerValue];

    return (string)($option['case_key'] ?? $option['value'] ?? $option['key'] ?? '');
}

function questionVisibleForCaseLocal(array $question, ?string $selectedCaseType): bool
{
    $categories = $question['category'] ?? [];

    if (!$categories || !is_array($categories)) {
        return true;
    }

    if ($selectedCaseType === null || $selectedCaseType === '') {
        return false;
    }

    return in_array($selectedCaseType, $categories, true);
}

function questionVisibleByHideWhenLocal(array $question, array $values): bool
{
    $hideWhen = $question['hide_when'] ?? [];
    if (!$hideWhen || !is_array($hideWhen)) {
        return true;
    }

    foreach ($hideWhen as $dependencyKey => $blockedAnswers) {
        $dependencyNumber = getQuestionNumberByKeyLocal((string)$dependencyKey);
        if ($dependencyNumber === null) {
            continue;
        }

        if (!array_key_exists($dependencyNumber, $values)) {
            continue;
        }

        $answerValue = (int)$values[$dependencyNumber];
        $blocked = array_map('intval', (array)$blockedAnswers);

        if (in_array($answerValue, $blocked, true)) {
            return false;
        }
    }

    return true;
}

function questionVisibleFullLocal(array $question, ?string $selectedCaseType, array $values): bool
{
    return questionVisibleForCaseLocal($question, $selectedCaseType)
        && questionVisibleByHideWhenLocal($question, $values);
}

function getVisibleBaseQuestionNumbersLocal(?string $selectedCaseType, array $values): array
{
    global $studyQuestions;

    $numbers = [];
    foreach ($studyQuestions as $number => $question) {
        if (!empty($question['validation'])) {
            continue;
        }

        if (!questionVisibleFullLocal($question, $selectedCaseType, $values)) {
            continue;
        }

        $numbers[] = (int)$number;
    }

    return $numbers;
}

function estimateSuspicionLocal(array $byKey): int
{
    $score = 0;

    if (($byKey['monthly_income'] ?? 0) >= 3 && ($byKey['debts'] ?? 0) === 0) $score += 18;
    if (($byKey['monthly_income'] ?? 0) >= 3 && ($byKey['basic_needs'] ?? 0) <= 1) $score += 18;
    if (($byKey['assets'] ?? 0) <= 1 && ($byKey['urgency'] ?? 0) >= 2) $score += 15;
    if (($byKey['external_support'] ?? 0) === 0 && ($byKey['zakat_support'] ?? 0) <= 1) $score += 10;
    if (($byKey['employment_status'] ?? 0) >= 3 && ($byKey['monthly_income'] ?? 0) <= 1) $score += 12;
    if (($byKey['housing_status'] ?? 0) === 0 && ($byKey['housing_space'] ?? 0) >= 2) $score += 10;
    if (($byKey['health_condition'] ?? 0) >= 3 && ($byKey['basic_needs'] ?? 0) <= 1) $score += 8;
    if (($byKey['urgency'] ?? 0) >= 3 && ($byKey['monthly_income'] ?? 0) <= 1 && ($byKey['basic_needs'] ?? 0) <= 1) $score += 15;

    if (($byKey['financial_fragility_priority'] ?? 0) >= 2 && ($byKey['income_shortfall_effect'] ?? 0) >= 2) $score += 10;
    if (($byKey['utility_cutoff_risk'] ?? 0) >= 2 && ($byKey['bill_payment_ability'] ?? 0) <= 1) $score += 8;
    if (($byKey['education_disruption_due_to_poverty'] ?? 0) >= 2 && ($byKey['children_schooling_status'] ?? 0) === 0) $score += 7;
    if (($byKey['has_sanad_app'] ?? 0) >= 2 && ($byKey['monthly_income'] ?? 0) <= 1) $score += 5;

    return min(100, $score);
}

function shouldForceValidationQuestionsLocal(array $answers): array
{
    $byKey = normalizeKeyAlias(mapAnswersByQuestionKey($answers));
    $suspicionScore = estimateSuspicionLocal($byKey);

    try {
        $randomAudit = random_int(1, 100) <= 15;
    } catch (Throwable $e) {
        $randomAudit = false;
    }

    return [
        'forced' => $suspicionScore >= 35 || $randomAudit,
        'suspicion_score' => $suspicionScore,
        'random_audit' => $randomAudit,
    ];
}

$questionNumbers = [
    'case_type' => getQuestionNumberByKeyLocal('case_type'),
    'monthly_income' => getQuestionNumberByKeyLocal('monthly_income'),
    'employment_status' => getQuestionNumberByKeyLocal('employment_status'),
    'housing_status' => getQuestionNumberByKeyLocal('housing_status'),
    'housing_space' => getQuestionNumberByKeyLocal('housing_space'),
    'external_support' => getQuestionNumberByKeyLocal('external_support'),
    'zakat_support' => getQuestionNumberByKeyLocal('zakat_support'),
    'health_condition' => getQuestionNumberByKeyLocal('health_condition'),
    'debts' => getQuestionNumberByKeyLocal('debts'),
    'basic_needs' => getQuestionNumberByKeyLocal('basic_needs'),
    'assets' => getQuestionNumberByKeyLocal('assets'),
    'urgency' => getQuestionNumberByKeyLocal('urgency'),
    'bill_payment_ability' => getQuestionNumberByKeyLocal('bill_payment_ability'),
    'children_schooling_status' => getQuestionNumberByKeyLocal('children_schooling_status'),
    'financial_fragility_priority' => getQuestionNumberByKeyLocal('financial_fragility_priority'),
    'income_shortfall_effect' => getQuestionNumberByKeyLocal('income_shortfall_effect'),
    'utility_cutoff_risk' => getQuestionNumberByKeyLocal('utility_cutoff_risk'),
    'education_disruption_due_to_poverty' => getQuestionNumberByKeyLocal('education_disruption_due_to_poverty'),
    'has_sanad_app' => getQuestionNumberByKeyLocal('has_sanad_app'),
];

$caseTypeQuestionNumber = $questionNumbers['case_type'];

$token = clean((string)($_GET['t'] ?? $_POST['token'] ?? ''));
$errors = [];
$values = [];

$meta = [
    'oath_confirmation' => clean((string)($_POST['oath_confirmation'] ?? '')),
    'location_consent' => clean((string)($_POST['location_consent'] ?? '')),
    'gps' => clean((string)($_POST['gps'] ?? '')),
    'address_text' => clean((string)($_POST['address_text'] ?? '')),
    'maps_link' => clean((string)($_POST['maps_link'] ?? '')),
    'expected_amount' => clean((string)($_POST['expected_amount'] ?? '')),
    'area' => clean((string)($_POST['area'] ?? '')),
];

$gateDeclined = false;
$showFormInitially = false;
$validationState = ['forced' => false, 'suspicion_score' => 0, 'random_audit' => false];
$selectedValidationNumbers = [];

$validation = validateUsableLinkByToken($token);
$link = $validation['link'] ?? null;
$candidate = $validation['candidate'] ?? null;

if (!$validation['ok']) {
    http_response_code(404);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validation['ok']) {
    $oathAccepted = $meta['oath_confirmation'] === 'yes';
    $locationAccepted = $meta['location_consent'] === 'yes';

    if (!$oathAccepted || !$locationAccepted) {
        $gateDeclined = true;
    } else {
        $showFormInitially = true;

        foreach ($studyQuestions as $number => $question) {
            if (isset($_POST['answers'][$number]) && is_scalar($_POST['answers'][$number])) {
                $values[(int)$number] = max(0, min(3, (int)$_POST['answers'][$number]));
            }
        }

        if (empty($values)) {
            $errors[] = 'لم يتم إرسال أي إجابات.';
        }

        $selectedCaseType = normalizeCaseTypeSelectionLocal(
            isset($values[(int)$caseTypeQuestionNumber]) ? (int)$values[(int)$caseTypeQuestionNumber] : null,
            $caseTypeQuestionNumber
        );

        $visibleBaseQuestionNumbers = getVisibleBaseQuestionNumbersLocal($selectedCaseType, $values);
        foreach ($visibleBaseQuestionNumbers as $qNumber) {
            if (!array_key_exists($qNumber, $values)) {
                $errors[] = 'يرجى الإجابة عن جميع الأسئلة المطلوبة لهذه الفئة.';
                break;
            }
        }

        if (!$errors) {
            $validationState = shouldForceValidationQuestionsLocal($values);

            if ($validationState['forced']) {
                $selectedValidationNumbers = array_map('intval', (array)($_POST['selected_validation_numbers'] ?? []));
                if (!$selectedValidationNumbers) {
                    $validationNumbers = getValidationQuestionNumbersLocal();
                    shuffle($validationNumbers);
                    $selectedValidationNumbers = array_slice($validationNumbers, 0, min(3, count($validationNumbers)));
                    sort($selectedValidationNumbers);
                }

                foreach ($selectedValidationNumbers as $qNumber) {
                    if (!array_key_exists($qNumber, $values)) {
                        $errors[] = 'تم تفعيل أسئلة التحقق الإضافية، يرجى إكمالها جميعاً.';
                        break;
                    }
                }
            }
        }

        if (!$errors) {
            try {
                $pdo = db();
                $pdo->beginTransaction();

                if (candidateHasSubmission((int)$candidate['id'])) {
                    throw new RuntimeException('تم إرسال هذه الحالة مسبقاً.');
                }

                $result = calculateResult($values);
                $committeeStatus = $result['category'] === 'review' ? 'review' : 'pending';
                $committeeNotes = implode("\n", array_filter(array_merge([
                    'الاستحقاق: ' . ($result['eligibility'] ?? '-'),
                    'درجة الثقة: ' . ($result['trust_score'] ?? 0) . '%',
                    'درجة الاشتباه الأولية في النموذج: ' . ($validationState['suspicion_score'] ?? 0) . '%',
                    'القسم قبل البدء: موافق',
                    'موافقة مشاركة الموقع: موافق',
                    'المنطقة: ' . ($meta['area'] !== '' ? $meta['area'] : '-'),
                    'المبلغ المتوقع: ' . ($meta['expected_amount'] !== '' ? $meta['expected_amount'] : '-'),
                ], $result['flags'] ?? [])));

                $snapshot = [
                    'answers' => $values,
                    'meta' => $meta,
                ];

                $insert = $pdo->prepare(
                    "INSERT INTO study_submissions (
                        candidate_id, link_id, total_score, poverty_percent, category,
                        committee_status, committee_notes, trust_score, trust_penalty,
                        eligibility, critical_flags_count, moderate_flags_count,
                        consistency_signals, smart_score_before_trust, trust_adjusted_score,
                        financial_stress_percent, housing_stress_percent, health_stress_percent,
                        support_weakness_percent, family_pressure_percent, suspicion_score,
                        random_audit_triggered, answers_snapshot_json, flags_json, notes_json, submitted_at
                    ) VALUES (
                        :candidate_id, :link_id, :total_score, :poverty_percent, :category,
                        :committee_status, :committee_notes, :trust_score, :trust_penalty,
                        :eligibility, :critical_flags_count, :moderate_flags_count,
                        :consistency_signals, :smart_score_before_trust, :trust_adjusted_score,
                        :financial_stress_percent, :housing_stress_percent, :health_stress_percent,
                        :support_weakness_percent, :family_pressure_percent, :suspicion_score,
                        :random_audit_triggered, :answers_snapshot_json, :flags_json, :notes_json, NOW()
                    )"
                );

                $insert->execute([
                    ':candidate_id' => (int)$candidate['id'],
                    ':link_id' => (int)$link['id'],
                    ':total_score' => (float)$result['total_score'],
                    ':poverty_percent' => (float)$result['poverty_percent'],
                    ':category' => (string)$result['category'],
                    ':committee_status' => $committeeStatus,
                    ':committee_notes' => $committeeNotes,
                    ':trust_score' => (int)$result['trust_score'],
                    ':trust_penalty' => (int)$result['trust_penalty'],
                    ':eligibility' => (string)$result['eligibility'],
                    ':critical_flags_count' => (int)$result['critical_flags_count'],
                    ':moderate_flags_count' => (int)$result['moderate_flags_count'],
                    ':consistency_signals' => (int)$result['consistency_signals'],
                    ':smart_score_before_trust' => (float)$result['smart_score_before_trust'],
                    ':trust_adjusted_score' => (float)$result['trust_adjusted_score'],
                    ':financial_stress_percent' => (float)$result['financial_stress_percent'],
                    ':housing_stress_percent' => (float)$result['housing_stress_percent'],
                    ':health_stress_percent' => (float)$result['health_stress_percent'],
                    ':support_weakness_percent' => (float)$result['support_weakness_percent'],
                    ':family_pressure_percent' => (float)$result['family_pressure_percent'],
                    ':suspicion_score' => (int)$validationState['suspicion_score'],
                    ':random_audit_triggered' => !empty($validationState['random_audit']) ? 1 : 0,
                    ':answers_snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ':flags_json' => json_encode($result['flags'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ':notes_json' => json_encode($result['notes'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);

                $submissionId = (int)$pdo->lastInsertId();

                $answerStmt = $pdo->prepare(
                    'INSERT INTO study_answers (submission_id, question_number, answer_value) VALUES (:submission_id, :question_number, :answer_value)'
                );

                foreach ($values as $questionNumber => $answerValue) {
                    $answerStmt->execute([
                        ':submission_id' => $submissionId,
                        ':question_number' => (int)$questionNumber,
                        ':answer_value' => (int)$answerValue,
                    ]);
                }

                $pdo->prepare('UPDATE study_links SET is_used = 1, used_at = NOW() WHERE id = ? LIMIT 1')
                    ->execute([(int)$link['id']]);

                $pdo->prepare("UPDATE study_candidates SET status = 'answered' WHERE id = ? LIMIT 1")
                    ->execute([(int)$candidate['id']]);

                $pdo->commit();

                header('Location: result_ai.php?id=' . $submissionId . '&submitted=1');
                exit;
            } catch (Throwable $e) {
                if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'تعذر حفظ الإجابات: ' . $e->getMessage();
            }
        }
    }
}

$selectedCaseType = normalizeCaseTypeSelectionLocal(
    isset($values[(int)$caseTypeQuestionNumber]) ? (int)$values[(int)$caseTypeQuestionNumber] : null,
    $caseTypeQuestionNumber
);

$validationQuestions = [];
$baseQuestions = [];
foreach ($studyQuestions as $number => $question) {
    if (!empty($question['validation'])) {
        $validationQuestions[$number] = $question;
    } else {
        $baseQuestions[$number] = $question;
    }
}

$selectedValidationQuestions = [];
if (!empty($validationState['forced']) && $validationQuestions) {
    if (!$selectedValidationNumbers) {
        $validationNumbers = array_keys($validationQuestions);
        shuffle($validationNumbers);
        $selectedValidationNumbers = array_slice($validationNumbers, 0, min(3, count($validationNumbers)));
        sort($selectedValidationNumbers);
    }

    foreach ($selectedValidationNumbers as $number) {
        if (isset($validationQuestions[$number])) {
            $selectedValidationQuestions[$number] = $validationQuestions[$number];
        }
    }
}

$baseQuestionsPerStep = 4;
$formClass = $showFormInitially ? '' : 'hidden';
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<link href="https://fonts.googleapis.com/css2?family=Cairo&display=swap" rel="stylesheet">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>نموذج دراسة الحالة الذكي</title>
    <style>
        :root{
            --bg:#f5f7fb;
            --card:#ffffff;
            --text:#1f2937;
            --muted:#6b7280;
            --line:#e5e7eb;
            --primary:#2563eb;
            --primary-dark:#1d4ed8;
            --primary-soft:#eff6ff;
            --danger-bg:#fee2e2;
            --danger-text:#991b1b;
            --warning-bg:#fef3c7;
            --warning-text:#92400e;
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
            max-width:1100px;
            margin:30px auto;
            padding:20px;
        }
        .card{
            background:var(--card);
            border-radius:18px;
            box-shadow:var(--shadow);
            padding:22px;
            margin-bottom:18px;
        }
        .hero{
            background:linear-gradient(135deg,#0f172a,#1d4ed8);
            color:#fff;
        }
        .hero .muted{color:rgba(255,255,255,.82)}
        h1,h2,h3{margin-top:0}
        .muted{color:var(--muted);font-size:14px}
        .alert{
            padding:14px 16px;
            border-radius:12px;
            margin-bottom:16px;
        }
        .alert-danger{background:var(--danger-bg);color:var(--danger-text)}
        .alert-warning{background:var(--warning-bg);color:var(--warning-text)}
        .grid{display:grid;gap:16px}
        .q{
            border:1px solid var(--line);
            border-radius:14px;
            padding:16px;
            background:#fafafa;
            scroll-margin-top:150px;
        }
        .q.current-focus{
            border-color:var(--primary);
            box-shadow:0 0 0 3px rgba(37,99,235,.08);
            background:#fff;
        }
        .q-title{
            font-weight:700;
            margin-bottom:8px;
            line-height:1.9;
        }
        .badge{
            display:inline-block;
            font-size:12px;
            padding:4px 10px;
            border-radius:999px;
            background:#eef2ff;
            color:#3730a3;
            margin-bottom:10px;
        }
        .options label{
            display:block;
            padding:10px 12px;
            border-radius:10px;
            margin-bottom:8px;
            background:#fff;
            border:1px solid var(--line);
            cursor:pointer;
            transition:.15s ease;
        }
        .options label:hover{
            border-color:#cbd5e1;
            background:#f8fafc;
        }
        .options label.selected{
            border-color:var(--primary);
            background:var(--primary-soft);
        }
        .options input{margin-left:8px}
        .btn{
            border:none;
            background:#111827;
            color:#fff;
            padding:14px 22px;
            border-radius:12px;
            cursor:pointer;
            font-size:15px;
            transition:.15s ease;
            font-family:inherit;
        }
        .btn:hover{opacity:.93}
        .btn:disabled{opacity:.55;cursor:not-allowed}
        .btn-primary{background:var(--primary)}
        .btn-primary:hover{background:var(--primary-dark)}
        .btn-light{background:#f1f5f9;color:#334155}
        .btn-row{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            align-items:center;
            justify-content:space-between;
        }
        .btn-actions{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
        }
        .validation-box{display:none}
        .validation-box.active{display:block}
        .footer-note{
            font-size:13px;
            color:var(--muted);
            line-height:1.8;
            margin:12px 0 0;
        }
        .location-row,
        .meta-row{
            display:grid;
            gap:12px;
            grid-template-columns:repeat(auto-fit,minmax(240px,1fr));
        }
        .field-text{
            width:100%;
            padding:12px;
            border-radius:12px;
            border:1px solid #d1d5db;
            background:#fff;
            font-family:inherit;
            font-size:14px;
        }
        .question-hidden{display:none !important}
        .gate-box{max-width:760px;margin:0 auto 20px auto}
        .thank-you{text-align:center;padding:70px 20px}
        .hidden{display:none !important}

        .progress-wrap{
            position:sticky;
            top:10px;
            z-index:5;
            background:rgba(245,247,251,.92);
            backdrop-filter:blur(8px);
            padding:8px 0 14px;
        }
        .progress-head{
            display:flex;
            justify-content:space-between;
            gap:12px;
            align-items:center;
            margin-bottom:10px;
            flex-wrap:wrap;
        }
        .progress-text{
            font-size:14px;
            color:#334155;
            font-weight:700;
        }
        .progress-bar{
            width:100%;
            height:12px;
            background:#e5e7eb;
            border-radius:999px;
            overflow:hidden;
        }
        .progress-bar > span{
            display:block;
            height:100%;
            width:0;
            background:linear-gradient(90deg,#2563eb,#3b82f6);
            transition:width .2s ease;
            border-radius:999px;
        }
        .step-hint{
            color:var(--muted);
            font-size:13px;
            margin-top:8px;
        }

        .questions-card-head{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
            flex-wrap:wrap;
            margin-bottom:14px;
        }
        .step-chip{
            background:var(--primary-soft);
            color:var(--primary-dark);
            padding:8px 12px;
            border-radius:999px;
            font-size:13px;
            font-weight:700;
        }
        .site-footer{
            text-align:center;
            color:var(--muted);
            font-size:13px;
            padding:8px 0 24px;
        }

        @media (max-width: 700px){
            .wrap{padding:14px}
            .card{padding:16px}
            .btn{width:100%}
            .btn-actions{width:100%}
            .btn-actions .btn{flex:1 1 100%}
        }
    </style>
</head>
<body>
<div class="wrap">

<div style="background:linear-gradient(90deg,#d32f2f,#b71c1c); 
            color:white; 
            padding:20px 12px 8px; 
            font-size:20px; 
            font-weight:bold; 
            border-radius:12px 12px 0 0; 
            text-align:center;">
    لجنة زكاة وصدقات مخيم حطين
</div>

<div class="card hero" style="font-family:'Cairo', sans-serif; direction:rtl; text-align:center; padding:20px; border-radius:0 0 12px 12px; box-shadow:0 2px 10px rgba(0,0,0,0.08); background:#2c3e50;">
    
    <h2 style="margin-bottom:10px; color:white; font-size:28px; font-weight:bold;">
        مقدمة الدراسة
    </h2>

    <p class="muted" style="line-height:2; font-size:15px; text-align:justify; color:#f1f1f1;">
        عزيزي المستفيد/ة،
        نسعى من خلال هذه الاستبانة إلى تطوير خدمات لجنة الزكاة وضمان وصول المساعدات لمستحقيها،
        وذلك عبر فهم احتياجات الأسر وتحديد أولوياتها.
        نؤكد أن جميع البيانات ستبقى سرية وتُستخدم فقط لتحسين الخدمات.
    </p>

<div style="background:linear-gradient(90deg,#d32f2f,#b71c1c); 
            color:white; 
            padding:20px 12px 8px; 
            font-size:20px; 
            font-weight:bold; 
            border-radius:12px 12px 0 0; 
            text-align:center;">
    لجنة زكاة وصدقات مخيم حطين
</div>

</div>

    <?php if ($validation['ok']): ?>
        <div class="card">
            <h1>نموذج دراسة الحالة الذكي</h1>
            <p class="muted">
                المرشح: <strong><?= e((string)$candidate['full_name']) ?></strong>
                — الهاتف: <strong><?= e((string)$candidate['phone']) ?></strong>
            </p>
            <p class="muted">
                يرجى الإجابة بدقة. تظهر الأسئلة المناسبة لفئة الحالة فقط، وقد تظهر أسئلة تحقق إضافية تلقائيًا لتعزيز دقة الدراسة.
            </p>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="alert alert-danger"><?= e((string)$validation['message']) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($validation['ok'] && $gateDeclined): ?>
        <div class="card thank-you">
            <h2>شكرًا لك</h2>
            <p class="muted" style="font-size:16px;line-height:2;">
                لا يمكن بدء الاستبيان إلا بعد الإقرار بالقسم والموافقة على مشاركة الموقع.
            </p>
        </div>
    <?php endif; ?>

    <?php if ($validation['ok'] && !$gateDeclined): ?>
        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?>
                    <div><?= e($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($validationState['forced'])): ?>
            <div class="alert alert-warning">تم تفعيل أسئلة تحقق إضافية لرفع دقة الدراسة.</div>
        <?php endif; ?>

        <div class="card gate-box <?= $showFormInitially ? 'hidden' : '' ?>" id="entryGateBox">
            <h2 style="margin-bottom:12px;">قبل بدء الاستبيان</h2>

            <div class="q">
                <div class="q-title">هل تقسم بالله العظيم أن جميع المعلومات التي ستذكرها هنا صحيحة، والله على ما أقول شهيد؟</div>
                <div class="options">
                    <label><input type="radio" name="gate_oath" value="yes" <?= $meta['oath_confirmation'] === 'yes' ? 'checked' : '' ?>> أقسم بالله العظيم</label>
                    <label><input type="radio" name="gate_oath" value="no" <?= $meta['oath_confirmation'] === 'no' ? 'checked' : '' ?>> لا أوافق</label>
                </div>
            </div>

            <div class="q" style="margin-top:14px;">
                <div class="q-title">هل توافق على إكمال الطلب ومشاركته ؟</div>
                <div class="options">
                    <label><input type="radio" name="gate_location" value="yes" <?= $meta['location_consent'] === 'yes' ? 'checked' : '' ?>> نعم، أوافق</label>
                    <label><input type="radio" name="gate_location" value="no" <?= $meta['location_consent'] === 'no' ? 'checked' : '' ?>> لا أوافق</label>
                </div>
            </div>

            <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;">
                <button type="button" class="btn btn-primary" id="startSurveyBtn">بدء الاستبيان</button>
                <button type="button" class="btn btn-light" id="declineSurveyBtn">عدم المتابعة</button>
            </div>
        </div>

        <div class="card thank-you hidden" id="gateThankYouBox">
            <h2>شكرًا لك</h2>
            <p class="muted" style="font-size:16px;line-height:2;">
                لا يمكن متابعة تعبئة الاستبيان بدون القسم والموافقة على مشاركة الموقع.
            </p>
        </div>

        <form method="post" action="" id="studyForm" class="<?= e($formClass) ?>">
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <input type="hidden" name="oath_confirmation" id="oathConfirmationInput" value="<?= e($meta['oath_confirmation']) ?>">
            <input type="hidden" name="location_consent" id="locationConsentInput" value="<?= e($meta['location_consent']) ?>">

            <?php foreach ($selectedValidationNumbers as $validationNumber): ?>
                <input type="hidden" name="selected_validation_numbers[]" value="<?= (int)$validationNumber ?>">
            <?php endforeach; ?>

            <div id="validationQuestionsBox"
                 data-server-forced="<?= !empty($validationState['forced']) ? '1' : '' ?>"
                 class="card validation-box <?= !empty($validationState['forced']) ? 'active' : '' ?>">
                <h2>أسئلة تحقق إضافية</h2>
                <p class="muted"><?= e(STUDY_VALIDATION_BOX_TEXT) ?></p>
                <div class="grid">
                    <?php foreach ($selectedValidationQuestions as $number => $question): ?>
                        <div class="q validation-question" data-question-number="<?= (int)$number ?>">
                            <div class="badge"><?= e(studyQuestionLabel($question, (int)$number)) ?></div>
                            <div class="q-title"><?= e((string)$question['text']) ?></div>
                            <div class="options">
                                <?php foreach (($question['options'] ?? []) as $optionValue => $option): ?>
                                    <label class="<?= isset($values[$number]) && (int)$values[$number] === (int)$optionValue ? 'selected' : '' ?>">
                                        <input type="radio"
                                               name="answers[<?= (int)$number ?>]"
                                               value="<?= (int)$optionValue ?>"
                                               <?= isset($values[$number]) && (int)$values[$number] === (int)$optionValue ? 'checked' : '' ?>>
                                        <?= e((string)($option['label'] ?? '')) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="progress-wrap">
                <div class="progress-head">
                    <div class="progress-text" id="progressText">الخطوة 1 من 1</div>
                    <div class="step-chip" id="stepChip">4 أسئلة في هذه الخطوة</div>
                </div>
                <div class="progress-bar">
                    <span id="progressBarFill"></span>
                </div>
                <div class="step-hint">بعد اختيار الإجابة ينتقل المؤشر تلقائيًا إلى السؤال التالي لتسريع تعبئة الطلب.</div>
            </div>

            <div class="card" id="baseQuestionsCard">
                <div class="questions-card-head">
                    <h2 style="margin:0;">الأسئلة الأساسية</h2>
                    <div class="step-chip" id="visibleCountChip">0 سؤال ظاهر</div>
                </div>

                <div class="grid" id="baseQuestionsGrid">
                    <?php foreach ($baseQuestions as $number => $question): ?>
                        <?php
                        $categories = $question['category'] ?? [];
                        $categoryAttr = '';
                        if (is_array($categories) && $categories) {
                            $categoryAttr = implode(',', array_map('strval', $categories));
                        }

                        $hideWhen = $question['hide_when'] ?? [];
                        $hideWhenJson = $hideWhen ? json_encode($hideWhen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';

                        $isVisible = questionVisibleFullLocal($question, $selectedCaseType, $values);
                        ?>
                        <div class="q base-question <?= $isVisible ? '' : 'question-hidden' ?>"
                             data-question-number="<?= (int)$number ?>"
                             data-question-key="<?= e((string)($question['key'] ?? '')) ?>"
                             data-categories="<?= e($categoryAttr) ?>"
                             data-hide-when='<?= e((string)$hideWhenJson) ?>'
                             data-step-index="0">
                            <div class="badge"><?= e(studyQuestionLabel($question, (int)$number)) ?></div>
                            <div class="q-title"><?= e((string)$question['text']) ?></div>
                            <div class="options">
                                <?php foreach (($question['options'] ?? []) as $optionValue => $option): ?>
                                    <?php
                                    $isCaseTypeQuestion = ((int)$number === (int)$caseTypeQuestionNumber);
                                    $caseKeyAttr = $isCaseTypeQuestion ? (string)($option['case_key'] ?? '') : '';
                                    $isChecked = isset($values[$number]) && (int)$values[$number] === (int)$optionValue;
                                    ?>
                                    <label class="<?= $isChecked ? 'selected' : '' ?>">
                                        <input type="radio"
                                               name="answers[<?= (int)$number ?>]"
                                               value="<?= (int)$optionValue ?>"
                                               <?= $isCaseTypeQuestion ? 'data-case-key="' . e($caseKeyAttr) . '"' : '' ?>
                                               <?= $isChecked ? 'checked' : '' ?>>
                                        <?= e((string)($option['label'] ?? '')) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card">
                <h2>بيانات إضافية</h2>
                <div class="meta-row">
                    <input class="field-text" type="number" name="expected_amount" placeholder="كم تتوقع المبلغ المطلوب؟" value="<?= e($meta['expected_amount']) ?>">
                    <input class="field-text" type="text" name="area" placeholder="المنطقة" value="<?= e($meta['area']) ?>">
                </div>
                <p class="footer-note">هذه البيانات تظهر للإدارة فقط وتُحفظ ضمن بيانات الطلب.</p>
            </div>

            <div class="card">
                <h2>أوافق</h2>

                <div style="margin-bottom:14px;">
                    <button type="button" class="btn btn-light" id="captureLocationBtn">📍 تحديث الأن وافق</button>
                </div>

                <div class="location-row">
                    <input class="field-text" type="text" name="address_text" placeholder="العنوان النصي (اختياري)" value="<?= e($meta['address_text']) ?>">
                    <input class="field-text" type="text" name="maps_link" placeholder="رابط خرائط (اختياري)" value="<?= e($meta['maps_link']) ?>">
                </div>

                <input type="hidden" name="gps" id="gpsField" value="<?= e($meta['gps']) ?>">
 <p class="muted" id="gpsNote"><?= $meta['gps'] !== '' ? 'تم حفظ المتطلبات: ' . e($meta['gps']) : 'سيتم حفظ  هنا بعد الموافقة والتقاطه.' ?></p>
            </div>

            <div class="card">
                <div class="btn-row">
                    <div class="btn-actions">
                        <button type="button" class="btn btn-light" id="prevStepBtn">السابق</button>
                        <button type="button" class="btn btn-primary" id="nextStepBtn">التالي</button>
                        <button class="btn btn-primary hidden" type="submit" id="submitStudyBtn">إرسال الدراسة</button>
                    </div>
                    <div class="muted">جميع البيانات تعامل بسرية تامة، وسيتم دراستها داخليًا فقط.</div>
                </div>
                <p class="footer-note"><?= e(STUDY_FORM_FOOTER_NOTE) ?></p>
            </div>
        </form>
    <?php endif; ?>

    <div class="site-footer">
        نموذج دراسة الحالة — تجربة مبسطة وسريعة ومتوافقة مع الجوال والكمبيوتر
    </div>
</div>

<script>
(function(){
    const form = document.getElementById('studyForm');
    const validationBox = document.getElementById('validationQuestionsBox');
    const gpsField = document.getElementById('gpsField');
    const gpsNote = document.getElementById('gpsNote');
    const entryGateBox = document.getElementById('entryGateBox');
    const gateThankYouBox = document.getElementById('gateThankYouBox');
    const startSurveyBtn = document.getElementById('startSurveyBtn');
    const declineSurveyBtn = document.getElementById('declineSurveyBtn');
    const oathConfirmationInput = document.getElementById('oathConfirmationInput');
    const locationConsentInput = document.getElementById('locationConsentInput');
    const captureLocationBtn = document.getElementById('captureLocationBtn');
    const prevStepBtn = document.getElementById('prevStepBtn');
    const nextStepBtn = document.getElementById('nextStepBtn');
    const submitStudyBtn = document.getElementById('submitStudyBtn');
    const progressBarFill = document.getElementById('progressBarFill');
    const progressText = document.getElementById('progressText');
    const stepChip = document.getElementById('stepChip');
    const visibleCountChip = document.getElementById('visibleCountChip');

    if (!form) return;

    const baseQuestionsPerStep = <?= (int)$baseQuestionsPerStep ?>;
    const q = <?= json_encode($questionNumbers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const caseTypeQuestionNumber = q.case_type;

    const baseQuestions = Array.from(form.querySelectorAll('.base-question'));
    const validationQuestions = Array.from(form.querySelectorAll('.validation-question'));

    let currentStep = 0;
    let totalSteps = 1;
    let autoAdvanceLock = false;

    function getCheckedInput(questionNumber){
        if (!questionNumber) return null;
        return form.querySelector('input[name="answers[' + questionNumber + ']"]:checked');
    }

    function getSelectedCaseKey(){
        const checked = getCheckedInput(caseTypeQuestionNumber);
        if (!checked) return '';
        return checked.dataset.caseKey || '';
    }

    function parseHideWhen(raw){
        if (!raw) return null;
        try {
            return JSON.parse(raw);
        } catch (e) {
            return null;
        }
    }

    function getQuestionNumberByKey(questionKey){
        const box = baseQuestions.find(function(item){
            return item.dataset.questionKey === questionKey;
        });

        if (!box) return null;
        const n = parseInt(box.dataset.questionNumber || '0', 10);
        return n > 0 ? n : null;
    }

    function questionMatchesHideWhen(box){
        const raw = box.dataset.hideWhen || '';
        const rules = parseHideWhen(raw);
        if (!rules || typeof rules !== 'object') {
            return true;
        }

        for (const questionKey in rules) {
            const dependencyNumber = getQuestionNumberByKey(questionKey);
            if (!dependencyNumber) continue;

            const checked = getCheckedInput(dependencyNumber);
            if (!checked) continue;

            const blockedValues = Array.isArray(rules[questionKey]) ? rules[questionKey].map(function(v){
                return parseInt(v, 10);
            }) : [];

            const selectedValue = parseInt(checked.value, 10);
            if (blockedValues.includes(selectedValue)) {
                return false;
            }
        }

        return true;
    }

    function isQuestionVisibleByCategory(box, selectedCaseKey){
        const raw = (box.dataset.categories || '').trim();
        if (!raw) return true;
        if (!selectedCaseKey) return false;

        const categories = raw.split(',').map(function(v){ return v.trim(); }).filter(Boolean);
        return categories.includes(selectedCaseKey);
    }

    function refreshSelectedLabels(){
        form.querySelectorAll('.options label').forEach(function(label){
            const input = label.querySelector('input[type="radio"]');
            if (!input) return;
            label.classList.toggle('selected', input.checked);
        });
    }

    function refreshCategoryQuestions(){
        const selectedCaseKey = getSelectedCaseKey();

        baseQuestions.forEach(function(box){
            const visibleByCategory = isQuestionVisibleByCategory(box, selectedCaseKey);
            const visibleByHideWhen = questionMatchesHideWhen(box);
            const shouldShow = visibleByCategory && visibleByHideWhen;

            if (shouldShow) {
                box.classList.remove('question-hidden');
            } else {
                box.classList.add('question-hidden');
                box.querySelectorAll('input[type="radio"]').forEach(function(input){
                    input.checked = false;
                });
            }
        });

        refreshSelectedLabels();
    }

    function getVisibleBaseQuestions(){
        return baseQuestions.filter(function(box){
            return !box.classList.contains('question-hidden');
        });
    }

    function getVisibleQuestionsInCurrentStep(){
        return getVisibleBaseQuestions().filter(function(box){
            return parseInt(box.dataset.stepIndex || '0', 10) === currentStep;
        });
    }

    function assignSteps(){
        const visible = getVisibleBaseQuestions();

        visible.forEach(function(box, index){
            const stepIndex = Math.floor(index / baseQuestionsPerStep);
            box.dataset.stepIndex = String(stepIndex);
        });

        totalSteps = Math.max(1, Math.ceil(visible.length / baseQuestionsPerStep));

        if (currentStep > totalSteps - 1) {
            currentStep = totalSteps - 1;
        }

        if (visibleCountChip) {
            visibleCountChip.textContent = visible.length + ' سؤال ظاهر';
        }
    }

    function clearFocusState(){
        baseQuestions.forEach(function(box){
            box.classList.remove('current-focus');
        });
    }

    function focusQuestionBox(box, smooth = true){
        if (!box) return;

        clearFocusState();
        box.classList.add('current-focus');

        const y = box.getBoundingClientRect().top + window.scrollY - 140;
        window.scrollTo({
            top: Math.max(0, y),
            behavior: smooth ? 'smooth' : 'auto'
        });
    }

    function updateProgress(){
        if (progressBarFill) {
            progressBarFill.style.width = ((currentStep + 1) / totalSteps * 100) + '%';
        }

        if (progressText) {
            progressText.textContent = 'الخطوة ' + (currentStep + 1) + ' من ' + totalSteps;
        }

        const currentVisibleStepQuestions = getVisibleQuestionsInCurrentStep();

        if (stepChip) {
            stepChip.textContent = currentVisibleStepQuestions.length + ' أسئلة في هذه الخطوة';
        }

        if (prevStepBtn) {
            prevStepBtn.disabled = currentStep === 0;
        }

        if (nextStepBtn) {
            nextStepBtn.classList.toggle('hidden', currentStep >= totalSteps - 1);
        }

        if (submitStudyBtn) {
            submitStudyBtn.classList.toggle('hidden', currentStep < totalSteps - 1);
        }
    }

    function showCurrentStep(focusFirst = true){
        const visible = getVisibleBaseQuestions();

        visible.forEach(function(box){
            const stepIndex = parseInt(box.dataset.stepIndex || '0', 10);
            box.style.display = stepIndex === currentStep ? '' : 'none';
        });

        updateProgress();

        const currentQuestions = getVisibleQuestionsInCurrentStep();
        if (focusFirst && currentQuestions.length) {
            const firstUnanswered = currentQuestions.find(function(box){
                return !box.querySelector('input[type="radio"]:checked');
            });
            focusQuestionBox(firstUnanswered || currentQuestions[0], true);
        }
    }

    function validateVisibleQuestionsInCurrentStep(showAlert = true){
        const currentQuestions = getVisibleQuestionsInCurrentStep();

        for (const qBox of currentQuestions) {
            const checked = qBox.querySelector('input[type="radio"]:checked');
            if (!checked) {
                if (showAlert) {
                    alert('يرجى الإجابة على جميع أسئلة هذه الخطوة أولاً.');
                }
                focusQuestionBox(qBox, true);
                return false;
            }
        }

        return true;
    }

    function validateValidationQuestions(){
        if (!validationBox || !validationBox.classList.contains('active')) {
            return true;
        }

        for (const qBox of validationQuestions) {
            const checked = qBox.querySelector('input[type="radio"]:checked');
            if (!checked) {
                alert('يرجى إكمال أسئلة التحقق الإضافية أولاً.');
                qBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return false;
            }
        }

        return true;
    }

    function captureLocation(){
        if (!navigator.geolocation) {
            if (gpsNote) gpsNote.textContent = 'المتصفح لا يدعم تحديد الموقع.';
            return;
        }

        if (gpsNote) gpsNote.textContent = 'جاري طلب الموقع...';

        navigator.geolocation.getCurrentPosition(function(position){
            const coords = position.coords.latitude + ',' + position.coords.longitude;
            if (gpsField) gpsField.value = coords;
            if (gpsNote) gpsNote.textContent = 'تم المسار: ' + coords;
        }, function(){
            if (gpsNote) gpsNote.textContent = 'تعذر التقاط الموقع، يمكنك إدخال العنوان أو رابط الخرائط.';
        });
    }

    function showThanksOnly(){
        if (entryGateBox) entryGateBox.classList.add('hidden');
        form.classList.add('hidden');
        if (gateThankYouBox) gateThankYouBox.classList.remove('hidden');
    }

    function initializeSurveyView(){
        refreshSelectedLabels();
        refreshCategoryQuestions();
        assignSteps();
        showCurrentStep(true);
    }

    function startSurvey(){
        const oath = document.querySelector('input[name="gate_oath"]:checked');
        const location = document.querySelector('input[name="gate_location"]:checked');

        if (!oath || !location) {
            alert('يرجى اختيار القسم وموافقة الموقع أولاً.');
            return;
        }

        if (oath.value !== 'yes' || location.value !== 'yes') {
            showThanksOnly();
            return;
        }

        if (oathConfirmationInput) oathConfirmationInput.value = 'yes';
        if (locationConsentInput) locationConsentInput.value = 'yes';

        if (entryGateBox) entryGateBox.classList.add('hidden');
        if (gateThankYouBox) gateThankYouBox.classList.add('hidden');
        form.classList.remove('hidden');

        initializeSurveyView();
        captureLocation();

        if (validationBox && validationBox.classList.contains('active')) {
            validationBox.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else {
            window.scrollTo({ top: Math.max(0, form.offsetTop - 20), behavior: 'smooth' });
        }
    }

    function moveToNextQuestionOrStep(currentBox){
        if (autoAdvanceLock) return;
        autoAdvanceLock = true;

        const currentQuestions = getVisibleQuestionsInCurrentStep();
        const currentIndex = currentQuestions.findIndex(function(box){
            return box === currentBox;
        });

        if (currentIndex >= 0 && currentIndex < currentQuestions.length - 1) {
            const nextBox = currentQuestions[currentIndex + 1];
            setTimeout(function(){
                focusQuestionBox(nextBox, true);
                autoAdvanceLock = false;
            }, 140);
            return;
        }

        if (currentStep < totalSteps - 1 && validateVisibleQuestionsInCurrentStep(false)) {
            setTimeout(function(){
                currentStep++;
                showCurrentStep(true);
                autoAdvanceLock = false;
            }, 180);
            return;
        }

        setTimeout(function(){
            autoAdvanceLock = false;
        }, 120);
    }

    if (startSurveyBtn) {
        startSurveyBtn.addEventListener('click', startSurvey);
    }

    if (declineSurveyBtn) {
        declineSurveyBtn.addEventListener('click', showThanksOnly);
    }

    if (captureLocationBtn) {
        captureLocationBtn.addEventListener('click', captureLocation);
    }

    if (nextStepBtn) {
        nextStepBtn.addEventListener('click', function(){
            if (!validateVisibleQuestionsInCurrentStep(true)) {
                return;
            }

            if (currentStep < totalSteps - 1) {
                currentStep++;
                showCurrentStep(true);
            }
        });
    }

    if (prevStepBtn) {
        prevStepBtn.addEventListener('click', function(){
            if (currentStep > 0) {
                currentStep--;
                showCurrentStep(true);
            }
        });
    }

    form.addEventListener('change', function(e){
        const target = e.target;
        if (!(target instanceof HTMLInputElement)) return;

        if (target.type === 'radio') {
            refreshSelectedLabels();
        }

        if (target.name === 'answers[' + caseTypeQuestionNumber + ']') {
            currentStep = 0;
            refreshCategoryQuestions();
            assignSteps();
            showCurrentStep(true);
            moveToNextQuestionOrStep(target.closest('.base-question'));
            return;
        }

        if (target.name.startsWith('answers[')) {
            refreshCategoryQuestions();
            assignSteps();
            showCurrentStep(false);

            const currentBox = target.closest('.base-question');
            if (currentBox && !currentBox.classList.contains('question-hidden')) {
                moveToNextQuestionOrStep(currentBox);
            }
            return;
        }

        refreshCategoryQuestions();
        assignSteps();
        showCurrentStep(false);
    });

    form.addEventListener('submit', function(e){
        if (!oathConfirmationInput || oathConfirmationInput.value !== 'yes') {
            e.preventDefault();
            alert('لا يمكن إرسال الاستبيان بدون الإقرار بالقسم.');
            return;
        }

        if (!locationConsentInput || locationConsentInput.value !== 'yes') {
            e.preventDefault();
            alert('لا يمكن إرسال الاستبيان بدون الموافقة على مشاركة الموقع.');
            return;
        }

        if (!validateValidationQuestions()) {
            e.preventDefault();
            return;
        }

        if (!validateVisibleQuestionsInCurrentStep(true)) {
            e.preventDefault();
            return;
        }

        const allVisible = getVisibleBaseQuestions();
        for (const qBox of allVisible) {
            const checked = qBox.querySelector('input[type="radio"]:checked');
            if (!checked) {
                e.preventDefault();
                alert('يرجى استكمال جميع الأسئلة الظاهرة قبل الإرسال.');
                currentStep = parseInt(qBox.dataset.stepIndex || '0', 10);
                showCurrentStep(true);
                return;
            }
        }
    });

    if (!form.classList.contains('hidden')) {
        initializeSurveyView();
    }
})();
</script>

<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-8971627917799044"
     crossorigin="anonymous"></script>


</body>
</html>