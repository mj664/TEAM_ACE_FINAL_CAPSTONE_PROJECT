<?php
// staff_reports.php - Staff Equipment Reports
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
    <title>Submit Reports - DRRMC Caloocan Hub</title>
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
        }
        
        .header h1 {
            font-size: 28px;
            color: #0d5c3e;
            margin-bottom: 5px;
        }
        
        .header p {
            font-size: 14px;
            color: #666;
        }
        
        .content {
            padding: 30px;
        }
        
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e0e0e0;
            background: white;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .tab {
            padding: 10px 20px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            color: #666;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
        }
        
        .tab.active {
            color: #0d5c3e;
            border-bottom-color: #0d5c3e;
        }
        
        .tab:hover {
            color: #0d5c3e;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
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
            margin-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 10px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            font-family: inherit;
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #0d5c3e;
            box-shadow: 0 0 0 2px rgba(13, 92, 62, 0.1);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .submit-btn {
            background: linear-gradient(135deg, #1e88e5 0%, #1565c0 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(30, 136, 229, 0.3);
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
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
        
        .status-draft {
            background: #e0e0e0;
            color: #666;
        }
        
        .status-submitted {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .status-reviewed {
            background: #d4edda;
            color: #155724;
        }
        
        .action-links {
            display: flex;
            gap: 10px;
        }
        
        .action-links a {
            padding: 4px 10px;
            border-radius: 3px;
            font-size: 12px;
            text-decoration: none;
            border: 1px solid #ddd;
            transition: all 0.3s ease;
        }
        
        .action-links a:hover {
            background: #f0f0f0;
        }
        
        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .alert-info {
            background: #d1ecf1;
            border-left: 4px solid #0c5460;
            color: #0c5460;
        }
        
        @media (max-width: 768px) {
            .container {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100%;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .tabs {
                flex-wrap: wrap;
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
                <li><a href="staff_reports.php" class="active">📄 Submit Reports</a></li>
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
                <h1>📄 Equipment Reports & Monitoring</h1>
                <p>Submit field reports and equipment status updates</p>
            </div>
            
            <!-- Content -->
            <div class="content">
                <!-- Tabs -->
                <div class="tabs">
                    <button class="tab active" onclick="switchTab('new-report')">📝 Submit New Report</button>
                    <button class="tab" onclick="switchTab('my-reports')">📋 My Reports</button>
                    <button class="tab" onclick="switchTab('equipment-status')">🔧 Equipment Status</button>
                </div>
                
                <!-- New Report Tab -->
                <div id="new-report" class="tab-content active">
                    <div class="section">
                        <h2>📝 Submit New Equipment Report</h2>
                        
                        <div class="alert alert-info">
                            ℹ️ Please provide detailed information about the equipment status, any issues encountered, and actions taken.
                        </div>
                        
                        <form>
                            <div class="form-group">
                                <label>Report Type *</label>
                                <select required>
                                    <option value="">Select Report Type</option>
                                    <option>Equipment Inspection</option>
                                    <option>Maintenance Report</option>
                                    <option>Damage/Issue Report</option>
                                    <option>Field Activity Report</option>
                                    <option>Resource Utilization</option>
                                </select>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Equipment/Item Name *</label>
                                    <input type="text" placeholder="e.g., Rescue Equipment, First Aid Kit" required>
                                </div>
                                <div class="form-group">
                                    <label>Condition Status *</label>
                                    <select required>
                                        <option value="">Select Status</option>
                                        <option>Excellent</option>
                                        <option>Good</option>
                                        <option>Fair</option>
                                        <option>Poor</option>
                                        <option>Damaged</option>
                                        <option>Non-functional</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Location/Area *</label>
                                    <input type="text" placeholder="Where is the equipment located?" required>
                                </div>
                                <div class="form-group">
                                    <label>Last Maintenance Date</label>
                                    <input type="date">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Detailed Report *</label>
                                <textarea placeholder="Describe the equipment status, any issues, observations, and actions taken. Include photos or evidence if applicable." required></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label>Recommended Actions</label>
                                <textarea placeholder="What should be done? (e.g., Repair, Replace, Maintain)" rows="3"></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label>Attach Supporting Document (Optional)</label>
                                <input type="file" accept=".pdf,.jpg,.png,.doc,.docx">
                            </div>
                            
                            <button type="submit" class="submit-btn">Submit Report</button>
                        </form>
                    </div>
                </div>
                
                <!-- My Reports Tab -->
                <div id="my-reports" class="tab-content">
                    <div class="section">
                        <h2>📋 My Submitted Reports</h2>
                        <table>
                            <thead>
                                <tr>
                                    <th>Report ID</th>
                                    <th>Type</th>
                                    <th>Equipment</th>
                                    <th>Submitted Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>REP-0001-2024</td>
                                    <td>Equipment Inspection</td>
                                    <td>Rescue Ropes</td>
                                    <td>Sep 1, 2024</td>
                                    <td><span class="status-badge status-reviewed">Reviewed</span></td>
                                    <td>
                                        <div class="action-links">
                                            <a href="#">View</a>
                                            <a href="#">Download</a>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td>REP-0002-2024</td>
                                    <td>Maintenance Report</td>
                                    <td>First Aid Kit</td>
                                    <td>Aug 28, 2024</td>
                                    <td><span class="status-badge status-submitted">Submitted</span></td>
                                    <td>
                                        <div class="action-links">
                                            <a href="#">View</a>
                                            <a href="#">Edit</a>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td>REP-0003-2024</td>
                                    <td>Damage Report</td>
                                    <td>Portable Generator</td>
                                    <td>Aug 25, 2024</td>
                                    <td><span class="status-badge status-reviewed">Reviewed</span></td>
                                    <td>
                                        <div class="action-links">
                                            <a href="#">View</a>
                                            <a href="#">Download</a>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td>REP-0004-2024</td>
                                    <td>Field Activity Report</td>
                                    <td>Emergency Shelters</td>
                                    <td>Aug 22, 2024</td>
                                    <td><span class="status-badge status-reviewed">Reviewed</span></td>
                                    <td>
                                        <div class="action-links">
                                            <a href="#">View</a>
                                            <a href="#">Download</a>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Equipment Status Tab -->
                <div id="equipment-status" class="tab-content">
                    <div class="section">
                        <h2>🔧 Equipment Status Overview</h2>
                        <table>
                            <thead>
                                <tr>
                                    <th>Equipment Name</th>
                                    <th>Category</th>
                                    <th>Current Condition</th>
                                    <th>Last Inspected</th>
                                    <th>Next Maintenance</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Rescue Ropes</td>
                                    <td>Equipment</td>
                                    <td><span class="status-badge status-reviewed">Excellent</span></td>
                                    <td>Sep 1, 2024</td>
                                    <td>Dec 1, 2024</td>
                                    <td>Recently inspected, all good</td>
                                </tr>
                                <tr>
                                    <td>First Aid Kit</td>
                                    <td>Medical Supplies</td>
                                    <td><span class="status-badge status-reviewed">Good</span></td>
                                    <td>Aug 28, 2024</td>
                                    <td>Nov 28, 2024</td>
                                    <td>Stock needs replenishment</td>
                                </tr>
                                <tr>
                                    <td>Portable Generator</td>
                                    <td>Equipment</td>
                                    <td><span class="status-badge status-draft">Fair</span></td>
                                    <td>Aug 25, 2024</td>
                                    <td>Sep 25, 2024</td>
                                    <td>Minor issues reported, under repair</td>
                                </tr>
                                <tr>
                                    <td>Emergency Shelters</td>
                                    <td>Relief Materials</td>
                                    <td><span class="status-badge status-reviewed">Good</span></td>
                                    <td>Aug 20, 2024</td>
                                    <td>Nov 20, 2024</td>
                                    <td>Stored safely, ready for deployment</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function switchTab(tabName) {
            // Hide all tab contents
            const contents = document.querySelectorAll('.tab-content');
            contents.forEach(content => {
                content.classList.remove('active');
            });
            
            // Remove active class from all tabs
            const tabs = document.querySelectorAll('.tab');
            tabs.forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName).classList.add('active');
            event.target.classList.add('active');
        }
    </script>
</body>
</html>
