# College Online Voting System - COMPLETE SETUP GUIDE

## 📋 Project Overview

This is a complete, production-ready College Online Voting System built with:
- **Frontend**: HTML, CSS, JavaScript
- **Backend**: PHP (Object-Oriented with Prepared Statements)
- **Database**: MySQL
- **Security**: Password Hashing, Session Management, SQL Injection Prevention

## 📁 File Structure

```
Voting/
├── database.sql              # Database schema (create tables)
├── db.php                    # Database connection handler
├── index.html                # Landing/Home page
├── register.php              # Student registration
├── login.php                 # Student login
├── vote.php                  # Voting page (multiple positions)
├── already_voted.php         # Confirmation page
└── logout.php                # Session destroyer
```

## 🚀 INSTALLATION & SETUP

### Step 1: Create the Database

1. Open phpMyAdmin (usually at `http://localhost/phpmyadmin`)
2. Go to "SQL" tab
3. Copy and paste the entire contents of `database.sql`
4. Click "Go" to execute

**Alternative Method (via Command Line):**
```bash
mysql -u root -p < database.sql
```

### Step 2: Verify Database Connection

Make sure your `db.php` file has correct credentials:
- Database Name: `voting_system`
- Username: `root` (XAMPP default)
- Password: `` (empty for XAMPP default)

If your setup is different, edit `db.php`:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASSWORD', '');        // Change if needed
define('DB_NAME', 'voting_system');
```

### Step 3: Access the Application

Open your browser and go to:
```
http://localhost/Voting/index.html
```

## 🔄 PAGE FLOW & USER JOURNEY

```
START
  ↓
index.html (Landing Page)
  ├→ Register Button → register.php
  │                      ↓
  │                   Validate & Store User
  │                      ↓
  │                   Redirect to → login.php
  │
  └→ Login Button → login.php
                      ↓
                   Validate Credentials
                      ↓
                   Check has_voted Flag
                      ├→ has_voted = 0 → vote.php
                      │
                      └→ has_voted = 1 → already_voted.php
                                           ↓
                                       Logout Button → logout.php → index.html
```

## 👤 USER REGISTRATION FLOW

**Fields Required:**
- ✓ First Name (required)
- ✓ Middle Name (optional)
- ✓ Last Name (required)
- ✓ Email ID (required, unique)
- ✓ Student ID (required, unique)
- ✓ Date of Birth (required)
- ✓ Department (required - dropdown)
- ✓ Password (required, min 6 chars, hashed with password_hash)

**Rules:**
- Student ID must be unique
- Email must be unique
- Passwords are compared and must match
- All validation is done before database insertion
- Duplicate prevention at registration

## 🗳️ VOTING PROCESS

### Three Electoral Positions:

**1. President**
   - Balen Shah
   - Ranju Darshana
   - Gagan Thapa

**2. Vice President**
   - Ravi Lamichhane
   - Sobita Goutam
   - Kulman Ghishing

**3. Secretary**
   - Harka Sampang
   - Renu Dahal
   - Swornim Wagle

### Voting Rules:
- User MUST select exactly ONE candidate per position
- Cannot proceed without all three selections
- Error message if any position is not selected
- Vote stored in database immediately upon submission
- User's `has_voted` flag set to 1 (permanent)
- Redirected to confirmation page after successful vote

## 🔐 SECURITY FEATURES

1. **Password Security**
   - Passwords hashed using `password_hash()` with default algorithm
   - Verification using `password_verify()`
   - Minimum 6 characters required

2. **SQL Injection Prevention**
   - All database queries use prepared statements
   - `bind_param()` used for all variables
   - No direct SQL concatenation

3. **Session Management**
   - `session_start()` on all protected pages
   - Sessions contain: `student_id`, `user_id`, `user_name`
   - Sessions destroyed on logout

4. **Input Validation**
   - Email format validation
   - HTML sanitization with `htmlspecialchars()`
   - Trim and validate all inputs
   - Type checking for database operations

5. **Double Voting Prevention**
   - `has_voted` flag checked on login
   - Checked again before vote submission
   - Foreign key constraint in database

## 📊 DATABASE SCHEMA

### Table 1: `users`
```sql
id              INT PRIMARY KEY AUTO_INCREMENT
first_name      VARCHAR(50) NOT NULL
middle_name     VARCHAR(50)
last_name       VARCHAR(50) NOT NULL
email           VARCHAR(100) UNIQUE NOT NULL
student_id      VARCHAR(20) UNIQUE NOT NULL
department      VARCHAR(100) NOT NULL
password        VARCHAR(255) NOT NULL
has_voted       INT DEFAULT 0
created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```

### Table 2: `votes`
```sql
vote_id         INT PRIMARY KEY AUTO_INCREMENT
student_id      VARCHAR(20) NOT NULL (FK)
president       VARCHAR(100) NOT NULL
vice_president  VARCHAR(100) NOT NULL
secretary       VARCHAR(100) NOT NULL
timestamp       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```

## 🧪 TESTING THE SYSTEM

### Test Registration:
1. Go to http://localhost/Voting/index.html
2. Click "Register"
3. Fill in all fields:
   - Name: John Doe
   - Email: john@college.edu
   - Student ID: STU-2024-001
   - DOB: 2005-01-15
   - Department: Computer Engineering
   - Password: test123456
4. Click Register
5. Should redirect to login with success message

### Test Login:
1. Enter Student ID: STU-2024-001
2. Enter Password: test123456
3. Click Login
4. Should redirect to vote.php

### Test Voting:
1. Select one candidate for each position
2. Review selections
3. Click "Submit Vote"
4. Should redirect to already_voted.php with confirmation

### Test Double-Voting Prevention:
1. Try to login again with same credentials
2. Should be redirected to already_voted.php
3. Button "Go to Home" redirects to index.html
4. Button "Logout" destroys session and redirects to index.html

## ⚙️ CONFIGURATION OPTIONS

**db.php** - Database Configuration
```php
define('DB_HOST', 'localhost');      // Your MySQL host
define('DB_USER', 'root');           // Your MySQL username
define('DB_PASSWORD', '');           // Your MySQL password
define('DB_NAME', 'voting_system');  // Database name
```

**vote.php** - Modify Candidates
Edit the `$candidates` array to change candidates:
```php
$candidates = [
    'president' => ['Candidate 1', 'Candidate 2', 'Candidate 3'],
    'vice_president' => ['Candidate 1', 'Candidate 2', 'Candidate 3'],
    'secretary' => ['Candidate 1', 'Candidate 2', 'Candidate 3']
];
```

**register.php** - Modify Departments
Edit the `$departments` array:
```php
$departments = [
    'Computer Engineering',
    'Civil Engineering',
    'Electrical Engineering',
    'Mechanical Engineering'
];
```

## 📱 RESPONSIVE DESIGN

All pages are fully responsive and work on:
- ✓ Desktop (1920px and above)
- ✓ Tablet (768px to 1024px)
- ✓ Mobile (320px to 767px)

CSS uses flexbox and media queries for adaptation.

## 🎨 UI/UX FEATURES

- **Modern Gradient Design**: Purple gradient (#667eea to #764ba2)
- **Clean Layout**: Centered containers with proper spacing
- **Form Validation**: Real-time and server-side validation
- **Error Messages**: Clear, user-friendly error displays
- **Success Feedback**: Confirmation messages and page redirects
- **Accessibility**: Proper labels, semantic HTML, keyboard navigation

## ⚠️ IMPORTANT NOTES

1. **Production Deployment**:
   - Remove error display in db.php for production
   - Use HTTPS instead of HTTP
   - Set proper file permissions (644 for PHP files)
   - Use strong database passwords
   - Keep credentials in environment variables

2. **Backup**: Always backup your database before voting begins

3. **Audit Trail**: The database automatically timestamps all votes

4. **Results Viewing**: Create a results.php page to view vote counts

## 🆘 TROUBLESHOOTING

**Problem**: "Database Connection Failed"
- **Solution**: Check db.php credentials, ensure MySQL is running

**Problem**: "Table doesn't exist"
- **Solution**: Run database.sql to create tables

**Problem**: "User doesn't exist after registration"
- **Solution**: Check MySQL error log, verify prepared statement syntax

**Problem**: "Can't submit vote"
- **Solution**: Ensure all three positions are selected, check browser console for errors

## 📞 QUICK REFERENCE

| Page | Purpose | Login Required |
|------|---------|----------------|
| index.html | Landing page | No |
| register.php | Student registration | No |
| login.php | User authentication | No |
| vote.php | Voting interface | Yes |
| already_voted.php | Vote confirmation | Yes |
| logout.php | Session cleanup | Yes |

## ✅ CHECKLIST FOR DEPLOYMENT

- [ ] Database created (database.sql executed)
- [ ] db.php credentials correct
- [ ] All PHP files in /Voting directory
- [ ] index.html accessible at /Voting/index.html
- [ ] Test registration works
- [ ] Test login works
- [ ] Test voting works
- [ ] Test double-voting prevention
- [ ] Test logout works
- [ ] HTTPS enabled (for production)
- [ ] Backup created

---

**Version**: 1.0
**Last Updated**: February 11, 2026
**Author**: Full-Stack Development Team
