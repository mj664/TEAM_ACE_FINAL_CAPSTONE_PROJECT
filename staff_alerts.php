<?php
// staff_alerts.php - Staff Alerts & Notifications
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

if(!isset($_SESSION['staff_user'])) {
    $_SESSION['staff_user'] = [
        'id' => 1,
        'fullname' => 'Maria Santos',
        'barangay' => 'Barangay 5',
        'position' => 'Barangay Health Worker',
        'email' => 'maria.santos@barangay5.gov.ph'
    ];
}

$staff = $_SESSION['staff_user'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alerts & Updates - DRRMC Caloocan Hub</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">
    <link rel="icon" type="image/png" sizes="64x64" href="assets/favicon-64.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/favicon-180.png">
    <link rel="stylesheet" href="assets/style.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            color: #333;
        }
        
        .container {
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar {
            width: 250px;
            background: linear-gradient(135deg, #0d5c3e 0%, #0a4a32 100%);
            color: white;
            padding: 20px;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }
        
        .sidebar-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid rgba(255,255,255,0.3);
        }
        
        .sidebar-header h2 {
            font-size: 18px;
            margin-bottom: 5px;
        }
        
        .sidebar-header p {
            font-size: 12px;
            opacity: 0.9;
        }
        
        .sidebar-menu {
            list-style: none;
        }
        
        .sidebar-menu li {
            margin: 15px 0;
        }
        
        .sidebar-menu a {
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            padding: 10px 15px;
            border-radius: 5px;
            transition: all 0.3s ease;
        }
        
        .sidebar-menu a:hover,
        .sidebar-menu a.active {
            background: rgba(255,255,255,0.2);
            padding-left: 25px;
        }
        
        .sidebar-menu a::before {
            content: '';
            width: 4px;
            height: 20px;
            background: #ff6b35;
            margin-right: 10px;
            border-radius: 2px;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .sidebar-menu a.active::before {
            opacity: 1;
        }
        
        .main-content {
            flex: 1;
            overflow-y: auto;
        }
        
        .header {
            background: white;
            padding: 20px 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-left h1 {
            font-size: 28px;
            color: #0d5c3e;
            margin-bottom: 5px;
        }
        
        .header-left p {
            font-size: 14px;
            color: #666;
        }
        
        .header-right {
            display: flex;
            gap: 10px;
        }
        
        .header-btn {
            background: #e0e0e0;
            color: #333;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.3s ease;
        }
        
        .header-btn:hover {
            background: #d0d0d0;
        }
        
        .content {
            padding: 30px;
        }
        
        .filter-section {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            background: white;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .filter-group label {
            font-size: 12px;
            font-weight: 600;
            color: #333;
        }
        
        .filter-group select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 13px;
        }
        
        .alert-card {
            background: white;
            border-left: 4px solid #0d5c3e;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
        }
        
        .alert-card:hover {
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .alert-card.critical {
            border-left-color: #ff6b35;
            background: linear-gradient(90deg, rgba(255, 107, 53, 0.05) 0%, white 100%);
        }
        
        .alert-card.warning {
            border-left-color: #ffc107;
            background: linear-gradient(90deg, rgba(255, 193, 7, 0.05) 0%, white 100%);
        }
        
        .alert-card.info {
            border-left-color: #1e88e5;
            background: linear-gradient(90deg, rgba(30, 136, 229, 0.05) 0%, white 100%);
        }
        
        .alert-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }
        
        .alert-title {
            font-weight: 600;
            font-size: 15px;
            color: #333;
        }
        
        .alert-type {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .type-critical {
            background: #ffebee;
            color: #c62828;
        }
        
        .type-warning {
            background: #fff8e1;
            color: #f57f17;
        }
        
        .type-info {
            background: #e3f2fd;
            color: #0d47a1;
        }
        
        .type-inventory {
            background: #f3e5f5;
            color: #6a1b9a;
        }
        
        .type-training {
            background: #e0f2f1;
            color: #00695c;
        }
        
        .alert-meta {
            font-size: 12px;
            color: #999;
            margin-bottom: 10px;
        }
        
        .alert-message {
            font-size: 14px;
            color: #555;
            line-height: 1.6;
            margin-bottom: 10px;
        }
        
        .alert-action {
            display: flex;
            gap: 10px;
            margin-top: 12px;
        }
        
        .btn-small {
            padding: 6px 12px;
            border: 1px solid #ddd;
            background: white;
            color: #333;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.3s ease;
        }
        
        .btn-small:hover {
            background: #f0f0f0;
        }
        
        .btn-small.primary {
            background: #0d5c3e;
            color: white;
            border-color: #0d5c3e;
        }
        
        .btn-small.primary:hover {
            background: #0a4a32;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
        
        .empty-state h3 {
            color: #333;
            margin-bottom: 10px;
        }
        
        .empty-state p {
            color: #999;
        }
        
        .notification-settings {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        
        .notification-settings h3 {
            color: #0d5c3e;
            margin-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 10px;
        }
        
        .setting-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .setting-item:last-child {
            border-bottom: none;
        }
        
        .toggle-switch {
            position: relative;
            width: 50px;
            height: 24px;
            background: #ddd;
            border-radius: 12px;
            cursor: pointer;
            transition: background 0.3s ease;
        }
        
        .toggle-switch.active {
            background: #0d5c3e;
        }
        
        .toggle-switch::after {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            background: white;
            border-radius: 50%;
            top: 2px;
            left: 2px;
            transition: left 0.3s ease;
        }
        
        .toggle-switch.active::after {
            left: 28px;
        }
        
        @media (max-width: 768px) {
            .container {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100%;
            }
            
            .filter-section {
                flex-direction: column;
            }
            
            .alert-header {
                flex-direction: column;
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>🛡️ Staff Portal</h2>
                <p><?php echo htmlspecialchars($staff['barangay']); ?></p>
            </div>
            
            <ul class="sidebar-menu">
                <li><a href="staff_dashboard.php">📊 Dashboard</a></li>
                <li><a href="staff_inventory.php">📦 Inventory Management</a></li>
                <li><a href="staff_requests.php">📝 My Requests</a></li>
                <li><a href="staff_reports.php">📄 Submit Reports</a></li>
                <li><a href="staff_alerts.php" class="active">🔔 Alerts & Updates</a></li>
                <li><a href="profile.php">👤 My Profile</a></li>
                <li><a href="security.php">🔐 Security</a></li>
                <li><a href="messages.php" class="sidebar-message-float" aria-label="Messages" title="Messages"><span class="sidebar-message-icon" aria-hidden="true">✉</span><span class="sidebar-message-label">Messages</span></a></li>
                <li><a href="logout.php" style="margin-top: 30px;">🚪 Log Out</a></li>
            </ul>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <div class="header-left">
                    <h1>🔔 Alerts & Updates</h1>
                    <p>Stay informed with important notifications and updates</p>
                </div>
                <div class="header-right">
                    <button class="header-btn">⚙️ Settings</button>
                    <button class="header-btn">✓ Mark All Read</button>
                </div>
            </div>
            
            <!-- Content -->
            <div class="content">
                <!-- Notification Settings Section -->
                <div class="notification-settings">
                    <h3>📧 Notification Preferences</h3>
                    
                    <div class="setting-item">
                        <div>
                            <strong>Inventory Alerts</strong>
                            <p style="font-size: 12px; color: #999; margin-top: 2px;">Get notified when items are low in stock</p>
                        </div>
                        <div class="toggle-switch active" onclick="toggleSwitch(this)"></div>
                    </div>
                    
                    <div class="setting-item">
                        <div>
                            <strong>Request Updates</strong>
                            <p style="font-size: 12px; color: #999; margin-top: 2px;">Receive updates on your resource requests</p>
                        </div>
                        <div class="toggle-switch active" onclick="toggleSwitch(this)"></div>
                    </div>
                    
                    <div class="setting-item">
                        <div>
                            <strong>Training Announcements</strong>
                            <p style="font-size: 12px; color: #999; margin-top: 2px;">Get notified about new training programs</p>
                        </div>
                        <div class="toggle-switch active" onclick="toggleSwitch(this)"></div>
                    </div>
                    
                    <div class="setting-item">
                        <div>
                            <strong>System Maintenance Notices</strong>
                            <p style="font-size: 12px; color: #999; margin-top: 2px;">Be informed about scheduled system updates</p>
                        </div>
                        <div class="toggle-switch" onclick="toggleSwitch(this)"></div>
                    </div>
                </div>
                
                <!-- Filter Section -->
                <div class="filter-section">
                    <div class="filter-group">
                        <label>Filter by Type</label>
                        <select onchange="filterAlerts()">
                            <option value="">All Alerts</option>
                            <option value="critical">Critical</option>
                            <option value="warning">Warning</option>
                            <option value="info">Information</option>
                            <option value="inventory">Inventory</option>
                            <option value="training">Training</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Sort by</label>
                        <select>
                            <option>Most Recent</option>
                            <option>Oldest First</option>
                            <option>Priority</option>
                        </select>
                    </div>
                </div>
                
                <!-- Alerts List -->
                <!-- Critical Alert -->
                <div class="alert-card critical">
                    <div class="alert-header">
                        <div>
                            <div class="alert-title">🚨 Critical Stock Alert</div>
                            <div class="alert-meta">Sep 1, 2024 - 3:45 PM</div>
                        </div>
                        <span class="alert-type type-critical">CRITICAL</span>
                    </div>
                    <div class="alert-message">
                        Face masks (PPE Box) has reached critical stock levels (2 boxes). Immediate replenishment required to meet community health standards.
                    </div>
                    <div class="alert-action">
                        <button class="btn-small primary">Submit Reorder Request</button>
                        <button class="btn-small">View Inventory</button>
                        <button class="btn-small">Dismiss</button>
                    </div>
                </div>
                
                <!-- Warning Alert -->
                <div class="alert-card warning">
                    <div class="alert-header">
                        <div>
                            <div class="alert-title">⚠️ Low Stock Warning</div>
                            <div class="alert-meta">Aug 31, 2024 - 10:20 AM</div>
                        </div>
                        <span class="alert-type type-warning">WARNING</span>
                    </div>
                    <div class="alert-message">
                        Emergency Shelters inventory is now at 5 units. Consider placing a reorder to maintain adequate stock levels for disaster response.
                    </div>
                    <div class="alert-action">
                        <button class="btn-small">View Details</button>
                        <button class="btn-small">Dismiss</button>
                    </div>
                </div>
                
                <!-- Training Alert -->
                <div class="alert-card info">
                    <div class="alert-header">
                        <div>
                            <div class="alert-title">📚 Upcoming Training Program</div>
                            <div class="alert-meta">Aug 30, 2024 - 2:15 PM</div>
                        </div>
                        <span class="alert-type type-training">TRAINING</span>
                    </div>
                    <div class="alert-message">
                        "Advanced First Aid Techniques" training is scheduled for next week (Sep 5-6, 2024) at the DRRMC office. Limited slots available. Register now!
                    </div>
                    <div class="alert-action">
                        <button class="btn-small primary">Register for Training</button>
                        <button class="btn-small">View Details</button>
                        <button class="btn-small">Dismiss</button>
                    </div>
                </div>
                
                <!-- Request Update Alert -->
                <div class="alert-card info">
                    <div class="alert-header">
                        <div>
                            <div class="alert-title">📝 Request Update: REQ-002-2024</div>
                            <div class="alert-meta">Aug 28, 2024 - 9:00 AM</div>
                        </div>
                        <span class="alert-type type-info">UPDATE</span>
                    </div>
                    <div class="alert-message">
                        Your resource request for "Wheelchair Accessories" has been approved and is ready for pickup. Please coordinate with the admin office for collection.
                    </div>
                    <div class="alert-action">
                        <button class="btn-small primary">Track Delivery</button>
                        <button class="btn-small">View Request</button>
                        <button class="btn-small">Dismiss</button>
                    </div>
                </div>
                
                <!-- Inventory Alert -->
                <div class="alert-card info">
                    <div class="alert-header">
                        <div>
                            <div class="alert-title">📦 Inventory Expiry Reminder</div>
                            <div class="alert-meta">Aug 25, 2024 - 11:30 AM</div>
                        </div>
                        <span class="alert-type type-inventory">INVENTORY</span>
                    </div>
                    <div class="alert-message">
                        First Aid Kit (expires on Dec 25, 2025) inventory check due soon. Please conduct inspection and submit condition report by next month.
                    </div>
                    <div class="alert-action">
                        <button class="btn-small primary">Submit Report</button>
                        <button class="btn-small">View Inventory</button>
                        <button class="btn-small">Dismiss</button>
                    </div>
                </div>
                
                <!-- Maintenance Notice -->
                <div class="alert-card info">
                    <div class="alert-header">
                        <div>
                            <div class="alert-title">🔧 Equipment Maintenance Due</div>
                            <div class="alert-meta">Aug 22, 2024 - 8:45 AM</div>
                        </div>
                        <span class="alert-type type-info">NOTICE</span>
                    </div>
                    <div class="alert-message">
                        Portable Generator is due for quarterly maintenance (last maintained on Aug 25, 2024). Please schedule inspection within the next 2 weeks.
                    </div>
                    <div class="alert-action">
                        <button class="btn-small">Schedule Maintenance</button>
                        <button class="btn-small">View Equipment Log</button>
                        <button class="btn-small">Dismiss</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function toggleSwitch(element) {
            element.classList.toggle('active');
        }
        
        function filterAlerts() {
            console.log('Filtering alerts...');
        }
    </script>
</body>
</html>
