# 🚀 QUICK START GUIDE - CAPSTONE(ACE) Inventory System

## Step-by-Step Setup (5 minutes)

### **Step 1: Start XAMPP Services**
1. Open **XAMPP Control Panel**
2. Click **Start** for **Apache** and **MySQL**
3. Wait for both to show as running (green)

---

### **Step 2: Import Database**
1. Open your browser and go to: `http://localhost/phpmyadmin`
2. In the left panel, click **New** database
3. Name it: `capstone_db`
4. Click **Create**
5. Now go to the **Import** tab
6. Click **Choose File** and select `db.sql` from your `CAPSTONE(ACE)` folder
7. Click **Import**
8. ✅ You should see: "Import has been successfully finished"

---

### **Step 3: Verify Connection**
1. Go to: `http://localhost/CAPSTONE(ACE)/login.php`
2. If you see the login page with the DRRMC logo, you're connected! ✓

---

### **Step 4: Create Your First Admin Account**

#### Option A: Via Registration Form (Recommended for Testing)
1. Click "Create an account" link
2. Fill in the registration form:
   - **Full Name**: Your Name
   - **Address**: Any address
   - **Barangay**: Select any
   - **Position**: Select any
   - **Email**: admin@test.com
   - **Password**: test123456
   - **Upload ID**: Use any image file (jpg/png/pdf)
3. Click "Submit Registration Request"
4. Go back to login page

#### Option B: Direct Database Update (Fastest for Admin)
1. Go to `http://localhost/phpmyadmin`
2. Click on `capstone_db` → `users` table
3. Click **Insert** to add new row:
   - `fullname`: Your Name
   - `email`: admin@test.com
   - `password`: Click the function dropdown → select **PASSWORD** ✓
   - `role`: admin
   - `status`: approved
4. Click **Go** to save

---

### **Step 5: Log In**
1. Go to: `http://localhost/CAPSTONE(ACE)/login.php`
2. **Email**: admin@test.com
3. **Password**: test123456
4. Click **Log In**
5. 🎉 You should see the Admin Dashboard!

---

## 🎨 What You'll See

### **Login/Registration Page**
- Professional design with DRRMC branding
- Two-column layout: Info on left, form on right
- File upload area for verification

### **Admin Dashboard**
- Sidebar with navigation menu
- Main inventory table with statuses
- Right sidebar with AI alerts & emergency contacts
- Green header with equipment inventory

### **User Dashboard**
- Similar to admin but read-only
- Can view inventory but not edit
- Simplified navigation

---

## 📱 Test the Features

### Add Sample Inventory Items
1. Click **📋 Resource Requests** in sidebar
2. Click **➕ Add New Item** button
3. Fill in:
   - **Item**: Oxygen Tanks
   - **Status**: Low Stock
   - **Required**: 10
   - **Current**: 3
   - Click **Add Item**

### Create a User Account for Testing
1. Log out (click **Log Out** in sidebar)
2. Go to login page and click **Create an account**
3. Register as a regular user (leave role as default)
4. Note the email used
5. Go to phpMyAdmin → `users` table
6. Find your new user
7. Change `status` to `approved`
8. Log in with that account to see user dashboard

---

## 🔑 Default Database Settings

```
Host: localhost
User: root  
Password: (empty)
Database: capstone_db
```

If your XAMPP MySQL has a different password, edit `config.php`:

```php
$db_pass = 'your_password_here';  // Line 5
```

---

## ❓ Troubleshooting

### "Database connection error"
- ✅ Is MySQL running? (Check XAMPP Control Panel)
- ✅ Is the database `capstone_db` created?
- ✅ Did you import `db.sql`?

### "Blank page or 'not found'"
- ✅ Is the file path correct? `http://localhost/CAPSTONE(ACE)/login.php`
- ✅ Are you using the exact folder name?

### "Login not working"
- ✅ Has the account status been set to `approved` in database?
- ✅ Is the password at least 6 characters?

### "Can't upload files"
- ✅ Is the `/uploads` folder writable?
- ✅ Try right-clicking folder → Properties → Security (Windows)

---

## 🎯 Ready to Use!

Your inventory system is now ready. You can:

✅ Register users  
✅ Manage inventory items  
✅ Track equipment status  
✅ View analytics alerts  
✅ Export data  

**Enjoy! 🚀**

---

*For full documentation, see README.md*
