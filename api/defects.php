<?php
/**
 * Defects API Endpoints
 * Handles CRUD operations for defect reports
 * 
 * LEARNING POINTS:
 * - File upload handling
 * - Database transactions
 * - Pagination
 * - Advanced queries with JOINs
 * - Role-based filtering
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';

startSession();
requireLogin();

$request_method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$defect_id = $_GET['id'] ?? null;

try {
    switch ($request_method) {
        case 'POST':
            handlePostDefects($action, $conn);
            break;
        case 'GET':
            handleGetDefects($action, $defect_id, $conn);
            break;
        case 'PUT':
            handlePutDefects($action, $defect_id, $conn);
            break;
        case 'DELETE':
            handleDeleteDefects($defect_id, $conn);
            break;
        default:
            sendError('Method not allowed', 405);
    }
} catch (Exception $e) {
    error_log('Defects API Error: ' . $e->getMessage());
    sendError($e->getMessage(), 500);
}

/**
 * Handle POST requests (create defect)
 */
function handlePostDefects($action, $conn) {
    requirePermission('create_defect');
    
    switch ($action) {
        case 'create':
            createDefect($conn);
            break;
        default:
            sendError('Invalid action', 400);
    }
}

/**
 * Handle GET requests (list, search, filter)
 */
function handleGetDefects($action, $defect_id, $conn) {
    switch ($action) {
        case 'list':
            listDefects($conn);
            break;
        case 'get':
            if (!$defect_id) sendError('Defect ID required', 400);
            getDefectDetail($defect_id, $conn);
            break;
        case 'search':
            searchDefects($conn);
            break;
        case 'statistics':
            getDefectStatistics($conn);
            break;
        default:
            sendError('Invalid action', 400);
    }
}

/**
 * CREATE DEFECT
 * 
 * LEARNING POINT:
 * This demonstrates:
 * - File upload with validation
 * - Unique ID generation
 * - Transaction-like operations
 * - Email notification sending
 */
function createDefect($conn) {
    $user = getCurrentUser();
    
    // Get form data
    $data = [
        'title' => $_POST['title'] ?? '',
        'description' => $_POST['description'] ?? '',
        'location_id' => $_POST['location_id'] ?? '',
        'category_id' => $_POST['category_id'] ?? '',
        'priority' => $_POST['priority'] ?? 'medium',
        'estimated_cost' => $_POST['estimated_cost'] ?? 0,
    ];
    
    // Validate required fields
    $errors = validateDefectData($data);
    if (!empty($errors)) {
        sendError(implode(', ', $errors), 400);
    }
    
    // Sanitize input
    $title = sanitizeInput($data['title']);
    $description = sanitizeInput($data['description']);
    $location_id = (int)$data['location_id'];
    $category_id = (int)$data['category_id'];
    $priority = sanitizeInput($data['priority']);
    $estimated_cost = (float)$data['estimated_cost'];
    $report_number = generateReportNumber();
    
    $photo_path = null;
    
    // Handle file upload
    if (isset($_FILES['photo']) && $_FILES['photo']['size'] > 0) {
        try {
            $photo_path = uploadFile($_FILES['photo']);
        } catch (Exception $e) {
            sendError('Photo upload failed: ' . $e->getMessage(), 400);
        }
    }
    
    try {
        // Insert defect
        $stmt = executeQuery($conn,
            "INSERT INTO defects 
             (report_number, location_id, category_id, title, description, reported_by, priority, status, photo_path, estimated_cost) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$report_number, $location_id, $category_id, $title, $description, $user['id'], $priority, 'reported', $photo_path, $estimated_cost],
            'siiissdssd'
        );
        
        $defect_id = getLastInsertId($conn);
        $stmt->close();
        
        // Log activity
        logActivity($user['id'], 'Create Defect', $defect_id);
        
        // Send notification to managers/admins
        sendDefectNotification($defect_id, 'new_defect', $conn);
        
        // Fetch and return the created defect
        $stmt = executeQuery($conn,
            "SELECT d.*, l.name as location_name, dc.name as category_name, 
                    CONCAT(u.first_name, ' ', u.last_name) as reported_by_name
             FROM defects d
             JOIN locations l ON d.location_id = l.id
             JOIN defect_categories dc ON d.category_id = dc.id
             JOIN users u ON d.reported_by = u.id
             WHERE d.id = ?",
            [$defect_id],
            'i'
        );
        
        $defect = fetchSingleRow($stmt);
        $stmt->close();
        
        sendSuccess($defect, 'Defect reported successfully');
        
    } catch (Exception $e) {
        // Delete uploaded file if database insert fails
        if ($photo_path) {
            deleteFile($photo_path);
        }
        throw $e;
    }
}

/**
 * LIST DEFECTS
 * With pagination and role-based filtering
 */
function listDefects($conn) {
    $user = getCurrentUser();
    $page = (int)($_GET['page'] ?? 1);
    $page = max(1, $page);
    $offset = ($page - 1) * ITEMS_PER_PAGE;
    
    try {
        // Build query based on role
        $where_clause = "WHERE 1=1";
        $params = [];
        $types = '';
        
        // Staff can only see their own defects
        if ($user['role'] === 'staff') {
            $where_clause .= " AND d.reported_by = ?";
            $params[] = $user['id'];
            $types .= 'i';
        }
        // Maintenance can only see assigned defects
        else if ($user['role'] === 'maintenance') {
            $where_clause .= " AND d.assigned_to = ?";
            $params[] = $user['id'];
            $types .= 'i';
        }
        // Managers and Admins see all
        
        // Get total count
        $count_stmt = executeQuery($conn,
            "SELECT COUNT(*) as total FROM defects d " . $where_clause,
            $params,
            $types
        );
        $count_result = fetchSingleRow($count_stmt);
        $total = $count_result['total'];
        $count_stmt->close();
        
        // Get paginated results
        $stmt = executeQuery($conn,
            "SELECT d.id, d.report_number, d.title, d.priority, d.status, d.created_at,
                    l.name as location_name, dc.name as category_name,
                    CONCAT(u.first_name, ' ', u.last_name) as reported_by_name,
                    CONCAT(m.first_name, ' ', m.last_name) as assigned_to_name
             FROM defects d
             JOIN locations l ON d.location_id = l.id
             JOIN defect_categories dc ON d.category_id = dc.id
             JOIN users u ON d.reported_by = u.id
             LEFT JOIN users m ON d.assigned_to = m.id
             " . $where_clause . "
             ORDER BY d.created_at DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$page, $offset]),
            $types . 'ii'
        );
        
        $defects = fetchAllRows($stmt);
        $stmt->close();
        
        $total_pages = ceil($total / ITEMS_PER_PAGE);
        
        sendSuccess([
            'defects' => $defects,
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

/**
 * GET DEFECT DETAIL
 */
function getDefectDetail($defect_id, $conn) {
    $user = getCurrentUser();
    $defect_id = (int)$defect_id;
    
    try {
        // Get defect details
        $stmt = executeQuery($conn,
            "SELECT d.*, l.name as location_name, dc.name as category_name,
                    CONCAT(u.first_name, ' ', u.last_name) as reported_by_name,
                    CONCAT(m.first_name, ' ', m.last_name) as assigned_to_name
             FROM defects d
             JOIN locations l ON d.location_id = l.id
             JOIN defect_categories dc ON d.category_id = dc.id
             JOIN users u ON d.reported_by = u.id
             LEFT JOIN users m ON d.assigned_to = m.id
             WHERE d.id = ?",
            [$defect_id],
            'i'
        );
        
        $defect = fetchSingleRow($stmt);
        $stmt->close();
        
        if (!$defect) {
            sendError('Defect not found', 404);
        }
        
        // Check permissions
        if ($user['role'] === 'staff' && $defect['reported_by'] != $user['id']) {
            sendError('Unauthorized', 403);
        }
        if ($user['role'] === 'maintenance' && $defect['assigned_to'] != $user['id']) {
            sendError('Unauthorized', 403);
        }
        
        // Get defect history
        $history_stmt = executeQuery($conn,
            "SELECT * FROM defect_history WHERE defect_id = ? ORDER BY change_date DESC",
            [$defect_id],
            'i'
        );
        $defect['history'] = fetchAllRows($history_stmt);
        $history_stmt->close();
        
        // Get comments
        $comments_stmt = executeQuery($conn,
            "SELECT dc.*, CONCAT(u.first_name, ' ', u.last_name) as user_name
             FROM defect_comments dc
             JOIN users u ON dc.user_id = u.id
             WHERE dc.defect_id = ?
             ORDER BY dc.created_at DESC",
            [$defect_id],
            'i'
        );
        $defect['comments'] = fetchAllRows($comments_stmt);
        $comments_stmt->close();
        
        sendSuccess($defect);
        
    } catch (Exception $e) {
        throw $e;
    }
}

/**
 * SEARCH DEFECTS
 */
function searchDefects($conn) {
    $query = sanitizeInput($_GET['q'] ?? '');
    $status = sanitizeInput($_GET['status'] ?? '');
    $priority = sanitizeInput($_GET['priority'] ?? '');
    $location_id = (int)($_GET['location_id'] ?? 0);
    
    $user = getCurrentUser();
    $where_clause = "WHERE 1=1";
    $params = [];
    $types = '';
    
    // Search query
    if (!empty($query)) {
        $where_clause .= " AND (d.title LIKE ? OR d.description LIKE ? OR d.report_number LIKE ?)";
        $search_term = '%' . $query . '%';
        $params = array_merge($params, [$search_term, $search_term, $search_term]);
        $types .= 'sss';
    }
    
    // Filter by status
    if (!empty($status)) {
        $where_clause .= " AND d.status = ?";
        $params[] = $status;
        $types .= 's';
    }
    
    // Filter by priority
    if (!empty($priority)) {
        $where_clause .= " AND d.priority = ?";
        $params[] = $priority;
        $types .= 's';
    }
    
    // Filter by location
    if ($location_id > 0) {
        $where_clause .= " AND d.location_id = ?";
        $params[] = $location_id;
        $types .= 'i';
    }
    
    // Role-based filtering
    if ($user['role'] === 'staff') {
        $where_clause .= " AND d.reported_by = ?";
        $params[] = $user['id'];
        $types .= 'i';
    } elseif ($user['role'] === 'maintenance') {
        $where_clause .= " AND d.assigned_to = ?";
        $params[] = $user['id'];
        $types .= 'i';
    }
    
    try {
        $stmt = executeQuery($conn,
            "SELECT d.id, d.report_number, d.title, d.priority, d.status, d.created_at,
                    l.name as location_name, dc.name as category_name
             FROM defects d
             JOIN locations l ON d.location_id = l.id
             JOIN defect_categories dc ON d.category_id = dc.id
             " . $where_clause . "
             ORDER BY d.created_at DESC
             LIMIT 50",
            $params,
            $types
        );
        
        $results = fetchAllRows($stmt);
        $stmt->close();
        
        sendSuccess(['results' => $results, 'count' => count($results)]);
        
    } catch (Exception $e) {
        throw $e;
    }
}

/**
 * GET DEFECT STATISTICS
 * For analytics and dashboard
 */
function getDefectStatistics($conn) {
    try {
        // Total defects by status
        $status_stmt = executeQuery($conn,
            "SELECT status, COUNT(*) as count FROM defects GROUP BY status"
        );
        $by_status = fetchAllRows($status_stmt);
        $status_stmt->close();
        
        // Total defects by priority
        $priority_stmt = executeQuery($conn,
            "SELECT priority, COUNT(*) as count FROM defects GROUP BY priority"
        );
        $by_priority = fetchAllRows($priority_stmt);
        $priority_stmt->close();
        
        // Defects by location
        $location_stmt = executeQuery($conn,
            "SELECT l.name, COUNT(d.id) as count
             FROM locations l
             LEFT JOIN defects d ON l.id = d.location_id
             GROUP BY l.id, l.name"
        );
        $by_location = fetchAllRows($location_stmt);
        $location_stmt->close();
        
        // Average resolution time
        $avg_stmt = executeQuery($conn,
            "SELECT AVG(DATEDIFF(completion_date, created_at)) as avg_days
             FROM defects WHERE completion_date IS NOT NULL"
        );
        $avg_result = fetchSingleRow($avg_stmt);
        $avg_stmt->close();
        
        sendSuccess([
            'by_status' => $by_status,
            'by_priority' => $by_priority,
            'by_location' => $by_location,
            'average_resolution_days' => $avg_result['avg_days'] ?? 0
        ]);
        
    } catch (Exception $e) {
        throw $e;
    }
}

/**
 * Handle PUT requests (update defect)
 */
function handlePutDefects($action, $defect_id, $conn) {
    if (!$defect_id) sendError('Defect ID required', 400);
    
    switch ($action) {
        case 'update':
            updateDefect($defect_id, $conn);
            break;
        case 'update-status':
            updateDefectStatus($defect_id, $conn);
            break;
        default:
            sendError('Invalid action', 400);
    }
}

/**
 * UPDATE DEFECT
 */
function updateDefect($defect_id, $conn) {
    requirePermission('edit_defect');
    
    $defect_id = (int)$defect_id;
    $data = json_decode(file_get_contents('php://input'), true);
    
    try {
        // Get current defect
        $stmt = executeQuery($conn, "SELECT * FROM defects WHERE id = ?", [$defect_id], 'i');
        $current = fetchSingleRow($stmt);
        $stmt->close();
        
        if (!$current) {
            sendError('Defect not found', 404);
        }
        
        // Update fields
        $updates = [];
        $params = [];
        $types = '';
        
        if (isset($data['title'])) {
            $updates[] = 'title = ?';
            $params[] = sanitizeInput($data['title']);
            $types .= 's';
        }
        if (isset($data['description'])) {
            $updates[] = 'description = ?';
            $params[] = sanitizeInput($data['description']);
            $types .= 's';
        }
        if (isset($data['priority'])) {
            $updates[] = 'priority = ?';
            $params[] = sanitizeInput($data['priority']);
            $types .= 's';
        }
        if (isset($data['estimated_cost'])) {
            $updates[] = 'estimated_cost = ?';
            $params[] = (float)$data['estimated_cost'];
            $types .= 'd';
        }
        
        if (empty($updates)) {
            sendError('No fields to update', 400);
        }
        
        $params[] = $defect_id;
        $types .= 'i';
        
        $stmt = executeQuery($conn,
            "UPDATE defects SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = ?",
            $params,
            $types
        );
        $stmt->close();
        
        // Log activity
        logActivity(getCurrentUser()['id'], 'Update Defect', $defect_id);
        
        sendSuccess(['message' => 'Defect updated successfully']);
        
    } catch (Exception $e) {
        throw $e;
    }
}

/**
 * UPDATE DEFECT STATUS
 */
function updateDefectStatus($defect_id, $conn) {
    $defect_id = (int)$defect_id;
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['status'])) {
        sendError('Status is required', 400);
    }
    
    $new_status = sanitizeInput($data['status']);
    $user = getCurrentUser();
    
    try {
        // Get current defect
        $stmt = executeQuery($conn, "SELECT * FROM defects WHERE id = ?", [$defect_id], 'i');
        $defect = fetchSingleRow($stmt);
        $stmt->close();
        
        if (!$defect) {
            sendError('Defect not found', 404);
        }
        
        // Check permissions
        if ($user['role'] === 'maintenance' && $defect['assigned_to'] != $user['id']) {
            sendError('Unauthorized: Not assigned to you', 403);
        }
        if ($user['role'] === 'staff') {
            sendError('Unauthorized: Cannot update status', 403);
        }
        
        $old_status = $defect['status'];
        
        // Update status
        $completion_date = ($new_status === 'resolved') ? 'NOW()' : 'NULL';
        
        $stmt = executeQuery($conn,
            "UPDATE defects SET status = ?, completion_date = IF(? = 'resolved', NOW(), completion_date), updated_at = NOW() WHERE id = ?",
            [$new_status, $new_status, $defect_id],
            'ssi'
        );
        $stmt->close();
        
        // Record in history
        $history_stmt = executeQuery($conn,
            "INSERT INTO defect_history (defect_id, action, old_value, new_value, changed_by) VALUES (?, ?, ?, ?, ?)",
            [$defect_id, 'status_change', $old_status, $new_status, $user['id']],
            'isssi'
        );
        $history_stmt->close();
        
        // Log activity
        logActivity($user['id'], 'Update Defect Status', $defect_id, ['old' => $old_status, 'new' => $new_status]);
        
        // Send notification
        sendDefectNotification($defect_id, 'status_update', $conn, ['new_status' => $new_status]);
        
        sendSuccess(['message' => 'Status updated to ' . $new_status]);
        
    } catch (Exception $e) {
        throw $e;
    }
}

/**
 * Handle DELETE requests
 */
function handleDeleteDefects($defect_id, $conn) {
    requirePermission('delete_defect');
    
    if (!$defect_id) sendError('Defect ID required', 400);
    
    $defect_id = (int)$defect_id;
    
    try {
        // Get defect to get photo path
        $stmt = executeQuery($conn, "SELECT photo_path FROM defects WHERE id = ?", [$defect_id], 'i');
        $defect = fetchSingleRow($stmt);
        $stmt->close();
        
        if (!$defect) {
            sendError('Defect not found', 404);
        }
        
        // Delete defect (cascade will handle related records)
        $stmt = executeQuery($conn, "DELETE FROM defects WHERE id = ?", [$defect_id], 'i');
        $stmt->close();
        
        // Delete photo file
        if ($defect['photo_path']) {
            deleteFile($defect['photo_path']);
        }
        
        // Log activity
        logActivity(getCurrentUser()['id'], 'Delete Defect', $defect_id);
        
        sendSuccess(['message' => 'Defect deleted successfully']);
        
    } catch (Exception $e) {
        throw $e;
    }
}

/**
 * Helper: Send notification when defect is created/updated
 */
function sendDefectNotification($defect_id, $type, $conn, $extra_data = []) {
    // This is called but actual email sending will be in notifications.php
    // For now, just log that a notification should be sent
    logActivity(0, 'Defect Notification Triggered', $defect_id, ['type' => $type]);
}

?>
