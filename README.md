# CAPSTONE(ACE) - DRRM Inventory System
**PHP + MySQL Inventory Management for Caloocan City Disaster Risk Reduction & Management Office**

---

## 📋 Project Structure

```
CAPSTONE(ACE)/
├── config.php                 # Database connection
├── init.php                   # Session & login helper
├── login.php                  # Login page
├── register.php               # Registration & verification upload
├── logout.php                 # Logout handler
├── admin_dashboard.php        # Admin home (inventory view + analytics)
├── user_dashboard.php         # User home (inventory view)
├── inventory.php              # Admin inventory management (CRUD)
├── assets/
│   └── style.css             # Professional styling (responsive design)
├── uploads/                  # Verification file uploads
└── db.sql                     # Database schema & setup
```

---

## ⚙️ Setup Instructions

### 1. **Database Setup**
- Open **phpMyAdmin** at `http://localhost/phpmyadmin`
- Create a new database named `capstone_db`
- Import `db.sql`:
  - Click **Import** tab
  - Choose `db.sql` file from the project folder
  - Click **Go**

### 2. **XAMPP Configuration**
- Ensure Apache and MySQL are running (XAMPP Control Panel)
- Database credentials in `config.php`:
  ```
  Host: localhost
  User: root
  Password: (empty by default)
  Database: capstone_db
  ```

### 3. **Access the System**
- Navigate to: `http://localhost/CAPSTONE(ACE)/login.php`

---

## 👤 User Roles & Access

### **Admin**
- View all inventory across barangays
- Manage inventory items (add, edit, delete)
- View AI predictive analytics alerts
- Export reports
- Approve/manage user registrations (manual via phpMyAdmin)

### **User (Staff/Coordinator)**
- View inventory levels
- Check equipment status
- Submit supply requests
- View emergency contacts

---

## 🔐 First-Time Admin Setup

1. **Register an account** via `/register.php`
   - Fill in details
   - Upload an ID (PNG, JPG, or PDF)
   
2. **Approve your account manually**:
   - Open phpMyAdmin → `capstone_db` → `users` table
   - Find your registered email
   - Change `role` to `admin`
   - Change `status` to `approved`
   - Save

3. **Log in** with your credentials at `/login.php`

---

## 🎨 Design Features

✅ Professional green & orange color scheme (matches DRRMC branding)  
✅ Responsive sidebar navigation  
✅ Status badges (Verified, Low Stock, Pending Repair)  
✅ Analytics cards with alerts  
✅ Mobile-optimized tables  
✅ Emergency contact quick-reference  
✅ File upload for verification  
✅ Session-based authentication  

---

## 📊 Core Pages

| Page | URL | Access |
|------|-----|--------|
| Login | `/login.php` | Public |
| Register | `/register.php` | Public |
| Admin Dashboard | `/admin_dashboard.php` | Admin only |
| Manage Inventory | `/inventory.php` | Admin only |
| User Dashboard | `/user_dashboard.php` | User only |
| Logout | `/logout.php` | All logged-in users |

---

## 🚀 Key Features

- ✅ User Registration with ID verification upload
- ✅ Role-based login (Admin/User)
- ✅ Admin approval workflow
- ✅ Inventory tracking dashboard
- ✅ Status monitoring (Verified, Low Stock, Pending Repair)
- ✅ AI alert predictions
- ✅ Emergency contact directory
- ✅ Responsive design (desktop, tablet, mobile)
- ✅ Session security
- ✅ Input validation & sanitization

---

## ✉️ Email notifications (Approval / Rejection)

- The system attempts to send emails using PHP's `mail()` function. On default XAMPP installs `mail()` is often unavailable, so the system falls back to logging outgoing emails to `logs/mail.log` for testing.
- To enable real SMTP delivery, configure your local PHP mail settings or install & configure an SMTP relay (or use PHPMailer to send via SMTP). The helper `send_mail()` is in `config.php`.


## 🔧 Database Schema

### `users` Table
```sql
id, fullname, address, barangay, position, 
verification_file, email, password, role, status, created_at
```

### `inventory` Table
```sql
id, item_description, status, required_quantity, 
current_stock, notes, created_at
```

---

## 📝 Notes

- **Password hashing**: Uses PHP `password_hash()` function
- **File uploads**: Stored in `/uploads/` with timestamped filenames
- **Session timeout**: Standard PHP session settings (expires on browser close)
- **Database errors**: Check browser console and error logs

---

## 🎯 Next Steps (Optional Enhancements)

- [ ] Email notifications for approvals
- [ ] Dashboard analytics charts
- [ ] Barcode scanning for inventory
- [ ] PDF report generation
- [ ] SMS alerts for critical stock levels
- [ ] Admin user management interface
- [ ] Audit logs

---

**Created for CAPSTONE(ACE) Project**  
*Disaster Risk Reduction & Management Inventory System*
