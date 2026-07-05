<?php
/**
 * Authentication API Endpoints
 * Handles user login, logout, and registration
 * 
 * LEARNING POINTS:
 * - POST requests for sensitive operations
 * - Password hashing with bcrypt
 * - Session management
 * - JSON responses
 * - Input validation
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';

// Start session
startSession();

// Get request method and action
$request_method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($request_method) {
        case 'POST':
            handlePost($action, $conn);
            break;
        case 'GET':
            handleGet($action, $conn);
            break;
        default:
            sendError('Method not allowed', 405);
    }
} catch (Exception $e) {
    error_log('Auth API Error: ' . $e->getMessage());
    sendError('An error occurred: ' . $e->getMessage(), 500);
}

/**
 * Handle POST requests (login, register, logout)
 */
function handlePost($action, $conn) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    switch ($action) {
        case 'login':
            loginUser($data, $conn);
            break;
        case 'register':
            registerUser($data, $conn);
            break;
        case 'logout':
            logoutUser();
            break;
        default:
            sendError('Invalid action', 400);
    }
}

/**
 * Handle GET requests (check auth status)
 */
function handleGet($action, $conn) {
    switch ($action) {
        case 'status':
            getAuthStatus();
            break;
        case 'user':
            getCurrentUserData($conn);
            break;
        default:
            sendError('Invalid action', 400);
    }
}

/**
 * LOGIN USER
 * 
 * LEARNING POINT:
 * This function demonstrates:
 * - Input validation and sanitization
 * - Password verification using bcrypt
 * - Session creation
 * - Error handling
 */
function loginUser($data, $conn) {
    // Validate input
    if (empty($data['email']) || empty($data['password'])) {
        sendError('Email and password are required', 400);
    }
    
    $email = sanitizeInput($data['email']);
    $password = $data['password'];
    
    // Validate email format
    if (!validateEmail($email)) {
        sendError('Invalid email format', 400);
    }
    
    try {
        // Query user by email
        $stmt = executeQuery($conn, 
            "SELECT id, first_name, last_name, email, password, role, status FROM users WHERE email = ?",
            [$email],
            's'
        );
        
        $user = fetchSingleRow($stmt);
        $stmt->close();
        
        // Check if user exists
        if (!$user) {
            // Don't reveal if email exists (security best practice)
            sendError('Invalid email or password', 401);
        }
        
        // Check if user is active
        if ($user['status'] !== 'active') {
            sendError('Your account has been deactivated', 403);
        }
        
        // Verify password (bcrypt comparison)
        if (!verifyPassword($password, $user['password'])) {
            sendError('Invalid email or password', 401);
        }
        
        // Create session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user'] = [
            'id' => $user['id'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'email' => $user['email'],
            'role' => $user['role'],
        ];
        $_SESSION['login_time'] = time();
        
        // Log activity
        logActivity($user['id'], 'User Login', null, ['ip' => $_SERVER['REMOTE_ADDR']]);
        
        sendSuccess([
            'user' => $_SESSION['user'],
            'message' => 'Login successful'
        ], 'Logged in successfully');
        
    } catch (Exception $e) {
        sendError('Database error: ' . $e->getMessage(), 500);
    }
}

/**
 * REGISTER USER (Admin only)
 * New users are typically created by admin
 */
function registerUser($data, $conn) {
    // Check if admin is creating the user
    if (!isLoggedIn() || !hasPermission('manage_users')) {
        sendError('Unauthorized: Only admins can create users', 403);
    }
    
    // Validate input
    $required_fields = ['first_name', 'last_name', 'email', 'password', 'role'];
    foreach ($required_fields as $field) {
        if (empty($data[$field])) {
            sendError($field . ' is required', 400);
        }
    }
    
    $first_name = sanitizeInput($data['first_name']);
    $last_name = sanitizeInput($data['last_name']);
    $email = sanitizeInput($data['email']);
    $password = $data['password'];
    $role = sanitizeInput($data['role']);
    $phone = sanitizeInput($data['phone'] ?? '');
    
    // Validate email
    if (!validateEmail($email)) {
        sendError('Invalid email format', 400);
    }
    
    // Validate role
    $valid_roles = ['admin', 'manager', 'staff', 'maintenance'];
    if (!in_array($role, $valid_roles)) {
        sendError('Invalid role', 400);
    }
    
    // Validate password strength
    if (strlen($password) < 6) {
        sendError('Password must be at least 6 characters', 400);
    }
    
    try {
        // Check if email already exists
        $stmt = executeQuery($conn, "SELECT id FROM users WHERE email = ?", [$email], 's');
        if (fetchSingleRow($stmt)) {
            $stmt->close();
            sendError('Email already exists', 400);
        }
        $stmt->close();
        
        // Hash password
        $hashed_password = hashPassword($password);
        
        // Insert new user
        $stmt = executeQuery($conn,
            "INSERT INTO users (first_name, last_name, email, password, role, phone, status) 
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$first_name, $last_name, $email, $hashed_password, $role, $phone, 'active'],
            'sssssss'
        );
        
        $user_id = getLastInsertId($conn);
        $stmt->close();
        
        // Log activity
        logActivity($_SESSION['user_id'], 'Create User', null, ['new_user_id' => $user_id]);
        
        sendSuccess(['user_id' => $user_id], 'User created successfully');
        
    } catch (Exception $e) {
        sendError('Database error: ' . $e->getMessage(), 500);
    }
}

/**
 * LOGOUT USER
 */
function logoutUser() {
    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        logActivity($user_id, 'User Logout');
    }
    
    // Destroy session
    $_SESSION = [];
    session_destroy();
    
    sendSuccess([], 'Logged out successfully');
}

/**
 * GET AUTH STATUS
 * Check if user is currently logged in
 */
function getAuthStatus() {
    if (isLoggedIn()) {
        sendSuccess([
            'authenticated' => true,
            'user' => $_SESSION['user']
        ]);
    } else {
        sendSuccess(['authenticated' => false]);
    }
}

/**
 * GET CURRENT USER DATA
 */
function getCurrentUserData($conn) {
    if (!isLoggedIn()) {
        sendError('Not authenticated', 401);
    }
    
    $user_id = $_SESSION['user_id'];
    
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
        sendError('Database error: ' . $e->getMessage(), 500);
    }
}

?>
