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
    $prefix = !empty($question['validation']) ? 'سؤال تحقق' : 'سؤال أساسي';
    return $prefix . ' #' . $number;
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

function getVisibleBaseQuestionNumbersLocal(?string $selectedCaseType): array
{
    global $studyQuestions;

    $numbers = [];
    foreach ($studyQuestions as $number => $question) {
        if (!empty($question['validation'])) {
            continue;
        }
        if (!questionVisibleForCaseLocal($question, $selectedCaseType)) {
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
];

$gateDeclined = false;
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

        $visibleBaseQuestionNumbers = getVisibleBaseQuestionNumbersLocal($selectedCaseType);
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
                ], $result['flags'] ?? [])));

                $snapshot = ['answers' => $values, 'meta' => $meta];

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
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>نموذج دراسة الحالة الذكي</title>
    <style>
        body{font-family:Tahoma,Arial,sans-serif;background:#f5f7fb;color:#1f2937;margin:0;padding:0}
        .wrap{max-width:1100px;margin:30px auto;padding:20px}
        .card{background:#fff;border-radius:16px;box-shadow:0 8px 30px rgba(0,0,0,.08);padding:22px;margin-bottom:18px}
        h1,h2,h3{margin-top:0}
        .muted{color:#6b7280;font-size:14px}
        .alert{padding:14px 16px;border-radius:12px;margin-bottom:16px}
        .alert-danger{background:#fee2e2;color:#991b1b}
        .alert-warning{background:#fef3c7;color:#92400e}
        .grid{display:grid;gap:16px}
        .q{border:1px solid #e5e7eb;border-radius:14px;padding:16px;background:#fafafa}
        .q-title{font-weight:700;margin-bottom:8px;line-height:1.8}
        .badge{display:inline-block;font-size:12px;padding:3px 9px;border-radius:999px;background:#eef2ff;color:#3730a3;margin-bottom:10px}
        .options label{display:block;padding:10px 12px;border-radius:10px;margin-bottom:8px;background:#fff;border:1px solid #e5e7eb;cursor:pointer}
        .options input{margin-left:8px}
        .btn{border:none;background:#111827;color:#fff;padding:14px 22px;border-radius:12px;cursor:pointer;font-size:15px}
        .btn:hover{opacity:.92}
        .btn-light{background:#f1f5f9;color:#334155}
        .validation-box{display:none}
        .validation-box.active{display:block}
        .footer-note{font-size:13px;color:#6b7280;line-height:1.8}
        .location-row{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(240px,1fr))}
        .field-text{width:100%;padding:12px;border-radius:12px;border:1px solid #d1d5db;background:#fff}
        .question-hidden{display:none!important}
        .gate-box{max-width:760px;margin:0 auto 20px auto}
        .thank-you{text-align:center;padding:70px 20px}
        .hidden{display:none!important}
    </style>
</head>
<body>
<div class="wrap">

    <div class="card">
        <h2 style="margin-bottom:10px;">مقدمة الدراسة</h2>
        <p class="muted" style="line-height:2;font-size:15px;">
            عزيزي المستفيد/ة، انطلاقاً من الحرص على تطوير جودة خدمات لجنة الزكاة ووصول المساعدات لمستحقيها بدقة،
            نضع بين أيديكم هذه الاستبانة الميدانية. تهدف هذه الدراسة إلى فهم واقع الاحتياجات المعيشية للأسر
            وتحديد الأولويات، وإجاباتكم الدقيقة هي الركيزة الأساسية لبناء قاعدة بيانات مهنية تسهم في تحسين
            آليات تقديم الخدمات. نؤكد لكم أن كافة البيانات ستُعامل بسرية تامة، وتُستخدم لأغراض دراسة الخدمات
            وتحسينها فقط.
        </p>
    </div>

    <?php if ($validation['ok']): ?>
        <div class="card">
            <h1>نموذج دراسة الحالة الذكي</h1>
            <p class="muted">المرشح: <strong><?= e((string)$candidate['full_name']) ?></strong> — الهاتف: <strong><?= e((string)$candidate['phone']) ?></strong></p>
            <p class="muted">يرجى الإجابة بدقة. تظهر الأسئلة المناسبة لفئة الحالة فقط، وقد تظهر أسئلة تحقق إضافية تلقائيًا لتعزيز دقة الدراسة.</p>
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

        <div class="card gate-box" id="entryGateBox">
            <h2 style="margin-bottom:12px;">قبل بدء الاستبيان</h2>

            <div class="q">
                <div class="q-title">هل تقسم بالله العظيم أن جميع المعلومات التي ستذكرها هنا صحيحة، والله على ما أقول شهيد؟</div>
                <div class="options">
                    <label><input type="radio" name="gate_oath" value="yes"> أقسم بالله العظيم</label>
                    <label><input type="radio" name="gate_oath" value="no"> لا أوافق</label>
                </div>
            </div>

            <div class="q" style="margin-top:14px;">
                <div class="q-title">هل توافق على اكمال الطلب ؟</div>
                <div class="options">
                    <label><input type="radio" name="gate_location" value="yes"> نعم، أوافق</label>
                    <label><input type="radio" name="gate_location" value="no"> لا أوافق</label>
                </div>
            </div>

            <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;">
                <button type="button" class="btn" id="startSurveyBtn">بدء الاستبيان</button>
                <button type="button" class="btn btn-light" id="declineSurveyBtn">عدم المتابعة</button>
            </div>
        </div>

        <div class="card thank-you hidden" id="gateThankYouBox">
            <h2>شكرًا لك</h2>
            <p class="muted" style="font-size:16px;line-height:2;">
                لا يمكن متابعة تعبئة الاستبيان بدون القسم والموافقة على مشاركة الموقع.
            </p>
        </div>

        <form method="post" action="" id="studyForm" class="hidden">
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
                                    <label>
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

            <div class="card">
                <h2>الأسئلة الأساسية</h2>
                <div class="grid">
                    <?php foreach ($baseQuestions as $number => $question): ?>
                        <?php
                        $categories = $question['category'] ?? [];
                        $categoryAttr = '';
                        if (is_array($categories) && $categories) {
                            $categoryAttr = implode(',', array_map('strval', $categories));
                        }
                        $isVisible = questionVisibleForCaseLocal($question, $selectedCaseType);
                        ?>
                        <div class="q base-question <?= $isVisible ? '' : 'question-hidden' ?>"
                             data-question-number="<?= (int)$number ?>"
                             data-categories="<?= e($categoryAttr) ?>">
                            <div class="badge"><?= e(studyQuestionLabel($question, (int)$number)) ?></div>
                            <div class="q-title"><?= e((string)$question['text']) ?></div>
                            <div class="options">
                                <?php foreach (($question['options'] ?? []) as $optionValue => $option): ?>
                                    <label>
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

            <div class="card">
                <h2>الموافقة على ارسال</h2>
                <p class="muted">تمت الموافقة على ان المعلومات صحيحة ( اضغط هنا ) .</p>

                <div style="margin-bottom:14px;">
                    <button type="button" class="btn btn-light" id="captureLocationBtn">📍 تحديث/الآن</button>
                </div>

                <div class="location-row">
                    <input class="field-text" type="text" name="address_text" placeholder="العنوان النصي (اختياري)" value="<?= e($meta['address_text']) ?>">
                    <input class="field-text" type="text" name="maps_link" placeholder="رابط خرائط (اختياري)" value="<?= e($meta['maps_link']) ?>">
                </div>

                <input type="hidden" name="gps" id="gpsField" value="<?= e($meta['gps']) ?>">
                <p class="muted" id="gpsNote"><?= $meta['gps'] !== '' ? 'location .' : 'سيتم حفظ الموقع الجغرافي هنا بعد الموافقة والتقاطه.' ?></p>
            </div>

            <div class="card">
                <button class="btn" type="submit">إرسال الدراسة</button>
                <p class="footer-note"><?= e(STUDY_FORM_FOOTER_NOTE) ?></p>
            </div>
        </form>
    <?php endif; ?>
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

    if (!form) return;

    const q = <?= json_encode($questionNumbers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const caseTypeQuestionNumber = q.case_type;

    function getVal(num){
        if (!num) return null;
        const checked = form.querySelector('input[name="answers[' + num + ']"]:checked');
        return checked ? parseInt(checked.value, 10) : null;
    }

    function getSelectedCaseLabel(){
        if (!caseTypeQuestionNumber) return '';
        const checked = form.querySelector('input[name="answers[' + caseTypeQuestionNumber + ']"]:checked');
        if (!checked) return '';
        const label = checked.closest('label');
        return label ? label.textContent.trim() : '';
    }

    function normalizeCaseType(label){
        const text = (label || '').trim();
        const map = {
            'يتيم':'orphan',
            'أرملة':'widow',
            'مطلقة':'divorced',
            'عاطل / مريض / فقير / مسن / أسرة سجين / ذوو إعاقة':'poor',
            'عاطل':'unemployed',
            'مريض':'sick',
            'فقير':'poor',
            'مسن':'elderly',
            'أسرة معيلها سجين':'prisoner',
            'أسرة سجين':'prisoner',
            'ذوو إعاقة':'disabled'
        };
        return map[text] || '';
    }

    function refreshCategoryQuestions(){
        const selectedType = normalizeCaseType(getSelectedCaseLabel());
        form.querySelectorAll('.base-question[data-categories]').forEach(function(box){
            const raw = box.getAttribute('data-categories') || '';
            if (!raw) {
                box.classList.remove('question-hidden');
                return;
            }
            const categories = raw.split(',').map(v => v.trim()).filter(Boolean);
            if (!selectedType) {
                box.classList.add('question-hidden');
                return;
            }
            if (categories.includes(selectedType)) {
                box.classList.remove('question-hidden');
            } else {
                box.classList.add('question-hidden');
                box.querySelectorAll('input[type="radio"]').forEach(function(input){
                    input.checked = false;
                });
            }
        });
    }

    function estimateSuspicionClient(){
        let score = 0;

        const monthly_income = getVal(q.monthly_income);
        const employment_status = getVal(q.employment_status);
        const housing_status = getVal(q.housing_status);
        const housing_space = getVal(q.housing_space);
        const external_support = getVal(q.external_support);
        const zakat_support = getVal(q.zakat_support);
        const health_condition = getVal(q.health_condition);
        const debts = getVal(q.debts);
        const basic_needs = getVal(q.basic_needs);
        const assets = getVal(q.assets);
        const urgency = getVal(q.urgency);
        const bill_payment_ability = getVal(q.bill_payment_ability);
        const children_schooling_status = getVal(q.children_schooling_status);
        const financial_fragility_priority = getVal(q.financial_fragility_priority);
        const income_shortfall_effect = getVal(q.income_shortfall_effect);
        const utility_cutoff_risk = getVal(q.utility_cutoff_risk);
        const education_disruption = getVal(q.education_disruption_due_to_poverty);
        const has_sanad_app = getVal(q.has_sanad_app);

        if (monthly_income === 3 && debts === 0) score += 18;
        if (monthly_income === 3 && basic_needs !== null && basic_needs <= 1) score += 18;
        if ((assets === 0 || assets === 1) && urgency !== null && urgency >= 2) score += 15;
        if (external_support === 0 && zakat_support !== null && zakat_support <= 1) score += 10;
        if (employment_status === 3 && monthly_income !== null && monthly_income <= 1) score += 12;
        if (housing_status === 0 && housing_space !== null && housing_space >= 2) score += 10;
        if (health_condition === 3 && basic_needs !== null && basic_needs <= 1) score += 8;
        if (urgency === 3 && monthly_income !== null && monthly_income <= 1 && basic_needs !== null && basic_needs <= 1) score += 15;

        if (financial_fragility_priority !== null && financial_fragility_priority >= 2 &&
            income_shortfall_effect !== null && income_shortfall_effect >= 2) score += 10;

        if (utility_cutoff_risk !== null && utility_cutoff_risk >= 2 &&
            bill_payment_ability !== null && bill_payment_ability <= 1) score += 8;

        if (education_disruption !== null && education_disruption >= 2 &&
            children_schooling_status !== null && children_schooling_status === 0) score += 7;

        if (has_sanad_app !== null && has_sanad_app >= 2 &&
            monthly_income !== null && monthly_income <= 1) score += 5;

        return Math.min(100, score);
    }

    function refreshValidationVisibility(){
        if (!validationBox) return;
        const suspicion = estimateSuspicionClient();
        if (suspicion >= 35) {
            validationBox.classList.add('active');
        } else if (!validationBox.dataset.serverForced) {
            validationBox.classList.remove('active');
        }
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
            if (gpsNote) gpsNote.textContent = 'تم التقاط الموقع الجغرافي: ' + coords;
        }, function(){
            if (gpsNote) gpsNote.textContent = 'تعذر التقاط الموقع، يمكنك إدخال العنوان أو رابط الخرائط.';
        });
    }

    function showThanksOnly(){
        if (entryGateBox) entryGateBox.classList.add('hidden');
        form.classList.add('hidden');
        if (gateThankYouBox) gateThankYouBox.classList.remove('hidden');
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

        captureLocation();
        if (validationBox && validationBox.classList.contains('active')) {
            validationBox.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else {
            window.scrollTo({ top: form.offsetTop - 20, behavior: 'smooth' });
        }
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

    form.addEventListener('change', function(){
        refreshCategoryQuestions();
        refreshValidationVisibility();
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
    });

    refreshCategoryQuestions();
    refreshValidationVisibility();
})();
</script>
</body>
</html>
