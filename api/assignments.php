<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';

startSession();
requireLogin();

$request_method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$assignment_id = $_GET['id'] ?? null;

try {
    switch ($request_method) {
        case 'POST':
            handlePostAssignments($action, $conn);
            break;
        case 'GET':
            handleGetAssignments($action, $assignment_id, $conn);
            break;
        case 'PUT':
            handlePutAssignments($action, $assignment_id, $conn);
            break;
        default:
            sendError('Method not allowed', 405);
    }
} catch (Exception $e) {
    error_log('Assignments API Error: ' . $e->getMessage());
    sendError($e->getMessage(), 500);
}

function handlePostAssignments($action, $conn) {
    requirePermission('assign_defect');
    
    switch ($action) {
        case 'create':
            createAssignment($conn);
            break;
        default:
            sendError('Invalid action', 400);
    }
}

function createAssignment($conn) {
    global $SLA_TIMES;
    
    $data = json_decode(file_get_contents('php://input'), true);
    $user = getCurrentUser();
    
    if (empty($data['defect_id']) || empty($data['assigned_to'])) {
        sendError('Defect ID and assigned_to are required', 400);
    }
    
    $defect_id = (int)$data['defect_id'];
    $assigned_to = (int)$data['assigned_to'];
    
    try {
        $defect_stmt = executeQuery($conn, "SELECT * FROM defects WHERE id = ?", [$defect_id], 'i');
        $defect = fetchSingleRow($defect_stmt);
        $defect_stmt->close();
        
        if (!$defect) {
            sendError('Defect not found', 404);
        }
        
        if ($defect['assigned_to']) {
            sendError('Defect is already assigned', 400);
        }
        
        $maint_stmt = executeQuery($conn,
            "SELECT id FROM users WHERE id = ? AND role = 'maintenance' AND status = 'active'",
            [$assigned_to],
            'i'
        );
        if (!fetchSingleRow($maint_stmt)) {
            $maint_stmt->close();
            sendError('Invalid maintenance person', 400);
        }
        $maint_stmt->close();
        
        $priority = $defect['priority'];
        $sla_hours = $SLA_TIMES[$priority] ?? 24;
        $expected_completion = date('Y-m-d', strtotime('+' . $sla_hours . ' hours'));
        
        $assign_stmt = executeQuery($conn,
            "INSERT INTO assignments (defect_id, assigned_to, assigned_by, expected_completion_date, status) 
             VALUES (?, ?, ?, ?, ?)",
            [$defect_id, $assigned_to, $user['id'], $expected_completion, 'pending'],
            'iiiss'
        );
        
        $assignment_id = getLastInsertId($conn);
        $assign_stmt->close();
        
        $update_stmt = executeQuery($conn,
            "UPDATE defects SET status = 'assigned', assigned_to = ? WHERE id = ?",
            [$assigned_to, $defect_id],
            'ii'
        );
        $update_stmt->close();
        
        $history_stmt = executeQuery($conn,
            "INSERT INTO defect_history (defect_id, action, new_value, changed_by) 
             VALUES (?, ?, ?, ?)",
            [$defect_id, 'assigned', 'Assigned to maintenance ID: ' . $assigned_to, $user['id']],
            'issi'
        );
        $history_stmt->close();
        
        logActivity($user['id'], 'Assign Defect', $defect_id, ['assignment_id' => $assignment_id]);
        
        sendSuccess([
            'assignment_id' => $assignment_id,
            'expected_completion' => $expected_completion
        ], 'Defect assigned successfully');
        
    } catch (Exception $e) {
        throw $e;
    }
}

function handleGetAssignments($action, $assignment_id, $conn) {
    switch ($action) {
        case 'list':
            listAssignments($conn);
            break;
        case 'get':
            if (!$assignment_id) sendError('Assignment ID required', 400);
            getAssignmentDetail($assignment_id, $conn);
            break;
        case 'pending':
            getPendingAssignments($conn);
            break;
        case 'by-user':
            getAssignmentsByUser($conn);
            break;
        default:
            sendError('Invalid action', 400);
    }
}

function listAssignments($conn) {
    $page = (int)($_GET['page'] ?? 1);
    $page = max(1, $page);
    $offset = ($page - 1) * ITEMS_PER_PAGE;
    
    try {
        $count_stmt = executeQuery($conn, "SELECT COUNT(*) as total FROM assignments");
        $count_result = fetchSingleRow($count_stmt);
        $total = $count_result['total'];
        $count_stmt->close();
        
        $stmt = executeQuery($conn,
            "SELECT a.*, d.report_number, d.title, d.priority, d.status as defect_status,
                    CONCAT(u1.first_name, ' ', u1.last_name) as assigned_to_name,
                    CONCAT(u2.first_name, ' ', u2.last_name) as assigned_by_name
             FROM assignments a
             JOIN defects d ON a.defect_id = d.id
             JOIN users u1 ON a.assigned_to = u1.id
             JOIN users u2 ON a.assigned_by = u2.id
             ORDER BY a.assignment_date DESC
             LIMIT ? OFFSET ?",
            [ITEMS_PER_PAGE, $offset],
            'ii'
        );
        
        $assignments = fetchAllRows($stmt);
        $stmt->close();
        
        $total_pages = ceil($total / ITEMS_PER_PAGE);
        
        sendSuccess([
            'assignments' => $assignments,
            'pagination' => [
                'page' => $page,
                'per_page' => ITEMS_PER_PAGE,
                'total' => $total,
                'total_pages' => $total_pages
            ]
        ]);
        
    } catch (Exception $e) {
        throw $e;
    }
}

function getAssignmentDetail($assignment_id, $conn) {
    $assignment_id = (int)$assignment_id;
    
    try {
        $stmt = executeQuery($conn,
            "SELECT a.*, d.*, l.name as location_name, dc.name as category_name,
                    CONCAT(u1.first_name, ' ', u1.last_name) as assigned_to_name,
                    CONCAT(u2.first_name, ' ', u2.last_name) as assigned_by_name
             FROM assignments a
             JOIN defects d ON a.defect_id = d.id
             JOIN locations l ON d.location_id = l.id
             JOIN defect_categories dc ON d.category_id = dc.id
             JOIN users u1 ON a.assigned_to = u1.id
             JOIN users u2 ON a.assigned_by = u2.id
             WHERE a.id = ?",
            [$assignment_id],
            'i'
        );
        
        $assignment = fetchSingleRow($stmt);
        $stmt->close();
        
        if (!$assignment) {
            sendError('Assignment not found', 404);
        }
        
        sendSuccess($assignment);
        
    } catch (Exception $e) {
        throw $e;
    }
}

function getPendingAssignments($conn) {
    try {
        $stmt = executeQuery($conn,
            "SELECT a.*, d.report_number, d.title, d.priority,
                    CONCAT(u.first_name, ' ', u.last_name) as assigned_to_name
             FROM assignments a
             JOIN defects d ON a.defect_id = d.id
             JOIN users u ON a.assigned_to = u.id
             WHERE a.status = 'pending'
             ORDER BY d.priority DESC, a.expected_completion_date ASC"
        );
        
        $assignments = fetchAllRows($stmt);
        $stmt->close();
        
        sendSuccess(['assignments' => $assignments]);
        
    } catch (Exception $e) {
        throw $e;
    }
}

function getAssignmentsByUser($conn) {
    $user = getCurrentUser();
    $user_id = (int)($_GET['user_id'] ?? $user['id']);
    
    try {
        $stmt = executeQuery($conn,
            "SELECT a.*, d.report_number, d.title, d.priority, d.status as defect_status,
                    l.name as location_name
             FROM assignments a
             JOIN defects d ON a.defect_id = d.id
             JOIN locations l ON d.location_id = l.id
             WHERE a.assigned_to = ?
             ORDER BY a.status DESC, a.expected_completion_date ASC",
            [$user_id],
            'i'
        );
        
        $assignments = fetchAllRows($stmt);
        $stmt->close();
        
        sendSuccess(['assignments' => $assignments]);
        
    } catch (Exception $e) {
        throw $e;
    }
}

function handlePutAssignments($action, $assignment_id, $conn) {
    if (!$assignment_id) sendError('Assignment ID required', 400);
    
    switch ($action) {
        case 'accept':
            acceptAssignment($assignment_id, $conn);
            break;
        case 'reject':
            rejectAssignment($assignment_id, $conn);
            break;
        case 'complete':
            completeAssignment($assignment_id, $conn);
            break;
        default:
            sendError('Invalid action', 400);
    }
}

function acceptAssignment($assignment_id, $conn) {
    $assignment_id = (int)$assignment_id;
    $user = getCurrentUser();
    
    try {
        $stmt = executeQuery($conn, "SELECT * FROM assignments WHERE id = ?", [$assignment_id], 'i');
        $assignment = fetchSingleRow($stmt);
        $stmt->close();
        
        if (!$assignment) {
            sendError('Assignment not found', 404);
        }
        
        if ($assignment['assigned_to'] != $user['id']) {
            sendError('Unauthorized', 403);
        }
        
        $update_stmt = executeQuery($conn,
            "UPDATE assignments SET status = 'accepted' WHERE id = ?",
            [$assignment_id],
            'i'
        );
        $update_stmt->close();
        
        $defect_stmt = executeQuery($conn,
            "UPDATE defects SET status = 'in_progress' WHERE id = ?",
            [$assignment['defect_id']],
            'i'
        );
        $defect_stmt->close();
        
        logActivity($user['id'], 'Accept Assignment', $assignment['defect_id']);
        
        sendSuccess(['message' => 'Assignment accepted']);
        
    } catch (Exception $e) {
        throw $e;
    }
}

function rejectAssignment($assignment_id, $conn) {
    $assignment_id = (int)$assignment_id;
    $user = getCurrentUser();
    
    try {
        $stmt = executeQuery($conn, "SELECT * FROM assignments WHERE id = ?", [$assignment_id], 'i');
        $assignment = fetchSingleRow($stmt);
        $stmt->close();
        
        if (!$assignment) {
            sendError('Assignment not found', 404);
        }
        
        if ($assignment['assigned_to'] != $user['id']) {
            sendError('Unauthorized', 403);
        }
        
        $delete_stmt = executeQuery($conn, "DELETE FROM assignments WHERE id = ?", [$assignment_id], 'i');
        $delete_stmt->close();
        
        $reset_stmt = executeQuery($conn,
            "UPDATE defects SET status = 'reported', assigned_to = NULL WHERE id = ?",
            [$assignment['defect_id']],
            'i'
        );
        $reset_stmt->close();
        
        logActivity($user['id'], 'Reject Assignment', $assignment['defect_id']);
        
        sendSuccess(['message' => 'Assignment rejected']);
        
    } catch (Exception $e) {
        throw $e;
    }
}

function completeAssignment($assignment_id, $conn) {
    $assignment_id = (int)$assignment_id;
    $user = getCurrentUser();
    
    try {
        $stmt = executeQuery($conn, "SELECT * FROM assignments WHERE id = ?", [$assignment_id], 'i');
        $assignment = fetchSingleRow($stmt);
        $stmt->close();
        
        if (!$assignment) {
            sendError('Assignment not found', 404);
        }
        
        if ($assignment['assigned_to'] != $user['id']) {
            sendError('Unauthorized', 403);
        }
        
        $update_stmt = executeQuery($conn,
            "UPDATE assignments SET status = 'completed', actual_completion_date = NOW() WHERE id = ?",
            [$assignment_id],
            'i'
        );
        $update_stmt->close();
        
        $defect_stmt = executeQuery($conn,
            "UPDATE defects SET status = 'resolved', completion_date = NOW() WHERE id = ?",
            [$assignment['defect_id']],
            'i'
        );
        $defect_stmt->close();
        
        logActivity($user['id'], 'Complete Assignment', $assignment['defect_id']);
        
        sendSuccess(['message' => 'Assignment completed']);
        
    } catch (Exception $e) {
        throw $e;
    }
}

?>