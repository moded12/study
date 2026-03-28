<?php
// /zaka/study/form_ai.php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/functions_ai.php';
require_once __DIR__ . '/includes/questions_ai.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function studyQuestionLabel(array $question, int $number): string
{
    $prefix = !empty($question['validation']) ? 'سؤال تحقق' : 'سؤال أساسي';
    return $prefix . ' #' . $number;
}

function getQuestionNumberByKey(string $wantedKey): ?int
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

$caseTypeQuestionNumber = getQuestionNumberByKey('case_type');
$token = clean((string)($_GET['t'] ?? $_POST['token'] ?? ''));
$errors = [];
$values = [];
$meta = [
    'location_consent' => clean((string)($_POST['location_consent'] ?? '')),
    'gps' => clean((string)($_POST['gps'] ?? '')),
    'address_text' => clean((string)($_POST['address_text'] ?? '')),
    'maps_link' => clean((string)($_POST['maps_link'] ?? '')),
];
$validationState = ['forced' => false, 'suspicion_score' => 0, 'random_audit' => false];
$selectedValidationNumbers = [];

$validation = validateUsableLinkByToken($token);
$link = $validation['link'] ?? null;
$candidate = $validation['candidate'] ?? null;

if (!$validation['ok']) {
    http_response_code(404);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validation['ok']) {
    foreach ($studyQuestions as $number => $question) {
        if (isset($_POST['answers'][$number]) && is_scalar($_POST['answers'][$number])) {
            $values[(int)$number] = max(0, min(3, (int)$_POST['answers'][$number]));
        }
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
            $answerStmt = $pdo->prepare('INSERT INTO study_answers (submission_id, question_number, answer_value) VALUES (:submission_id, :question_number, :answer_value)');
            foreach ($values as $questionNumber => $answerValue) {
                $answerStmt->execute([
                    ':submission_id' => $submissionId,
                    ':question_number' => (int)$questionNumber,
                    ':answer_value' => (int)$answerValue,
                ]);
            }

            $pdo->prepare('UPDATE study_links SET is_used = 1, used_at = NOW() WHERE id = ? LIMIT 1')->execute([(int)$link['id']]);
            $pdo->prepare("UPDATE study_candidates SET status = 'answered' WHERE id = ? LIMIT 1")->execute([(int)$candidate['id']]);

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
        h1,h2,h3{margin-top:0}.muted{color:#6b7280;font-size:14px}
        .alert{padding:14px 16px;border-radius:12px;margin-bottom:16px}
        .alert-danger{background:#fee2e2;color:#991b1b}.alert-warning{background:#fef3c7;color:#92400e}
        .grid{display:grid;gap:16px}.q{border:1px solid #e5e7eb;border-radius:14px;padding:16px;background:#fafafa}
        .q-title{font-weight:700;margin-bottom:8px;line-height:1.8}
        .badge{display:inline-block;font-size:12px;padding:3px 9px;border-radius:999px;background:#eef2ff;color:#3730a3;margin-bottom:10px}
        .options label{display:block;padding:10px 12px;border-radius:10px;margin-bottom:8px;background:#fff;border:1px solid #e5e7eb;cursor:pointer}
        .options input{margin-left:8px}.btn{border:none;background:#111827;color:#fff;padding:14px 22px;border-radius:12px;cursor:pointer;font-size:15px}
        .btn:hover{opacity:.92}.validation-box{display:none}.validation-box.active{display:block}
        .footer-note{font-size:13px;color:#6b7280;line-height:1.8}
        .location-row{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(240px,1fr))}
        .field-text{width:100%;padding:12px;border-radius:12px;border:1px solid #d1d5db;background:#fff}
        .question-hidden{display:none!important}
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>نموذج دراسة الحالة الذكي</h1>
        <?php if ($validation['ok']): ?>
            <p class="muted">المرشح: <strong><?= e((string)$candidate['full_name']) ?></strong> — الهاتف: <strong><?= e((string)$candidate['phone']) ?></strong></p>
            <p class="muted">يرجى الإجابة بدقة. قد تظهر أسئلة تحقق إضافية تلقائيًا لتعزيز دقة الدراسة.</p>
        <?php else: ?>
            <div class="alert alert-danger"><?= e((string)$validation['message']) ?></div>
        <?php endif; ?>
    </div>

    <?php if ($validation['ok']): ?>
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

        <form method="post" action="" id="studyForm">
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <?php foreach ($selectedValidationNumbers as $validationNumber): ?>
                <input type="hidden" name="selected_validation_numbers[]" value="<?= (int)$validationNumber ?>">
            <?php endforeach; ?>

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
                        <div class="q base-question <?= $isVisible ? '' : 'question-hidden' ?>" data-question-number="<?= (int)$number ?>" data-categories="<?= e($categoryAttr) ?>">
                            <div class="badge"><?= e(studyQuestionLabel($question, (int)$number)) ?></div>
                            <div class="q-title"><?= e((string)$question['text']) ?></div>
                            <div class="options">
                                <?php foreach (($question['options'] ?? []) as $optionValue => $option): ?>
                                    <label>
                                        <input type="radio" name="answers[<?= (int)$number ?>]" value="<?= (int)$optionValue ?>" <?= isset($values[$number]) && (int)$values[$number] === (int)$optionValue ? 'checked' : '' ?>>
                                        <?= e((string)($option['label'] ?? '')) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card">
                <h2>الموقع</h2>
                <p class="muted">Do you agree to share your location?</p>
                <div class="options">
                    <label><input type="radio" name="location_consent" value="yes" <?= $meta['location_consent'] === 'yes' ? 'checked' : '' ?>> Yes</label>
                    <label><input type="radio" name="location_consent" value="no" <?= $meta['location_consent'] === 'no' ? 'checked' : '' ?>> No</label>
                </div>
                <div class="location-row">
                    <input class="field-text" type="text" name="address_text" placeholder="العنوان النصي (اختياري)" value="<?= e($meta['address_text']) ?>">
                    <input class="field-text" type="text" name="maps_link" placeholder="رابط خرائط (اختياري)" value="<?= e($meta['maps_link']) ?>">
                </div>
                <input type="hidden" name="gps" id="gpsField" value="<?= e($meta['gps']) ?>">
                <p class="muted" id="gpsNote"><?= $meta['gps'] !== '' ? 'تم التقاط الموقع الجغرافي.' : 'لن يتم التقاط الموقع إلا بعد الموافقة.' ?></p>
            </div>

            <div id="validationQuestionsBox" data-server-forced="<?= !empty($validationState['forced']) ? '1' : '' ?>" class="card validation-box <?= !empty($validationState['forced']) ? 'active' : '' ?>">
                <h2>أسئلة تحقق إضافية</h2>
                <p class="muted">هذه الأسئلة تظهر بحسب نمط الإجابات أو بشكل عشوائي محدود لتحسين دقة التقييم.</p>
                <div class="grid">
                    <?php foreach ($selectedValidationQuestions as $number => $question): ?>
                        <div class="q validation-question" data-question-number="<?= (int)$number ?>">
                            <div class="badge"><?= e(studyQuestionLabel($question, (int)$number)) ?></div>
                            <div class="q-title"><?= e((string)$question['text']) ?></div>
                            <div class="options">
                                <?php foreach (($question['options'] ?? []) as $optionValue => $option): ?>
                                    <label>
                                        <input type="radio" name="answers[<?= (int)$number ?>]" value="<?= (int)$optionValue ?>" <?= isset($values[$number]) && (int)$values[$number] === (int)$optionValue ? 'checked' : '' ?>>
                                        <?= e((string)($option['label'] ?? '')) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card">
                <button class="btn" type="submit">إرسال الدراسة</button>
                <p class="footer-note">مهم: قد يفعّل النظام أسئلة تحقق إضافية عند وجود تناقضات أو ضمن تدقيق عشوائي محدود، ولا يتم إظهار منطق التقييم للمتقدم.</p>
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
    if (!form) return;

    const caseTypeQuestionNumber = <?= $caseTypeQuestionNumber !== null ? (int)$caseTypeQuestionNumber : 'null' ?>;

    function getVal(num){
        const checked = form.querySelector('input[name="answers[' + num + ']"]:checked');
        return checked ? parseInt(checked.value, 10) : null;
    }
    function getSelectedCaseLabel(){
        if (caseTypeQuestionNumber === null) return '';
        const checked = form.querySelector('input[name="answers[' + caseTypeQuestionNumber + ']"]:checked');
        if (!checked) return '';
        const label = checked.closest('label');
        return label ? label.textContent.trim() : '';
    }
    function normalizeCaseType(label){
        const text = (label || '').trim();
        const map = {'يتيم':'orphan','أرملة':'widow','مطلقة':'divorced','عاطل':'unemployed','مريض':'sick','فقير':'poor','مسن':'elderly','أسرة معيلها سجين':'prisoner','أسرة سجين':'prisoner','ذوو إعاقة':'disabled'};
        return map[text] || '';
    }
    function refreshCategoryQuestions(){
        const selectedType = normalizeCaseType(getSelectedCaseLabel());
        form.querySelectorAll('.base-question[data-categories]').forEach(function(box){
            const raw = box.getAttribute('data-categories') || '';
            if (!raw) { box.classList.remove('question-hidden'); return; }
            const categories = raw.split(',').map(v => v.trim()).filter(Boolean);
            if (!selectedType) { box.classList.add('question-hidden'); return; }
            if (categories.includes(selectedType)) {
                box.classList.remove('question-hidden');
            } else {
                box.classList.add('question-hidden');
                box.querySelectorAll('input[type="radio"]').forEach(function(input){ input.checked = false; });
            }
        });
    }
    function estimateSuspicionClient(){
        let score = 0;
        const monthly_income = getVal(2), employment_status = getVal(4), housing_status = getVal(5), housing_space = getVal(6), external_support = getVal(7), zakat_support = getVal(8), health_condition = getVal(9), debts = getVal(10), basic_needs = getVal(12), assets = getVal(13), urgency = getVal(15);
        if (monthly_income === 3 && debts === 0) score += 18;
        if (monthly_income === 3 && basic_needs !== null && basic_needs <= 1) score += 18;
        if ((assets === 0 || assets === 1) && urgency !== null && urgency >= 2) score += 15;
        if (external_support === 0 && zakat_support !== null && zakat_support <= 1) score += 10;
        if (employment_status === 3 && monthly_income !== null && monthly_income <= 1) score += 12;
        if (housing_status === 0 && housing_space !== null && housing_space >= 2) score += 10;
        if (health_condition === 3 && basic_needs !== null && basic_needs <= 1) score += 8;
        if (urgency === 3 && monthly_income !== null && monthly_income <= 1 && basic_needs !== null && basic_needs <= 1) score += 15;
        return Math.min(100, score);
    }
    function refreshValidationVisibility(){
        if (!validationBox) return;
        const suspicion = estimateSuspicionClient();
        if (suspicion >= 35) validationBox.classList.add('active');
        else if (!validationBox.dataset.serverForced) validationBox.classList.remove('active');
    }
    function bindLocationConsent(){
        form.querySelectorAll('input[name="location_consent"]').forEach(function(radio){
            radio.addEventListener('change', function(){
                if (this.value !== 'yes') { if (gpsNote) gpsNote.textContent = 'تم رفض مشاركة الموقع.'; return; }
                if (!navigator.geolocation) { if (gpsNote) gpsNote.textContent = 'المتصفح لا يدعم تحديد الموقع.'; return; }
                if (gpsNote) gpsNote.textContent = 'جاري طلب الموقع...';
                navigator.geolocation.getCurrentPosition(function(position){
                    const coords = position.coords.latitude + ',' + position.coords.longitude;
                    if (gpsField) gpsField.value = coords;
                    if (gpsNote) gpsNote.textContent = 'تم التقاط الموقع الجغرافي.';
                }, function(){
                    if (gpsNote) gpsNote.textContent = 'تعذر التقاط الموقع، يمكنك إدخال العنوان أو رابط الخرائط.';
                });
            });
        });
    }
    form.addEventListener('change', function(){ refreshCategoryQuestions(); refreshValidationVisibility(); });
    refreshCategoryQuestions(); refreshValidationVisibility(); bindLocationConsent();
})();
</script>
</body>
</html>
