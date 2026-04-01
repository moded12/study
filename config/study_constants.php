<?php
declare(strict_types=1);

/**
 * ثوابت نظام دراسة الحالة
 * هذا الملف مخصص فقط للثوابت العامة التي لا تتغير كثيراً.
 */

const STUDY_CASE_TYPES = [
    'orphan'     => 'يتيم',
    'widow'      => 'أرملة',
    'divorced'   => 'مطلقة',
    'unemployed' => 'عاطل',
    'sick'       => 'مريض',
    'poor'       => 'فقير',
    'elderly'    => 'مسن',
    'prisoner'   => 'أسرة سجين',
    'disabled'   => 'ذوو إعاقة',
];

const STUDY_COMMITTEE_STATUSES = [
    'pending'  => 'بانتظار المراجعة',
    'review'   => 'قيد الدراسة',
    'approved' => 'قبول',
    'rejected' => 'رفض',
];

const STUDY_CATEGORY_LABELS = [
    'low'    => 'احتياج منخفض',
    'medium' => 'احتياج متوسط',
    'high'   => 'احتياج مرتفع',
    'review' => 'بحاجة مراجعة دقيقة',
];

const STUDY_BADGE_CLASSES = [
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
];

const STUDY_THRESHOLDS = [
    'validation_force_score' => 20,
    'validation_random_audit_percent' => 15,
    'review_trust_threshold' => 35,
    'review_critical_flags_threshold' => 3,
    'high_need_percent' => 78,
    'medium_high_need_percent' => 58,
    'medium_need_percent' => 38,
];

const STUDY_AXIS_KEYS = [
    'financial' => [
        'monthly_income',
        'debts',
        'food_spending_capacity',
        'bill_payment_ability',
        'borrowing_frequency',
        'income_stability_hidden',
        'expense_pressure_hidden',
    ],
    'housing' => [
        'housing_status',
        'housing_space',
        'housing_privacy_hidden',
    ],
    'health' => [
        'health_condition',
        'treatment_delay',
    ],
    'support' => [
        'external_support',
        'zakat_support',
        'support_network_strength',
        'shock_resilience_hidden',
    ],
    'family' => [
        'family_size',
        'education_burden',
        'children_schooling_status',
        'seasonal_hardship',
    ],
];

const STUDY_KEY_ALIASES = [
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
    'camp_residence' => ['camp_residence'],
    'financial_fragility_priority' => ['financial_fragility_priority'],
    'income_shortfall_effect' => ['income_shortfall_effect'],
    'education_disruption_due_to_poverty' => ['education_disruption_due_to_poverty'],
    'utility_cutoff_risk' => ['utility_cutoff_risk'],
    'has_sanad_app' => ['has_sanad_app'],
];

const STUDY_NUMERIC_BANDS = [
    'monthly_income' => [
        'thresholds' => [350, 250, 150],
        'reverse' => true,
    ],
    'housing_space' => [
        'thresholds' => [4, 3, 2],
        'reverse' => false,
    ],
    'bill_payment_ability' => [
        'thresholds' => [60, 120, 220],
        'reverse' => false,
    ],
    'expense_pressure_hidden' => [
        'thresholds' => [0, 60, 180],
        'reverse' => false,
    ],
    'medical_cost' => [
        'thresholds' => [0, 40, 120],
        'reverse' => false,
    ],
    'debts' => [
        'thresholds' => [0, 300, 1000],
        'reverse' => false,
    ],
    'students' => [
        'thresholds' => [0, 1, 2],
        'reverse' => false,
    ],
];
