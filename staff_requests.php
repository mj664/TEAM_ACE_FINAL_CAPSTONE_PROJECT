<?php
// staff_requests.php - Staff Requests Management
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
    <title>My Requests - DRRMC Caloocan Hub</title>
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
        
        .content {
            padding: 30px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid #0d5c3e;
            text-align: center;
        }
        
        .stat-card h3 {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
        }
        
        .stat-number {
            font-size: 28px;
            font-weight: bold;
            color: #0d5c3e;
        }
        
        .section {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 15px;
        }
        
        .section h2 {
            font-size: 18px;
            color: #0d5c3e;
        }
        
        .action-btn {
            background: linear-gradient(135deg, #ff6b35 0%, #e55a28 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 107, 53, 0.3);
        }
        
        .request-card {
            background: #f9f9f9;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
        }
        
        .request-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .request-id {
            font-weight: 600;
            color: #0d5c3e;
            font-size: 14px;
        }
        
        .request-date {
            font-size: 12px;
            color: #999;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-approved {
            background: #d4edda;
            color: #155724;
        }
        
        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status-fulfilled {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .request-item {
            margin-bottom: 10px;
            padding: 10px;
            background: white;
            border-radius: 5px;
            border-left: 3px solid #ff6b35;
        }
        
        .request-item strong {
            color: #333;
        }
        
        .request-item p {
            margin: 3px 0;
            font-size: 13px;
        }
        
        .request-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        
        .btn-small {
            padding: 6px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.3s ease;
            background: white;
            color: #333;
        }
        
        .btn-small:hover {
            background: #f0f0f0;
        }
        
        .btn-small.delete {
            border-color: #ff6b35;
            color: #ff6b35;
        }
        
        .btn-small.delete:hover {
            background: #fff5f0;
        }
        
        .filter-section {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-group label {
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .filter-group select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 13px;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            overflow-y: auto;
        }
        
        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 30px;
            border-radius: 10px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.3);
        }
        
        .modal-header {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 20px;
            color: #0d5c3e;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 10px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            font-family: inherit;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
            border-top: 2px solid #f0f0f0;
            padding-top: 20px;
        }
        
        .btn-cancel {
            background: #e0e0e0;
            color: #333;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
        }
        
        .btn-submit {
            background: linear-gradient(135deg, #ff6b35 0%, #e55a28 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
        }
        
        .btn-submit:hover {
            box-shadow: 0 5px 15px rgba(255, 107, 53, 0.3);
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
                <li><a href="staff_requests.php" class="active">📝 My Requests</a></li>
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
                    <h1>📝 Resource Requests</h1>
                    <p>Submit and track your resource requests</p>
                </div>
            </div>
            
            <!-- Content -->
            <div class="content">
                <!-- Stats -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <h3>Total Requests</h3>
                        <div class="stat-number">8</div>
                    </div>
                    <div class="stat-card">
                        <h3>Pending</h3>
                        <div class="stat-number">2</div>
                    </div>
                    <div class="stat-card">
                        <h3>Approved</h3>
                        <div class="stat-number">5</div>
                    </div>
                    <div class="stat-card">
                        <h3>Fulfilled</h3>
                        <div class="stat-number">3</div>
                    </div>
                </div>
                
                <!-- Submit New Request -->
                <div class="section">
                    <div class="section-header">
                        <h2>Submit New Request</h2>
                        <button class="action-btn" onclick="openNewRequestModal()">📤 New Request</button>
                    </div>
                </div>
                
                <!-- Filter -->
                <div class="section">
                    <div class="filter-section">
                        <div class="filter-group">
                            <label>Filter by Status</label>
                            <select onchange="filterRequests()">
                                <option value="">All Statuses</option>
                                <option value="pending">Pending</option>
                                <option value="approved">Approved</option>
                                <option value="fulfilled">Fulfilled</option>
                                <option value="rejected">Rejected</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Sort By</label>
                            <select>
                                <option>Most Recent</option>
                                <option>Oldest First</option>
                                <option>Status</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- Requests List -->
                <div class="section">
                    <h2 style="margin-bottom: 20px;">Your Requests</h2>
                    
                    <!-- Pending Request -->
                    <div class="request-card">
                        <div class="request-header">
                            <div>
                                <div class="request-id">REQ-001-2024</div>
                                <div class="request-date">Submitted: Sep 1, 2024 - 2:30 PM</div>
                            </div>
                            <span class="status-badge status-pending">Under Review</span>
                        </div>
                        <div class="request-item">
                            <strong>Emergency Water Supplies</strong>
                            <p>Quantity: 50 liters</p>
                            <p>Priority: High</p>
                            <p>Reason: Community emergency relief during flood season</p>
                        </div>
                        <div class="request-actions">
                            <button class="btn-small">View Details</button>
                            <button class="btn-small">Modify</button>
                            <button class="btn-small delete">Withdraw</button>
                        </div>
                    </div>
                    
                    <!-- Approved Request -->
                    <div class="request-card">
                        <div class="request-header">
                            <div>
                                <div class="request-id">REQ-002-2024</div>
                                <div class="request-date">Submitted: Aug 29, 2024 - 10:15 AM</div>
                            </div>
                            <span class="status-badge status-approved">Approved</span>
                        </div>
                        <div class="request-item">
                            <strong>Wheelchair Accessories</strong>
                            <p>Quantity: 3 units</p>
                            <p>Priority: Medium</p>
                            <p>Reason: PWD assistance and accessibility support</p>
                        </div>
                        <div class="request-actions">
                            <button class="btn-small">View Details</button>
                            <button class="btn-small">Track Delivery</button>
                        </div>
                    </div>
                    
                    <!-- Fulfilled Request -->
                    <div class="request-card">
                        <div class="request-header">
                            <div>
                                <div class="request-id">REQ-003-2024</div>
                                <div class="request-date">Submitted: Aug 25, 2024 - 11:00 AM</div>
                            </div>
                            <span class="status-badge status-fulfilled">Fulfilled</span>
                        </div>
                        <div class="request-item">
                            <strong>Training Materials & Manuals</strong>
                            <p>Quantity: 25 sets</p>
                            <p>Priority: Medium</p>
                            <p>Reason: Staff capacity building for disaster response</p>
                        </div>
                        <div class="request-actions">
                            <button class="btn-small">View Details</button>
                            <button class="btn-small">Confirm Receipt</button>
                        </div>
                    </div>
                    
                    <!-- Rejected Request -->
                    <div class="request-card">
                        <div class="request-header">
                            <div>
                                <div class="request-id">REQ-004-2024</div>
                                <div class="request-date">Submitted: Aug 20, 2024 - 1:45 PM</div>
                            </div>
                            <span class="status-badge status-rejected">Rejected</span>
                        </div>
                        <div class="request-item">
                            <strong>Advanced Medical Equipment</strong>
                            <p>Quantity: 2 units</p>
                            <p>Priority: High</p>
                            <p>Reason: Specialized equipment not available in current inventory</p>
                        </div>
                        <div class="request-actions">
                            <button class="btn-small">View Details</button>
                            <button class="btn-small">Resubmit</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- New Request Modal -->
    <div id="newRequestModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">📤 Submit New Resource Request</div>
            <form>
                <div class="form-group">
                    <label>Item/Resource Name *</label>
                    <input type="text" placeholder="e.g., Emergency Water Supplies" required>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Quantity *</label>
                        <input type="number" placeholder="0" required>
                    </div>
                    <div class="form-group">
                        <label>Unit *</label>
                        <select required>
                            <option value="">Select Unit</option>
                            <option>units</option>
                            <option>boxes</option>
                            <option>kilos</option>
                            <option>liters</option>
                            <option>meters</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Priority Level *</label>
                    <select required>
                        <option value="">Select Priority</option>
                        <option>Low</option>
                        <option>Medium</option>
                        <option>High</option>
                        <option>Critical</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Reason for Request *</label>
                    <textarea placeholder="Explain why this resource is needed..." rows="4" required></textarea>
                </div>
                
                <div class="form-group">
                    <label>Requested Delivery Date</label>
                    <input type="date">
                </div>
                
                <div class="form-group">
                    <label>Additional Notes</label>
                    <textarea placeholder="Any additional information..." rows="2"></textarea>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeModal('newRequestModal')">Cancel</button>
                    <button type="submit" class="btn-submit">Submit Request</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openNewRequestModal() {
            document.getElementById('newRequestModal').style.display = 'block';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        function filterRequests() {
            // Filter logic would go here
            console.log('Filtering requests...');
        }
        
        window.onclick = function(event) {
            const modal = document.getElementById('newRequestModal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }
    </script>
</body>
</html>
