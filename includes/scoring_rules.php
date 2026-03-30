<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/study_constants.php';

/**
 * هذا الملف يجمع قواعد التقييم المتغيرة.
 * عند تعديل منطق التقييم مستقبلاً غالباً سيكون التعديل هنا.
 */

function studyTrustRules(array $byKey): array
{
    $rules = [];

    $rules[] = [
        'when' => static fn(array $x): bool =>
            ($x['monthly_income'] ?? 0) <= 1 &&
            (
                ($x['basic_needs'] ?? 0) >= 3 ||
                ($x['food_spending_capacity'] ?? 0) >= 3 ||
                ($x['expense_pressure_hidden'] ?? 0) >= 3
            ),
        'flag' => '⚠️ تناقض مالي: دخل معلن مرتفع/متوسط نسبيًا مع عجز معيشي شديد جدًا.',
        'penalty' => 14,
        'note' => 'يحتاج إلى تحقق ميداني أو مستندات دخل/التزامات.',
        'critical' => true,
    ];

    $rules[] = [
        'when' => static fn(array $x): bool =>
            ($x['monthly_income'] ?? 0) >= 3 &&
            ($x['debts'] ?? 0) === 0 &&
            ($x['borrowing_frequency'] ?? 0) === 0 &&
            ($x['expense_pressure_hidden'] ?? 0) <= 1 &&
            ($x['food_spending_capacity'] ?? 0) <= 1,
        'flag' => '⚠️ سلوك غير منطقي: دخل شبه معدوم دون ديون أو استدانة أو ضغط مالي ظاهر.',
        'penalty' => 16,
        'note' => 'غالبًا يوجد مصدر دعم غير مصرح به أو عدم دقة في وصف الواقع.',
        'critical' => true,
    ];

    $rules[] = [
        'when' => static fn(array $x): bool =>
            ($x['assets'] ?? 0) === 0 &&
            (($x['asset_liquidity_hidden'] ?? 0) <= 1 || ($x['expense_pressure_hidden'] ?? 0) >= 2) &&
            ($x['urgency'] ?? 0) >= 2,
        'flag' => '⚠️ تضارب أصول: تم الإقرار بوجود أصول مؤثرة مع وصف حاجة شديدة.',
        'penalty' => 14,
        'note' => 'يلزم التحقق من نوع الأصل وقابليته للبيع أو الاستفادة منه.',
        'critical' => true,
    ];

    $rules[] = [
        'when' => static fn(array $x): bool =>
            ($x['has_sanad_app'] ?? 0) >= 2 &&
            ($x['monthly_income'] ?? 0) >= 2,
        'flag' => '⚠️ لا يملك تطبيق سند رغم وجود دخل قابل للتحقق.',
        'penalty' => 6,
        'note' => 'قد يضعف ذلك إمكانية التحقق السريع من المعلومات.',
        'critical' => false,
    ];

    return $rules;
}

function studyHardshipRules(array $byKey): array
{
    $bonus = [];
    $penalty = [];

    $bonus[] = [
        'when' => static fn(array $x): bool =>
            ($x['legal_status'] ?? 0) === 3,
        'score' => 6,
        'flag' => 'أولوية خاصة: من أبناء غزة بدون رقم وطني',
        'note' => 'الحالة القانونية تحد من الوصول إلى برامج الدعم الرسمية.',
    ];

    $bonus[] = [
        'when' => static fn(array $x): bool =>
            ($x['monthly_income'] ?? 0) >= 3 &&
            ($x['external_support'] ?? 0) >= 3 &&
            ($x['basic_needs'] ?? 0) >= 2,
        'score' => 5,
        'flag' => 'احتياج شديد: لا دخل ولا دعم مع عجز في الاحتياجات الأساسية',
    ];

    $bonus[] = [
        'when' => static fn(array $x): bool =>
            ($x['financial_fragility_priority'] ?? 0) >= 2 &&
            ($x['income_shortfall_effect'] ?? 0) >= 2,
        'score' => 3,
        'flag' => 'هشاشة مالية مرتفعة تؤثر على أساسيات الأسرة',
    ];

    $bonus[] = [
        'when' => static fn(array $x): bool =>
            ($x['education_disruption_due_to_poverty'] ?? 0) >= 2,
        'score' => 2,
        'flag' => 'الوضع المالي أثر فعليًا على استمرارية التعليم',
    ];

    $penalty[] = [
        'when' => static fn(array $x): bool =>
            ($x['assets'] ?? 0) === 0,
        'score' => 6,
        'flag' => 'وجود أصول أو ممتلكات مؤثرة',
        'note' => 'وجود أصول يستدعي مراجعة اللجنة للتأكد من حجم الاستحقاق الفعلي.',
    ];

    $penalty[] = [
        'when' => static fn(array $x): bool =>
            ($x['inheritance_or_salary'] ?? 0) === 0,
        'score' => 5,
        'flag' => 'وجود دخل شبه ثابت أو راتب ورثة/ضمان مؤثر',
    ];

    return [
        'bonus' => $bonus,
        'penalty' => $penalty,
    ];
}
