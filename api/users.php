<?php
/**
 * Users API Endpoints
 * Admin-only endpoints for user management
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';

startSession();
requireLogin();
requirePermission('manage_users');

$request_method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$user_id = $_GET['id'] ?? null;

try {
    switch ($request_method) {
        case 'POST':
            handlePostUsers($action, $conn);
            break;
        case 'GET':
            handleGetUsers($action, $user_id, $conn);
            break;
        case 'PUT':
            handlePutUsers($action, $user_id, $conn);
            break;
        case 'DELETE':
            handleDeleteUsers($user_id, $conn);
            break;
        default:
            sendError('Method not allowed', 405);
    }
} catch (Exception $e) {
    error_log('Users API Error: ' . $e->getMessage());
    sendError($e->getMessage(), 500);
}

function handlePostUsers($action, $conn) {
    switch ($action) {
        case 'create':
            createUser($conn);
            break;
        default:
            sendError('Invalid action', 400);
    }
}

function createUser($conn) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $required = ['first_name', 'last_name', 'email', 'role'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            sendError(ucfirst(str_replace('_', ' ', $field)) . ' is required', 400);
        }
    }
    
    $first_name = sanitizeInput($data['first_name']);
    $last_name = sanitizeInput($data['last_name']);
    $email = sanitizeInput($data['email']);
    $role = sanitizeInput($data['role']);
    $phone = sanitizeInput($data['phone'] ?? '');
    
    if (!validateEmail($email)) {
        sendError('Invalid email format', 400);
    }
    
    $valid_roles = ['student', 'manager', 'maintenance', 'admin'];
    if (!in_array($role, $valid_roles)) {
        sendError('Invalid role. Valid roles: ' . implode(', ', $valid_roles), 400);
    }
    
    $temp_password = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
    $hashed_password = hashPassword($temp_password);
    
    try {
        $check_stmt = executeQuery($conn, "SELECT id FROM users WHERE email = ?", [$email], 's');
        if (fetchSingleRow($check_stmt)) {
            $check_stmt->close();
            sendError('Email already exists', 400);
        }
        $check_stmt->close();
        
        $stmt = executeQuery($conn,
            "INSERT INTO users (first_name, last_name, email, password, role, phone, status) 
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$first_name, $last_name, $email, $hashed_password, $role, $phone, 'active'],
            'sssssss'
        );
        
        $new_user_id = getLastInsertId($conn);
        $stmt->close();
        
        logActivity($_SESSION['user_id'], 'Create User', null, [
            'new_user_id' => $new_user_id,
            'email' => $email,
            'role' => $role
        ]);
        
        sendSuccess([
            'user_id' => $new_user_id,
            'email' => $email,
            'temporary_password' => $temp_password
        ], 'User created successfully');
        
    } catch (Exception $e) {
        throw $e;
    }
}

function handleGetUsers($action, $user_id, $conn) {
    switch ($action) {
        case 'list':
            listUsers($conn);
            break;
        case 'get':
            if (!$user_id) sendError('User ID required', 400);
            getUserDetail($user_id, $conn);
            break;
        case 'by-role':
            getUsersByRole($conn);
            break;
        default:
            sendError('Invalid action', 400);
    }
}

function listUsers($conn) {
    $page = (int)($_GET['page'] ?? 1);
    $page = max(1, $page);
    $role_filter = sanitizeInput($_GET['role'] ?? '');
    $status_filter = sanitizeInput($_GET['status'] ?? '');
    
    $offset = ($page - 1) * ITEMS_PER_PAGE;
    
    try {
        $where = "WHERE 1=1";
        $params = [];
        $types = '';
        
        if (!empty($role_filter)) {
            $where .= " AND role = ?";
            $params[] = $role_filter;
            $types .= 's';
        }
        
        if (!empty($status_filter)) {
            $where .= " AND status = ?";
            $params[] = $status_filter;
            $types .= 's';
        }
        
        $count_stmt = executeQuery($conn,
            "SELECT COUNT(*) as total FROM users " . $where,
            $params,
            $types
        );
        $count_result = fetchSingleRow($count_stmt);
        $total = $count_result['total'];
        $count_stmt->close();
        
        $stmt = executeQuery($conn,
            "SELECT id, first_name, last_name, email, role, phone, status, created_at 
             FROM users " . $where . "
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [ITEMS_PER_PAGE, $offset]),
            $types . 'ii'
        );
        
        $users = fetchAllRows($stmt);
        $stmt->close();
        
        $total_pages = ceil($total / ITEMS_PER_PAGE);
        
        sendSuccess([
            'users' => $users,
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

function getUserDetail($user_id, $conn) {
    $user_id = (int)$user_id;
    
    try {
        $stmt = executeQuery($conn,
            "SELECT id, first_name, last_name, email, role, phone, status, created_at 
             FROM users WHERE id = ?",
            [$user_id],
            'i'
        );
        
        $user = fetchSingleRow($stmt);
        $stmt->close();
        
        if (!$user) {
            sendError('User not found', 404);
        }
        
        sendSuccess($user);
        
    } catch (Exception $e) {
        throw $e;
    }
}

function getUsersByRole($conn) {
    $role = sanitizeInput($_GET['role'] ?? '');
    
    if (empty($role)) {
        sendError('Role parameter required', 400);
    }
    
    $valid_roles = ['student', 'manager', 'maintenance', 'admin'];
    if (!in_array($role, $valid_roles)) {
        sendError('Invalid role', 400);
    }
    
    try {
        $stmt = executeQuery($conn,
            "SELECT id, first_name, last_name, email 
             FROM users 
             WHERE role = ? AND status = 'active'
             ORDER BY first_name, last_name",
            [$role],
            's'
        );
        
        $users = fetchAllRows($stmt);
        $stmt->close();
        
        sendSuccess(['users' => $users]);
        
    } catch (Exception $e) {
        throw $e;
    }
}

function handlePutUsers($action, $user_id, $conn) {
    if (!$user_id) sendError('User ID required', 400);
    
    switch ($action) {
        case 'update':
            updateUser($user_id, $conn);
            break;
        case 'change-password':
            changeUserPassword($user_id, $conn);
            break;
        case 'change-status':
            changeUserStatus($user_id, $conn);
            break;
        default:
            sendError('Invalid action', 400);
    }
}

function updateUser($user_id, $conn) {
    $user_id = (int)$user_id;
    $data = json_decode(file_get_contents('php://input'), true);
    
    try {
        $updates = [];
        $params = [];
        $types = '';
        
        if (isset($data['first_name'])) {
            $updates[] = 'first_name = ?';
            $params[] = sanitizeInput($data['first_name']);
            $types .= 's';
        }
        
        if (isset($data['last_name'])) {
            $updates[] = 'last_name = ?';
            $params[] = sanitizeInput($data['last_name']);
            $types .= 's';
        }
        
        if (isset($data['phone'])) {
            $updates[] = 'phone = ?';
            $params[] = sanitizeInput($data['phone']);
            $types .= 's';
        }
        
        if (empty($updates)) {
            sendError('No fields to update', 400);
        }
        
        $params[] = $user_id;
        $types .= 'i';
        
        $stmt = executeQuery($conn,
            "UPDATE users SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = ?",
            $params,
            $types
        );
        $stmt->close();
        
        logActivity($_SESSION['user_id'], 'Update User', null, ['updated_user_id' => $user_id]);
        
        sendSuccess(['message' => 'User updated successfully']);
        
    } catch (Exception $e) {
        throw $e;
    }
}

function changeUserPassword($user_id, $conn) {
    $user_id = (int)$user_id;
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['password'])) {
        sendError('Password is required', 400);
    }
    
    if (strlen($data['password']) < 6) {
        sendError('Password must be at least 6 characters', 400);
    }
    
    try {
        $hashed_password = hashPassword($data['password']);
        
        $stmt = executeQuery($conn,
            "UPDATE users SET password = ? WHERE id = ?",
            [$hashed_password, $user_id],
            'si'
        );
        $stmt->close();
        
        logActivity($_SESSION['user_id'], 'Reset User Password', null, ['user_id' => $user_id]);
        
        sendSuccess(['message' => 'Password changed successfully']);
        
    } catch (Exception $e) {
        throw $e;
    }
}

function changeUserStatus($user_id, $conn) {
    $user_id = (int)$user_id;
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['status'])) {
        sendError('Status is required', 400);
    }
    
    $valid_statuses = ['active', 'inactive'];
    if (!in_array($data['status'], $valid_statuses)) {
        sendError('Invalid status', 400);
    }
    
    try {
        $stmt = executeQuery($conn,
            "UPDATE users SET status = ? WHERE id = ?",
            [$data['status'], $user_id],
            'si'
        );
        $stmt->close();
        
        logActivity($_SESSION['user_id'], 'Change User Status', null, [
            'user_id' => $user_id,
            'new_status' => $data['status']
        ]);
        
        sendSuccess(['message' => 'User status changed to ' . $data['status']]);
        
    } catch (Exception $e) {
        throw $e;
    }
}

function handleDeleteUsers($user_id, $conn) {
    if (!$user_id) sendError('User ID required', 400);
    
    $user_id = (int)$user_id;
    
    try {
        $stmt = executeQuery($conn,
            "UPDATE users SET status = 'inactive' WHERE id = ?",
            [$user_id],
            'i'
        );
        $stmt->close();
        
        logActivity($_SESSION['user_id'], 'Deactivate User', null, ['user_id' => $user_id]);
        
        sendSuccess(['message' => 'User deactivated']);
        
    } catch (Exception $e) {
        throw $e;
    }
}

?>