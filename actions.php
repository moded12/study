<?php
// /zaka/study/actions.php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/admin/bootstrap.php';
require_once __DIR__ . '/includes/functions_ai.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flashError('طلب غير صالح.');
    redirect('admin.php');
}

verifyCsrf();

$action = trim((string)($_GET['action'] ?? ''));
$pdo = db();

if ($action === '') {
    flashError('الإجراء غير محدد.');
    redirect('admin.php');
}

function responseViewByCommitteeStatusLocal(string $status): string
{
    return match ($status) {
        'approved' => 'approved',
        'rejected' => 'rejected',
        'reserve'  => 'reserve',
        'waiting', 'review', 'pending' => 'waiting',
        default => 'all',
    };
}

function redirectResponsesByCommitteeStatusLocal(string $status): void
{
    $view = responseViewByCommitteeStatusLocal($status);
    redirect('admin.php?tab=responses&response_view=' . urlencode($view));
}

switch ($action) {
    case 'import_candidates':
        $bulk = trim((string)($_POST['bulk_candidates'] ?? ''));

        if ($bulk === '') {
            flashError('الرجاء إدخال بيانات المرشحين.');
            redirect('admin.php?tab=import');
        }

        $lines = preg_split('/\r\n|\r|\n/u', $bulk) ?: [];
        $inserted = 0;
        $duplicates = 0;
        $invalid = 0;

        $stmtCheck = $pdo->prepare("SELECT id FROM study_candidates WHERE phone = ? LIMIT 1");
        $stmtInsert = $pdo->prepare("
            INSERT INTO study_candidates (full_name, phone, status, created_at)
            VALUES (?, ?, 'new', NOW())
        ");

        try {
            $pdo->beginTransaction();

            foreach ($lines as $line) {
                $line = trim((string)$line);
                if ($line === '') {
                    continue;
                }

                $parsed = parseCandidateLine($line);
                if ($parsed === false) {
                    $invalid++;
                    continue;
                }

                $stmtCheck->execute([$parsed['phone']]);
                if ($stmtCheck->fetch()) {
                    $duplicates++;
                    continue;
                }

                $stmtInsert->execute([$parsed['full_name'], $parsed['phone']]);
                $inserted++;
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flashError('حدث خطأ أثناء الاستيراد: ' . $e->getMessage());
            redirect('admin.php?tab=import');
        }

        flashSuccess("تمت الإضافة: {$inserted} | مكرر: {$duplicates} | غير صالح: {$invalid}");
        redirect('admin.php?tab=candidates');
        break;

    case 'import_candidates_file':
        if (!isset($_FILES['txt_file']) || !is_array($_FILES['txt_file'])) {
            flashError('لم يتم رفع الملف.');
            redirect('admin.php?tab=import');
        }

        $file = $_FILES['txt_file'];

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flashError('حدث خطأ أثناء رفع الملف.');
            redirect('admin.php?tab=import');
        }

        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'txt') {
            flashError('يُسمح فقط بملفات txt.');
            redirect('admin.php?tab=import');
        }

        $content = file_get_contents((string)$file['tmp_name']);
        if ($content === false) {
            flashError('تعذر قراءة الملف.');
            redirect('admin.php?tab=import');
        }

        $lines = preg_split('/\r\n|\r|\n/u', $content) ?: [];
        $inserted = 0;
        $duplicates = 0;
        $invalid = 0;

        $stmtCheck = $pdo->prepare("SELECT id FROM study_candidates WHERE phone = ? LIMIT 1");
        $stmtInsert = $pdo->prepare("
            INSERT INTO study_candidates (full_name, phone, status, created_at)
            VALUES (?, ?, 'new', NOW())
        ");

        try {
            $pdo->beginTransaction();

            foreach ($lines as $line) {
                $line = trim((string)$line);
                if ($line === '') {
                    continue;
                }

                $parsed = parseCandidateLine($line);
                if ($parsed === false) {
                    $invalid++;
                    continue;
                }

                $stmtCheck->execute([$parsed['phone']]);
                if ($stmtCheck->fetch()) {
                    $duplicates++;
                    continue;
                }

                $stmtInsert->execute([$parsed['full_name'], $parsed['phone']]);
                $inserted++;
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flashError('حدث خطأ أثناء استيراد الملف: ' . $e->getMessage());
            redirect('admin.php?tab=import');
        }

        flashSuccess("تم استيراد الملف. المضاف: {$inserted} | مكرر: {$duplicates} | غير صالح: {$invalid}");
        redirect('admin.php?tab=candidates');
        break;

    case 'add_candidate':
        $fullName = normalizeName((string)($_POST['full_name'] ?? ''));
        $phone = normalizePhone((string)($_POST['phone'] ?? ''));

        if ($fullName === '' || $phone === '') {
            flashError('الاسم والهاتف مطلوبان.');
            redirect('admin.php?tab=candidates');
        }

        $stmtCheck = $pdo->prepare("SELECT id FROM study_candidates WHERE phone = ? LIMIT 1");
        $stmtCheck->execute([$phone]);
        if ($stmtCheck->fetch()) {
            flashError('رقم الهاتف موجود مسبقاً.');
            redirect('admin.php?tab=candidates');
        }

        $stmtInsert = $pdo->prepare("
            INSERT INTO study_candidates (full_name, phone, status, created_at)
            VALUES (?, ?, 'new', NOW())
        ");

        try {
            $stmtInsert->execute([$fullName, $phone]);
        } catch (Throwable $e) {
            flashError('حدث خطأ أثناء إضافة المرشح: ' . $e->getMessage());
            redirect('admin.php?tab=candidates');
        }

        flashSuccess('تمت إضافة المرشح بنجاح.');
        redirect('admin.php?tab=candidates');
        break;

    case 'update_candidate':
        $candidateId = (int)($_POST['candidate_id'] ?? 0);
        $fullName = normalizeName((string)($_POST['full_name'] ?? ''));
        $phone = normalizePhone((string)($_POST['phone'] ?? ''));

        if ($candidateId <= 0 || $fullName === '' || $phone === '') {
            flashError('بيانات التعديل غير صالحة.');
            redirect('admin.php?tab=candidates');
        }

        $stmtCheck = $pdo->prepare("
            SELECT id
            FROM study_candidates
            WHERE phone = ? AND id <> ?
            LIMIT 1
        ");
        $stmtCheck->execute([$phone, $candidateId]);
        if ($stmtCheck->fetch()) {
            flashError('رقم الهاتف مستخدم لمرشح آخر.');
            redirect('admin.php?tab=candidates');
        }

        $stmtUpdate = $pdo->prepare("
            UPDATE study_candidates
            SET full_name = ?, phone = ?
            WHERE id = ?
            LIMIT 1
        ");

        try {
            $stmtUpdate->execute([$fullName, $phone, $candidateId]);
        } catch (Throwable $e) {
            flashError('حدث خطأ أثناء تعديل المرشح: ' . $e->getMessage());
            redirect('admin.php?tab=candidates');
        }

        flashSuccess('تم تعديل المرشح بنجاح.');
        redirect('admin.php?tab=candidates');
        break;

    case 'delete_candidate':
        $candidateId = (int)($_POST['candidate_id'] ?? 0);

        if ($candidateId <= 0) {
            flashError('رقم المرشح غير صالح.');
            redirect('admin.php?tab=candidates');
        }

        try {
            $pdo->beginTransaction();

            $stmtSubIds = $pdo->prepare("SELECT id FROM study_submissions WHERE candidate_id = ?");
            $stmtSubIds->execute([$candidateId]);
            $submissionIds = $stmtSubIds->fetchAll(PDO::FETCH_COLUMN);

            if ($submissionIds) {
                $placeholders = implode(',', array_fill(0, count($submissionIds), '?'));

                $stmtDeleteAnswers = $pdo->prepare("DELETE FROM study_answers WHERE submission_id IN ($placeholders)");
                $stmtDeleteAnswers->execute(array_map('intval', $submissionIds));

                $stmtDeleteSubs = $pdo->prepare("DELETE FROM study_submissions WHERE id IN ($placeholders)");
                $stmtDeleteSubs->execute(array_map('intval', $submissionIds));
            }

            $stmtDeleteLinks = $pdo->prepare("DELETE FROM study_links WHERE candidate_id = ?");
            $stmtDeleteLinks->execute([$candidateId]);

            $stmtDeleteCandidate = $pdo->prepare("DELETE FROM study_candidates WHERE id = ? LIMIT 1");
            $stmtDeleteCandidate->execute([$candidateId]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flashError('حدث خطأ أثناء حذف المرشح: ' . $e->getMessage());
            redirect('admin.php?tab=candidates');
        }

        flashSuccess('تم حذف المرشح وكل البيانات المرتبطة به.');
        redirect('admin.php?tab=candidates');
        break;

    case 'delete_candidates_selected':
        $candidateIds = $_POST['candidate_ids'] ?? [];

        if (!is_array($candidateIds) || !$candidateIds) {
            flashError('الرجاء تحديد مرشح واحد على الأقل للحذف.');
            redirect('admin.php?tab=candidates');
        }

        $candidateIds = array_values(array_unique(array_map('intval', $candidateIds)));
        $candidateIds = array_filter($candidateIds, fn($id) => $id > 0);

        if (!$candidateIds) {
            flashError('القائمة المحددة غير صالحة.');
            redirect('admin.php?tab=candidates');
        }

        try {
            $pdo->beginTransaction();

            $placeholders = implode(',', array_fill(0, count($candidateIds), '?'));

            $stmtSubIds = $pdo->prepare("SELECT id FROM study_submissions WHERE candidate_id IN ($placeholders)");
            $stmtSubIds->execute($candidateIds);
            $submissionIds = $stmtSubIds->fetchAll(PDO::FETCH_COLUMN);

            if ($submissionIds) {
                $subPlaceholders = implode(',', array_fill(0, count($submissionIds), '?'));

                $stmtDeleteAnswers = $pdo->prepare("DELETE FROM study_answers WHERE submission_id IN ($subPlaceholders)");
                $stmtDeleteAnswers->execute(array_map('intval', $submissionIds));

                $stmtDeleteSubs = $pdo->prepare("DELETE FROM study_submissions WHERE id IN ($subPlaceholders)");
                $stmtDeleteSubs->execute(array_map('intval', $submissionIds));
            }

            $stmtDeleteLinks = $pdo->prepare("DELETE FROM study_links WHERE candidate_id IN ($placeholders)");
            $stmtDeleteLinks->execute($candidateIds);

            $stmtDeleteCandidates = $pdo->prepare("DELETE FROM study_candidates WHERE id IN ($placeholders)");
            $stmtDeleteCandidates->execute($candidateIds);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flashError('حدث خطأ أثناء حذف المحددين: ' . $e->getMessage());
            redirect('admin.php?tab=candidates');
        }

        flashSuccess('تم حذف المرشحين المحددين وما يرتبط بهم.');
        redirect('admin.php?tab=candidates');
        break;

    case 'generate_links':
        $stmtCandidates = $pdo->query("
            SELECT c.id
            FROM study_candidates c
            WHERE NOT EXISTS (
                SELECT 1 FROM study_links l WHERE l.candidate_id = c.id
            )
            ORDER BY c.id ASC
        ");
        $candidates = $stmtCandidates->fetchAll();

        if (!$candidates) {
            flashInfo('لا يوجد مرشحون بحاجة إلى توليد روابط.');
            redirect('admin.php?tab=candidates');
        }

        $stmtInsert = $pdo->prepare("
            INSERT INTO study_links (candidate_id, token, is_used, created_at)
            VALUES (?, ?, 0, NOW())
        ");

        $stmtUpdateCandidate = $pdo->prepare("
            UPDATE study_candidates
            SET status = 'sent'
            WHERE id = ? AND status = 'new'
            LIMIT 1
        ");

        $generated = 0;

        try {
            $pdo->beginTransaction();

            foreach ($candidates as $row) {
                $candidateId = (int)$row['id'];
                $token = generateUniqueStudyToken();

                $stmtInsert->execute([$candidateId, $token]);
                $stmtUpdateCandidate->execute([$candidateId]);
                $generated++;
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flashError('حدث خطأ أثناء توليد الروابط: ' . $e->getMessage());
            redirect('admin.php?tab=candidates');
        }

        flashSuccess("تم توليد {$generated} رابط بنجاح.");
        redirect('admin.php?tab=links');
        break;

    case 'generate_links_selected':
        $candidateIds = $_POST['candidate_ids'] ?? [];

        if (!is_array($candidateIds) || !$candidateIds) {
            flashError('الرجاء تحديد مرشح واحد على الأقل.');
            redirect('admin.php?tab=candidates');
        }

        $candidateIds = array_values(array_unique(array_map('intval', $candidateIds)));
        $candidateIds = array_filter($candidateIds, fn($id) => $id > 0);

        if (!$candidateIds) {
            flashError('القائمة المحددة غير صالحة.');
            redirect('admin.php?tab=candidates');
        }

        $placeholders = implode(',', array_fill(0, count($candidateIds), '?'));

        $stmtSelected = $pdo->prepare("
            SELECT id
            FROM study_candidates
            WHERE id IN ($placeholders)
            ORDER BY id ASC
        ");
        $stmtSelected->execute($candidateIds);
        $selectedCandidates = $stmtSelected->fetchAll();

        if (!$selectedCandidates) {
            flashError('لم يتم العثور على المرشحين المحددين.');
            redirect('admin.php?tab=candidates');
        }

        $stmtCheckLink = $pdo->prepare("SELECT id FROM study_links WHERE candidate_id = ? LIMIT 1");
        $stmtInsert = $pdo->prepare("
            INSERT INTO study_links (candidate_id, token, is_used, created_at)
            VALUES (?, ?, 0, NOW())
        ");
        $stmtUpdateCandidate = $pdo->prepare("
            UPDATE study_candidates
            SET status = 'sent'
            WHERE id = ? AND status = 'new'
            LIMIT 1
        ");

        $generated = 0;
        $alreadyHasLink = 0;

        try {
            $pdo->beginTransaction();

            foreach ($selectedCandidates as $candidate) {
                $candidateId = (int)$candidate['id'];

                $stmtCheckLink->execute([$candidateId]);
                if ($stmtCheckLink->fetch()) {
                    $alreadyHasLink++;
                    continue;
                }

                $token = generateUniqueStudyToken();
                $stmtInsert->execute([$candidateId, $token]);
                $stmtUpdateCandidate->execute([$candidateId]);
                $generated++;
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flashError('حدث خطأ أثناء توليد روابط المحددين: ' . $e->getMessage());
            redirect('admin.php?tab=candidates');
        }

        $msg = "تم توليد {$generated} رابط.";
        if ($alreadyHasLink > 0) {
            $msg .= " | لديهم روابط مسبقاً: {$alreadyHasLink}";
        }

        flashSuccess($msg);
        redirect('admin.php?tab=links');
        break;

    case 'generate_single_link':
        $candidateId = (int)($_POST['candidate_id'] ?? 0);

        if ($candidateId <= 0) {
            flashError('رقم المرشح غير صالح.');
            redirect('admin.php?tab=candidates');
        }

        $stmtCheckLink = $pdo->prepare("SELECT id FROM study_links WHERE candidate_id = ? LIMIT 1");
        $stmtCheckLink->execute([$candidateId]);
        if ($stmtCheckLink->fetch()) {
            flashInfo('هذا المرشح يملك رابطاً بالفعل.');
            redirect('admin.php?tab=links');
        }

        $stmtInsert = $pdo->prepare("
            INSERT INTO study_links (candidate_id, token, is_used, created_at)
            VALUES (?, ?, 0, NOW())
        ");
        $stmtUpdateCandidate = $pdo->prepare("
            UPDATE study_candidates
            SET status = 'sent'
            WHERE id = ? AND status = 'new'
            LIMIT 1
        ");

        try {
            $token = generateUniqueStudyToken();
            $stmtInsert->execute([$candidateId, $token]);
            $stmtUpdateCandidate->execute([$candidateId]);
        } catch (Throwable $e) {
            flashError('حدث خطأ أثناء توليد الرابط: ' . $e->getMessage());
            redirect('admin.php?tab=candidates');
        }

        flashSuccess('تم توليد الرابط بنجاح.');
        redirect('admin.php?tab=links');
        break;

    case 'regenerate_link':
        $linkId = (int)($_POST['link_id'] ?? 0);

        if ($linkId <= 0) {
            flashError('رقم الرابط غير صالح.');
            redirect('admin.php?tab=links');
        }

        $stmtGet = $pdo->prepare("SELECT id, candidate_id FROM study_links WHERE id = ? LIMIT 1");
        $stmtGet->execute([$linkId]);
        $link = $stmtGet->fetch();

        if (!$link) {
            flashError('الرابط غير موجود.');
            redirect('admin.php?tab=links');
        }

        $newToken = generateUniqueStudyToken();

        $stmtUpdate = $pdo->prepare("
            UPDATE study_links
            SET token = ?, is_used = 0, used_at = NULL
            WHERE id = ?
            LIMIT 1
        ");

        try {
            $stmtUpdate->execute([$newToken, $linkId]);

            $stmtResetCandidate = $pdo->prepare("
                UPDATE study_candidates
                SET status = 'sent'
                WHERE id = ?
                LIMIT 1
            ");
            $stmtResetCandidate->execute([(int)$link['candidate_id']]);
        } catch (Throwable $e) {
            flashError('حدث خطأ أثناء إعادة توليد الرابط: ' . $e->getMessage());
            redirect('admin.php?tab=links');
        }

        flashSuccess('تم إعادة توليد الرابط بنجاح.');
        redirect('admin.php?tab=links');
        break;

    case 'delete_link':
        $linkId = (int)($_POST['link_id'] ?? 0);

        if ($linkId <= 0) {
            flashError('رقم الرابط غير صالح.');
            redirect('admin.php?tab=links');
        }

        $stmtGet = $pdo->prepare("SELECT id, candidate_id FROM study_links WHERE id = ? LIMIT 1");
        $stmtGet->execute([$linkId]);
        $link = $stmtGet->fetch();

        if (!$link) {
            flashError('الرابط غير موجود.');
            redirect('admin.php?tab=links');
        }

        try {
            $pdo->beginTransaction();

            $stmtSubIds = $pdo->prepare("SELECT id FROM study_submissions WHERE link_id = ?");
            $stmtSubIds->execute([$linkId]);
            $submissionIds = $stmtSubIds->fetchAll(PDO::FETCH_COLUMN);

            if ($submissionIds) {
                $subPlaceholders = implode(',', array_fill(0, count($submissionIds), '?'));

                $stmtDeleteAnswers = $pdo->prepare("DELETE FROM study_answers WHERE submission_id IN ($subPlaceholders)");
                $stmtDeleteAnswers->execute(array_map('intval', $submissionIds));

                $stmtDeleteSubs = $pdo->prepare("DELETE FROM study_submissions WHERE id IN ($subPlaceholders)");
                $stmtDeleteSubs->execute(array_map('intval', $submissionIds));
            }

            $stmtDeleteLink = $pdo->prepare("DELETE FROM study_links WHERE id = ? LIMIT 1");
            $stmtDeleteLink->execute([$linkId]);

            $stmtCountLinks = $pdo->prepare("SELECT COUNT(*) FROM study_links WHERE candidate_id = ?");
            $stmtCountLinks->execute([(int)$link['candidate_id']]);
            $linksCount = (int)$stmtCountLinks->fetchColumn();

            $newStatus = ($linksCount > 0) ? 'sent' : 'new';

            $stmtResetCandidate = $pdo->prepare("
                UPDATE study_candidates
                SET status = ?
                WHERE id = ?
                LIMIT 1
            ");
            $stmtResetCandidate->execute([$newStatus, (int)$link['candidate_id']]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flashError('حدث خطأ أثناء حذف الرابط: ' . $e->getMessage());
            redirect('admin.php?tab=links');
        }

        flashSuccess('تم حذف الرابط وما يرتبط به.');
        redirect('admin.php?tab=links');
        break;

    case 'update_decision':
        $submissionId = (int)($_POST['submission_id'] ?? 0);
        $committeeStatus = trim((string)($_POST['committee_status'] ?? ''));
        $committeeNotes = cleanTextarea($_POST['committee_notes'] ?? '');

        if ($submissionId <= 0) {
            flashError('رقم الحالة غير صالح.');
            redirect('admin.php?tab=responses');
        }

        $allowedStatuses = ['pending', 'approved', 'rejected', 'review', 'reserve', 'waiting'];
        if (!in_array($committeeStatus, $allowedStatuses, true)) {
            flashError('قرار اللجنة غير صالح.');
            redirect('admin.php?tab=responses');
        }

        $stmt = $pdo->prepare("
            UPDATE study_submissions
            SET committee_status = ?, committee_notes = ?
            WHERE id = ?
            LIMIT 1
        ");

        try {
            $stmt->execute([
                $committeeStatus,
                $committeeNotes !== '' ? $committeeNotes : null,
                $submissionId,
            ]);
        } catch (Throwable $e) {
            flashError('حدث خطأ أثناء حفظ القرار: ' . $e->getMessage());
            redirect('admin.php?tab=responses');
        }

        flashSuccess('تم حفظ قرار اللجنة بنجاح.');
        redirectResponsesByCommitteeStatusLocal($committeeStatus);
        break;

    case 'set_committee_status':
        $submissionId = (int)($_POST['submission_id'] ?? 0);
        $committeeStatus = trim((string)($_POST['committee_status'] ?? ''));
        $committeeNotes = cleanTextarea($_POST['committee_notes'] ?? '');

        if ($submissionId <= 0) {
            flashError('رقم الحالة غير صالح.');
            redirect('admin.php?tab=responses');
        }

        $allowedStatuses = ['pending', 'approved', 'rejected', 'review', 'reserve', 'waiting'];
        if (!in_array($committeeStatus, $allowedStatuses, true)) {
            flashError('قرار اللجنة غير صالح.');
            redirect('admin.php?tab=responses');
        }

        $stmtCheck = $pdo->prepare("SELECT id FROM study_submissions WHERE id = ? LIMIT 1");
        $stmtCheck->execute([$submissionId]);
        if (!$stmtCheck->fetch()) {
            flashError('الحالة المطلوبة غير موجودة.');
            redirect('admin.php?tab=responses');
        }

        $stmt = $pdo->prepare("
            UPDATE study_submissions
            SET committee_status = ?, committee_notes = ?
            WHERE id = ?
            LIMIT 1
        ");

        try {
            $stmt->execute([
                $committeeStatus,
                $committeeNotes !== '' ? $committeeNotes : null,
                $submissionId,
            ]);
        } catch (Throwable $e) {
            flashError('حدث خطأ أثناء تحديث قرار اللجنة: ' . $e->getMessage());
            redirect('admin.php?tab=responses');
        }

        flashSuccess('تم تحديث قرار اللجنة بنجاح.');
        redirectResponsesByCommitteeStatusLocal($committeeStatus);
        break;

    default:
        flashError('الإجراء المطلوب غير معروف.');
        redirect('admin.php');
        break;
}