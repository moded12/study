<?php
// /zaka/study/includes/functions_ai.php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config/study_constants.php';
require_once __DIR__ . '/../config/study_texts.php';
require_once __DIR__ . '/scoring_rules.php';
require_once __DIR__ . '/questions_ai.php';

function clean(string $value): string { return trim($value); }
function normalizeName(string $name): string { $name = trim($name); $name = preg_replace('/\s+/u', ' ', $name) ?? ''; return trim($name); }
function normalizePhone(string $phone): string { $phone = trim($phone); $phone = preg_replace('/[^\d+]/u', '', $phone) ?? ''; return trim($phone); }
function cleanTextarea(?string $value): string { $value = trim((string)$value); $value = preg_replace("/\r\n|\r/u", "\n", $value) ?? ''; return trim($value); }
function generateToken(int $bytes = 16): string { return bin2hex(random_bytes($bytes)); }

function generateUniqueStudyToken(): string {
    $pdo = db();
    do {
        $token = generateToken(16);
        $stmt = $pdo->prepare("SELECT id FROM study_links WHERE token = ? LIMIT 1");
        $stmt->execute([$token]);
        $exists = $stmt->fetch();
    } while ($exists);
    return $token;
}

function getLinkByToken(string $token): array|false {
    $stmt = db()->prepare("SELECT * FROM study_links WHERE token = ? LIMIT 1");
    $stmt->execute([$token]);
    return $stmt->fetch();
}
function getStudyLinkById(int $linkId): array|false {
    $stmt = db()->prepare("SELECT * FROM study_links WHERE id = ? LIMIT 1");
    $stmt->execute([$linkId]);
    return $stmt->fetch();
}
function getCandidate(int $candidateId): array|false {
    $stmt = db()->prepare("SELECT * FROM study_candidates WHERE id = ? LIMIT 1");
    $stmt->execute([$candidateId]);
    return $stmt->fetch();
}
function getCandidateByPhone(string $phone): array|false {
    $stmt = db()->prepare("SELECT * FROM study_candidates WHERE phone = ? LIMIT 1");
    $stmt->execute([$phone]);
    return $stmt->fetch();
}
function candidateHasLink(int $candidateId): bool {
    $stmt = db()->prepare("SELECT id FROM study_links WHERE candidate_id = ? LIMIT 1");
    $stmt->execute([$candidateId]);
    return (bool)$stmt->fetch();
}
function candidateHasSubmission(int $candidateId): bool {
    $stmt = db()->prepare("SELECT id FROM study_submissions WHERE candidate_id = ? LIMIT 1");
    $stmt->execute([$candidateId]);
    return (bool)$stmt->fetch();
}
function getSubmission(int $submissionId): array|false {
    $stmt = db()->prepare("SELECT * FROM study_submissions WHERE id = ? LIMIT 1");
    $stmt->execute([$submissionId]);
    return $stmt->fetch();
}
function getSubmissionAnswers(int $submissionId): array {
    $stmt = db()->prepare("SELECT question_number, answer_value FROM study_answers WHERE submission_id = ? ORDER BY question_number ASC");
    $stmt->execute([$submissionId]);
    return $stmt->fetchAll();
}

function normalizeAnswersInput(array $answers): array {
    $normalized = [];
    foreach ($answers as $key => $value) {
        if (is_array($value)) continue;
        if (is_numeric($key)) { $normalized[(int)$key] = max(0, min(3, (int)$value)); continue; }
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

function mapAnswersByQuestionKey(array $answers): array {
    global $studyQuestions;
    $normalized = normalizeAnswersInput($answers);
    $mapped = [];
    foreach ($studyQuestions as $questionNumber => $question) {
        $key = (string)($question['key'] ?? ('q' . $questionNumber));
        $mapped[$key] = (int)($normalized[$questionNumber] ?? 0);
    }
    return $mapped;
}

function normalizeBandValue(int|float|string|null $value, array $thresholds, bool $reverse = false): int {
    if (is_string($value) && trim($value) === '') return 0;
    $numeric = (float)$value;
    if ($reverse) {
        if ($numeric > $thresholds[0]) return 0;
        if ($numeric >= $thresholds[1]) return 1;
        if ($numeric >= $thresholds[2]) return 2;
        return 3;
    }
    if ($numeric <= $thresholds[0]) return 0;
    if ($numeric <= $thresholds[1]) return 1;
    if ($numeric <= $thresholds[2]) return 2;
    return 3;
}

function normalizeKeyAlias(array $byKey): array {
    foreach (STUDY_KEY_ALIASES as $canonical => $aliases) {
        foreach ($aliases as $alias) {
            if (array_key_exists($alias, $byKey)) { $byKey[$canonical] = $byKey[$alias]; break; }
        }
        if (!array_key_exists($canonical, $byKey)) $byKey[$canonical] = 0;
    }

    foreach (STUDY_NUMERIC_BANDS as $key => $config) {
        if (array_key_exists($key, $byKey)) {
            $byKey[$key] = normalizeBandValue($byKey[$key], $config['thresholds'], (bool)($config['reverse'] ?? false));
        }
    }

    if (($byKey['university'] ?? 0) >= 2 || ($byKey['students'] ?? 0) >= 2) $byKey['education_burden'] = max((int)$byKey['education_burden'], 2);
    if (($byKey['medical_condition'] ?? 0) >= 2 || ($byKey['medical_cost'] ?? 0) >= 2) $byKey['health_condition'] = max((int)$byKey['health_condition'], 2);
    if (($byKey['support'] ?? 0) === 0 && ($byKey['support_type'] ?? 0) !== 0) $byKey['external_support'] = min((int)$byKey['external_support'], 1);

    if (($byKey['financial_fragility_priority'] ?? 0) >= 2) $byKey['food_spending_capacity'] = max((int)$byKey['food_spending_capacity'], (int)$byKey['financial_fragility_priority']);
    if (($byKey['income_shortfall_effect'] ?? 0) >= 2) {
        $byKey['basic_needs'] = max((int)$byKey['basic_needs'], (int)$byKey['income_shortfall_effect']);
        $byKey['shock_resilience_hidden'] = max((int)$byKey['shock_resilience_hidden'], (int)$byKey['income_shortfall_effect']);
    }
    if (($byKey['education_disruption_due_to_poverty'] ?? 0) >= 1) {
        $byKey['children_schooling_status'] = max((int)$byKey['children_schooling_status'], (int)$byKey['education_disruption_due_to_poverty']);
        $byKey['education_burden'] = max((int)$byKey['education_burden'], (int)$byKey['education_disruption_due_to_poverty']);
    }
    if (($byKey['utility_cutoff_risk'] ?? 0) >= 1) {
        $byKey['bill_payment_ability'] = max((int)$byKey['bill_payment_ability'], (int)$byKey['utility_cutoff_risk']);
        $byKey['expense_pressure_hidden'] = max((int)$byKey['expense_pressure_hidden'], (int)$byKey['utility_cutoff_risk']);
    }
    if (($byKey['camp_residence'] ?? 0) >= 2) {
        $byKey['residence_area'] = max((int)$byKey['residence_area'], (int)$byKey['camp_residence']);
        $byKey['housing_status'] = max((int)$byKey['housing_status'], min(3, (int)$byKey['camp_residence']));
    }

    return $byKey;
}

function isValidationQuestion(array $question): bool { return (bool)($question['validation'] ?? false); }
function questionBelongsToCaseType(array $question, ?string $caseType): bool {
    if ($caseType === null || $caseType === '') return empty($question['category']);
    $categories = $question['category'] ?? [];
    if (!is_array($categories) || !$categories) return true;
    return in_array($caseType, $categories, true);
}
function getValidationQuestionNumbers(): array {
    global $studyQuestions;
    $numbers = [];
    foreach ($studyQuestions as $number => $question) if (isValidationQuestion($question)) $numbers[] = (int)$number;
    return $numbers;
}

function shouldForceValidationQuestions(array $answers): array {
    $normalized = normalizeAnswersInput($answers);
    $byKey = normalizeKeyAlias(mapAnswersByQuestionKey($normalized));
    $score = 0; $reasons = [];

    if (($byKey['monthly_income'] ?? 0) >= 3 && ($byKey['debts'] ?? 0) === 0) { $score += 10; $reasons[] = 'دخل منخفض جدًا مع غياب الديون'; }
    if (($byKey['monthly_income'] ?? 0) <= 1 && ($byKey['basic_needs'] ?? 0) >= 3) { $score += 12; $reasons[] = 'دخل جيد نسبيًا مع عجز أساسي شديد'; }
    if (($byKey['housing_status'] ?? 0) === 0 && ($byKey['urgency'] ?? 0) >= 3) { $score += 8; $reasons[] = 'سكن جيد مع استعجال شديد'; }
    if (($byKey['assets'] ?? 0) === 0 && ($byKey['urgency'] ?? 0) >= 2) { $score += 10; $reasons[] = 'وجود أصول مع استعجال مرتفع'; }
    if (($byKey['external_support'] ?? 0) >= 3 && ($byKey['shock_resilience_hidden'] ?? 0) <= 1) { $score += 5; $reasons[] = 'نفي الدعم مع قدرة صمود جيدة'; }
    if (($byKey['financial_fragility_priority'] ?? 0) >= 2 && ($byKey['income_shortfall_effect'] ?? 0) >= 2) { $score += 8; $reasons[] = 'هشاشة مالية مرتفعة تؤثر على الأساسيات'; }
    if (($byKey['utility_cutoff_risk'] ?? 0) >= 2 && ($byKey['bill_payment_ability'] ?? 0) <= 1) { $score += 7; $reasons[] = 'خطر مرتفع في انقطاع الخدمات مع وصف قدرة دفع أفضل'; }
    if (($byKey['has_sanad_app'] ?? 0) >= 2 && ($byKey['monthly_income'] ?? 0) <= 1) { $score += 5; $reasons[] = 'انخفاض قابلية التحقق من الدخل عبر تطبيق سند'; }

    try { $randomAudit = random_int(1, 100) <= (int)STUDY_THRESHOLDS['validation_random_audit_percent']; }
    catch (Throwable $e) { $randomAudit = false; }

    if ($randomAudit) { $score += 10; $reasons[] = 'تدقيق عشوائي'; }

    return [
        'forced' => $score >= (int)STUDY_THRESHOLDS['validation_force_score'],
        'suspicion_score' => $score,
        'reasons' => $reasons,
        'random_audit' => $randomAudit,
    ];
}

function calculateWeightedMetrics(array $answers): array {
    global $studyQuestions;
    $normalized = normalizeAnswersInput($answers);
    $weightedTotal = 0.0; $maxWeightedTotal = 0.0; $baseWeighted = 0.0; $baseMax = 0.0; $validationWeighted = 0.0; $validationMax = 0.0;
    foreach ($studyQuestions as $questionNumber => $question) {
        $weight = (float)($question['weight'] ?? 1);
        $answerKey = (int)($normalized[$questionNumber] ?? 0);
        $answerScore = (int)($question['options'][$answerKey]['score'] ?? 0);
        $weighted = $answerScore * $weight; $maxWeighted = 3 * $weight;
        $weightedTotal += $weighted; $maxWeightedTotal += $maxWeighted;
        if (isValidationQuestion($question)) { $validationWeighted += $weighted; $validationMax += $maxWeighted; }
        else { $baseWeighted += $weighted; $baseMax += $maxWeighted; }
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

function axisPercent(array $byKey, array $keys): float {
    $sum = 0; $max = 0;
    foreach ($keys as $key) { $value = (int)($byKey[$key] ?? 0); $sum += $value; $max += 3; }
    if ($max <= 0) return 0.0;
    return round(($sum / $max) * 100, 2);
}

function evaluateTrustAndConsistency(array $byKey): array {
    $trustScore = 100; $flags = []; $notes = []; $penalty = 0; $criticalCount = 0; $moderateCount = 0;

    foreach (studyTrustRules($byKey) as $rule) {
        $shouldApply = isset($rule['when']) && is_callable($rule['when']) ? (bool)$rule['when']($byKey) : false;
        if (!$shouldApply) continue;
        $flags[] = (string)$rule['flag'];
        if (!empty($rule['note'])) $notes[] = (string)$rule['note'];
        $rulePenalty = (int)($rule['penalty'] ?? 0);
        $penalty += $rulePenalty; $trustScore -= $rulePenalty;
        if (!empty($rule['critical'])) $criticalCount++; else $moderateCount++;
    }

    $type = (string)($byKey['case_type'] ?? '');

    if ($type === 'orphan' && ($byKey['orphans'] ?? 0) === 0) { $flags[]='⚠️ تصنيف يتيم بدون وجود أيتام فعليًا في البيانات.'; $notes[]='يلزم التحقق من اختيار الفئة أو من تعريف الحالة.'; $penalty+=15; $trustScore-=15; $criticalCount++; }
    if ($type === 'widow' && ($byKey['widow_children'] ?? 0) === 0 && ($byKey['family_size'] ?? 0) >= 2) { $flags[]='⚠️ أرملة مع غياب معلومات الأبناء رغم وجود أسرة أكبر من فرد واحد.'; $notes[]='ربما البيانات ناقصة أو الفئة المختارة غير دقيقة.'; $penalty+=8; $trustScore-=8; $moderateCount++; }
    if ($type === 'divorced' && ($byKey['divorced_support'] ?? 0) === 0 && ($byKey['monthly_income'] ?? 0) <= 1) { $flags[]='⚠️ مطلقة مع وجود نفقة/دعم كافٍ محتمل ودخل غير منخفض.'; $notes[]='يستحسن مراجعة حقيقة النفقة أو الدعم المقدم.'; $penalty+=8; $trustScore-=8; $moderateCount++; }
    if ($type === 'disabled' && ($byKey['health_condition'] ?? 0) <= 1) { $flags[]='⚠️ ذوو إعاقة دون أثر صحي/وظيفي ظاهر في الإجابات.'; $notes[]='يحتاج توصيف نوع الإعاقة ومدى تأثيرها على المعيشة.'; $penalty+=10; $trustScore-=10; $criticalCount++; }
    if ($type === 'prisoner' && ($byKey['prison_years'] ?? 0) === 0) { $flags[]='⚠️ أسرة سجين دون مدة سجن أو مؤشر واضح على غياب المعيل.'; $notes[]='يلزم توضيح وضع المعيل القانوني ومدة غيابه.'; $penalty+=10; $trustScore-=10; $moderateCount++; }

    if (($byKey['financial_fragility_priority'] ?? 0) >= 3 && ($byKey['food_spending_capacity'] ?? 0) <= 1) { $flags[]='⚠️ تضارب في الهشاشة المالية: الأسرة تقول إنها تقلص الغذاء أولًا مع مؤشرات غذائية أقل حدة.'; $notes[]='قد تكون هناك مبالغة في توصيف أولويات التقليص أو حاجة إلى توضيح أدق.'; $penalty+=7; $trustScore-=7; $moderateCount++; }
    if (($byKey['income_shortfall_effect'] ?? 0) >= 3 && ($byKey['shock_resilience_hidden'] ?? 0) <= 1) { $flags[]='⚠️ تضارب في أثر نقص الدخل: تم وصف أثر شديد مع قدرة صمود أعلى من المتوقع.'; $penalty+=8; $trustScore-=8; $moderateCount++; }
    if (($byKey['education_disruption_due_to_poverty'] ?? 0) >= 2 && ($byKey['children_schooling_status'] ?? 0) === 0) { $flags[]='⚠️ تضارب تعليمي إضافي: ذُكر تأجيل/حرمان من التعليم دون أثر تعليمي ظاهر في باقي الإجابات.'; $penalty+=6; $trustScore-=6; $moderateCount++; }
    if (($byKey['utility_cutoff_risk'] ?? 0) >= 2 && ($byKey['bill_payment_ability'] ?? 0) === 0) { $flags[]='⚠️ تضارب فواتير: خطر انقطاع الخدمات مرتفع رغم وصف قدرة جيدة على السداد.'; $penalty+=6; $trustScore-=6; $moderateCount++; }

    $consistencySignals = 0;
    if (($byKey['monthly_income'] ?? 0) >= 2 && ($byKey['income_stability_hidden'] ?? 0) >= 2) $consistencySignals++;
    if (($byKey['basic_needs'] ?? 0) >= 2 && ($byKey['food_spending_capacity'] ?? 0) >= 2) $consistencySignals++;
    if (($byKey['housing_space'] ?? 0) >= 2 && ($byKey['housing_privacy_hidden'] ?? 0) >= 2) $consistencySignals++;
    if (($byKey['health_condition'] ?? 0) >= 2 && ($byKey['treatment_delay'] ?? 0) >= 1) $consistencySignals++;
    if (($byKey['assets'] ?? 0) >= 2 && ($byKey['asset_liquidity_hidden'] ?? 0) >= 2) $consistencySignals++;
    if (($byKey['financial_fragility_priority'] ?? 0) >= 2 && ($byKey['income_shortfall_effect'] ?? 0) >= 2) $consistencySignals++;

    if ($consistencySignals >= 4) { $trustScore += 6; $notes[]='✅ اتساق مرتفع بين عدة أسئلة مباشرة وتمويهية، ما يعزز موثوقية الحالة.'; }
    elseif ($consistencySignals >= 2) { $trustScore += 3; $notes[]='✅ توجد مؤشرات اتساق جيدة بين المحاور الأساسية.'; }

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

function evaluateHardshipAdjustments(array $byKey): array {
    $bonus = 0; $penalty = 0; $flags = []; $notes = [];
    $rules = studyHardshipRules($byKey);

    foreach ($rules['bonus'] ?? [] as $rule) {
        $shouldApply = isset($rule['when']) && is_callable($rule['when']) ? (bool)$rule['when']($byKey) : false;
        if (!$shouldApply) continue;
        $bonus += (int)($rule['score'] ?? 0);
        if (!empty($rule['flag'])) $flags[] = (string)$rule['flag'];
        if (!empty($rule['note'])) $notes[] = (string)$rule['note'];
    }
    foreach ($rules['penalty'] ?? [] as $rule) {
        $shouldApply = isset($rule['when']) && is_callable($rule['when']) ? (bool)$rule['when']($byKey) : false;
        if (!$shouldApply) continue;
        $penalty += (int)($rule['score'] ?? 0);
        if (!empty($rule['flag'])) $flags[] = (string)$rule['flag'];
        if (!empty($rule['note'])) $notes[] = (string)$rule['note'];
    }

    $type = (string)($byKey['case_type'] ?? '');
    if ($type === 'orphan' && ($byKey['orphans'] ?? 0) >= 2) { $bonus += 3; $flags[]='أولوية فئة: وجود أيتام بعدد مؤثر'; }
    if ($type === 'widow') { $bonus += 2; $flags[]='أولوية فئة: أرملة'; }
    if ($type === 'disabled' || $type === 'sick') { $bonus += 2; $flags[]='أولوية فئة: مرض/إعاقة مؤثرة'; }
    if ($type === 'prisoner') { $bonus += 2; $flags[]='أولوية فئة: غياب المعيل بسبب السجن'; }

    if (($byKey['asset_liquidity_hidden'] ?? 0) === 0) { $penalty += 3; $flags[]='يمكن توفير سيولة سريعة من أصل أو ممتلك قائم'; }
    if (($byKey['external_support'] ?? 0) === 0 && ($byKey['zakat_support'] ?? 0) <= 1) { $penalty += 3; $flags[]='وجود دعم ثابت من جهات أخرى'; }
    if (($byKey['shock_resilience_hidden'] ?? 0) === 0 && ($byKey['basic_needs'] ?? 0) <= 1) { $penalty += 2; $notes[]='قدرة الأسرة على امتصاص الصدمة الشهرية تخفف أولوية الاستعجال.'; }

    if (($byKey['camp_residence'] ?? 0) >= 2) { $bonus += 1; $flags[]='السكن داخل المخيم أو في منطقة مكتظة يزيد الهشاشة السكنية'; }
    if (($byKey['financial_fragility_priority'] ?? 0) >= 2 && ($byKey['income_shortfall_effect'] ?? 0) >= 2) { $bonus += 3; $flags[]='هشاشة مالية مرتفعة تؤثر على أساسيات الأسرة'; }
    if (($byKey['education_disruption_due_to_poverty'] ?? 0) >= 2) { $bonus += 2; $flags[]='الوضع المالي أثر فعليًا على استمرارية التعليم'; }
    if (($byKey['utility_cutoff_risk'] ?? 0) >= 2) { $bonus += 2; $flags[]='خطر مرتفع في استمرار خدمات الكهرباء/الماء/الإنترنت'; }

    return ['bonus_score' => $bonus, 'penalty_score' => $penalty, 'flags' => $flags, 'notes' => $notes];
}

function classifyEligibility(float $povertyPercent, int $trustScore, int $criticalFlagsCount): array {
    if ($trustScore < (int)STUDY_THRESHOLDS['review_trust_threshold'] || $criticalFlagsCount >= (int)STUDY_THRESHOLDS['review_critical_flags_threshold']) {
        return ['category'=>'review','category_label'=>STUDY_CATEGORY_LABELS['review'],'recommendation'=>'الحالة قد تكون محتاجة، لكن موثوقية الإجابات منخفضة وتستلزم تحققًا إضافيًا قبل أي قرار نهائي.','eligibility'=>'بحاجة مراجعة'];
    }
    if ($povertyPercent >= (float)STUDY_THRESHOLDS['high_need_percent'] && $trustScore >= 70) {
        return ['category'=>'high','category_label'=>'احتياج مرتفع جدًا','recommendation'=>'الحالة شديدة الاحتياج وموثوقيتها جيدة؛ توصى بالأولوية العاجلة في الدراسة والدعم.','eligibility'=>'مستحق بشدة'];
    }
    if ($povertyPercent >= (float)STUDY_THRESHOLDS['medium_high_need_percent'] && $trustScore >= 55) {
        return ['category'=>'high','category_label'=>STUDY_CATEGORY_LABELS['high'],'recommendation'=>'الحالة ذات أولوية مرتفعة وتحتاج معالجة سريعة أو دعم مرحلي واضح.','eligibility'=>'مستحق جزئيًا'];
    }
    if ($povertyPercent >= (float)STUDY_THRESHOLDS['medium_need_percent'] && $trustScore >= 45) {
        return ['category'=>'medium','category_label'=>STUDY_CATEGORY_LABELS['medium'],'recommendation'=>'الحالة تستحق دراسة فعلية، وقد تكون مؤهلة لدعم جزئي أو مشروط وفق موارد اللجنة.','eligibility'=>'مستحق جزئيًا'];
    }
    return ['category'=>'low','category_label'=>STUDY_CATEGORY_LABELS['low'],'recommendation'=>'الأولوية أقل من الحالات الأشد، ولا يوصى بالدعم إلا إذا ظهرت وثائق أو ظروف إضافية.','eligibility'=>'غير مستحق'];
}

function calculateResult(array $answers): array {
    $normalizedAnswers = normalizeAnswersInput($answers);
    $byKey = normalizeKeyAlias(mapAnswersByQuestionKey($normalizedAnswers));
    $metrics = calculateWeightedMetrics($normalizedAnswers);
    $hardship = evaluateHardshipAdjustments($byKey);
    $trust = evaluateTrustAndConsistency($byKey);

    $baseScore = (float)$metrics['weighted_total'];
    $smartScoreBeforeTrust = max(0, $baseScore + $hardship['bonus_score'] - $hardship['penalty_score']);
    $finalSmartScore = round($smartScoreBeforeTrust * ($trust['trust_score'] / 100), 2);
    $maxScore = (float)$metrics['max_weighted_total'];
    $povertyPercent = $maxScore > 0 ? round(($finalSmartScore / $maxScore) * 100, 2) : 0.0;

    $classification = classifyEligibility($povertyPercent, $trust['trust_score'], $trust['critical_flags_count']);

    $financialStress = axisPercent($byKey, STUDY_AXIS_KEYS['financial']);
    $housingStress = axisPercent($byKey, STUDY_AXIS_KEYS['housing']);
    $healthStress = axisPercent($byKey, STUDY_AXIS_KEYS['health']);
    $supportWeakness = axisPercent($byKey, STUDY_AXIS_KEYS['support']);
    $familyPressure = axisPercent($byKey, STUDY_AXIS_KEYS['family']);

    $flags = array_values(array_unique(array_merge($hardship['flags'], $trust['flags'])));
    $notes = array_values(array_unique(array_merge($hardship['notes'], $trust['notes'])));

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

function studyCategoryLabel(string $category): string { return STUDY_CATEGORY_LABELS[$category] ?? 'غير محدد'; }
function candidateStatusLabel(string $status): string {
    return match ($status) {
        'new' => 'جديد',
        'sent' => 'تم إنشاء رابط',
        'answered' => 'أجاب على النموذج',
        default => 'غير محدد',
    };
}
function committeeStatusLabel(string $status): string { return STUDY_COMMITTEE_STATUSES[$status] ?? 'غير محدد'; }
function studyBadgeClass(string $type): string { return STUDY_BADGE_CLASSES[$type] ?? 'secondary'; }

function studyBaseUrl(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $https . '://' . $host . '/zaka/study';
}
function buildStudyLink(string $token): string { return studyBaseUrl() . '/form_ai.php?t=' . urlencode($token); }

function validateUsableLinkByToken(string $token): array {
    $link = getLinkByToken($token);
    if (!$link) return ['ok'=>false,'message'=>'الرابط غير موجود','link'=>null,'candidate'=>null];
    if ((int)$link['is_used'] === 1) return ['ok'=>false,'message'=>'تم استخدام هذا الرابط مسبقًا','link'=>$link,'candidate'=>null];
    $candidate = getCandidate((int)$link['candidate_id']);
    if (!$candidate) return ['ok'=>false,'message'=>'بيانات المرشح غير موجودة','link'=>$link,'candidate'=>null];
    return ['ok'=>true,'message'=>'ok','link'=>$link,'candidate'=>$candidate];
}

function parseCandidateLine(string $line): array|false {
    $line = trim($line);
    if ($line === '') return false;
    $patterns = ['/^(.+?)\s*\|\s*(.+)$/u','/^(.+?)\s*-\s*(.+)$/u','/^(.+?)\s*,\s*(.+)$/u'];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $line, $matches)) {
            $name = normalizeName((string)$matches[1]);
            $phone = normalizePhone((string)$matches[2]);
            if ($name === '' || $phone === '') return false;
            return ['full_name'=>$name,'phone'=>$phone];
        }
    }
    return false;
}

function getStudyStats(): array {
    $pdo = db();
    return [
        'candidates'  => (int)$pdo->query("SELECT COUNT(*) FROM study_candidates")->fetchColumn(),
        'links'       => (int)$pdo->query("SELECT COUNT(*) FROM study_links")->fetchColumn(),
        'used_links'  => (int)$pdo->query("SELECT COUNT(*) FROM study_links WHERE is_used = 1")->fetchColumn(),
        'submissions' => (int)$pdo->query("SELECT COUNT(*) FROM study_submissions")->fetchColumn(),
    ];
}
function getStudyCandidates(int $limit = 200): array {
    $limit = max(1, min($limit, 1000));
    $stmt = db()->query("SELECT c.id,c.full_name,c.phone,c.status,c.created_at,(SELECT COUNT(*) FROM study_links l WHERE l.candidate_id = c.id) AS links_count FROM study_candidates c ORDER BY c.id DESC LIMIT {$limit}");
    return $stmt->fetchAll();
}
function getStudyLinks(int $limit = 200): array {
    $limit = max(1, min($limit, 1000));
    $stmt = db()->query("SELECT l.id,l.candidate_id,l.token,l.is_used,l.created_at,l.used_at,c.full_name,c.phone FROM study_links l INNER JOIN study_candidates c ON c.id = l.candidate_id ORDER BY l.id DESC LIMIT {$limit}");
    return $stmt->fetchAll();
}
function getStudySubmissions(int $limit = 200): array {
    $limit = max(1, min($limit, 1000));
    $stmt = db()->query("SELECT s.id,s.candidate_id,s.link_id,s.total_score,s.poverty_percent,s.category,s.committee_status,s.committee_notes,s.submitted_at,c.full_name,c.phone,l.token FROM study_submissions s INNER JOIN study_candidates c ON c.id = s.candidate_id INNER JOIN study_links l ON l.id = s.link_id ORDER BY s.id DESC LIMIT {$limit}");
    return $stmt->fetchAll();
}
