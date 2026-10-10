<?php
require_once 'auth.php';
startAppSession();
require_once 'db.php';
requireAdmin();
require_once 'includes/navigation.php';

function auditAdminAction($conn, $action, $entityType, $entityId, $details = '') {
    $stmt = $conn->prepare(
        'INSERT INTO admin_audit_log (actor_user_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?)'
    );
    $actorId = (int) $_SESSION['user_id'];
    $stmt->bind_param('issis', $actorId, $action, $entityType, $entityId, $details);
    $stmt->execute();
    $stmt->close();
}

function parseElectionDate($value) {
    $date = DateTime::createFromFormat('Y-m-d\TH:i', $value);
    $dateErrors = DateTime::getLastErrors();
    if (!$date || ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count']))
        || $date->format('Y-m-d\TH:i') !== $value) {
        return false;
    }
    return $date->format('Y-m-d H:i:s');
}

function assertElectionEditable($conn, $electionId) {
    $stmt = $conn->prepare(
        "SELECT status, starts_at FROM elections WHERE id = ? FOR UPDATE"
    );
    $stmt->bind_param('i', $electionId);
    $stmt->execute();
    $election = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$election || !in_array($election['status'], ['draft', 'scheduled'], true)
        || strtotime($election['starts_at']) <= time()) {
        throw new RuntimeException('This election can no longer be edited.');
    }
    return $election;
}

function saveCandidatePhoto($file) {
    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 2 * 1024 * 1024
        || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Choose an image no larger than 2 MB.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Candidate photos must be JPEG, PNG, or WebP images.');
    }

    $directory = __DIR__ . '/uploads/candidates';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('The candidate photo directory could not be created.');
    }

    $relativePath = 'uploads/candidates/' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], __DIR__ . '/' . $relativePath)) {
        throw new RuntimeException('The candidate photo could not be saved.');
    }
    return $relativePath;
}

function removeCandidatePhoto($relativePath) {
    if (is_string($relativePath)
        && preg_match('#^uploads/candidates/[a-f0-9]{32}\.(jpg|png|webp)$#', $relativePath)) {
        $path = __DIR__ . '/' . $relativePath;
        if (is_file($path) && !unlink($path)) {
            error_log('Could not remove candidate photo: ' . $relativePath);
        }
    }
}

$errors = [];
$success = $_SESSION['admin_flash'] ?? '';
unset($_SESSION['admin_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    } else {
        $transactionStarted = false;
        $newPhotoPath = null;
        try {
            $action = requestString($_POST, 'action');

            if ($action === 'mark_admin_notification_read') {
                $notificationId = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$notificationId) {
                    throw new RuntimeException('Select a valid notification.');
                }
                $adminId = (int) $_SESSION['user_id'];
                $notificationStmt = $conn->prepare(
                    'UPDATE notifications SET read_at = CURRENT_TIMESTAMP
                     WHERE id = ? AND user_id = ? AND read_at IS NULL'
                );
                $notificationStmt->bind_param('ii', $notificationId, $adminId);
                $notificationStmt->execute();
                $notificationStmt->close();
                $_SESSION['admin_flash'] = 'Notification marked as read.';
            } elseif ($action === 'change_admin_password') {
                $currentPassword = requestString($_POST, 'current_password');
                $newPassword = requestString($_POST, 'new_password');
                $confirmPassword = requestString($_POST, 'confirm_password');
                $passwordStmt = $conn->prepare("SELECT password FROM users WHERE id = ? AND role = 'admin'");
                $adminId = (int) $_SESSION['user_id'];
                $passwordStmt->bind_param('i', $adminId);
                $passwordStmt->execute();
                $adminPassword = $passwordStmt->get_result()->fetch_assoc();
                $passwordStmt->close();
                if (!$adminPassword || !password_verify($currentPassword, $adminPassword['password'])) {
                    throw new RuntimeException('Your current password is incorrect.');
                }
                if (strlen($newPassword) < 8 || $newPassword !== $confirmPassword) {
                    throw new RuntimeException('Enter matching new passwords with at least 8 characters.');
                }
                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $passwordStmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ? AND role = 'admin'");
                $passwordStmt->bind_param('si', $passwordHash, $adminId);
                $passwordStmt->execute();
                $passwordStmt->close();
                auditAdminAction($conn, 'admin_password_changed', 'user', $adminId);
                $_SESSION['admin_flash'] = 'Administrator password changed successfully.';
            } elseif ($action === 'add_student') {
                $firstName = trim(requestString($_POST, 'first_name'));
                $middleName = trim(requestString($_POST, 'middle_name'));
                $lastName = trim(requestString($_POST, 'last_name'));
                $email = trim(requestString($_POST, 'email'));
                $studentId = trim(requestString($_POST, 'student_id'));
                $department = trim(requestString($_POST, 'department'));
                $password = requestString($_POST, 'password');
                $departments = ['Computer Engineering', 'Civil Engineering', 'Electrical Engineering', 'Mechanical Engineering'];
                if ($firstName === '' || strlen($firstName) > 50 || strlen($middleName) > 50
                    || $lastName === '' || strlen($lastName) > 50
                    || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100
                    || strlen($studentId) < 3 || strlen($studentId) > 20
                    || !in_array($department, $departments, true) || strlen($password) < 8) {
                    throw new RuntimeException('Enter valid student details and an initial password of at least 8 characters.');
                }
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare(
                    "INSERT INTO users (first_name, middle_name, last_name, email, student_id, department, password, has_voted, role, is_verified, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 0, 'student', 0, 1)"
                );
                $stmt->bind_param('sssssss', $firstName, $middleName, $lastName, $email, $studentId, $department, $passwordHash);
                $stmt->execute();
                $userId = $stmt->insert_id;
                $stmt->close();
                auditAdminAction($conn, 'student_added', 'student', $userId);
                $_SESSION['admin_flash'] = 'Student account created. Verify the student after checking their eligibility.';
            } elseif ($action === 'update_student') {
                $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
                $firstName = trim(requestString($_POST, 'first_name'));
                $middleName = trim(requestString($_POST, 'middle_name'));
                $lastName = trim(requestString($_POST, 'last_name'));
                $email = trim(requestString($_POST, 'email'));
                $department = trim(requestString($_POST, 'department'));
                $departments = ['Computer Engineering', 'Civil Engineering', 'Electrical Engineering', 'Mechanical Engineering'];
                if (!$userId || $firstName === '' || strlen($firstName) > 50
                    || strlen($middleName) > 50 || $lastName === '' || strlen($lastName) > 50
                    || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100
                    || !in_array($department, $departments, true)) {
                    throw new RuntimeException('Enter valid student details before saving.');
                }
                $stmt = $conn->prepare(
                    "UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, email = ?, department = ?
                     WHERE id = ? AND role = 'student'"
                );
                $stmt->bind_param('sssssi', $firstName, $middleName, $lastName, $email, $department, $userId);
                $stmt->execute();
                $stmt->close();
                auditAdminAction($conn, 'student_updated', 'student', $userId);
                $_SESSION['admin_flash'] = 'Student details updated.';
            } elseif ($action === 'verify_student') {
                $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$userId || (($_POST['verified'] ?? '') !== '1' && ($_POST['verified'] ?? '') !== '0')) {
                    throw new RuntimeException('Select a valid student verification action.');
                }
                $verified = (int) $_POST['verified'];
                $stmt = $conn->prepare("UPDATE users SET is_verified = ? WHERE id = ? AND role = 'student'");
                $stmt->bind_param('ii', $verified, $userId);
                $stmt->execute();
                $changed = $stmt->affected_rows;
                $stmt->close();
                if (!$changed) {
                    throw new RuntimeException('The student record was not changed.');
                }
                auditAdminAction($conn, $verified ? 'student_verified' : 'student_unverified', 'student', $userId);
                $_SESSION['admin_flash'] = $verified ? 'Student verified.' : 'Student verification revoked.';
            } elseif ($action === 'set_student_active') {
                $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$userId || (($_POST['is_active'] ?? '') !== '1' && ($_POST['is_active'] ?? '') !== '0')) {
                    throw new RuntimeException('Select a valid student account status.');
                }
                $isActive = (int) $_POST['is_active'];
                $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ? AND role = 'student'");
                $stmt->bind_param('ii', $isActive, $userId);
                $stmt->execute();
                $changed = $stmt->affected_rows;
                $stmt->close();
                if (!$changed) {
                    throw new RuntimeException('The student account status was not changed.');
                }
                auditAdminAction($conn, $isActive ? 'student_activated' : 'student_deactivated', 'student', $userId);
                $_SESSION['admin_flash'] = $isActive ? 'Student account activated.' : 'Student account deactivated.';
            } elseif ($action === 'create_election') {
                $title = trim(requestString($_POST, 'title'));
                $description = trim(requestString($_POST, 'description'));
                $startsAt = parseElectionDate(requestString($_POST, 'starts_at'));
                $endsAt = parseElectionDate(requestString($_POST, 'ends_at'));
                $positionNames = array_values(array_unique(array_filter(
                    array_map('trim', preg_split('/\r\n|\r|\n/', requestString($_POST, 'positions'))),
                    function ($name) {
                        return $name !== '';
                    }
                )));
                if ($title === '' || strlen($title) > 200 || !$startsAt || !$endsAt
                    || strtotime($startsAt) <= time() || strtotime($endsAt) <= strtotime($startsAt)
                    || !$positionNames) {
                    throw new RuntimeException('Provide a title, future end time, valid date range, and at least one position.');
                }

                $conn->begin_transaction();
                $transactionStarted = true;
                $stmt = $conn->prepare(
                    "INSERT INTO elections (title, description, starts_at, ends_at, status)
                     VALUES (?, ?, ?, ?, 'draft')"
                );
                $stmt->bind_param('ssss', $title, $description, $startsAt, $endsAt);
                $stmt->execute();
                $electionId = $conn->insert_id;
                $stmt->close();

                $positionStmt = $conn->prepare(
                    'INSERT INTO positions (election_id, name, sort_order) VALUES (?, ?, ?)'
                );
                foreach ($positionNames as $index => $name) {
                    if (strlen($name) > 100) {
                        throw new RuntimeException('Position names must be 100 characters or fewer.');
                    }
                    $order = $index + 1;
                    $positionStmt->bind_param('isi', $electionId, $name, $order);
                    $positionStmt->execute();
                }
                $positionStmt->close();
                auditAdminAction($conn, 'election_created', 'election', $electionId);
                $conn->commit();
                $transactionStarted = false;
                $_SESSION['admin_flash'] = 'Election created as a draft.';
            } elseif ($action === 'update_election') {
                $electionId = filter_var($_POST['election_id'] ?? null, FILTER_VALIDATE_INT);
                $title = trim(requestString($_POST, 'title'));
                $description = trim(requestString($_POST, 'description'));
                $startsAt = parseElectionDate(requestString($_POST, 'starts_at'));
                $endsAt = parseElectionDate(requestString($_POST, 'ends_at'));
                if (!$electionId || $title === '' || strlen($title) > 200 || !$startsAt || !$endsAt
                    || strtotime($startsAt) <= time() || strtotime($endsAt) <= strtotime($startsAt)) {
                    throw new RuntimeException('Provide a title and a valid future election date range.');
                }
                $conn->begin_transaction();
                $transactionStarted = true;
                assertElectionEditable($conn, $electionId);
                $stmt = $conn->prepare(
                    'UPDATE elections SET title = ?, description = ?, starts_at = ?, ends_at = ? WHERE id = ?'
                );
                $stmt->bind_param('ssssi', $title, $description, $startsAt, $endsAt, $electionId);
                $stmt->execute();
                $stmt->close();
                auditAdminAction($conn, 'election_updated', 'election', $electionId);
                $conn->commit();
                $transactionStarted = false;
                $_SESSION['admin_flash'] = 'Election details updated.';
            } elseif ($action === 'add_position') {
                $electionId = filter_var($_POST['election_id'] ?? null, FILTER_VALIDATE_INT);
                $name = trim(requestString($_POST, 'name'));
                if (!$electionId || $name === '' || strlen($name) > 100) {
                    throw new RuntimeException('Provide a valid position name.');
                }
                $conn->begin_transaction();
                $transactionStarted = true;
                assertElectionEditable($conn, $electionId);
                $stmt = $conn->prepare(
                    'INSERT INTO positions (election_id, name, sort_order) VALUES (?, ?, (SELECT COUNT(*) + 1 FROM (SELECT id FROM positions WHERE election_id = ?) AS existing_positions))'
                );
                $stmt->bind_param('isi', $electionId, $name, $electionId);
                $stmt->execute();
                $positionId = $conn->insert_id;
                $stmt->close();
                auditAdminAction($conn, 'position_added', 'position', $positionId);
                $conn->commit();
                $transactionStarted = false;
                $_SESSION['admin_flash'] = 'Position added.';
            } elseif ($action === 'set_election_status') {
                $electionId = filter_var($_POST['election_id'] ?? null, FILTER_VALIDATE_INT);
                $status = $_POST['status'] ?? '';
                if (!$electionId || !in_array($status, ['scheduled', 'active', 'closed', 'publish'], true)) {
                    throw new RuntimeException('Select a valid election action.');
                }
                $conn->begin_transaction();
                $transactionStarted = true;
                $stmt = $conn->prepare('SELECT title, status, starts_at, ends_at, results_published FROM elections WHERE id = ? FOR UPDATE');
                $stmt->bind_param('i', $electionId);
                $stmt->execute();
                $election = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$election) {
                    throw new RuntimeException('Election not found.');
                }

                if (in_array($status, ['scheduled', 'active'], true)) {
                    $positionStmt = $conn->prepare(
                        'SELECT COUNT(*) AS total, SUM((SELECT COUNT(*) FROM candidates c WHERE c.position_id = p.id AND c.status = \'approved\') = 0) AS without_candidates FROM positions p WHERE p.election_id = ?'
                    );
                    $positionStmt->bind_param('i', $electionId);
                    $positionStmt->execute();
                    $positionState = $positionStmt->get_result()->fetch_assoc();
                    $positionStmt->close();
                    if ((int) $positionState['total'] === 0 || (int) $positionState['without_candidates'] !== 0) {
                        throw new RuntimeException('Every position needs at least one approved candidate.');
                    }
                }

                if ($status === 'scheduled' && $election['status'] === 'draft'
                    && strtotime($election['starts_at']) > time() && strtotime($election['ends_at']) > time()) {
                    $newStatus = 'scheduled';
                } elseif ($status === 'active' && $election['status'] === 'scheduled'
                    && strtotime($election['starts_at']) <= time() && strtotime($election['ends_at']) > time()) {
                    $newStatus = 'active';
                } elseif ($status === 'closed' && in_array($election['status'], ['scheduled', 'active'], true)) {
                    $newStatus = 'closed';
                } elseif ($status === 'publish' && !$election['results_published']
                    && in_array($election['status'], ['scheduled', 'active', 'closed'], true)
                    && strtotime($election['ends_at']) <= time()) {
                    $newStatus = 'closed';
                } else {
                    throw new RuntimeException('This election cannot make that status transition.');
                }

                if ($newStatus === 'active' && $election['status'] !== 'active') {
                    $eligibilityStmt = $conn->prepare(
                        "INSERT INTO election_eligibility (election_id, user_id)
                         SELECT ?, id FROM users WHERE role = 'student' AND is_verified = 1 AND is_active = 1"
                    );
                    $eligibilityStmt->bind_param('i', $electionId);
                    $eligibilityStmt->execute();
                    $eligibleCount = $eligibilityStmt->affected_rows;
                    $eligibilityStmt->close();
                    if ($eligibleCount === 0) {
                        throw new RuntimeException('Verify at least one student before activating this election.');
                    }
                }

                $published = $status === 'publish' ? 1 : (int) $election['results_published'];
                $stmt = $conn->prepare('UPDATE elections SET status = ?, results_published = ? WHERE id = ?');
                $stmt->bind_param('sii', $newStatus, $published, $electionId);
                $stmt->execute();
                $stmt->close();
                auditAdminAction($conn, $status === 'publish' ? 'results_published' : 'election_' . $newStatus, 'election', $electionId);

                if ($status === 'scheduled') {
                    $noticeTitle = 'Election scheduled';
                    $noticeMessage = 'The upcoming election "' . $election['title'] . '" is scheduled. Sign in to review its positions and candidates.';
                    $notice = $conn->prepare(
                        "INSERT INTO notifications (user_id, election_id, title, message)
                         SELECT id, ?, ?, ? FROM users WHERE role = 'student' AND is_verified = 1 AND is_active = 1"
                    );
                    $notice->bind_param('iss', $electionId, $noticeTitle, $noticeMessage);
                    $notice->execute();
                    $notice->close();
                }

                $conn->commit();
                $transactionStarted = false;
                $_SESSION['admin_flash'] = $status === 'publish' ? 'Results are now published.' : 'Election status updated.';
            } elseif ($action === 'add_candidate') {
                $electionId = filter_var($_POST['election_id'] ?? null, FILTER_VALIDATE_INT);
                $positionId = filter_var($_POST['position_id'] ?? null, FILTER_VALIDATE_INT);
                $studentId = trim(requestString($_POST, 'student_id'));
                $manifesto = trim(requestString($_POST, 'manifesto'));
                if (!$electionId || !$positionId || $studentId === '') {
                    throw new RuntimeException('Select an election, position, and verified student.');
                }

                $newPhotoPath = saveCandidatePhoto($_FILES['photo'] ?? null);
                $conn->begin_transaction();
                $transactionStarted = true;
                assertElectionEditable($conn, $electionId);

                $positionStmt = $conn->prepare('SELECT id FROM positions WHERE id = ? AND election_id = ?');
                $positionStmt->bind_param('ii', $positionId, $electionId);
                $positionStmt->execute();
                $positionExists = $positionStmt->get_result()->fetch_assoc();
                $positionStmt->close();
                if (!$positionExists) {
                    throw new RuntimeException('The selected position does not belong to this election.');
                }

                $studentStmt = $conn->prepare(
                    "SELECT student_id, CONCAT(first_name, ' ', last_name) AS name
                     FROM users WHERE student_id = ? AND role = 'student' AND is_verified = 1"
                );
                $studentStmt->bind_param('s', $studentId);
                $studentStmt->execute();
                $student = $studentStmt->get_result()->fetch_assoc();
                $studentStmt->close();
                if (!$student) {
                    throw new RuntimeException('Candidates must be verified registered students.');
                }

                $candidateStmt = $conn->prepare(
                    "INSERT INTO candidates (election_id, position_id, student_id, name, photo_path, manifesto, status)
                     VALUES (?, ?, ?, ?, ?, ?, 'pending')"
                );
                $candidateStmt->bind_param(
                    'iissss',
                    $electionId,
                    $positionId,
                    $student['student_id'],
                    $student['name'],
                    $newPhotoPath,
                    $manifesto
                );
                $candidateStmt->execute();
                $candidateId = $conn->insert_id;
                $candidateStmt->close();
                auditAdminAction($conn, 'candidate_added', 'candidate', $candidateId);
                $conn->commit();
                $transactionStarted = false;
                $_SESSION['admin_flash'] = 'Candidate added for administrator approval.';
            } elseif ($action === 'update_candidate') {
                $candidateId = filter_var($_POST['candidate_id'] ?? null, FILTER_VALIDATE_INT);
                $electionId = filter_var($_POST['election_id'] ?? null, FILTER_VALIDATE_INT);
                $status = $_POST['status'] ?? '';
                $manifesto = trim(requestString($_POST, 'manifesto'));
                if (!$candidateId || !$electionId || !in_array($status, ['pending', 'approved', 'rejected'], true)) {
                    throw new RuntimeException('Select a valid candidate status.');
                }
                $newPhotoPath = saveCandidatePhoto($_FILES['photo'] ?? null);
                $conn->begin_transaction();
                $transactionStarted = true;
                assertElectionEditable($conn, $electionId);
                $photoStmt = $conn->prepare('SELECT photo_path FROM candidates WHERE id = ? AND election_id = ? FOR UPDATE');
                $photoStmt->bind_param('ii', $candidateId, $electionId);
                $photoStmt->execute();
                $oldCandidate = $photoStmt->get_result()->fetch_assoc();
                $photoStmt->close();
                if (!$oldCandidate) {
                    throw new RuntimeException('Candidate not found in this election.');
                }

                $photoPath = $newPhotoPath ?? $oldCandidate['photo_path'];
                $stmt = $conn->prepare(
                    'UPDATE candidates SET manifesto = ?, photo_path = ?, status = ? WHERE id = ? AND election_id = ?'
                );
                $stmt->bind_param('sssii', $manifesto, $photoPath, $status, $candidateId, $electionId);
                $stmt->execute();
                $stmt->close();
                auditAdminAction($conn, 'candidate_updated', 'candidate', $candidateId, 'status=' . $status);
                $conn->commit();
                $transactionStarted = false;
                if ($newPhotoPath) {
                    removeCandidatePhoto($oldCandidate['photo_path']);
                }
                $_SESSION['admin_flash'] = 'Candidate details updated.';
            } elseif ($action === 'delete_candidate') {
                $candidateId = filter_var($_POST['candidate_id'] ?? null, FILTER_VALIDATE_INT);
                $electionId = filter_var($_POST['election_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$candidateId || !$electionId) {
                    throw new RuntimeException('Select a valid candidate.');
                }
                $conn->begin_transaction();
                $transactionStarted = true;
                assertElectionEditable($conn, $electionId);
                $photoStmt = $conn->prepare('SELECT photo_path FROM candidates WHERE id = ? AND election_id = ? FOR UPDATE');
                $photoStmt->bind_param('ii', $candidateId, $electionId);
                $photoStmt->execute();
                $candidate = $photoStmt->get_result()->fetch_assoc();
                $photoStmt->close();
                if (!$candidate) {
                    throw new RuntimeException('Candidate not found in this election.');
                }
                $stmt = $conn->prepare('DELETE FROM candidates WHERE id = ? AND election_id = ?');
                $stmt->bind_param('ii', $candidateId, $electionId);
                $stmt->execute();
                $stmt->close();
                auditAdminAction($conn, 'candidate_deleted', 'candidate', $candidateId);
                $conn->commit();
                $transactionStarted = false;
                removeCandidatePhoto($candidate['photo_path']);
                $_SESSION['admin_flash'] = 'Candidate removed.';
            } else {
                throw new RuntimeException('Unknown administrative action.');
            }

            $redirectSection = [
                'mark_admin_notification_read' => '?nav=notifications#admin-notifications',
                'change_admin_password' => '?nav=settings#settings'
            ];
            header('Location: admin.php' . ($redirectSection[$action] ?? ''));
            exit();
        } catch (mysqli_sql_exception $exception) {
            if ($transactionStarted) {
                $conn->rollback();
            }
            if ($newPhotoPath) {
                removeCandidatePhoto($newPhotoPath);
            }
            if ((int) $exception->getCode() === 1062) {
                if ($action === 'update_student') {
                    $errors[] = 'That email address is already used by another account.';
                } elseif ($action === 'add_student') {
                    $errors[] = 'That student ID or email address is already registered.';
                } else {
                    $errors[] = 'This position already has that student as a candidate.';
                }
            } else {
                error_log('Admin operation database error: ' . $exception->getMessage());
                $errors[] = 'The administrative change could not be saved.';
            }
        } catch (RuntimeException $exception) {
            if ($transactionStarted) {
                $conn->rollback();
            }
            if ($newPhotoPath) {
                removeCandidatePhoto($newPhotoPath);
            }
            $errors[] = $exception->getMessage();
        }
    }
}

$stats = $conn->query(
    "SELECT
        (SELECT COUNT(*) FROM users WHERE role = 'student') AS total_students,
        (SELECT COUNT(*) FROM users WHERE role = 'student' AND is_verified = 1 AND is_active = 1) AS verified_students,
        (SELECT COUNT(*) FROM candidates) AS total_candidates,
        (SELECT COUNT(*) FROM elections) AS total_elections,
        (SELECT COUNT(*) FROM elections WHERE status = 'active' AND CURRENT_TIMESTAMP BETWEEN starts_at AND ends_at) AS active_elections,
        (SELECT COUNT(*) FROM elections WHERE status = 'closed') AS completed_elections,
        (SELECT COUNT(*) FROM ballots) AS total_ballots,
        (SELECT COUNT(*) FROM election_eligibility) AS election_eligible_voters,
        (SELECT COUNT(*) FROM election_participation) AS election_participation"
)->fetch_assoc();

$electionSearch = trim(requestString($_GET, 'election_q'));
$electionStatus = $_GET['election_status'] ?? 'all';
if (!in_array($electionStatus, ['all', 'draft', 'scheduled', 'active', 'closed'], true)) {
    $electionStatus = 'all';
}
$candidateSearch = trim(requestString($_GET, 'candidate_q'));
$candidatePositionId = filter_var($_GET['candidate_position'] ?? null, FILTER_VALIDATE_INT);
$candidatePositionId = $candidatePositionId ? (int) $candidatePositionId : 0;
$electionStmt = $conn->prepare(
    'SELECT e.*,
        (SELECT COUNT(*) FROM positions p WHERE p.election_id = e.id) AS position_count,
        (SELECT COUNT(*) FROM candidates c WHERE c.election_id = e.id) AS candidate_count,
        (SELECT COUNT(*) FROM election_participation ep WHERE ep.election_id = e.id) AS turnout
     FROM elections e
     WHERE (? = \'\' OR e.title LIKE ? OR e.description LIKE ?)
       AND (? = \'all\' OR e.status = ?)
     ORDER BY e.starts_at DESC, e.id DESC'
);
$electionSearchLike = '%' . $electionSearch . '%';
$electionStmt->bind_param('sssss', $electionSearch, $electionSearchLike, $electionSearchLike, $electionStatus, $electionStatus);
$electionStmt->execute();
$elections = $electionStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$electionStmt->close();

$eligibleStudents = $conn->query(
    "SELECT id, student_id, first_name, last_name
     FROM users WHERE role = 'student' AND is_verified = 1 AND is_active = 1 ORDER BY last_name, first_name"
)->fetch_all(MYSQLI_ASSOC);

$search = trim(requestString($_GET, 'q'));
$studentFilter = $_GET['student_filter'] ?? 'all';
if (!in_array($studentFilter, ['all', 'pending', 'verified', 'active', 'inactive'], true)) {
    $studentFilter = 'all';
}
$studentStmt = $conn->prepare(
    "SELECT u.id, u.student_id, u.first_name, u.middle_name, u.last_name, u.email, u.department,
            u.is_verified, u.is_active, u.created_at,
            (EXISTS(SELECT 1 FROM legacy_voter_participation lvp WHERE lvp.student_id = u.student_id)
             OR EXISTS(SELECT 1 FROM election_participation ep WHERE ep.user_id = u.id)) AS has_voted
     FROM users u
     WHERE u.role = 'student'
       AND (? = 'all' OR (? = 'pending' AND u.is_verified = 0) OR (? = 'verified' AND u.is_verified = 1)
            OR (? = 'active' AND u.is_active = 1) OR (? = 'inactive' AND u.is_active = 0))
       AND (? = '' OR u.student_id LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)
     ORDER BY u.created_at DESC"
);
$searchLike = '%' . $search . '%';
$studentStmt->bind_param(
    'ssssssssss',
    $studentFilter,
    $studentFilter,
    $studentFilter,
    $studentFilter,
    $studentFilter,
    $search,
    $searchLike,
    $searchLike,
    $searchLike,
    $searchLike
);
$studentStmt->execute();
$students = $studentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$studentStmt->close();

$candidateStmt = $conn->prepare(
    'SELECT c.*, p.name AS position_name
     FROM candidates c JOIN positions p ON p.id = c.position_id
     WHERE c.election_id = ?
       AND (? = \'\' OR c.name LIKE ? OR COALESCE(c.student_id, \'\') LIKE ?)
       AND (? = 0 OR c.position_id = ?)
     ORDER BY p.sort_order, p.id, c.name, c.id'
);
$candidateSearchLike = '%' . $candidateSearch . '%';
foreach ($elections as &$electionRow) {
    $electionId = (int) $electionRow['id'];
    $candidateStmt->bind_param(
        'isssii',
        $electionId,
        $candidateSearch,
        $candidateSearchLike,
        $candidateSearchLike,
        $candidatePositionId,
        $candidatePositionId
    );
    $candidateStmt->execute();
    $electionRow['candidates'] = $candidateStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $positionStmt = $conn->prepare('SELECT id, name FROM positions WHERE election_id = ? ORDER BY sort_order, id');
    $positionStmt->bind_param('i', $electionId);
    $positionStmt->execute();
    $electionRow['positions'] = $positionStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $positionStmt->close();

    $editable = in_array($electionRow['status'], ['draft', 'scheduled'], true)
        && strtotime($electionRow['starts_at']) > time();
    $electionRow['editable'] = $editable;
}
unset($electionRow);
$candidateStmt->close();
$positionFilterOptions = $conn->query(
    'SELECT p.id, p.name, e.title AS election_title FROM positions p
     JOIN elections e ON e.id = p.election_id ORDER BY e.starts_at DESC, p.sort_order, p.name'
)->fetch_all(MYSQLI_ASSOC);
$adminId = (int) $_SESSION['user_id'];
$notificationStmt = $conn->prepare(
    'SELECT id, title, message, created_at, read_at
     FROM notifications WHERE user_id = ?
     ORDER BY read_at IS NULL DESC, created_at DESC LIMIT 30'
);
$notificationStmt->bind_param('i', $adminId);
$notificationStmt->execute();
$adminNotifications = $notificationStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$notificationStmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - College Voting System</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 22px; background: #f2f4fa; color: #283044; font-family: Arial, sans-serif; }
        main { max-width: 1250px; margin: auto; }
        header, section, .election { background: #fff; border-radius: 10px; padding: 20px; margin-bottom: 18px; box-shadow: 0 2px 10px rgba(20,30,60,.08); }
        header { display: flex; justify-content: space-between; align-items: center; background: #283b66; color: white; }
        h1, h2, h3 { margin-top: 0; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit,minmax(170px,1fr)); gap: 12px; margin-bottom: 18px; }
        .stat { background: white; border-radius: 9px; padding: 18px; box-shadow: 0 2px 10px rgba(20,30,60,.08); }
        .stat strong { display: block; font-size: 1.7em; color: #586de0; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(270px,1fr)); gap: 14px; }
        label { display: block; margin: 10px 0 5px; font-weight: 600; }
        input, textarea, select { width: 100%; padding: 10px; border: 1px solid #cbd1df; border-radius: 6px; font: inherit; }
        textarea { min-height: 76px; resize: vertical; }
        button, .button { border: 0; border-radius: 6px; padding: 10px 15px; background: #586de0; color: white; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
        button.danger { background: #c43b4b; }
        button.secondary { background: #64748b; }
        .inline-form { display: inline-block; margin: 4px; }
        .row { border-top: 1px solid #e8eaf0; padding: 12px 0; }
        .candidate { background: #f8f9fc; padding: 14px; border-radius: 8px; margin: 10px 0; }
        .candidate img { max-width: 88px; max-height: 88px; object-fit: cover; border-radius: 7px; }
        .notice { padding: 12px; border-radius: 7px; background: #e9f5ec; color: #236b35; margin: 12px 0; }
        .error { padding: 12px; border-radius: 7px; background: #fff0f0; color: #942e2e; margin: 12px 0; }
        .muted { color: #697386; }
        @media(max-width:600px) { body { padding: 10px; } header { align-items: flex-start; flex-direction: column; gap: 10px; } }
    </style>
    <link rel="stylesheet" href="includes/navigation.css">
</head>
<body>
<?php
$activeNavigation = requestString($_GET, 'nav');
if (!in_array($activeNavigation, ['admin-dashboard', 'students', 'candidates', 'elections', 'results', 'reports', 'notifications', 'audit', 'settings'], true)) {
    $activeNavigation = 'admin-dashboard';
}
$navigationTitles = [
    'admin-dashboard' => ['Admin Dashboard', 'Manage elections and review system activity.'],
    'students' => ['Student Management', 'Review student accounts, eligibility, and status.'],
    'candidates' => ['Candidate Management', 'Review and manage election candidates.'],
    'elections' => ['Election Management', 'Create elections, positions, and schedules.'],
    'results' => ['Election Results', 'Review election results and turnout.'],
    'reports' => ['Election Reports', 'Review election totals and participation reports.'],
    'notifications' => ['Notifications', 'Election and account updates for your administrator account.'],
    'audit' => ['Audit Logs', 'Review recorded administrator actions.'],
    'settings' => ['Settings', 'Update your administrator account password.']
];
renderAuthenticatedNavigation($navigationTitles[$activeNavigation][0], $navigationTitles[$activeNavigation][1], $activeNavigation);
?>
<main>
    <?php if ($success): ?><div class="notice"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php foreach ($errors as $error): ?><div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endforeach; ?>

    <?php if ($activeNavigation === 'notifications'): ?>
        <section id="admin-notifications">
            <h2>Notifications</h2>
            <?php if (!$adminNotifications): ?><p class="muted">No notifications yet.</p><?php endif; ?>
            <?php foreach ($adminNotifications as $notification): ?>
                <article class="row">
                    <strong><?php echo htmlspecialchars($notification['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <p><?php echo nl2br(htmlspecialchars($notification['message'], ENT_QUOTES, 'UTF-8')); ?></p>
                    <small class="muted"><?php echo htmlspecialchars($notification['created_at'], ENT_QUOTES, 'UTF-8'); ?></small>
                    <?php if (!$notification['read_at']): ?>
                        <form method="POST" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="mark_admin_notification_read">
                            <input type="hidden" name="notification_id" value="<?php echo (int) $notification['id']; ?>">
                            <button type="submit">Mark as read</button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <div class="stats">
        <div class="stat"><strong><?php echo (int) $stats['total_students']; ?></strong>Registered students</div>
        <div class="stat"><strong><?php echo (int) $stats['verified_students']; ?></strong>Verified eligible students</div>
        <div class="stat"><strong><?php echo (int) $stats['total_candidates']; ?></strong>Candidates</div>
        <div class="stat"><strong><?php echo (int) $stats['total_elections']; ?></strong>Elections</div>
        <div class="stat"><strong><?php echo (int) $stats['active_elections']; ?></strong>Currently active</div>
        <div class="stat"><strong><?php echo (int) $stats['completed_elections']; ?></strong>Completed elections</div>
        <div class="stat"><strong><?php echo (int) $stats['total_ballots']; ?></strong>Anonymous ballots</div>
        <div class="stat"><strong><?php echo (int) $stats['election_participation']; ?> / <?php echo (int) $stats['election_eligible_voters']; ?></strong>Participation / eligible election entries</div>
        <div class="stat"><strong><?php echo (int) $stats['election_eligible_voters'] ? round((int) $stats['election_participation'] / (int) $stats['election_eligible_voters'] * 100, 1) : 0; ?>%</strong>Aggregate turnout</div>
    </div>

    <section id="student-management">
        <h2>Student management</h2>
        <form method="GET" class="grid">
            <div><label for="q">Search students</label><input id="q" name="q" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Student ID, name, or email"></div>
            <div><label for="student_filter">Filter</label><select id="student_filter" name="student_filter">
                <?php foreach (['all' => 'All students', 'pending' => 'Awaiting verification', 'verified' => 'Verified', 'active' => 'Active accounts', 'inactive' => 'Inactive accounts'] as $value => $label): ?>
                    <option value="<?php echo $value; ?>" <?php echo $studentFilter === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select></div>
            <div style="align-self:end"><button type="submit">Search / filter</button></div>
        </form>
        <details>
            <summary>Add student account</summary>
            <p class="muted">New accounts are inactive from voting eligibility until an administrator verifies them. Give the initial password to the student privately and ask them to change it after signing in.</p>
            <form method="POST" class="grid">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="add_student">
                <div><label>First name</label><input name="first_name" maxlength="50" required></div>
                <div><label>Middle name</label><input name="middle_name" maxlength="50"></div>
                <div><label>Last name</label><input name="last_name" maxlength="50" required></div>
                <div><label>Email</label><input type="email" name="email" maxlength="100" required></div>
                <div><label>Student ID</label><input name="student_id" minlength="3" maxlength="20" required></div>
                <div><label>Department</label><select name="department" required>
                    <option value="">Select department</option>
                    <?php foreach (['Computer Engineering', 'Civil Engineering', 'Electrical Engineering', 'Mechanical Engineering'] as $department): ?>
                        <option value="<?php echo htmlspecialchars($department, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($department, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select></div>
                <div><label>Initial password (8+ characters)</label><input type="password" name="password" minlength="8" autocomplete="new-password" required></div>
                <div style="align-self:end"><button type="submit">Add student</button></div>
            </form>
        </details>
        <?php if (!$students): ?><p class="muted">No students match this search.</p><?php endif; ?>
        <?php foreach ($students as $student): ?>
            <div class="row">
                <strong><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                · <?php echo htmlspecialchars($student['student_id'] . ' · ' . $student['email'] . ' · ' . $student['department'], ENT_QUOTES, 'UTF-8'); ?>
                <div class="muted">Account: <?php echo $student['is_active'] ? 'Active' : 'Inactive'; ?>
                    · Eligibility: <?php echo $student['is_verified'] ? 'Verified' : 'Pending'; ?>
                    · Voting: <?php echo $student['has_voted'] ? 'Participated' : 'No participation recorded'; ?>
                    · Registered: <?php echo htmlspecialchars($student['created_at'], ENT_QUOTES, 'UTF-8'); ?></div>
                <details>
                    <summary>Edit student details</summary>
                    <form method="POST" class="grid">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="update_student">
                        <input type="hidden" name="user_id" value="<?php echo (int) $student['id']; ?>">
                        <div><label>First name</label><input name="first_name" maxlength="50" required value="<?php echo htmlspecialchars($student['first_name'], ENT_QUOTES, 'UTF-8'); ?>"></div>
                        <div><label>Middle name</label><input name="middle_name" maxlength="50" value="<?php echo htmlspecialchars($student['middle_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></div>
                        <div><label>Last name</label><input name="last_name" maxlength="50" required value="<?php echo htmlspecialchars($student['last_name'], ENT_QUOTES, 'UTF-8'); ?>"></div>
                        <div><label>Email</label><input type="email" name="email" maxlength="100" required value="<?php echo htmlspecialchars($student['email'], ENT_QUOTES, 'UTF-8'); ?>"></div>
                        <div><label>Department</label><select name="department" required>
                            <?php foreach (['Computer Engineering', 'Civil Engineering', 'Electrical Engineering', 'Mechanical Engineering'] as $department): ?>
                                <option value="<?php echo htmlspecialchars($department, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $student['department'] === $department ? 'selected' : ''; ?>><?php echo htmlspecialchars($department, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select></div>
                        <button type="submit">Save student</button>
                    </form>
                </details>
                <?php if (!(int) $student['is_verified']): ?>
                    <form method="POST" class="inline-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="verify_student"><input type="hidden" name="user_id" value="<?php echo (int) $student['id']; ?>"><input type="hidden" name="verified" value="1">
                        <button type="submit">Verify eligibility</button>
                    </form>
                <?php else: ?>
                    <form method="POST" class="inline-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="verify_student"><input type="hidden" name="user_id" value="<?php echo (int) $student['id']; ?>"><input type="hidden" name="verified" value="0">
                        <button class="secondary" type="submit" onclick="return confirm('Revoke this student election eligibility?')">Revoke eligibility</button>
                    </form>
                <?php endif; ?>
                <form method="POST" class="inline-form" onsubmit="return confirm('<?php echo $student['is_active'] ? 'Deactivate' : 'Activate'; ?> this student account?');">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="set_student_active"><input type="hidden" name="user_id" value="<?php echo (int) $student['id']; ?>"><input type="hidden" name="is_active" value="<?php echo $student['is_active'] ? '0' : '1'; ?>">
                    <button class="<?php echo $student['is_active'] ? 'danger' : ''; ?>" type="submit"><?php echo $student['is_active'] ? 'Deactivate account' : 'Activate account'; ?></button>
                </form>
            </div>
        <?php endforeach; ?>
    </section>

    <section id="elections">
        <h2>Create election</h2>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="create_election">
            <div class="grid">
                <div><label for="title">Election title</label><input id="title" name="title" maxlength="200" required></div>
                <div><label for="starts_at">Starts</label><input id="starts_at" name="starts_at" type="datetime-local" required></div>
                <div><label for="ends_at">Ends</label><input id="ends_at" name="ends_at" type="datetime-local" required></div>
            </div>
            <label for="description">Description and instructions</label><textarea id="description" name="description"></textarea>
            <label for="positions">Positions (one per line)</label><textarea id="positions" name="positions" required placeholder="President&#10;Secretary"></textarea>
            <button type="submit">Create draft election</button>
        </form>
    </section>

    <section>
        <h2>Elections</h2>
        <form method="GET" class="grid">
            <div><label for="election_q">Search elections</label><input id="election_q" name="election_q" value="<?php echo htmlspecialchars($electionSearch, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Title or description"></div>
            <div><label for="election_status">Election status</label><select id="election_status" name="election_status">
                <?php foreach (['all' => 'All statuses', 'draft' => 'Draft', 'scheduled' => 'Upcoming', 'active' => 'Active', 'closed' => 'Completed'] as $value => $label): ?>
                    <option value="<?php echo $value; ?>" <?php echo $electionStatus === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select></div>
            <div id="candidate-management"><label for="candidate_q">Search candidates</label><input id="candidate_q" name="candidate_q" value="<?php echo htmlspecialchars($candidateSearch, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Candidate name or student ID"></div>
            <div><label for="candidate_position">Candidate position</label><select id="candidate_position" name="candidate_position">
                <option value="0">All positions</option>
                <?php foreach ($positionFilterOptions as $positionOption): ?>
                    <option value="<?php echo (int) $positionOption['id']; ?>" <?php echo $candidatePositionId === (int) $positionOption['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($positionOption['election_title'] . ' - ' . $positionOption['name'], ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select></div>
            <div style="align-self:end"><button type="submit">Apply filters</button></div>
        </form>
        <?php if (!$elections): ?><p class="muted">No elections have been created yet.</p><?php endif; ?>
        <?php foreach ($elections as $election): ?>
            <article class="election">
                <h3><?php echo htmlspecialchars($election['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                <p><?php echo htmlspecialchars($election['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="muted">
                    <?php echo htmlspecialchars($election['starts_at'] . ' to ' . $election['ends_at'], ENT_QUOTES, 'UTF-8'); ?>
                    · Status: <?php echo htmlspecialchars($election['status'], ENT_QUOTES, 'UTF-8'); ?>
                    · <?php echo (int) $election['position_count']; ?> positions
                    · <?php echo (int) $election['candidate_count']; ?> candidates
                    · <?php echo (int) $election['turnout']; ?> voters
                </p>
                <?php if ($election['results_published']): ?><a class="button" href="results.php?election_id=<?php echo (int) $election['id']; ?>">View published results</a><?php endif; ?>

                <?php if ($election['editable']): ?>
                    <form method="POST" class="row">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="update_election"><input type="hidden" name="election_id" value="<?php echo (int) $election['id']; ?>">
                        <div class="grid">
                            <div><label>Title</label><input name="title" maxlength="200" required value="<?php echo htmlspecialchars($election['title'], ENT_QUOTES, 'UTF-8'); ?>"></div>
                            <div><label>Starts</label><input name="starts_at" type="datetime-local" required value="<?php echo htmlspecialchars(str_replace(' ', 'T', substr($election['starts_at'], 0, 16)), ENT_QUOTES, 'UTF-8'); ?>"></div>
                            <div><label>Ends</label><input name="ends_at" type="datetime-local" required value="<?php echo htmlspecialchars(str_replace(' ', 'T', substr($election['ends_at'], 0, 16)), ENT_QUOTES, 'UTF-8'); ?>"></div>
                        </div>
                        <label>Description</label><textarea name="description"><?php echo htmlspecialchars($election['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        <button type="submit">Save election details</button>
                    </form>

                    <form method="POST" class="inline-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="add_position"><input type="hidden" name="election_id" value="<?php echo (int) $election['id']; ?>">
                        <label>Add position</label><input name="name" maxlength="100" required><button type="submit">Add position</button>
                    </form>

                    <form method="POST" enctype="multipart/form-data" class="row">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="add_candidate"><input type="hidden" name="election_id" value="<?php echo (int) $election['id']; ?>">
                        <h4>Add candidate (approval required)</h4>
                        <div class="grid">
                            <div><label>Position</label><select name="position_id" required><option value="">Select position</option><?php foreach ($election['positions'] as $position): ?><option value="<?php echo (int) $position['id']; ?>"><?php echo htmlspecialchars($position['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
                            <div><label>Verified student</label><select name="student_id" required><option value="">Select student</option><?php foreach ($eligibleStudents as $student): ?><option value="<?php echo htmlspecialchars($student['student_id'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name'] . ' (' . $student['student_id'] . ')', ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
                            <div><label>Photo (JPEG, PNG, WebP; max 2 MB)</label><input type="file" name="photo" accept="image/jpeg,image/png,image/webp"></div>
                        </div>
                        <label>Manifesto</label><textarea name="manifesto"></textarea>
                        <button type="submit">Add candidate</button>
                    </form>
                <?php endif; ?>

                <?php foreach ($election['candidates'] as $candidate): ?>
                    <div class="candidate">
                        <?php if (!empty($candidate['photo_path'])): ?><img src="<?php echo htmlspecialchars($candidate['photo_path'], ENT_QUOTES, 'UTF-8'); ?>" alt="Candidate photo"><?php endif; ?>
                        <strong><?php echo htmlspecialchars($candidate['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        · <?php echo htmlspecialchars($candidate['student_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                        · <?php echo htmlspecialchars($candidate['position_name'], ENT_QUOTES, 'UTF-8'); ?>
                        · <?php echo htmlspecialchars($candidate['status'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php if ($election['editable']): ?>
                            <form method="POST" enctype="multipart/form-data" class="row">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="update_candidate"><input type="hidden" name="candidate_id" value="<?php echo (int) $candidate['id']; ?>"><input type="hidden" name="election_id" value="<?php echo (int) $election['id']; ?>">
                                <label>Manifesto</label><textarea name="manifesto"><?php echo htmlspecialchars($candidate['manifesto'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                                <label>Photo</label><input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
                                <label>Status</label><select name="status"><option value="pending" <?php echo $candidate['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option><option value="approved" <?php echo $candidate['status'] === 'approved' ? 'selected' : ''; ?>>Approved</option><option value="rejected" <?php echo $candidate['status'] === 'rejected' ? 'selected' : ''; ?>>Rejected</option></select>
                                <button type="submit">Save / review candidate</button>
                            </form>
                            <form method="POST" class="inline-form" onsubmit="return confirm('Remove this candidate from the draft election?');">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="delete_candidate"><input type="hidden" name="candidate_id" value="<?php echo (int) $candidate['id']; ?>"><input type="hidden" name="election_id" value="<?php echo (int) $election['id']; ?>">
                                <button class="danger" type="submit">Remove candidate</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php if ($election['status'] === 'draft'): ?>
                    <form method="POST" class="inline-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="set_election_status"><input type="hidden" name="status" value="scheduled"><input type="hidden" name="election_id" value="<?php echo (int) $election['id']; ?>">
                        <button type="submit">Schedule election</button>
                    </form>
                <?php elseif ($election['status'] === 'scheduled'): ?>
                    <form method="POST" class="inline-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="set_election_status"><input type="hidden" name="status" value="active"><input type="hidden" name="election_id" value="<?php echo (int) $election['id']; ?>">
                        <button type="submit">Activate when window opens</button>
                    </form>
                    <form method="POST" class="inline-form" onsubmit="return confirm('Close this election? Voting will stop immediately.');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="set_election_status"><input type="hidden" name="status" value="closed"><input type="hidden" name="election_id" value="<?php echo (int) $election['id']; ?>">
                        <button class="danger" type="submit">Close election</button>
                    </form>
                <?php elseif ($election['status'] === 'active'): ?>
                    <form method="POST" class="inline-form" onsubmit="return confirm('Close voting for this election now?');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="set_election_status"><input type="hidden" name="status" value="closed"><input type="hidden" name="election_id" value="<?php echo (int) $election['id']; ?>">
                        <button class="danger" type="submit">Close voting</button>
                    </form>
                <?php endif; ?>

                <?php if (!$election['results_published'] && strtotime($election['ends_at']) <= time()): ?>
                    <form method="POST" class="inline-form" onsubmit="return confirm('Publish final results? This cannot be undone.');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="set_election_status"><input type="hidden" name="status" value="publish"><input type="hidden" name="election_id" value="<?php echo (int) $election['id']; ?>">
                        <button type="submit">Publish results</button>
                    </form>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>

    <section id="audit-logs">
        <h2>Audit log</h2>
        <?php $auditRows = $conn->query(
            'SELECT a.action, a.entity_type, a.entity_id, a.details, a.created_at, u.student_id
             FROM admin_audit_log a LEFT JOIN users u ON u.id = a.actor_user_id
             ORDER BY a.id DESC LIMIT 30'
        )->fetch_all(MYSQLI_ASSOC); ?>
        <?php if (!$auditRows): ?><p class="muted">No administrative actions have been recorded.</p><?php endif; ?>
        <?php foreach ($auditRows as $audit): ?>
            <div class="row">
                <?php echo htmlspecialchars($audit['created_at'] . ' · ' . ($audit['student_id'] ?? 'removed account') . ' · ' . $audit['action'] . ' · ' . $audit['entity_type'] . ' #' . $audit['entity_id'] . ' ' . ($audit['details'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endforeach; ?>
    </section>

    <section id="settings">
        <h2>Administrator settings</h2>
        <p class="muted">Change your password. Your current password is required to confirm this update.</p>
        <form method="POST" class="grid">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="change_admin_password">
            <div><label for="admin_current_password">Current password</label><input id="admin_current_password" name="current_password" type="password" autocomplete="current-password" required></div>
            <div><label for="admin_new_password">New password (8+ characters)</label><input id="admin_new_password" name="new_password" type="password" minlength="8" autocomplete="new-password" required></div>
            <div><label for="admin_confirm_password">Confirm new password</label><input id="admin_confirm_password" name="confirm_password" type="password" minlength="8" autocomplete="new-password" required></div>
            <div style="align-self:end"><button type="submit">Change password</button></div>
        </form>
    </section>
</main>
<?php renderAuthenticatedNavigationEnd(); ?>
</body>
</html>
