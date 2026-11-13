<?php
// profile_demo.php - Standalone demo profile page (no authentication required)
session_start();

// Set demo data
$user = [
    'fullname' => 'Head Admin',
    'email' => 'admin@caloocan.gov',
    'address' => 'Municipal Hall, Caloocan',
    'barangay' => 'Caloocan',
    'position' => 'System Administrator',
    'role' => 'admin'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - DRRMC Caloocan Hub</title>
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
            max-width: 900px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        
        .header {
            background: linear-gradient(135deg, #0d5c3e 0%, #0a4a32 100%);
            color: white;
            padding: 40px 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            font-size: 32px;
            margin-bottom: 10px;
        }
        
        .header p {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .profile-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            overflow: hidden;
            margin-bottom: 30px;
        }
        
        .profile-header {
            background: linear-gradient(135deg, #0d5c3e 0%, #0a4a32 100%);
            padding: 40px 30px;
            color: white;
            text-align: center;
        }
        
        .avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 40px;
        }
        
        .profile-name {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .profile-role {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .profile-content {
            padding: 40px 30px;
        }
        
        .info-section {
            margin-bottom: 40px;
        }
        
        .info-section h3 {
            font-size: 18px;
            color: #0d5c3e;
            margin-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 10px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .info-item {
            padding: 15px;
            background: #f9f9f9;
            border-radius: 8px;
            border-left: 4px solid #0d5c3e;
        }
        
        .info-label {
            font-size: 12px;
            color: #999;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .info-value {
            font-size: 16px;
            color: #333;
            font-weight: 500;
        }
        
        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 40px;
            padding-top: 30px;
            border-top: 2px solid #f0f0f0;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #0d5c3e 0%, #0a4a32 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(13, 92, 62, 0.3);
        }
        
        .btn-secondary {
            background: #e0e0e0;
            color: #333;
        }
        
        .btn-secondary:hover {
            background: #d0d0d0;
        }
        
        .badge {
            display: inline-block;
            padding: 6px 12px;
            background: #d4edda;
            color: #155724;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        @media (max-width: 768px) {
            .header h1 {
                font-size: 24px;
            }
            
            .profile-name {
                font-size: 20px;
            }
            
            .button-group {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>👤 My Profile</h1>
            <p>Manage your account information and preferences</p>
        </div>
        
        <!-- Profile Card -->
        <div class="profile-card">
            <!-- Profile Header -->
            <div class="profile-header">
                <div class="avatar">👨‍💼</div>
                <div class="profile-name"><?php echo htmlspecialchars($user['fullname']); ?></div>
                <div class="profile-role"><?php echo htmlspecialchars($user['position']); ?></div>
                <div style="margin-top: 15px;">
                    <span class="badge"><?php echo strtoupper($user['role']); ?></span>
                </div>
            </div>
            
            <!-- Profile Content -->
            <div class="profile-content">
                <!-- Personal Information -->
                <div class="info-section">
                    <h3>📋 Personal Information</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Full Name</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['fullname']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Email Address</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['email']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Position</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['position']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Barangay/Area</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['barangay']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Address</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['address']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Account Role</div>
                            <div class="info-value"><?php echo strtoupper($user['role']); ?></div>
                        </div>
                    </div>
                </div>
                
                <!-- Account Status -->
                <div class="info-section">
                    <h3>🔐 Account Status</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Account Status</div>
                            <div class="info-value">
                                <span class="badge">Active</span>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Last Login</div>
                            <div class="info-value">Sep 1, 2024 - 7:30 PM</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Member Since</div>
                            <div class="info-value">Jan 15, 2024</div>
                        </div>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div class="button-group">
                    <button class="btn btn-primary">✏️ Edit Profile</button>
                    <button class="btn btn-primary">🔑 Change Password</button>
                    <button class="btn btn-secondary">📄 View Activity Log</button>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
