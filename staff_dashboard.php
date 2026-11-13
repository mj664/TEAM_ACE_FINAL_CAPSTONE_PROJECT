<?php
// staff_dashboard.php - Barangay Staff Dashboard
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Simulate staff login
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
    <title>Staff Dashboard - DRRMC Caloocan Hub</title>
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
            gap: 20px;
            align-items: center;
        }
        
        .user-info {
            text-align: right;
            font-size: 14px;
        }
        
        .user-info p {
            color: #666;
        }
        
        .user-info strong {
            color: #333;
        }
        
        .logout-btn {
            background: #ff6b35;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.3s ease;
        }
        
        .logout-btn:hover {
            background: #e55a28;
        }
        
        .content {
            padding: 30px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid #0d5c3e;
        }
        
        .stat-card h3 {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
            text-transform: uppercase;
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #0d5c3e;
            margin-bottom: 5px;
        }
        
        .stat-info {
            font-size: 12px;
            color: #999;
        }
        
        .stat-card.orange {
            border-left-color: #ff6b35;
        }
        
        .stat-card.orange .stat-number {
            color: #ff6b35;
        }
        
        .stat-card.blue {
            border-left-color: #1e88e5;
        }
        
        .stat-card.blue .stat-number {
            color: #1e88e5;
        }
        
        .section {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        
        .section h2 {
            font-size: 18px;
            color: #0d5c3e;
            margin-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 10px;
        }
        
        .action-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .action-btn {
            background: linear-gradient(135deg, #0d5c3e 0%, #0a4a32 100%);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(13, 92, 62, 0.3);
        }
        
        .action-btn.orange {
            background: linear-gradient(135deg, #ff6b35 0%, #e55a28 100%);
        }
        
        .action-btn.orange:hover {
            box-shadow: 0 5px 15px rgba(255, 107, 53, 0.3);
        }
        
        .action-btn.blue {
            background: linear-gradient(135deg, #1e88e5 0%, #1565c0 100%);
        }
        
        .action-btn.blue:hover {
            box-shadow: 0 5px 15px rgba(30, 136, 229, 0.3);
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        th {
            background: #f9f9f9;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #e0e0e0;
        }
        
        td {
            padding: 12px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        tr:hover {
            background: #f9f9f9;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-active {
            background: #d4edda;
            color: #155724;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-low {
            background: #f8d7da;
            color: #721c24;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #999;
        }
        
        .empty-state p {
            margin-bottom: 15px;
        }
        
        @media (max-width: 768px) {
            .container {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100%;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
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
                <li><a href="staff_dashboard.php" class="active">📊 Dashboard</a></li>
                <li><a href="staff_inventory.php">📦 Inventory Management</a></li>
                <li><a href="staff_requests.php">📝 My Requests</a></li>
                <li><a href="staff_reports.php">📄 Submit Reports</a></li>
                <li><a href="staff_alerts.php">🔔 Alerts & Updates</a></li>
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
                    <h1>Welcome, <?php echo htmlspecialchars(explode(' ', $staff['fullname'])[0]); ?></h1>
                    <p><?php echo htmlspecialchars($staff['position']); ?></p>
                </div>
                <div class="header-right">
                    <div class="user-info">
                        <p><strong><?php echo htmlspecialchars($staff['fullname']); ?></strong></p>
                        <p><?php echo htmlspecialchars($staff['email']); ?></p>
                    </div>
                    <a href="logout.php" class="logout-btn">Logout</a>
                </div>
            </div>
            
            <!-- Content -->
            <div class="content">
                <!-- Quick Stats -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <h3>📦 Inventory Items</h3>
                        <div class="stat-number">24</div>
                        <div class="stat-info">Currently in stock</div>
                    </div>
                    <div class="stat-card orange">
                        <h3>📝 Pending Requests</h3>
                        <div class="stat-number">3</div>
                        <div class="stat-info">Awaiting approval</div>
                    </div>
                    <div class="stat-card blue">
                        <h3>📄 Reports Submitted</h3>
                        <div class="stat-number">12</div>
                        <div class="stat-info">This month</div>
                    </div>
                    <div class="stat-card">
                        <h3>🔔 New Alerts</h3>
                        <div class="stat-number">2</div>
                        <div class="stat-info">Requires attention</div>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="section">
                    <h2>Quick Actions</h2>
                    <div class="action-buttons">
                        <a href="staff_inventory.php" class="action-btn">➕ Add Inventory</a>
                        <a href="staff_requests.php" class="action-btn orange">📤 Submit Request</a>
                        <a href="staff_reports.php" class="action-btn blue">📋 New Report</a>
                        <a href="staff_alerts.php" class="action-btn">🔔 View Alerts</a>
                    </div>
                </div>
                
                <!-- Recent Inventory Activities -->
                <div class="section">
                    <h2>📦 Recent Inventory Updates</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Category</th>
                                <th>Quantity</th>
                                <th>Last Updated</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>First Aid Kit</td>
                                <td>Medical Supplies</td>
                                <td>8 units</td>
                                <td>Sep 1, 2024 - 10:30 AM</td>
                                <td><span class="status-badge status-active">In Stock</span></td>
                            </tr>
                            <tr>
                                <td>Emergency Shelters</td>
                                <td>Relief Materials</td>
                                <td>5 units</td>
                                <td>Aug 30, 2024 - 3:15 PM</td>
                                <td><span class="status-badge status-low">Low Stock</span></td>
                            </tr>
                            <tr>
                                <td>Rescue Ropes</td>
                                <td>Equipment</td>
                                <td>12 units</td>
                                <td>Aug 28, 2024 - 9:00 AM</td>
                                <td><span class="status-badge status-active">In Stock</span></td>
                            </tr>
                            <tr>
                                <td>Face Masks (Box)</td>
                                <td>PPE</td>
                                <td>2 boxes</td>
                                <td>Aug 25, 2024 - 2:45 PM</td>
                                <td><span class="status-badge status-pending">Pending Order</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pending Requests -->
                <div class="section">
                    <h2>📝 Your Pending Requests</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Request ID</th>
                                <th>Item Requested</th>
                                <th>Quantity</th>
                                <th>Submitted Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>#REQ-001-2024</td>
                                <td>Emergency Water Supplies</td>
                                <td>50 liters</td>
                                <td>Aug 31, 2024</td>
                                <td><span class="status-badge status-pending">Under Review</span></td>
                            </tr>
                            <tr>
                                <td>#REQ-002-2024</td>
                                <td>Wheelchair Accessories</td>
                                <td>3 units</td>
                                <td>Aug 29, 2024</td>
                                <td><span class="status-badge status-pending">Approved</span></td>
                            </tr>
                            <tr>
                                <td>#REQ-003-2024</td>
                                <td>Additional Training Materials</td>
                                <td>25 sets</td>
                                <td>Aug 25, 2024</td>
                                <td><span class="status-badge status-active">Fulfilled</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Important Alerts -->
                <div class="section">
                    <h2>🔔 Important Alerts</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Alert</th>
                                <th>Type</th>
                                <th>Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Low stock warning: Face Masks</td>
                                <td>Inventory</td>
                                <td>Sep 1, 2024</td>
                                <td><a href="#" style="color: #0d5c3e; text-decoration: none;">View</a></td>
                            </tr>
                            <tr>
                                <td>New training scheduled for next week</td>
                                <td>Training</td>
                                <td>Aug 31, 2024</td>
                                <td><a href="#" style="color: #0d5c3e; text-decoration: none;">View</a></td>
                            </tr>
                            <tr>
                                <td>Monthly equipment inspection due</td>
                                <td>Maintenance</td>
                                <td>Aug 30, 2024</td>
                                <td><a href="#" style="color: #0d5c3e; text-decoration: none;">View</a></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
