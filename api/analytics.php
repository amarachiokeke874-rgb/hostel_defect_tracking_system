<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';

startSession();
requireLogin();
requirePermission('view_analytics');

$request_method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    if ($request_method !== 'GET') {
        sendError('Only GET requests allowed', 405);
    }
    
    switch ($action) {
        case 'dashboard':
            getDashboardStats($conn);
            break;
        case 'by-status':
            getDefectsByStatus($conn);
            break;
        case 'by-priority':
            getDefectsByPriority($conn);
            break;
        case 'by-location':
            getDefectsByLocation($conn);
            break;
        case 'by-category':
            getDefectsByCategory($conn);
            break;
        case 'maintenance-performance':
            getMaintenancePerformance($conn);
            break;
        case 'sla-status':
            getSLAStatus($conn);
            break;
        case 'cost-analysis':
            getCostAnalysis($conn);
            break;
        default:
            sendError('Invalid action', 400);
    }
} catch (Exception $e) {
    error_log('Analytics API Error: ' . $e->getMessage());
    sendError($e->getMessage(), 500);
}

function getDashboardStats($conn) {
    try {
        $total_stmt = executeQuery($conn, "SELECT COUNT(*) as total FROM defects");
        $total = fetchSingleRow($total_stmt)['total'];
        $total_stmt->close();
        
        $status_stmt = executeQuery($conn,
            "SELECT status, COUNT(*) as count FROM defects GROUP BY status"
        );
        $by_status = fetchAllRows($status_stmt);
        $status_stmt->close();
        
        $open_stmt = executeQuery($conn,
            "SELECT COUNT(*) as total FROM defects WHERE status NOT IN ('resolved', 'closed')"
        );
        $open = fetchSingleRow($open_stmt)['total'];
        $open_stmt->close();
        
        $critical_stmt = executeQuery($conn,
            "SELECT COUNT(*) as total FROM defects WHERE priority = 'critical' AND status NOT IN ('resolved', 'closed')"
        );
        $critical = fetchSingleRow($critical_stmt)['total'];
        $critical_stmt->close();
        
        $avg_stmt = executeQuery($conn,
            "SELECT AVG(DATEDIFF(completion_date, created_at)) as avg_days 
             FROM defects WHERE completion_date IS NOT NULL"
        );
        $avg_days = fetchSingleRow($avg_stmt)['avg_days'] ?? 0;
        $avg_stmt->close();
        
        sendSuccess([
            'total_defects' => $total,
            'open_defects' => $open,
            'critical_defects' => $critical,
            'average_resolution_days' => round($avg_days, 1),
            'by_status' => $by_status
        ]);
        
    } catch (Exception $e) {
        throw $e;
    }
}

function getDefectsByStatus($conn) {
    try {
        $stmt = executeQuery($conn,
            "SELECT status, COUNT(*) as count FROM defects GROUP BY status ORDER BY count DESC"
        );
        
        $results = fetchAllRows($stmt);
        $stmt->close();
        
        sendSuccess(['data' => $results]);
        
    } catch (Exception $e) {
        throw $e;
    }
}

function getDefectsByPriority($conn) {
    try {
        $stmt = executeQuery($conn,
            "SELECT priority, COUNT(*) as count FROM defects GROUP BY priority 
             ORDER BY FIELD(priority, 'critical', 'high', 'medium', 'low')"
        );
        
        $results = fetchAllRows($stmt);
        $stmt->close();
        
        sendSuccess(['data' => $results]);
        
    } catch (Exception $e) {
        throw $e;
    }
}

function getDefectsByLocation($conn) {
    try {
        $stmt = executeQuery($conn,
            "SELECT l.name, COUNT(d.id) as defect_count,
                    SUM(CASE WHEN d.status = 'resolved' THEN 1 ELSE 0 END) as resolved,
                    SUM(CASE WHEN d.status NOT IN ('resolved', 'closed') THEN 1 ELSE 0 END) as open
             FROM locations l
             LEFT JOIN defects d ON l.id = d.location_id
             GROUP BY l.id, l.name
             ORDER BY defect_count DESC"
        );
        
        $results = fetchAllRows($stmt);
        $stmt->close();
        
        sendSuccess(['data' => $results]);
        
    } catch (Exception $e) {
        throw $e;
    }
}

function getDefectsByCategory($conn) {
    try {
        $stmt = executeQuery($conn,
            "SELECT dc.name, COUNT(d.id) as defect_count,
                    AVG(d.actual_cost) as avg_cost
             FROM defect_categories dc
             LEFT JOIN defects d ON dc.id = d.category_id
             GROUP BY dc.id, dc.name
             ORDER BY defect_count DESC"
        );
        
        $results = fetchAllRows($stmt);
        $stmt->close();
        
        sendSuccess(['data' => $results]);
        
    } catch (Exception $e) {
        throw $e;
    }
}

function getMaintenancePerformance($conn) {
    try {
        $stmt = executeQuery($conn,
            "SELECT 
                CONCAT(u.first_name, ' ', u.last_name) as maintenance_name,
                COUNT(a.id) as total_assigned,
                SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN a.status = 'pending' THEN 1 ELSE 0 END) as pending,
                AVG(DATEDIFF(a.actual_completion_date, a.assignment_date)) as avg_days
             FROM users u
             LEFT JOIN assignments a ON u.id = a.assigned_to
             WHERE u.role = 'maintenance'
             GROUP BY u.id, u.first_name, u.last_name
             ORDER BY completed DESC"
        );
        
        $results = fetchAllRows($stmt);
        $stmt->close();
        
        sendSuccess(['data' => $results]);
        
    } catch (Exception $e) {
        throw $e;
    }
}

function getSLAStatus($conn) {
    global $SLA_TIMES;
    
    try {
        $breach_stmt = executeQuery($conn,
            "SELECT d.id, d.report_number, d.title, d.priority, d.created_at,
                    CONCAT(u.first_name, ' ', u.last_name) as assigned_to_name
             FROM defects d
             LEFT JOIN users u ON d.assigned_to = u.id
             WHERE d.status NOT IN ('resolved', 'closed')
             AND DATE_ADD(d.created_at, INTERVAL 24 HOUR) < NOW()
             ORDER BY d.created_at ASC"
        );
        
        $breached = fetchAllRows($breach_stmt);
        $breach_stmt->close();
        
        $approaching_stmt = executeQuery($conn,
            "SELECT d.id, d.report_number, d.title, d.priority, d.created_at
             FROM defects d
             WHERE d.status NOT IN ('resolved', 'closed')
             AND DATE_ADD(d.created_at, INTERVAL 20 HOUR) > NOW()
             AND DATE_ADD(d.created_at, INTERVAL 24 HOUR) < NOW()"
        );
        
        $approaching = fetchAllRows($approaching_stmt);
        $approaching_stmt->close();
        
        sendSuccess([
            'breached_count' => count($breached),
            'approaching_count' => count($approaching),
            'breached_defects' => $breached,
            'approaching_defects' => $approaching
        ]);
        
    } catch (Exception $e) {
        throw $e;
    }
}

function getCostAnalysis($conn) {
    try {
        $total_stmt = executeQuery($conn,
            "SELECT SUM(actual_cost) as total_cost FROM defects WHERE actual_cost > 0"
        );
        $total_cost = fetchSingleRow($total_stmt)['total_cost'] ?? 0;
        $total_stmt->close();
        
        $location_stmt = executeQuery($conn,
            "SELECT l.name, SUM(d.actual_cost) as total_cost, COUNT(d.id) as defect_count,
                    AVG(d.actual_cost) as avg_cost
             FROM locations l
             LEFT JOIN defects d ON l.id = d.location_id AND d.actual_cost > 0
             GROUP BY l.id, l.name
             ORDER BY total_cost DESC"
        );
        $by_location = fetchAllRows($location_stmt);
        $location_stmt->close();
        
        $category_stmt = executeQuery($conn,
            "SELECT dc.name, SUM(d.actual_cost) as total_cost, AVG(d.actual_cost) as avg_cost
             FROM defect_categories dc
             LEFT JOIN defects d ON dc.id = d.category_id AND d.actual_cost > 0
             GROUP BY dc.id, dc.name
             ORDER BY total_cost DESC"
        );
        $by_category = fetchAllRows($category_stmt);
        $category_stmt->close();
        
        sendSuccess([
            'total_cost' => $total_cost,
            'by_location' => $by_location,
            'by_category' => $by_category
        ]);
        
    } catch (Exception $e) {
        throw $e;
    }
}

?>