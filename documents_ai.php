<?php
// /zaka/study/documents_ai.php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/functions_ai.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function readSubmissionForDocuments(int $submissionId): array|false
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

function documentsAlreadyUploaded(int $submissionId): array
{
    $stmt = db()->prepare("SELECT doc_key, file_path, original_name FROM study_documents WHERE submission_id = ? ORDER BY id ASC");
    $stmt->execute([$submissionId]);
    return $stmt->fetchAll();
}

function hasUploadedDoc(array $docs, string $key): bool
{
    foreach ($docs as $doc) {
        if ((string)($doc['doc_key'] ?? '') === $key) {
            return true;
        }
    }
    return false;
}

function shouldRequireDocuments(array $submission): bool
{
    if ((int)($submission['documents_required'] ?? 0) === 1) {
        return true;
    }

    $trust = (int)($submission['trust_score'] ?? 0);
    $poverty = (float)($submission['poverty_percent'] ?? 0);
    $committee = (string)($submission['committee_status'] ?? 'pending');

    if ($committee === 'rejected') {
        return false;
    }

    return $trust >= 70 && $poverty >= 50;
}

function ensureDocumentsRequestState(PDO $pdo, array $submission): array
{
    $submissionId = (int)$submission['id'];
    $required = (int)($submission['documents_required'] ?? 0);
    $deadline = $submission['documents_deadline'] ?? null;

    if ($required === 0) {
        $stmt = $pdo->prepare("
            UPDATE study_submissions
            SET documents_required = 1,
                documents_deadline = DATE_ADD(NOW(), INTERVAL 7 DAY)
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$submissionId]);

        $submission['documents_required'] = 1;
        $submission['documents_deadline'] = date('Y-m-d H:i:s', strtotime('+7 days'));
    } elseif ($deadline === null || trim((string)$deadline) === '') {
        $stmt = $pdo->prepare("
            UPDATE study_submissions
            SET documents_deadline = DATE_ADD(NOW(), INTERVAL 7 DAY)
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$submissionId]);

        $submission['documents_deadline'] = date('Y-m-d H:i:s', strtotime('+7 days'));
    }

    return $submission;
}

function saveUploadedDocument(PDO $pdo, int $submissionId, string $docKey, array $file, string $uploadDirAbs, string $uploadDirRel): void
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('فشل رفع الملف: ' . $docKey);
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    $originalName = (string)($file['name'] ?? '');

    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('الملف المرفوع غير صالح: ' . $docKey);
    }

    $mime = mime_content_type($tmp) ?: '';
    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($allowedMimes[$mime])) {
        throw new RuntimeException('صيغة الملف غير مدعومة. المسموح: JPG / PNG / WEBP فقط.');
    }

    if (!is_dir($uploadDirAbs) && !mkdir($uploadDirAbs, 0775, true) && !is_dir($uploadDirAbs)) {
        throw new RuntimeException('تعذر إنشاء مجلد الحفظ.');
    }

    $extension = $allowedMimes[$mime];
    $safeName = 'submission_' . $submissionId . '_' . $docKey . '_' . bin2hex(random_bytes(6)) . '.' . $extension;

    $destinationAbs = rtrim($uploadDirAbs, '/\\') . DIRECTORY_SEPARATOR . $safeName;
    $destinationRel = rtrim($uploadDirRel, '/\\') . '/' . $safeName;

    $stmtOld = $pdo->prepare("SELECT id, file_path FROM study_documents WHERE submission_id = ? AND doc_key = ? LIMIT 1");
    $stmtOld->execute([$submissionId, $docKey]);
    $old = $stmtOld->fetch();

    if (!move_uploaded_file($tmp, $destinationAbs)) {
        throw new RuntimeException('تعذر حفظ الملف على السيرفر.');
    }

    if ($old) {
        $stmtUpdate = $pdo->prepare("
            UPDATE study_documents
            SET original_name = ?, file_path = ?, uploaded_at = NOW()
            WHERE id = ?
            LIMIT 1
        ");
        $stmtUpdate->execute([$originalName, $destinationRel, (int)$old['id']]);

        $oldPath = __DIR__ . '/' . ltrim((string)$old['file_path'], '/');
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    } else {
        $stmtInsert = $pdo->prepare("
            INSERT INTO study_documents (submission_id, doc_key, original_name, file_path, uploaded_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmtInsert->execute([$submissionId, $docKey, $originalName, $destinationRel]);
    }
}

$submissionId = max(0, (int)($_GET['id'] ?? $_POST['submission_id'] ?? 0));
$errors = [];
$success = '';

$submission = $submissionId > 0 ? readSubmissionForDocuments($submissionId) : false;

if (!$submission) {
    http_response_code(404);
}

if ($submission) {
    $pdo = db();

    if (!shouldRequireDocuments($submission)) {
        $errors[] = 'هذه الحالة غير مؤهلة حاليًا لطلب الوثائق.';
    } else {
        $submission = ensureDocumentsRequestState($pdo, $submission);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors) {
        $uploadDirRel = 'uploads/documents';
        $uploadDirAbs = __DIR__ . '/uploads/documents';

        try {
            $pdo->beginTransaction();

            $requiredKeys = ['doc_1', 'doc_2', 'doc_3'];

            foreach ($requiredKeys as $docKey) {
                if (isset($_FILES[$docKey]) && is_array($_FILES[$docKey]) && ($_FILES[$docKey]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    saveUploadedDocument($pdo, (int)$submission['id'], $docKey, $_FILES[$docKey], $uploadDirAbs, $uploadDirRel);
                }
            }

            $uploadedDocs = documentsAlreadyUploaded((int)$submission['id']);
            $allDone = hasUploadedDoc($uploadedDocs, 'doc_1')
                && hasUploadedDoc($uploadedDocs, 'doc_2')
                && hasUploadedDoc($uploadedDocs, 'doc_3');

            if ($allDone) {
                $stmtDone = $pdo->prepare("
                    UPDATE study_submissions
                    SET documents_completed = 1
                    WHERE id = ?
                    LIMIT 1
                ");
                $stmtDone->execute([(int)$submission['id']]);
            }

            $pdo->commit();

            $submission = readSubmissionForDocuments((int)$submission['id']);
            $success = $allDone
                ? 'تم رفع جميع الوثائق المطلوبة بنجاح.'
                : 'تم حفظ ما تم رفعه بنجاح، ويمكنك استكمال باقي الوثائق قبل انتهاء المهلة.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'تعذر رفع الوثائق: ' . $e->getMessage();
        }
    }

    $uploadedDocs = documentsAlreadyUploaded((int)$submission['id']);
    $deadline = trim((string)($submission['documents_deadline'] ?? ''));
    $documentsCompleted = (int)($submission['documents_completed'] ?? 0) === 1;
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>إرفاق الوثائق</title>
    <style>
        body{font-family:Tahoma,Arial,sans-serif;background:#f5f7fb;color:#1f2937;margin:0;padding:0}
        .wrap{max-width:850px;margin:30px auto;padding:20px}
        .card{background:#fff;border-radius:16px;box-shadow:0 8px 30px rgba(0,0,0,.08);padding:22px;margin-bottom:18px}
        .hero{background:linear-gradient(135deg,#0f172a,#1d4ed8);color:#fff}
        .muted{color:#6b7280;font-size:14px}
        .hero .muted{color:rgba(255,255,255,.82)}
        .alert{padding:14px 16px;border-radius:12px;margin-bottom:16px}
        .alert-danger{background:#fee2e2;color:#991b1b}
        .alert-success{background:#dcfce7;color:#166534}
        .doc-box{border:1px solid #e5e7eb;border-radius:14px;padding:16px;margin-bottom:14px;background:#fafafa}
        .doc-title{font-weight:700;margin-bottom:10px}
        .status-ok{display:inline-block;padding:4px 10px;border-radius:999px;background:#dcfce7;color:#166534;font-size:12px;font-weight:700}
        .status-pending{display:inline-block;padding:4px 10px;border-radius:999px;background:#fef3c7;color:#92400e;font-size:12px;font-weight:700}
        .field-file{display:block;width:100%;padding:10px;border:1px solid #d1d5db;border-radius:12px;background:#fff}
        .btn{border:none;background:#2563eb;color:#fff;padding:12px 18px;border-radius:12px;cursor:pointer;font-family:inherit}
        .btn:hover{opacity:.93}
        .deadline{font-weight:700;color:#b45309}
    </style>
</head>
<body>
<div class="wrap">

    <?php if (!$submission): ?>
        <div class="card">
            <div class="alert alert-danger">الطلب غير موجود.</div>
        </div>
    <?php else: ?>
        <div class="card hero">
            <h1>إرفاق الوثائق الداعمة</h1>
            <p class="muted">
                الاسم: <strong><?= e((string)$submission['full_name']) ?></strong>
                — الهاتف: <strong><?= e((string)$submission['phone']) ?></strong>
            </p>
            <p class="muted">
                تم قبول طلبكم مبدئيًا، ونرجو تزويد اللجنة بالوثائق المطلوبة خلال مدة أقصاها
                <span class="deadline"><?= e($deadline !== '' ? $deadline : '-') ?></span>
            </p>
        </div>

        <?php foreach ($errors as $error): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endforeach; ?>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success"><?= e($success) ?></div>
        <?php endif; ?>

        <?php if (!$errors): ?>
            <div class="card">
                <h2>الوثائق المطلوبة</h2>
                <p class="muted">يمكنك استخدام كاميرا الهاتف مباشرة لتصوير الوثائق ورفعها.</p>

                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="submission_id" value="<?= (int)$submission['id'] ?>">

                    <div class="doc-box">
                        <div class="doc-title">
                            وثيقة 1: هوية شخصية / دفتر عائلة
                            <?php if (hasUploadedDoc($uploadedDocs, 'doc_1')): ?>
                                <span class="status-ok">تم الرفع</span>
                            <?php else: ?>
                                <span class="status-pending">بانتظار الرفع</span>
                            <?php endif; ?>
                        </div>
                        <input class="field-file" type="file" name="doc_1" accept="image/*" capture="environment">
                    </div>

                    <div class="doc-box">
                        <div class="doc-title">
                            وثيقة 2: إثبات دخل / عدم عمل / راتب
                            <?php if (hasUploadedDoc($uploadedDocs, 'doc_2')): ?>
                                <span class="status-ok">تم الرفع</span>
                            <?php else: ?>
                                <span class="status-pending">بانتظار الرفع</span>
                            <?php endif; ?>
                        </div>
                        <input class="field-file" type="file" name="doc_2" accept="image/*" capture="environment">
                    </div>

                    <div class="doc-box">
                        <div class="doc-title">
                            وثيقة 3: وثيقة داعمة حسب الحالة
                            <?php if (hasUploadedDoc($uploadedDocs, 'doc_3')): ?>
                                <span class="status-ok">تم الرفع</span>
                            <?php else: ?>
                                <span class="status-pending">بانتظار الرفع</span>
                            <?php endif; ?>
                        </div>
                        <input class="field-file" type="file" name="doc_3" accept="image/*" capture="environment">
                    </div>

                    <button class="btn" type="submit">حفظ الوثائق</button>
                </form>
            </div>

            <div class="card">
                <h2>حالة الاستكمال</h2>
                <p class="muted">
                    <?php if ($documentsCompleted): ?>
                        تم استكمال جميع الوثائق المطلوبة بنجاح.
                    <?php else: ?>
                        ما زال الطلب بحاجة إلى استكمال الوثائق قبل انتهاء المهلة.
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>