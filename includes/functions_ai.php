<?php
// /zaka/study/includes/functions.php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/questions_ai.php';

/**
 * تنظيف نص عادي
 */
function clean(string $value): string
{
    return trim($value);
}

/**
 * تنظيف الاسم
 */
function normalizeName(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/\s+/u', ' ', $name) ?? '';
    return trim($name);
}

/**
 * تنظيف الهاتف مع الإبقاء على الأرقام و +
 */
function normalizePhone(string $phone): string
{
    $phone = trim($phone);
    $phone = preg_replace('/[^\d+]/u', '', $phone) ?? '';
    return trim($phone);
}

/**
 * تنظيف textarea
 */
function cleanTextarea(?string $value): string
{
    $value = trim((string)$value);
    $value = preg_replace("/\r\n|\r/u", "\n", $value) ?? '';
    return trim($value);
}

/**
 * توليد Token آمن
 */
function generateToken(int $bytes = 16): string
{
    return bin2hex(random_bytes($bytes));
}

/**
 * توليد Token غير مكرر
 */
function generateUniqueStudyToken(): string
{
    $pdo = db();

    do {
        $token = generateToken(16);
        $stmt = $pdo->prepare("SELECT id FROM study_links WHERE token = ? LIMIT 1");
        $stmt->execute([$token]);
        $exists = $stmt->fetch();
    } while ($exists);

    return $token;
}

/**
 * جلب الرابط بواسطة التوكن
 */
function getLinkByToken(string $token): array|false
{
    $stmt = db()->prepare("SELECT * FROM study_links WHERE token = ? LIMIT 1");
    $stmt->execute([$token]);
    return $stmt->fetch();
}

/**
 * جلب الرابط بواسطة المعرف
 */
function getStudyLinkById(int $linkId): array|false
{
    $stmt = db()->prepare("SELECT * FROM study_links WHERE id = ? LIMIT 1");
    $stmt->execute([$linkId]);
    return $stmt->fetch();
}

/**
 * جلب المرشح
 */
function getCandidate(int $candidateId): array|false
{
    $stmt = db()->prepare("SELECT * FROM study_candidates WHERE id = ? LIMIT 1");
    $stmt->execute([$candidateId]);
    return $stmt->fetch();
}

/**
 * جلب المرشح بواسطة الهاتف
 */
function getCandidateByPhone(string $phone): array|false
{
    $stmt = db()->prepare("SELECT * FROM study_candidates WHERE phone = ? LIMIT 1");
    $stmt->execute([$phone]);
    return $stmt->fetch();
}

/**
 * هل لدى المرشح رابط؟
 */
function candidateHasLink(int $candidateId): bool
{
    $stmt = db()->prepare("SELECT id FROM study_links WHERE candidate_id = ? LIMIT 1");
    $stmt->execute([$candidateId]);
    return (bool)$stmt->fetch();
}

/**
 * هل لدى المرشح إرسال سابق؟
 */
function candidateHasSubmission(int $candidateId): bool
{
    $stmt = db()->prepare("SELECT id FROM study_submissions WHERE candidate_id = ? LIMIT 1");
    $stmt->execute([$candidateId]);
    return (bool)$stmt->fetch();
}

/**
 * جلب الإرسال بواسطة المعرف
 */
function getSubmission(int $submissionId): array|false
{
    $stmt = db()->prepare("SELECT * FROM study_submissions WHERE id = ? LIMIT 1");
    $stmt->execute([$submissionId]);
    return $stmt->fetch();
}

/**
 * جلب إجابات إرسال معيّن
 */
function getSubmissionAnswers(int $submissionId): array
{
    $stmt = db()->prepare("
        SELECT question_number, answer_value
        FROM study_answers
        WHERE submission_id = ?
        ORDER BY question_number ASC
    ");
    $stmt->execute([$submissionId]);
    return $stmt->fetchAll();
}

/**
 * تحويل الإجابات إلى خريطة question_number => answer_value
 */
function normalizeAnswersInput(array $answers): array
{
    $normalized = [];

    foreach ($answers as $key => $value) {
        if (is_array($value)) {
            continue;
        }

        if (is_numeric($key)) {
            $normalized[(int)$key] = max(0, min(3, (int)$value));
            continue;
        }

        if (is_array($value) && isset($value['question_number'], $value['answer_value'])) {
            $normalized[(int)$value['question_number']] = max(0, min(3, (int)$value['answer_value']));
        }
    }

    foreach ($answers as $row) {
        if (is_array($row) && isset($row['question_number'], $row['answer_value'])) {
            $normalized[(int)$row['question_number']] = max(0, min(3, (int)$row['answer_value']));
        }
    }

    ksort($normalized);
    return $normalized;
}

/**
 * تحويل الإجابات إلى خريطة بالمفاتيح الذكية
 */
function mapAnswersByQuestionKey(array $answers): array
{
    global $studyQuestions;

    $normalized = normalizeAnswersInput($answers);
    $mapped = [];

    foreach ($studyQuestions as $questionNumber => $question) {
        $key = (string)($question['key'] ?? ('q' . $questionNumber));
        $mapped[$key] = (int)($normalized[$questionNumber] ?? 0);
    }

    return $mapped;
}

/**
 * تطبيع aliases بين مفاتيح بنك الأسئلة الجديد ومفاتيح محرك التقييم الحالي.
 * هذا يسمح بتطوير questions_ai.php بدون كسر طبقة التحليل القديمة.
 */
function normalizeKeyAlias(array $byKey): array
{
    $aliasGroups = [
        'case_type' => ['case_type', 'category'],
        'legal_status' => ['legal_status'],
        'monthly_income' => ['monthly_income', 'income'],
        'family_size' => ['family_size'],
        'employment_status' => ['employment_status', 'job_status'],
        'housing_status' => ['housing_status', 'housing'],
        'housing_space' => ['housing_space', 'rooms'],
        'external_support' => ['external_support', 'support'],
        'zakat_support' => ['zakat_support'],
        'health_condition' => ['health_condition', 'medical_condition'],
        'debts' => ['debts'],
        'education_burden' => ['education_burden', 'students', 'university'],
        'basic_needs' => ['basic_needs'],
        'assets' => ['assets'],
        'inheritance_or_salary' => ['inheritance_or_salary'],
        'urgency' => ['urgency', 'urgent'],
        'food_spending_capacity' => ['food_spending_capacity'],
        'bill_payment_ability' => ['bill_payment_ability', 'bills_monthly'],
        'borrowing_frequency' => ['borrowing_frequency', 'borrow'],
        'income_stability_hidden' => ['income_stability_hidden', 'income_stability'],
        'expense_pressure_hidden' => ['expense_pressure_hidden', 'bills_arrears', 'late_bills'],
        'housing_privacy_hidden' => ['housing_privacy_hidden'],
        'treatment_delay' => ['treatment_delay', 'medical_access'],
        'children_schooling_status' => ['children_schooling_status'],
        'support_network_strength' => ['support_network_strength'],
        'asset_liquidity_hidden' => ['asset_liquidity_hidden'],
        'shock_resilience_hidden' => ['shock_resilience_hidden', 'coping_capacity'],
        'seasonal_hardship' => ['seasonal_hardship'],
        'residence_area' => ['residence_area', 'location'],
        'location_consent' => ['location_consent', 'allow_location'],
        'gps_location' => ['gps_location', 'gps'],
        'orphans' => ['orphans'],
        'widow_children' => ['widow_children'],
        'divorced_support' => ['divorced_support'],
        'disability_type' => ['disability_type'],
        'prison_years' => ['prison_years'],
        'medical_cost' => ['medical_cost'],
        'debt_reason' => ['debt_reason'],
        'support_type' => ['support_type'],
    ];

    foreach ($aliasGroups as $canonical => $aliases) {
        foreach ($aliases as $alias) {
            if (array_key_exists($alias, $byKey)) {
                $byKey[$canonical] = $byKey[$alias];
                break;
            }
        }

        if (!array_key_exists($canonical, $byKey)) {
            $byKey[$canonical] = 0;
        }
    }

    $byKey['monthly_income'] = normalizeBandValue($byKey['monthly_income'], [
        0 => 350,
        1 => 250,
        2 => 150,
    ], true);

    $byKey['housing_space'] = normalizeBandValue($byKey['housing_space'], [
        0 => 4,
        1 => 3,
        2 => 2,
    ], false);

    $byKey['bill_payment_ability'] = normalizeBandValue($byKey['bill_payment_ability'], [
        0 => 60,
        1 => 120,
        2 => 220,
    ], false);

    $byKey['expense_pressure_hidden'] = normalizeBandValue($byKey['expense_pressure_hidden'], [
        0 => 0,
        1 => 60,
        2 => 180,
    ], false);

    $byKey['medical_cost'] = normalizeBandValue($byKey['medical_cost'], [
        0 => 0,
        1 => 40,
        2 => 120,
    ], false);

    $byKey['debts'] = normalizeBandValue($byKey['debts'], [
        0 => 0,
        1 => 300,
        2 => 1000,
    ], false);

    $byKey['students'] = normalizeBandValue($byKey['students'] ?? 0, [
        0 => 0,
        1 => 1,
        2 => 2,
    ], false);

    if (($byKey['university'] ?? 0) >= 2 || ($byKey['students'] ?? 0) >= 2) {
        $byKey['education_burden'] = max((int)$byKey['education_burden'], 2);
    }

    if (($byKey['medical_condition'] ?? 0) >= 2 || ($byKey['medical_cost'] ?? 0) >= 2) {
        $byKey['health_condition'] = max((int)$byKey['health_condition'], 2);
    }

    if (($byKey['support'] ?? 0) === 0 && ($byKey['support_type'] ?? 0) !== 0) {
        $byKey['external_support'] = min((int)$byKey['external_support'], 1);
    }

    return $byKey;
}

/**
 * تحويل القيمة الرقمية/الخيار إلى شريحة 0..3.
 * إذا كانت reverse=true فالقيمة الأعلى تعني احتياجًا أقل.
 */
function normalizeBandValue(int|float|string|null $value, array $thresholds, bool $reverse = false): int
{
    if (is_string($value) && trim($value) === '') {
        return 0;
    }

    $numeric = (float)$value;

    if ($reverse) {
        if ($numeric > $thresholds[0]) {
            return 0;
        }
        if ($numeric >= $thresholds[1]) {
            return 1;
        }
        if ($numeric >= $thresholds[2]) {
            return 2;
        }
        return 3;
    }

    if ($numeric <= $thresholds[0]) {
        return 0;
    }
    if ($numeric <= $thresholds[1]) {
        return 1;
    }
    if ($numeric <= $thresholds[2]) {
        return 2;
    }
    return 3;
}

/**
 * هل السؤال سؤال تحقق/تمويه؟
 */
function isValidationQuestion(array $question): bool
{
    return (bool)($question['validation'] ?? false);
}

/**
 * هل السؤال خاص بفئة محددة؟
 */
function questionBelongsToCaseType(array $question, ?string $caseType): bool
{
    if ($caseType === null || $caseType === '') {
        return empty($question['category']);
    }

    $categories = $question['category'] ?? [];
    if (!is_array($categories) || !$categories) {
        return true;
    }

    return in_array($caseType, $categories, true);
}

/**
 * أرقام أسئلة التحقق فقط
 */
function getValidationQuestionNumbers(): array
{
    global $studyQuestions;

    $numbers = [];
    foreach ($studyQuestions as $number => $question) {
        if (isValidationQuestion($question)) {
            $numbers[] = (int)$number;
        }
    }

    return $numbers;
}

/**
 * تقدير الحاجة لإظهار أسئلة التحقق من إجابات أساسية أولية.
 */
function shouldForceValidationQuestions(array $answers): array
{
    $normalized = normalizeAnswersInput($answers);
    $byKey = normalizeKeyAlias(mapAnswersByQuestionKey($normalized));

    $score = 0;
    $reasons = [];

    if (($byKey['monthly_income'] ?? 0) >= 3 && ($byKey['debts'] ?? 0) === 0) {
        $score += 10;
        $reasons[] = 'دخل منخفض جدًا مع غياب الديون';
    }

    if (($byKey['monthly_income'] ?? 0) <= 1 && ($byKey['basic_needs'] ?? 0) >= 3) {
        $score += 12;
        $reasons[] = 'دخل جيد نسبيًا مع عجز أساسي شديد';
    }

    if (($byKey['housing_status'] ?? 0) === 0 && ($byKey['urgency'] ?? 0) >= 3) {
        $score += 8;
        $reasons[] = 'سكن جيد مع استعجال شديد';
    }

    if (($byKey['assets'] ?? 0) === 0 && ($byKey['urgency'] ?? 0) >= 2) {
        $score += 10;
        $reasons[] = 'وجود أصول مع استعجال مرتفع';
    }

    if (($byKey['external_support'] ?? 0) >= 3 && ($byKey['shock_resilience_hidden'] ?? 0) <= 1) {
        $score += 5;
        $reasons[] = 'نفي الدعم مع قدرة صمود جيدة';
    }

    $randomAudit = random_int(1, 100) <= 15;
    if ($randomAudit) {
        $score += 10;
        $reasons[] = 'تدقيق عشوائي';
    }

    return [
        'forced' => $score >= 20,
        'suspicion_score' => $score,
        'reasons' => $reasons,
        'random_audit' => $randomAudit,
    ];
}

/**
 * حساب المجموع الموزون وقيمته العليا
 */
function calculateWeightedMetrics(array $answers): array
{
    global $studyQuestions;

    $normalized = normalizeAnswersInput($answers);
    $weightedTotal = 0.0;
    $maxWeightedTotal = 0.0;
    $baseWeighted = 0.0;
    $baseMax = 0.0;
    $validationWeighted = 0.0;
    $validationMax = 0.0;

    foreach ($studyQuestions as $questionNumber => $question) {
        $weight = (float)($question['weight'] ?? 1);
        $answerKey = (int)($normalized[$questionNumber] ?? 0);
        $answerScore = (int)($question['options'][$answerKey]['score'] ?? 0);

        $weighted = $answerScore * $weight;
        $maxWeighted = 3 * $weight;

        $weightedTotal += $weighted;
        $maxWeightedTotal += $maxWeighted;

        if (isValidationQuestion($question)) {
            $validationWeighted += $weighted;
            $validationMax += $maxWeighted;
        } else {
            $baseWeighted += $weighted;
            $baseMax += $maxWeighted;
        }
    }

    return [
        'weighted_total' => round($weightedTotal, 2),
        'max_weighted_total' => round($maxWeightedTotal, 2),
        'base_weighted' => round($baseWeighted, 2),
        'base_max' => round($baseMax, 2),
        'validation_weighted' => round($validationWeighted, 2),
        'validation_max' => round($validationMax, 2),
    ];
}

/**
 * نسبة محور معيّن من 0 إلى 100
 */
function axisPercent(array $byKey, array $keys): float
{
    $sum = 0;
    $max = 0;

    foreach ($keys as $key) {
        $value = (int)($byKey[$key] ?? 0);
        $sum += $value;
        $max += 3;
    }

    if ($max <= 0) {
        return 0.0;
    }

    return round(($sum / $max) * 100, 2);
}

/**
 * تقييم الموثوقية وكشف التناقضات
 */
function evaluateTrustAndConsistency(array $byKey): array
{
    $trustScore = 100;
    $flags = [];
    $notes = [];
    $penalty = 0;
    $criticalCount = 0;
    $moderateCount = 0;
    $type = (string)($byKey['case_type'] ?? '');

    $addFlag = static function (
        string $text,
        int $trustPenalty,
        string $note = '',
        bool $critical = false
    ) use (&$trustScore, &$flags, &$notes, &$penalty, &$criticalCount, &$moderateCount): void {
        $flags[] = $text;
        if ($note !== '') {
            $notes[] = $note;
        }
        $penalty += $trustPenalty;
        $trustScore -= $trustPenalty;
        if ($critical) {
            $criticalCount++;
        } else {
            $moderateCount++;
        }
    };

    if (
        ($byKey['monthly_income'] ?? 0) <= 1 &&
        (
            ($byKey['basic_needs'] ?? 0) >= 3 ||
            ($byKey['food_spending_capacity'] ?? 0) >= 3 ||
            ($byKey['expense_pressure_hidden'] ?? 0) >= 3
        )
    ) {
        $addFlag(
            '⚠️ تناقض مالي: دخل معلن مرتفع/متوسط نسبيًا مع عجز معيشي شديد جدًا.',
            14,
            'يحتاج إلى تحقق ميداني أو مستندات دخل/التزامات.',
            true
        );
    }

    if (
        ($byKey['monthly_income'] ?? 0) >= 3 &&
        ($byKey['debts'] ?? 0) === 0 &&
        ($byKey['borrowing_frequency'] ?? 0) === 0 &&
        ($byKey['expense_pressure_hidden'] ?? 0) <= 1 &&
        ($byKey['food_spending_capacity'] ?? 0) <= 1
    ) {
        $addFlag(
            '⚠️ سلوك غير منطقي: دخل شبه معدوم دون ديون أو استدانة أو ضغط مالي ظاهر.',
            16,
            'غالبًا يوجد مصدر دعم غير مصرح به أو عدم دقة في وصف الواقع.',
            true
        );
    }

    if (
        ($byKey['assets'] ?? 0) === 0 &&
        (($byKey['asset_liquidity_hidden'] ?? 0) <= 1 || ($byKey['expense_pressure_hidden'] ?? 0) >= 2) &&
        ($byKey['urgency'] ?? 0) >= 2
    ) {
        $addFlag(
            '⚠️ تضارب أصول: تم الإقرار بوجود أصول مؤثرة مع وصف حاجة شديدة.',
            14,
            'يلزم التحقق من نوع الأصل وقابليته للبيع أو الاستفادة منه.',
            true
        );
    }

    if (
        ($byKey['external_support'] ?? 0) >= 3 &&
        (($byKey['support_network_strength'] ?? 0) <= 1 || ($byKey['income_stability_hidden'] ?? 0) <= 1)
    ) {
        $addFlag(
            '⚠️ احتمال إخفاء مصادر إسناد: نفي وجود دعم مع مؤشرات على وجود شبكة أو تدفق دخل مستقر.',
            10,
            'يستحسن سؤال المتقدم عن المساعدات غير الرسمية والتحويلات العائلية.'
        );
    }

    if (
        ($byKey['employment_status'] ?? 0) >= 3 &&
        ($byKey['income_stability_hidden'] ?? 0) <= 1 &&
        ($byKey['monthly_income'] ?? 0) <= 1
    ) {
        $addFlag(
            '⚠️ تناقض عمل/دخل: بطالة طويلة مع انتظام دخل جيد نسبيًا.',
            10,
            'قد يكون هناك دخل غير مصرح به أو تعريف غير دقيق لحالة العمل.'
        );
    }

    if (
        ($byKey['housing_status'] ?? 0) === 0 &&
        ($byKey['housing_space'] ?? 0) >= 3 &&
        ($byKey['housing_privacy_hidden'] ?? 0) >= 3
    ) {
        $addFlag(
            '⚠️ تناقض سكني: وصف السكن كملك مناسب مع اكتظاظ شديد وفقدان خصوصية كبير.',
            8,
            'قد يكون السكن ملكًا لكنه غير صالح، أو توجد دقة منخفضة في الإجابات.'
        );
    }

    if (
        ($byKey['health_condition'] ?? 0) >= 3 &&
        ($byKey['treatment_delay'] ?? 0) === 0 &&
        ($byKey['debts'] ?? 0) === 0 &&
        ($byKey['basic_needs'] ?? 0) <= 1
    ) {
        $addFlag(
            '⚠️ مؤشر يحتاج مراجعة: عبء صحي شديد دون أثر مالي أو علاجي ظاهر.',
            8,
            'ربما الحالة الصحية مغطاة بالكامل من جهة أخرى، أو يوجد تضخيم للوصف الصحي.'
        );
    }

    if (
        ($byKey['education_burden'] ?? 0) >= 3 &&
        ($byKey['children_schooling_status'] ?? 0) === 0
    ) {
        $addFlag(
            '⚠️ تضارب تعليمي: عبء تعليمي كبير معلن دون أثر فعلي ظاهر على تعليم الأبناء.',
            6,
            'قد يكون العبء حقيقيًا لكن يحتاج توصيفًا أدق.'
        );
    }

    if (
        ($byKey['urgency'] ?? 0) >= 3 &&
        ($byKey['shock_resilience_hidden'] ?? 0) <= 1 &&
        ($byKey['basic_needs'] ?? 0) <= 1
    ) {
        $addFlag(
            '⚠️ تضخيم في الاستعجال: تم وصف الحاجة كعاجلة جدًا مع قدرة جيدة نسبيًا على الاستمرار.',
            10,
            'الحالة قد تكون مستحقة لكن ليس بالأولوية القصوى.'
        );
    }

    if (
        ($byKey['monthly_income'] ?? 0) >= 3 &&
        ($byKey['seasonal_hardship'] ?? 0) <= 1 &&
        ($byKey['expense_pressure_hidden'] ?? 0) <= 1
    ) {
        $addFlag(
            '⚠️ تناقض معيشي: دخل منخفض جدًا معلن دون ضغط موسمي أو ضغط آخر الشهر.',
            8,
            'هذا يضعف موثوقية تقدير الدخل أو يشير لوجود مصدر دعم غير مصرح به.'
        );
    }

    if (
        ($byKey['external_support'] ?? 0) === 0 &&
        ($byKey['borrowing_frequency'] ?? 0) >= 3 &&
        ($byKey['food_spending_capacity'] ?? 0) >= 2
    ) {
        $addFlag(
            '⚠️ تناقض في الدعم الخارجي: وجود دعم ثابت كافٍ لا ينسجم مع استدانة شبه دائمة وعجز غذائي.',
            12,
            'قد تكون قيمة الدعم مبالغًا فيها أو أسيء فهم السؤال.',
            true
        );
    }

    // كشف ذكي حسب الفئة
    if ($type === 'orphan' && ($byKey['orphans'] ?? 0) === 0) {
        $addFlag(
            '⚠️ تصنيف يتيم بدون وجود أيتام فعليًا في البيانات.',
            15,
            'يلزم التحقق من اختيار الفئة أو من تعريف الحالة.',
            true
        );
    }

    if ($type === 'widow' && ($byKey['widow_children'] ?? 0) === 0 && ($byKey['family_size'] ?? 0) >= 2) {
        $addFlag(
            '⚠️ أرملة مع غياب معلومات الأبناء رغم وجود أسرة أكبر من فرد واحد.',
            8,
            'ربما البيانات ناقصة أو الفئة المختارة غير دقيقة.'
        );
    }

    if ($type === 'divorced' && ($byKey['divorced_support'] ?? 0) === 0 && ($byKey['monthly_income'] ?? 0) <= 1) {
        $addFlag(
            '⚠️ مطلقة مع وجود نفقة/دعم كافٍ محتمل ودخل غير منخفض.',
            8,
            'يستحسن مراجعة حقيقة النفقة أو الدعم المقدم.'
        );
    }

    if ($type === 'disabled' && ($byKey['health_condition'] ?? 0) <= 1) {
        $addFlag(
            '⚠️ ذوو إعاقة دون أثر صحي/وظيفي ظاهر في الإجابات.',
            10,
            'يحتاج توصيف نوع الإعاقة ومدى تأثيرها على المعيشة.',
            true
        );
    }

    if ($type === 'prisoner' && ($byKey['prison_years'] ?? 0) === 0) {
        $addFlag(
            '⚠️ أسرة سجين دون مدة سجن أو مؤشر واضح على غياب المعيل.',
            10,
            'يلزم توضيح وضع المعيل القانوني ومدة غيابه.'
        );
    }

    $consistencySignals = 0;
    if (($byKey['monthly_income'] ?? 0) >= 2 && ($byKey['income_stability_hidden'] ?? 0) >= 2) {
        $consistencySignals++;
    }
    if (($byKey['basic_needs'] ?? 0) >= 2 && ($byKey['food_spending_capacity'] ?? 0) >= 2) {
        $consistencySignals++;
    }
    if (($byKey['housing_space'] ?? 0) >= 2 && ($byKey['housing_privacy_hidden'] ?? 0) >= 2) {
        $consistencySignals++;
    }
    if (($byKey['health_condition'] ?? 0) >= 2 && ($byKey['treatment_delay'] ?? 0) >= 1) {
        $consistencySignals++;
    }
    if (($byKey['assets'] ?? 0) >= 2 && ($byKey['asset_liquidity_hidden'] ?? 0) >= 2) {
        $consistencySignals++;
    }

    if ($consistencySignals >= 4) {
        $trustScore += 6;
        $notes[] = '✅ اتساق مرتفع بين عدة أسئلة مباشرة وتمويهية، ما يعزز موثوقية الحالة.';
    } elseif ($consistencySignals >= 2) {
        $trustScore += 3;
        $notes[] = '✅ توجد مؤشرات اتساق جيدة بين المحاور الأساسية.';
    }

    $trustScore = max(5, min(100, $trustScore));

    return [
        'trust_score' => $trustScore,
        'trust_penalty' => $penalty,
        'flags' => $flags,
        'notes' => $notes,
        'critical_flags_count' => $criticalCount,
        'moderate_flags_count' => $moderateCount,
        'consistency_signals' => $consistencySignals,
    ];
}

/**
 * احتساب الإضافات والخصومات المباشرة من واقع الحاجة
 */
function evaluateHardshipAdjustments(array $byKey): array
{
    $bonus = 0;
    $penalty = 0;
    $flags = [];
    $notes = [];
    $type = (string)($byKey['case_type'] ?? '');

    if (($byKey['legal_status'] ?? 0) === 3) {
        $bonus += 6;
        $flags[] = 'أولوية خاصة: من أبناء غزة بدون رقم وطني';
        $notes[] = 'الحالة القانونية تحد من الوصول إلى برامج الدعم الرسمية.';
    }

    if (
        ($byKey['monthly_income'] ?? 0) >= 3 &&
        ($byKey['external_support'] ?? 0) >= 3 &&
        ($byKey['basic_needs'] ?? 0) >= 2
    ) {
        $bonus += 5;
        $flags[] = 'احتياج شديد: لا دخل ولا دعم مع عجز في الاحتياجات الأساسية';
    }

    if (
        ($byKey['family_size'] ?? 0) >= 2 &&
        (($byKey['housing_space'] ?? 0) >= 2 || ($byKey['housing_privacy_hidden'] ?? 0) >= 2)
    ) {
        $bonus += 3;
        $flags[] = 'اكتظاظ سكني مع عدد أفراد مرتفع';
    }

    if (
        ($byKey['health_condition'] ?? 0) >= 2 &&
        ($byKey['monthly_income'] ?? 0) >= 2
    ) {
        $bonus += 4;
        $flags[] = 'عبء صحي ثقيل مع ضعف دخل';
    }

    if (
        ($byKey['debts'] ?? 0) >= 2 &&
        ($byKey['external_support'] ?? 0) >= 2
    ) {
        $bonus += 2;
        $flags[] = 'ديون مؤثرة مع ضعف في الدعم الخارجي';
    }

    if (
        ($byKey['food_spending_capacity'] ?? 0) >= 2 &&
        ($byKey['shock_resilience_hidden'] ?? 0) >= 2
    ) {
        $bonus += 4;
        $flags[] = 'هشاشة معيشية مرتفعة: الغذاء والاستمرار الشهري في خطر';
    }

    if (
        ($byKey['education_burden'] ?? 0) >= 2 &&
        ($byKey['children_schooling_status'] ?? 0) >= 2
    ) {
        $bonus += 2;
        $flags[] = 'تعثر تعليمي ناتج عن ضعف القدرة المالية';
    }

    if ($type === 'orphan' && ($byKey['orphans'] ?? 0) >= 2) {
        $bonus += 3;
        $flags[] = 'أولوية فئة: وجود أيتام بعدد مؤثر';
    }

    if ($type === 'widow') {
        $bonus += 2;
        $flags[] = 'أولوية فئة: أرملة';
    }

    if ($type === 'disabled' || $type === 'sick') {
        $bonus += 2;
        $flags[] = 'أولوية فئة: مرض/إعاقة مؤثرة';
    }

    if ($type === 'prisoner') {
        $bonus += 2;
        $flags[] = 'أولوية فئة: غياب المعيل بسبب السجن';
    }

    if (($byKey['assets'] ?? 0) === 0) {
        $penalty += 6;
        $flags[] = 'وجود أصول أو ممتلكات مؤثرة';
        $notes[] = 'وجود أصول يستدعي مراجعة اللجنة للتأكد من حجم الاستحقاق الفعلي.';
    } elseif (($byKey['assets'] ?? 0) === 1) {
        $penalty += 3;
        $notes[] = 'يوجد أصل أو ممتلك محدود الأثر.';
    }

    if (($byKey['asset_liquidity_hidden'] ?? 0) === 0) {
        $penalty += 3;
        $flags[] = 'يمكن توفير سيولة سريعة من أصل أو ممتلك قائم';
    }

    if (($byKey['inheritance_or_salary'] ?? 0) === 0) {
        $penalty += 5;
        $flags[] = 'وجود دخل شبه ثابت أو راتب ورثة/ضمان مؤثر';
    } elseif (($byKey['inheritance_or_salary'] ?? 0) === 1) {
        $penalty += 2;
    }

    if (
        ($byKey['external_support'] ?? 0) === 0 &&
        ($byKey['zakat_support'] ?? 0) <= 1
    ) {
        $penalty += 3;
        $flags[] = 'وجود دعم ثابت من جهات أخرى';
    }

    if (
        ($byKey['shock_resilience_hidden'] ?? 0) === 0 &&
        ($byKey['basic_needs'] ?? 0) <= 1
    ) {
        $penalty += 2;
        $notes[] = 'قدرة الأسرة على امتصاص الصدمة الشهرية تخفف أولوية الاستعجال.';
    }

    return [
        'bonus_score' => $bonus,
        'penalty_score' => $penalty,
        'flags' => $flags,
        'notes' => $notes,
    ];
}

/**
 * تصنيف الاستحقاق النهائي
 */
function classifyEligibility(float $povertyPercent, int $trustScore, int $criticalFlagsCount): array
{
    if ($trustScore < 35 || $criticalFlagsCount >= 3) {
        return [
            'category' => 'review',
            'category_label' => 'بحاجة مراجعة دقيقة',
            'recommendation' => 'الحالة قد تكون محتاجة، لكن موثوقية الإجابات منخفضة وتستلزم تحققًا إضافيًا قبل أي قرار نهائي.',
            'eligibility' => 'بحاجة مراجعة',
        ];
    }

    if ($povertyPercent >= 78 && $trustScore >= 70) {
        return [
            'category' => 'high',
            'category_label' => 'احتياج مرتفع جدًا',
            'recommendation' => 'الحالة شديدة الاحتياج وموثوقيتها جيدة؛ توصى بالأولوية العاجلة في الدراسة والدعم.',
            'eligibility' => 'مستحق بشدة',
        ];
    }

    if ($povertyPercent >= 58 && $trustScore >= 55) {
        return [
            'category' => 'high',
            'category_label' => 'احتياج مرتفع',
            'recommendation' => 'الحالة ذات أولوية مرتفعة وتحتاج معالجة سريعة أو دعم مرحلي واضح.',
            'eligibility' => 'مستحق جزئيًا',
        ];
    }

    if ($povertyPercent >= 38 && $trustScore >= 45) {
        return [
            'category' => 'medium',
            'category_label' => 'احتياج متوسط',
            'recommendation' => 'الحالة تستحق دراسة فعلية، وقد تكون مؤهلة لدعم جزئي أو مشروط وفق موارد اللجنة.',
            'eligibility' => 'مستحق جزئيًا',
        ];
    }

    return [
        'category' => 'low',
        'category_label' => 'احتياج منخفض',
        'recommendation' => 'الأولوية أقل من الحالات الأشد، ولا يوصى بالدعم إلا إذا ظهرت وثائق أو ظروف إضافية.',
        'eligibility' => 'غير مستحق',
    ];
}

/**
 * حساب النتيجة الذكية AI-Level
 */
function calculateResult(array $answers): array
{
    $normalizedAnswers = normalizeAnswersInput($answers);
    $byKey = mapAnswersByQuestionKey($normalizedAnswers);
    $byKey = normalizeKeyAlias($byKey);

    $metrics = calculateWeightedMetrics($normalizedAnswers);
    $hardship = evaluateHardshipAdjustments($byKey);
    $trust = evaluateTrustAndConsistency($byKey);

    $baseScore = (float)$metrics['weighted_total'];
    $smartScoreBeforeTrust = $baseScore + $hardship['bonus_score'] - $hardship['penalty_score'];
    $smartScoreBeforeTrust = max(0, $smartScoreBeforeTrust);

    $trustFactor = $trust['trust_score'] / 100;
    $finalSmartScore = round($smartScoreBeforeTrust * $trustFactor, 2);

    $maxScore = (float)$metrics['max_weighted_total'];
    $povertyPercent = $maxScore > 0
        ? round(($finalSmartScore / $maxScore) * 100, 2)
        : 0.0;

    $classification = classifyEligibility(
        $povertyPercent,
        $trust['trust_score'],
        $trust['critical_flags_count']
    );

    $financialStress = axisPercent($byKey, [
        'monthly_income',
        'debts',
        'food_spending_capacity',
        'bill_payment_ability',
        'borrowing_frequency',
        'income_stability_hidden',
        'expense_pressure_hidden',
    ]);

    $housingStress = axisPercent($byKey, [
        'housing_status',
        'housing_space',
        'housing_privacy_hidden',
    ]);

    $healthStress = axisPercent($byKey, [
        'health_condition',
        'treatment_delay',
    ]);

    $supportWeakness = axisPercent($byKey, [
        'external_support',
        'zakat_support',
        'support_network_strength',
        'shock_resilience_hidden',
    ]);

    $familyPressure = axisPercent($byKey, [
        'family_size',
        'education_burden',
        'children_schooling_status',
        'seasonal_hardship',
    ]);

    $flags = array_values(array_unique(array_merge(
        $hardship['flags'],
        $trust['flags']
    )));

    $notes = array_values(array_unique(array_merge(
        $hardship['notes'],
        $trust['notes']
    )));

    return [
        'total_score' => $finalSmartScore,
        'poverty_percent' => $povertyPercent,
        'category' => $classification['category'],
        'category_label' => $classification['category_label'],
        'recommendation' => $classification['recommendation'],
        'eligibility' => $classification['eligibility'],
        'flags' => $flags,
        'notes' => $notes,
        'base_score' => round($baseScore, 2),
        'bonus_score' => $hardship['bonus_score'],
        'penalty_score' => $hardship['penalty_score'],
        'max_score' => round($maxScore, 2),
        'trust_score' => $trust['trust_score'],
        'trust_penalty' => $trust['trust_penalty'],
        'critical_flags_count' => $trust['critical_flags_count'],
        'moderate_flags_count' => $trust['moderate_flags_count'],
        'consistency_signals' => $trust['consistency_signals'],
        'smart_score_before_trust' => round($smartScoreBeforeTrust, 2),
        'trust_adjusted_score' => $finalSmartScore,
        'financial_stress_percent' => $financialStress,
        'housing_stress_percent' => $housingStress,
        'health_stress_percent' => $healthStress,
        'support_weakness_percent' => $supportWeakness,
        'family_pressure_percent' => $familyPressure,
        'base_questions_score' => $metrics['base_weighted'],
        'validation_questions_score' => $metrics['validation_weighted'],
        'base_questions_max' => $metrics['base_max'],
        'validation_questions_max' => $metrics['validation_max'],
    ];
}

/**
 * ترجمة تصنيف الاحتياج
 */
function studyCategoryLabel(string $category): string
{
    return match ($category) {
        'low'    => 'احتياج منخفض',
        'medium' => 'احتياج متوسط',
        'high'   => 'احتياج مرتفع',
        'review' => 'بحاجة مراجعة دقيقة',
        default  => 'غير محدد',
    };
}

/**
 * ترجمة حالة المرشح
 */
function candidateStatusLabel(string $status): string
{
    return match ($status) {
        'new'      => 'جديد',
        'sent'     => 'تم إنشاء رابط',
        'answered' => 'أجاب على النموذج',
        default    => 'غير محدد',
    };
}

/**
 * ترجمة حالة اللجنة
 */
function committeeStatusLabel(string $status): string
{
    return match ($status) {
        'pending'  => 'بانتظار المراجعة',
        'approved' => 'قبول',
        'rejected' => 'رفض',
        'review'   => 'قيد الدراسة',
        default    => 'غير محدد',
    };
}

/**
 * ألوان الشارات
 */
function studyBadgeClass(string $type): string
{
    return match ($type) {
        'new'      => 'secondary',
        'sent'     => 'primary',
        'answered' => 'success',
        'low'      => 'secondary',
        'medium'   => 'warning',
        'high'     => 'danger',
        'review'   => 'warning',
        'pending'  => 'secondary',
        'approved' => 'success',
        'rejected' => 'danger',
        'active'   => 'primary',
        'used'     => 'success',
        default    => 'secondary',
    };
}

/**
 * رابط أساس النظام
 */
function studyBaseUrl(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $https . '://' . $host . '/zaka/study';
}

/**
 * بناء رابط النموذج
 */
function buildStudyLink(string $token): string
{
    return studyBaseUrl() . '/form_ai.php?t=' . urlencode($token);
}

/**
 * التحقق من صلاحية الرابط
 */
function validateUsableLinkByToken(string $token): array
{
    $link = getLinkByToken($token);

    if (!$link) {
        return [
            'ok' => false,
            'message' => 'الرابط غير موجود',
            'link' => null,
            'candidate' => null,
        ];
    }

    if ((int)$link['is_used'] === 1) {
        return [
            'ok' => false,
            'message' => 'تم استخدام هذا الرابط مسبقًا',
            'link' => $link,
            'candidate' => null,
        ];
    }

    $candidate = getCandidate((int)$link['candidate_id']);

    if (!$candidate) {
        return [
            'ok' => false,
            'message' => 'بيانات المرشح غير موجودة',
            'link' => $link,
            'candidate' => null,
        ];
    }

    return [
        'ok' => true,
        'message' => 'ok',
        'link' => $link,
        'candidate' => $candidate,
    ];
}

/**
 * تقسيم سطر الاستيراد إلى اسم + هاتف
 */
function parseCandidateLine(string $line): array|false
{
    $line = trim($line);
    if ($line === '') {
        return false;
    }

    $patterns = [
        '/^(.+?)\s*\|\s*(.+)$/u',
        '/^(.+?)\s*-\s*(.+)$/u',
        '/^(.+?)\s*,\s*(.+)$/u',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $line, $matches)) {
            $name  = normalizeName((string)$matches[1]);
            $phone = normalizePhone((string)$matches[2]);

            if ($name === '' || $phone === '') {
                return false;
            }

            return [
                'full_name' => $name,
                'phone' => $phone,
            ];
        }
    }

    return false;
}

/**
 * إحصائيات عامة
 */
function getStudyStats(): array
{
    $pdo = db();

    return [
        'candidates'  => (int)$pdo->query("SELECT COUNT(*) FROM study_candidates")->fetchColumn(),
        'links'       => (int)$pdo->query("SELECT COUNT(*) FROM study_links")->fetchColumn(),
        'used_links'  => (int)$pdo->query("SELECT COUNT(*) FROM study_links WHERE is_used = 1")->fetchColumn(),
        'submissions' => (int)$pdo->query("SELECT COUNT(*) FROM study_submissions")->fetchColumn(),
    ];
}

/**
 * المرشحون
 */
function getStudyCandidates(int $limit = 200): array
{
    $limit = max(1, min($limit, 1000));

    $stmt = db()->query("
        SELECT
            c.id,
            c.full_name,
            c.phone,
            c.status,
            c.created_at,
            (
                SELECT COUNT(*)
                FROM study_links l
                WHERE l.candidate_id = c.id
            ) AS links_count
        FROM study_candidates c
        ORDER BY c.id DESC
        LIMIT {$limit}
    ");

    return $stmt->fetchAll();
}

/**
 * الروابط
 */
function getStudyLinks(int $limit = 200): array
{
    $limit = max(1, min($limit, 1000));

    $stmt = db()->query("
        SELECT
            l.id,
            l.candidate_id,
            l.token,
            l.is_used,
            l.created_at,
            l.used_at,
            c.full_name,
            c.phone
        FROM study_links l
        INNER JOIN study_candidates c ON c.id = l.candidate_id
        ORDER BY l.id DESC
        LIMIT {$limit}
    ");

    return $stmt->fetchAll();
}

/**
 * الردود
 */
function getStudySubmissions(int $limit = 200): array
{
    $limit = max(1, min($limit, 1000));

    $stmt = db()->query("
        SELECT
            s.id,
            s.candidate_id,
            s.link_id,
            s.total_score,
            s.poverty_percent,
            s.category,
            s.committee_status,
            s.committee_notes,
            s.submitted_at,
            c.full_name,
            c.phone,
            l.token
        FROM study_submissions s
        INNER JOIN study_candidates c ON c.id = s.candidate_id
        INNER JOIN study_links l ON l.id = s.link_id
        ORDER BY s.id DESC
        LIMIT {$limit}
    ");

    return $stmt->fetchAll();
}
