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
    if ($finfo === false) {
        throw new RuntimeException('Image validation is temporarily unavailable.');
    }
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $image = getimagesize($file['tmp_name']);
    if (!isset($extensions[$mime]) || $image === false || $image['mime'] !== $mime
        || $image[0] < 1 || $image[1] < 1 || $image[0] > 6000 || $image[1] > 6000) {
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
                $passwordConfirmation = requestString($_POST, 'password_confirmation');
                $departments = ['Computer Engineering', 'Civil Engineering', 'Electrical Engineering', 'Mechanical Engineering'];
                if ($firstName === '' || strlen($firstName) > 50 || strlen($middleName) > 50
                    || $lastName === '' || strlen($lastName) > 50
                    || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100
                    || strlen($studentId) < 3 || strlen($studentId) > 20
                    || !in_array($department, $departments, true) || strlen($password) < 8
                    || $password !== $passwordConfirmation) {
                    throw new RuntimeException('Enter valid student details and matching initial passwords of at least 8 characters.');
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
                'change_admin_password' => '?nav=settings#settings',
                'add_student' => '?nav=students#student-management',
                'update_student' => '?nav=students#student-management',
                'verify_student' => '?nav=students#student-management',
                'set_student_active' => '?nav=students#student-management'
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
$studentStats = $conn->query(
    "SELECT
        COUNT(*) AS total_students,
        COALESCE(SUM(is_active = 1), 0) AS active_students,
        COALESCE(SUM(is_active = 0), 0) AS inactive_students,
        COALESCE(SUM(created_at >= CURRENT_TIMESTAMP - INTERVAL 30 DAY), 0) AS recently_registered
     FROM users
     WHERE role = 'student'"
)->fetch_assoc();

$electionSearch = trim(requestString($_GET, 'election_q'));
$electionStatus = $_GET['election_status'] ?? 'all';
if (!in_array($electionStatus, ['all', 'draft', 'scheduled', 'active', 'closed'], true)) {
    $electionStatus = 'all';
}
$electionPage = filter_var($_GET['election_page'] ?? 1, FILTER_VALIDATE_INT);
$electionPage = $electionPage && $electionPage > 0 ? (int) $electionPage : 1;
$electionsPerPage = 10;
$candidateSearch = trim(requestString($_GET, 'candidate_q'));
$candidateSearchLike = '%' . $candidateSearch . '%';
$candidatePageSize = 10;
$candidatePositionId = filter_var($_GET['candidate_position'] ?? null, FILTER_VALIDATE_INT);
$candidatePositionId = $candidatePositionId ? (int) $candidatePositionId : 0;
$candidatePageOptions = $_GET['candidate_page'] ?? [];
if (!is_array($candidatePageOptions)) {
    $candidatePageOptions = [];
}
$electionCountStmt = $conn->prepare(
    'SELECT COUNT(*) AS total FROM elections
     WHERE (? = \'\' OR title LIKE ? OR description LIKE ?)
       AND (? = \'all\' OR status = ?)'
);
$electionSearchLike = '%' . $electionSearch . '%';
$electionCountStmt->bind_param('sssss', $electionSearch, $electionSearchLike, $electionSearchLike, $electionStatus, $electionStatus);
$electionCountStmt->execute();
$electionCount = (int) $electionCountStmt->get_result()->fetch_assoc()['total'];
$electionCountStmt->close();
$electionPageCount = max(1, (int) ceil($electionCount / $electionsPerPage));
$electionPage = min($electionPage, $electionPageCount);
$electionOffset = ($electionPage - 1) * $electionsPerPage;
$electionStmt = $conn->prepare(
    'SELECT e.*,
        (SELECT COUNT(*) FROM positions p WHERE p.election_id = e.id) AS position_count,
        (SELECT COUNT(*) FROM candidates c WHERE c.election_id = e.id) AS candidate_count,
        (SELECT COUNT(*) FROM election_participation ep WHERE ep.election_id = e.id) AS turnout,
        (SELECT COUNT(*) FROM candidates fc
         WHERE fc.election_id = e.id
           AND (? = \'\' OR fc.name LIKE ? OR COALESCE(fc.student_id, \'\') LIKE ?)
           AND (? = 0 OR fc.position_id = ?)) AS matching_candidate_count
     FROM elections e
     WHERE (? = \'\' OR e.title LIKE ? OR e.description LIKE ?)
       AND (? = \'all\' OR e.status = ?)
     ORDER BY e.starts_at DESC, e.id DESC
     LIMIT ? OFFSET ?'
);
$electionStmt->bind_param(
    'sssiisssssii',
    $candidateSearch,
    $candidateSearchLike,
    $candidateSearchLike,
    $candidatePositionId,
    $candidatePositionId,
    $electionSearch,
    $electionSearchLike,
    $electionSearchLike,
    $electionStatus,
    $electionStatus,
    $electionsPerPage,
    $electionOffset
);
$electionStmt->execute();
$elections = $electionStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$electionStmt->close();

$eligibleStudents = $conn->query(
    "SELECT id, student_id, first_name, last_name
     FROM users WHERE role = 'student' AND is_verified = 1 AND is_active = 1 ORDER BY last_name, first_name"
)->fetch_all(MYSQLI_ASSOC);

$search = trim(requestString($_GET, 'q'));
$studentFilter = $_GET['student_filter'] ?? 'all';
if (!in_array($studentFilter, ['all', 'active', 'inactive'], true)) {
    $studentFilter = 'all';
}
$studentSort = requestString($_GET, 'student_sort');
$studentSorts = [
    'name_asc' => 'u.first_name ASC, u.middle_name ASC, u.last_name ASC, u.id ASC',
    'name_desc' => 'u.first_name DESC, u.middle_name DESC, u.last_name DESC, u.id DESC',
    'student_id_asc' => 'u.student_id ASC, u.id ASC',
    'student_id_desc' => 'u.student_id DESC, u.id DESC',
    'registered_desc' => 'u.created_at DESC, u.id DESC',
    'registered_asc' => 'u.created_at ASC, u.id ASC'
];
if (!isset($studentSorts[$studentSort])) {
    $studentSort = 'registered_desc';
}
$studentOrderBy = $studentSorts[$studentSort];
$studentAccountCondition = $studentFilter === 'active'
    ? 'AND u.is_active = 1'
    : ($studentFilter === 'inactive' ? 'AND u.is_active = 0' : '');
$studentPage = filter_var($_GET['student_page'] ?? 1, FILTER_VALIDATE_INT);
$studentPage = $studentPage && $studentPage > 0 ? (int) $studentPage : 1;
$studentsPerPage = 10;
$studentCountStmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM users u
     WHERE u.role = 'student'
       $studentAccountCondition
       AND (? = '' OR u.student_id LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?
            OR CONCAT_WS(' ', u.first_name, NULLIF(u.middle_name, ''), u.last_name) LIKE ?)"
);
$searchLike = '%' . $search . '%';
$studentCountStmt->bind_param(
    'ssssss',
    $search,
    $searchLike,
    $searchLike,
    $searchLike,
    $searchLike,
    $searchLike
);
$studentCountStmt->execute();
$studentCount = (int) $studentCountStmt->get_result()->fetch_assoc()['total'];
$studentCountStmt->close();
$studentPageCount = max(1, (int) ceil($studentCount / $studentsPerPage));
$studentPage = min($studentPage, $studentPageCount);
$studentOffset = ($studentPage - 1) * $studentsPerPage;
$studentStmt = $conn->prepare(
    "SELECT u.id, u.student_id, u.first_name, u.middle_name, u.last_name, u.email, u.department,
            u.is_verified, u.is_active, u.created_at,
            (EXISTS(SELECT 1 FROM legacy_voter_participation lvp WHERE lvp.student_id = u.student_id)
             OR EXISTS(SELECT 1 FROM election_participation ep WHERE ep.user_id = u.id)) AS has_voted,
            (SELECT COUNT(*) FROM election_participation ep WHERE ep.user_id = u.id) AS election_participation_count
     FROM users u
     WHERE u.role = 'student'
       $studentAccountCondition
       AND (? = '' OR u.student_id LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?
            OR CONCAT_WS(' ', u.first_name, NULLIF(u.middle_name, ''), u.last_name) LIKE ?)
     ORDER BY $studentOrderBy
     LIMIT ? OFFSET ?"
);
$studentStmt->bind_param(
    'ssssssii',
    $search,
    $searchLike,
    $searchLike,
    $searchLike,
    $searchLike,
    $searchLike,
    $studentsPerPage,
    $studentOffset
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
     ORDER BY p.sort_order, p.id, c.name, c.id
     LIMIT ? OFFSET ?'
);
foreach ($elections as &$electionRow) {
    $electionId = (int) $electionRow['id'];
    $candidatePage = filter_var($candidatePageOptions[$electionId] ?? 1, FILTER_VALIDATE_INT);
    $candidatePage = $candidatePage && $candidatePage > 0 ? (int) $candidatePage : 1;
    $candidateCount = (int) $electionRow['matching_candidate_count'];
    $candidatePages = max(1, (int) ceil($candidateCount / $candidatePageSize));
    $candidatePage = min($candidatePage, $candidatePages);
    $candidateOffset = ($candidatePage - 1) * $candidatePageSize;
    $electionRow['candidate_page'] = $candidatePage;
    $electionRow['candidate_pages'] = $candidatePages;
    $electionRow['candidate_offset'] = $candidateOffset;
    $candidateStmt->bind_param(
        'isssiiii',
        $electionId,
        $candidateSearch,
        $candidateSearchLike,
        $candidateSearchLike,
        $candidatePositionId,
        $candidatePositionId,
        $candidatePageSize,
        $candidateOffset
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
        .student-section-heading, .student-results-heading { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        .student-section-heading { margin-bottom: 20px; }
        .student-section-heading h2, .student-results-heading h3 { margin: 0; color: #26354d; }
        .student-section-heading p { margin: 6px 0 0; }
        .student-summary { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin: 0 0 20px; }
        .student-summary-card { display: flex; min-width: 0; align-items: center; gap: 12px; padding: 16px; border: 1px solid #e8ebf2; border-radius: 12px; background: #fbfcff; }
        .student-summary-icon { display: grid; width: 40px; height: 40px; flex: 0 0 40px; place-items: center; border-radius: 11px; background: #eef0ff; color: #5368d5; font-size: 19px; font-weight: 700; }
        .student-summary-card.is-active .student-summary-icon { background: #e9f7ef; color: #228451; }
        .student-summary-card.is-inactive .student-summary-icon { background: #fff0ed; color: #b34a3e; }
        .student-summary-card.is-recent .student-summary-icon { background: #fff6e7; color: #a96a17; }
        .student-summary-card strong, .student-summary-card div span { display: block; }
        .student-summary-card strong { color: #26354d; font-size: 23px; line-height: 1.2; }
        .student-summary-card div span { margin-top: 4px; color: #6c788c; font-size: 12px; }
        .student-filter-actions { display: flex; flex-wrap: wrap; align-items: end; gap: 8px; }
        .button-secondary { background: #64748b !important; }
        .student-results-heading { margin: 24px 0 12px; }
        .student-results-heading h3 { font-size: 16px; }
        .student-results-heading > span { color: #6c788c; font-size: 13px; }
        .student-table-wrap { width: 100%; overflow-x: auto; border: 1px solid #e8ebf2; border-radius: 10px; }
        .student-table { width: 100%; border-collapse: collapse; text-align: left; }
        .student-table th, .student-table td { padding: 13px 12px; border-bottom: 1px solid #edf0f5; vertical-align: middle; }
        .student-table th { background: #f8f9fc; color: #69758a; font-size: 11px; font-weight: 700; letter-spacing: .045em; text-transform: uppercase; white-space: nowrap; }
        .student-table td { color: #3a475b; font-size: 13px; }
        .student-table tbody tr:last-child td { border-bottom: 0; }
        .student-table tbody tr:hover { background: #fbfcff; }
        .student-identity { display: flex; min-width: 155px; align-items: center; gap: 9px; }
        .student-identity strong { color: #2b384d; }
        .student-avatar { display: grid; width: 34px; height: 34px; flex: 0 0 34px; place-items: center; border-radius: 50%; background: #edf0ff; color: #5368d5; font-size: 11px; font-weight: 700; }
        .student-email { color: #5266cb; text-decoration: none; overflow-wrap: anywhere; }
        .student-email:hover { text-decoration: underline; }
        .student-status { display: inline-flex; min-height: 25px; align-items: center; padding: 3px 9px; border-radius: 20px; font-size: 11px; font-weight: 700; white-space: nowrap; }
        .student-status.is-active { background: #e9f7ef; color: #21794b; }
        .student-status.is-inactive { background: #fff0ed; color: #a63e36; }
        .student-actions { display: flex; min-width: 145px; flex-wrap: wrap; align-items: center; gap: 7px; }
        .student-action-details { position: relative; }
        .student-action-details > summary { display: inline-flex; min-height: 32px; align-items: center; padding: 6px 10px; border: 1px solid #dfe4ef; border-radius: 7px; background: #fff; color: #4d5cc1; cursor: pointer; font-size: 12px; font-weight: 650; list-style: none; }
        .student-action-details > summary::-webkit-details-marker { display: none; }
        .student-action-details > summary:hover { background: #f4f5ff; }
        .student-details-panel, .student-edit-form { position: absolute; z-index: 5; top: 38px; right: 0; width: min(330px, calc(100vw - 54px)); padding: 16px; border: 1px solid #e1e5ef; border-radius: 11px; background: #fff; box-shadow: 0 12px 32px rgba(32, 45, 74, .16); }
        .student-details-panel h4 { margin: 0 0 12px; color: #26354d; font-size: 15px; }
        .student-details-panel dl { display: grid; grid-template-columns: 1fr 1.3fr; gap: 8px 12px; margin: 0; font-size: 12px; }
        .student-details-panel dt { color: #738096; }
        .student-details-panel dd { margin: 0; color: #2f3c50; font-weight: 600; overflow-wrap: anywhere; }
        .student-details-panel p { margin: 14px 0 0; font-size: 11px; }
        .student-edit-form { display: grid; gap: 9px; }
        .student-edit-form label { margin: 0; color: #46536a; font-size: 12px; }
        .student-edit-form input, .student-edit-form select { min-height: 36px; margin-top: 4px; padding: 7px 9px; font-size: 12px; }
        .student-edit-form .muted { margin: 0; font-size: 11px; }
        .student-action-form { margin: 0; }
        .student-action-link { padding: 5px 2px; background: none; color: #4d5fc8; font-size: 11px; }
        .student-action-link:hover { background: none; text-decoration: underline; }
        .student-action-link.is-danger { color: #a63e36; }
        .student-empty-state { display: grid; justify-items: center; gap: 8px; padding: 36px 16px; border: 1px dashed #dce2ed; border-radius: 10px; text-align: center; }
        .student-empty-state > span { color: #8793a7; font-size: 30px; }
        .student-empty-state strong { color: #334158; }
        .student-empty-state p { margin: 0; color: #758198; font-size: 13px; }
        .student-empty-state a { color: #4d5fc8; font-size: 13px; }
        @media(max-width:600px) { body { padding: 10px; } header { align-items: flex-start; flex-direction: column; gap: 10px; } }
        @media(max-width:900px) { .student-summary { grid-template-columns: repeat(2, minmax(0,1fr)); } }
        @media(max-width:640px) {
            .student-section-heading { align-items: flex-start; flex-direction: column; }
            .student-summary-card { gap: 9px; padding: 12px; }
            .student-summary-icon { width: 34px; height: 34px; flex-basis: 34px; }
            .student-summary-card strong { font-size: 20px; }
            .student-summary-card div span { font-size: 11px; }
            .student-filter-actions > * { flex: 1 1 auto; text-align: center; }
            .student-results-heading { align-items: flex-start; flex-direction: column; gap: 5px; }
            .student-table-wrap { overflow: visible; border: 0; }
            .student-table, .student-table tbody, .student-table tr, .student-table td { display: block; width: 100%; }
            .student-table thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; }
            .student-table tbody { display: grid; gap: 12px; }
            .student-table tbody tr { padding: 8px 13px; border: 1px solid #e8ebf2; border-radius: 10px; background: #fff; }
            .student-table td { display: grid; grid-template-columns: 112px minmax(0,1fr); gap: 8px; padding: 9px 0; border-bottom: 1px solid #f0f2f6; overflow-wrap: anywhere; }
            .student-table td:last-child { border-bottom: 0; }
            .student-table td::before { content: attr(data-label); color: #758198; font-size: 11px; font-weight: 650; }
            .student-table td[data-label="Student"] { display: block; }
            .student-table td[data-label="Student"]::before { content: none; }
            .student-actions { min-width: 0; }
            .student-details-panel, .student-edit-form { right: auto; left: 0; }
        }
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

    <?php if ($activeNavigation === 'admin-dashboard'): ?>
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
    <?php endif; ?>

    <section id="student-management">
        <div class="student-section-heading">
            <div>
                <h2>Student Management</h2>
                <p class="muted">View, search, and manage registered students in the online voting system.</p>
            </div>
            <button class="button student-add-button" type="button" data-student-add-open>＋ Add Student</button>
        </div>
        <div class="student-summary" aria-label="Student account summary">
            <article class="student-summary-card">
                <span class="student-summary-icon" aria-hidden="true">♙</span>
                <div><strong><?php echo (int) $studentStats['total_students']; ?></strong><span>Total Students</span></div>
            </article>
            <article class="student-summary-card is-active">
                <span class="student-summary-icon" aria-hidden="true">✓</span>
                <div><strong><?php echo (int) $studentStats['active_students']; ?></strong><span>Active Students</span></div>
            </article>
            <article class="student-summary-card is-inactive">
                <span class="student-summary-icon" aria-hidden="true">–</span>
                <div><strong><?php echo (int) $studentStats['inactive_students']; ?></strong><span>Inactive Students</span></div>
            </article>
            <article class="student-summary-card is-recent">
                <span class="student-summary-icon" aria-hidden="true">◷</span>
                <div><strong><?php echo (int) $studentStats['recently_registered']; ?></strong><span>Registered in last 30 days</span></div>
            </article>
        </div>
        <form method="GET" class="grid">
            <input type="hidden" name="nav" value="students">
            <div><label for="q">Search by student, ID, or email</label><input id="q" name="q" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Enter a name, Student ID, or email"></div>
            <div><label for="student_filter">Account status</label><select id="student_filter" name="student_filter">
                <?php foreach (['all' => 'All students', 'active' => 'Active', 'inactive' => 'Inactive'] as $value => $label): ?>
                    <option value="<?php echo $value; ?>" <?php echo $studentFilter === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select></div>
            <div><label for="student_sort">Sort by</label><select id="student_sort" name="student_sort">
                <?php foreach ([
                    'registered_desc' => 'Newest registrations',
                    'registered_asc' => 'Oldest registrations',
                    'name_asc' => 'Name A–Z',
                    'name_desc' => 'Name Z–A',
                    'student_id_asc' => 'Student ID A–Z',
                    'student_id_desc' => 'Student ID Z–A'
                ] as $value => $label): ?>
                    <option value="<?php echo $value; ?>" <?php echo $studentSort === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select></div>
            <div class="student-filter-actions">
                <button type="submit">Apply filters</button>
                <a class="button button-secondary" href="admin.php?nav=students#student-management">Clear filters</a>
            </div>
        </form>
        <details id="student-add-details">
            <summary>Add student account</summary>
            <p class="muted">The initial password is securely hashed. New accounts require administrator verification before the student can participate in elections. Share the initial password privately.</p>
            <form method="POST" class="grid">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="add_student">
                <div><label for="new_student_first_name">First name *</label><input id="new_student_first_name" name="first_name" maxlength="50" autocomplete="given-name" required></div>
                <div><label for="new_student_middle_name">Middle name</label><input id="new_student_middle_name" name="middle_name" maxlength="50" autocomplete="additional-name"></div>
                <div><label for="new_student_last_name">Last name *</label><input id="new_student_last_name" name="last_name" maxlength="50" autocomplete="family-name" required></div>
                <div><label for="new_student_email">Email *</label><input id="new_student_email" type="email" name="email" maxlength="100" autocomplete="email" required></div>
                <div><label for="new_student_id">Student ID *</label><input id="new_student_id" name="student_id" minlength="3" maxlength="20" required></div>
                <div><label for="new_student_department">Faculty / class *</label><select id="new_student_department" name="department" required>
                    <option value="">Select department</option>
                    <?php foreach (['Computer Engineering', 'Civil Engineering', 'Electrical Engineering', 'Mechanical Engineering'] as $department): ?>
                        <option value="<?php echo htmlspecialchars($department, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($department, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select></div>
                <div><label for="new_student_password">Initial password (8+ characters) *</label><input id="new_student_password" type="password" name="password" minlength="8" autocomplete="new-password" required></div>
                <div><label for="new_student_password_confirmation">Confirm initial password *</label><input id="new_student_password_confirmation" type="password" name="password_confirmation" minlength="8" autocomplete="new-password" required></div>
                <div style="align-self:end"><button type="submit">Add student</button></div>
            </form>
        </details>
        <div class="student-results-heading">
            <h3>Registered students</h3>
            <span><?php echo $studentCount; ?> matching record<?php echo $studentCount === 1 ? '' : 's'; ?></span>
        </div>
        <?php if (!$students): ?>
            <div class="student-empty-state">
                <span aria-hidden="true">⌕</span>
                <strong>No students found</strong>
                <p>Try a different name, Student ID, email address, or account-status filter.</p>
                <a href="admin.php?nav=students#student-management">Clear search and filters</a>
            </div>
        <?php else: ?>
            <div class="student-table-wrap">
                <table class="student-table">
                    <thead><tr>
                        <th scope="col">Student</th>
                        <th scope="col">Student ID</th>
                        <th scope="col">Email</th>
                        <th scope="col">Faculty / class</th>
                        <th scope="col">Registered on</th>
                        <th scope="col">Status</th>
                        <th scope="col">Actions</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($students as $student): ?>
                        <?php
                        $fullName = trim(implode(' ', array_filter([
                            $student['first_name'],
                            $student['middle_name'] ?? '',
                            $student['last_name']
                        ], function ($part) { return trim((string) $part) !== ''; })));
                        $studentInitials = strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1));
                        $registeredAt = new DateTimeImmutable($student['created_at']);
                        ?>
                        <tr>
                            <td data-label="Student">
                                <div class="student-identity">
                                    <span class="student-avatar" aria-hidden="true"><?php echo htmlspecialchars($studentInitials, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <strong><?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?></strong>
                                </div>
                            </td>
                            <td data-label="Student ID"><?php echo htmlspecialchars($student['student_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td data-label="Email"><a class="student-email" href="mailto:<?php echo htmlspecialchars($student['email'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($student['email'], ENT_QUOTES, 'UTF-8'); ?></a></td>
                            <td data-label="Faculty / class"><?php echo htmlspecialchars($student['department'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td data-label="Registered on"><time datetime="<?php echo htmlspecialchars($registeredAt->format(DateTimeInterface::ATOM), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($registeredAt->format('M j, Y'), ENT_QUOTES, 'UTF-8'); ?></time></td>
                            <td data-label="Status"><span class="student-status <?php echo $student['is_active'] ? 'is-active' : 'is-inactive'; ?>"><?php echo $student['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                            <td data-label="Actions">
                                <div class="student-actions">
                                    <details class="student-action-details">
                                        <summary>View</summary>
                                        <div class="student-details-panel">
                                            <h4><?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?></h4>
                                            <dl>
                                                <dt>Student ID</dt><dd><?php echo htmlspecialchars($student['student_id'], ENT_QUOTES, 'UTF-8'); ?></dd>
                                                <dt>Email address</dt><dd><?php echo htmlspecialchars($student['email'], ENT_QUOTES, 'UTF-8'); ?></dd>
                                                <dt>Faculty / class</dt><dd><?php echo htmlspecialchars($student['department'], ENT_QUOTES, 'UTF-8'); ?></dd>
                                                <dt>Registered</dt><dd><?php echo htmlspecialchars($registeredAt->format('F j, Y \a\t g:i A'), ENT_QUOTES, 'UTF-8'); ?></dd>
                                                <dt>Account status</dt><dd><?php echo $student['is_active'] ? 'Active' : 'Inactive'; ?></dd>
                                                <dt>Election eligibility</dt><dd><?php echo $student['is_verified'] ? 'Verified' : 'Awaiting verification'; ?></dd>
                                                <dt>Election participation</dt><dd><?php echo (int) $student['election_participation_count']; ?> election<?php echo (int) $student['election_participation_count'] === 1 ? '' : 's'; ?></dd>
                                            </dl>
                                            <p class="muted">Ballot selections are private and are not displayed here.</p>
                                        </div>
                                    </details>
                                    <details class="student-action-details">
                                        <summary>Edit</summary>
                                        <form method="POST" class="student-edit-form">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="update_student">
                                            <input type="hidden" name="user_id" value="<?php echo (int) $student['id']; ?>">
                                            <label>First name<input name="first_name" maxlength="50" required value="<?php echo htmlspecialchars($student['first_name'], ENT_QUOTES, 'UTF-8'); ?>"></label>
                                            <label>Middle name<input name="middle_name" maxlength="50" value="<?php echo htmlspecialchars($student['middle_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></label>
                                            <label>Last name<input name="last_name" maxlength="50" required value="<?php echo htmlspecialchars($student['last_name'], ENT_QUOTES, 'UTF-8'); ?>"></label>
                                            <label>Email<input type="email" name="email" maxlength="100" required value="<?php echo htmlspecialchars($student['email'], ENT_QUOTES, 'UTF-8'); ?>"></label>
                                            <label>Faculty / class<select name="department" required>
                                                <?php foreach (['Computer Engineering', 'Civil Engineering', 'Electrical Engineering', 'Mechanical Engineering'] as $department): ?>
                                                    <option value="<?php echo htmlspecialchars($department, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $student['department'] === $department ? 'selected' : ''; ?>><?php echo htmlspecialchars($department, ENT_QUOTES, 'UTF-8'); ?></option>
                                                <?php endforeach; ?>
                                            </select></label>
                                            <p class="muted">Passwords and Student IDs are not changed by this form.</p>
                                            <button type="submit">Save changes</button>
                                        </form>
                                    </details>
                                    <?php if (!(int) $student['is_verified']): ?>
                                        <form method="POST" class="student-action-form">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="verify_student"><input type="hidden" name="user_id" value="<?php echo (int) $student['id']; ?>"><input type="hidden" name="verified" value="1">
                                            <button class="student-action-link" type="submit">Verify eligibility</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" class="student-action-form" onsubmit="return confirm('<?php echo $student['is_active'] ? 'Deactivate' : 'Activate'; ?> this student account? <?php echo $student['is_active'] ? 'They will no longer be able to sign in, but existing participation records will be preserved.' : 'They can sign in again once their account is verified.'; ?>');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="action" value="set_student_active"><input type="hidden" name="user_id" value="<?php echo (int) $student['id']; ?>"><input type="hidden" name="is_active" value="<?php echo $student['is_active'] ? '0' : '1'; ?>">
                                        <button class="student-action-link <?php echo $student['is_active'] ? 'is-danger' : ''; ?>" type="submit"><?php echo $student['is_active'] ? 'Deactivate' : 'Activate'; ?></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php if ($studentCount > 0): ?>
            <?php
            $studentFirst = $studentOffset + 1;
            $studentLast = $studentOffset + count($students);
            $studentPageParams = [
                'nav' => 'students',
                'q' => $search,
                'student_filter' => $studentFilter,
                'student_sort' => $studentSort
            ];
            ?>
            <div class="pagination-summary">
                <span>Showing <?php echo $studentFirst; ?>–<?php echo $studentLast; ?> of <?php echo $studentCount; ?> students</span>
                <nav class="pagination" aria-label="Student pages">
                    <?php if ($studentPage > 1): ?>
                        <a href="admin.php?<?php echo htmlspecialchars(http_build_query($studentPageParams + ['student_page' => $studentPage - 1]), ENT_QUOTES, 'UTF-8'); ?>#student-management">Previous</a>
                    <?php else: ?><span aria-disabled="true">Previous</span><?php endif; ?>
                    <span aria-current="page">Page <?php echo $studentPage; ?> of <?php echo $studentPageCount; ?></span>
                    <?php if ($studentPage < $studentPageCount): ?>
                        <a href="admin.php?<?php echo htmlspecialchars(http_build_query($studentPageParams + ['student_page' => $studentPage + 1]), ENT_QUOTES, 'UTF-8'); ?>#student-management">Next</a>
                    <?php else: ?><span aria-disabled="true">Next</span><?php endif; ?>
                </nav>
            </div>
        <?php endif; ?>
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
            <input type="hidden" name="nav" value="elections">
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
                <?php if ((int) $election['matching_candidate_count'] > 0): ?>
                    <?php
                    $candidateFirst = $election['candidate_offset'] + 1;
                    $candidateLast = $election['candidate_offset'] + count($election['candidates']);
                    $candidatePageParams = [
                        'nav' => 'candidates',
                        'election_q' => $electionSearch,
                        'election_status' => $electionStatus,
                        'candidate_q' => $candidateSearch,
                        'candidate_position' => $candidatePositionId,
                        'election_page' => $electionPage,
                        'candidate_page' => $candidatePageOptions
                    ];
                    ?>
                    <div class="pagination-summary">
                        <span>Showing <?php echo $candidateFirst; ?>–<?php echo $candidateLast; ?> of <?php echo (int) $election['matching_candidate_count']; ?> matching candidates</span>
                        <nav class="pagination" aria-label="Candidate pages for <?php echo htmlspecialchars($election['title'], ENT_QUOTES, 'UTF-8'); ?>">
                            <?php if ($election['candidate_page'] > 1): ?>
                                <?php $candidatePageParams['candidate_page'][(int) $election['id']] = $election['candidate_page'] - 1; ?>
                                <a href="admin.php?<?php echo htmlspecialchars(http_build_query($candidatePageParams), ENT_QUOTES, 'UTF-8'); ?>#candidate-management">Previous</a>
                            <?php else: ?><span aria-disabled="true">Previous</span><?php endif; ?>
                            <span aria-current="page">Page <?php echo (int) $election['candidate_page']; ?> of <?php echo (int) $election['candidate_pages']; ?></span>
                            <?php if ($election['candidate_page'] < $election['candidate_pages']): ?>
                                <?php $candidatePageParams['candidate_page'][(int) $election['id']] = $election['candidate_page'] + 1; ?>
                                <a href="admin.php?<?php echo htmlspecialchars(http_build_query($candidatePageParams), ENT_QUOTES, 'UTF-8'); ?>#candidate-management">Next</a>
                            <?php else: ?><span aria-disabled="true">Next</span><?php endif; ?>
                        </nav>
                    </div>
                <?php endif; ?>

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
        <?php if ($electionCount > 0): ?>
            <?php
            $electionFirst = $electionOffset + 1;
            $electionLast = $electionOffset + count($elections);
            $electionPageParams = [
                'nav' => 'elections',
                'election_q' => $electionSearch,
                'election_status' => $electionStatus,
                'candidate_q' => $candidateSearch,
                'candidate_position' => $candidatePositionId
            ];
            ?>
            <div class="pagination-summary">
                <span>Showing <?php echo $electionFirst; ?>–<?php echo $electionLast; ?> of <?php echo $electionCount; ?> elections</span>
                <nav class="pagination" aria-label="Election pages">
                    <?php if ($electionPage > 1): ?>
                        <a href="admin.php?<?php echo htmlspecialchars(http_build_query($electionPageParams + ['election_page' => $electionPage - 1]), ENT_QUOTES, 'UTF-8'); ?>#elections">Previous</a>
                    <?php else: ?><span aria-disabled="true">Previous</span><?php endif; ?>
                    <span aria-current="page">Page <?php echo $electionPage; ?> of <?php echo $electionPageCount; ?></span>
                    <?php if ($electionPage < $electionPageCount): ?>
                        <a href="admin.php?<?php echo htmlspecialchars(http_build_query($electionPageParams + ['election_page' => $electionPage + 1]), ENT_QUOTES, 'UTF-8'); ?>#elections">Next</a>
                    <?php else: ?><span aria-disabled="true">Next</span><?php endif; ?>
                </nav>
            </div>
        <?php endif; ?>
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
