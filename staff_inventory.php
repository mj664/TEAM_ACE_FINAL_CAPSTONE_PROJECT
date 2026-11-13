<?php
// staff_inventory.php - Staff Inventory Management
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
    <title>Inventory Management - DRRMC Caloocan Hub</title>
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
            background: linear-gradient(135deg, #0d5c3e 0%, #0a4a32 100%);
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
            box-shadow: 0 5px 15px rgba(13, 92, 62, 0.3);
        }
        
        .action-btn.secondary {
            background: #e0e0e0;
            color: #333;
        }
        
        .filter-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-group label {
            font-weight: 600;
            margin-bottom: 5px;
            color: #333;
            font-size: 14px;
        }
        
        .filter-group input,
        .filter-group select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
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
        
        .status-active {
            background: #d4edda;
            color: #155724;
        }
        
        .status-low {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status-critical {
            background: #f5c6cb;
            color: #721c24;
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
            cursor: pointer;
            border: 1px solid #ddd;
            transition: all 0.3s ease;
        }
        
        .action-links a:hover {
            background: #f0f0f0;
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
        }
        
        .modal-content {
            background: white;
            margin: 10% auto;
            padding: 30px;
            border-radius: 10px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.3);
        }
        
        .modal-header {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 20px;
            color: #0d5c3e;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #333;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        
        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
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
            background: linear-gradient(135deg, #0d5c3e 0%, #0a4a32 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
        }
        
        .btn-submit:hover {
            box-shadow: 0 5px 15px rgba(13, 92, 62, 0.3);
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
                <li><a href="staff_inventory.php" class="active">📦 Inventory Management</a></li>
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
                    <h1>📦 Inventory Management</h1>
                    <p>Manage and track all barangay resources</p>
                </div>
            </div>
            
            <!-- Content -->
            <div class="content">
                <!-- Add Inventory Section -->
                <div class="section">
                    <div class="section-header">
                        <h2>Add New Inventory Item</h2>
                        <button class="action-btn" onclick="openAddModal()">➕ New Item</button>
                    </div>
                    
                    <!-- Filter Section -->
                    <div class="filter-section">
                        <div class="filter-group">
                            <label>Search by Item Name</label>
                            <input type="text" placeholder="e.g., First Aid Kit">
                        </div>
                        <div class="filter-group">
                            <label>Category</label>
                            <select>
                                <option>All Categories</option>
                                <option>Medical Supplies</option>
                                <option>Relief Materials</option>
                                <option>Equipment</option>
                                <option>PPE</option>
                                <option>Food & Water</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Stock Status</label>
                            <select>
                                <option>All Status</option>
                                <option>In Stock</option>
                                <option>Low Stock</option>
                                <option>Critical</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- Inventory List -->
                <div class="section">
                    <h2 style="margin-bottom: 20px;">Current Inventory Items</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Item Name</th>
                                <th>Category</th>
                                <th>Quantity</th>
                                <th>Unit</th>
                                <th>Expiry Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>First Aid Kit</td>
                                <td>Medical Supplies</td>
                                <td>8</td>
                                <td>units</td>
                                <td>Dec 25, 2025</td>
                                <td><span class="status-badge status-active">In Stock</span></td>
                                <td>
                                    <div class="action-links">
                                        <a href="#" onclick="openEditModal()">Edit</a>
                                        <a href="#" style="color: #ff6b35;">Delete</a>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td>Emergency Shelters</td>
                                <td>Relief Materials</td>
                                <td>5</td>
                                <td>units</td>
                                <td>N/A</td>
                                <td><span class="status-badge status-low">Low Stock</span></td>
                                <td>
                                    <div class="action-links">
                                        <a href="#" onclick="openEditModal()">Edit</a>
                                        <a href="#" style="color: #ff6b35;">Delete</a>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td>Rescue Ropes</td>
                                <td>Equipment</td>
                                <td>12</td>
                                <td>units</td>
                                <td>N/A</td>
                                <td><span class="status-badge status-active">In Stock</span></td>
                                <td>
                                    <div class="action-links">
                                        <a href="#" onclick="openEditModal()">Edit</a>
                                        <a href="#" style="color: #ff6b35;">Delete</a>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td>Face Masks (Box)</td>
                                <td>PPE</td>
                                <td>2</td>
                                <td>boxes</td>
                                <td>Jun 30, 2025</td>
                                <td><span class="status-badge status-critical">Critical</span></td>
                                <td>
                                    <div class="action-links">
                                        <a href="#" onclick="openEditModal()">Edit</a>
                                        <a href="#" style="color: #ff6b35;">Delete</a>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td>Water Containers</td>
                                <td>Food & Water</td>
                                <td>20</td>
                                <td>liters</td>
                                <td>N/A</td>
                                <td><span class="status-badge status-active">In Stock</span></td>
                                <td>
                                    <div class="action-links">
                                        <a href="#" onclick="openEditModal()">Edit</a>
                                        <a href="#" style="color: #ff6b35;">Delete</a>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td>Portable Generator</td>
                                <td>Equipment</td>
                                <td>3</td>
                                <td>units</td>
                                <td>N/A</td>
                                <td><span class="status-badge status-active">In Stock</span></td>
                                <td>
                                    <div class="action-links">
                                        <a href="#" onclick="openEditModal()">Edit</a>
                                        <a href="#" style="color: #ff6b35;">Delete</a>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add/Edit Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">Add New Inventory Item</div>
            <form>
                <div class="form-group">
                    <label>Item Name *</label>
                    <input type="text" placeholder="e.g., First Aid Kit" required>
                </div>
                
                <div class="form-group">
                    <label>Category *</label>
                    <select required>
                        <option value="">Select Category</option>
                        <option>Medical Supplies</option>
                        <option>Relief Materials</option>
                        <option>Equipment</option>
                        <option>PPE</option>
                        <option>Food & Water</option>
                    </select>
                </div>
                
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
                
                <div class="form-group">
                    <label>Expiry Date (if applicable)</label>
                    <input type="date">
                </div>
                
                <div class="form-group">
                    <label>Notes</label>
                    <textarea placeholder="Add any additional notes..." rows="3"></textarea>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeModal('addModal')">Cancel</button>
                    <button type="submit" class="btn-submit">Add Item</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openAddModal() {
            document.getElementById('addModal').style.display = 'block';
        }
        
        function openEditModal() {
            document.getElementById('addModal').style.display = 'block';
            document.querySelector('.modal-header').textContent = 'Edit Inventory Item';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            document.querySelector('.modal-header').textContent = 'Add New Inventory Item';
        }
        
        window.onclick = function(event) {
            const modal = document.getElementById('addModal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }
    </script>
</body>
</html>
