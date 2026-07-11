# College Online Voting System

A complete, secure online voting system designed for college elections. This project includes a modern frontend with PHP backend and MySQL database.

## 📋 Project Structure

```
voting/
├── index.html              # Landing page
├── register.php            # Student registration
├── login.php               # Student login
├── vote.php                # Voting page
├── vote_success.php        # Vote confirmation
├── already_voted.php       # Already voted message
├── logout.php              # Logout functionality
├── config.php              # Database configuration
├── style.css               # Styling (responsive design)
├── database_setup.sql      # Database creation script
└── README.md              # This file
```

## 🚀 Features

### Part 1: Front Page (index.html)
- Modern landing page with college branding
- Register and Login buttons
- System description and features
- Responsive design

### Part 2: Authentication System
**Registration Page (register.php)**
- Student information form with validation
- Unique Student ID verification
- Password hashing with PHP's `password_hash()`
- 6+ character password requirement
- Department selection (4 departments)

**Login Page (login.php)**
- Secure authentication with prepared statements
- Automatic redirection based on voting status
- Error handling and validation

### Part 3: Voting System
**Voting Page (vote.php)**
- Radio button selection for 3 positions:
  - **President**: Balen Shah, Ranju Darshana, Gagan Thapa
  - **Vice President**: Ravi Lamichhane, Sobita Goutam, Kulman Ghishing
  - **Secretary**: Harka Sampang, Renu Dahal, Swornim Wagle
- One vote per student enforcement
- Session-based user verification

**Vote Submission & Confirmation**
- vote_success.php: Thank you page after successful voting
- already_voted.php: Page for users who have already voted
- Double-vote prevention using database flag

**Logout System (logout.php)**
- Session destruction
- Confirmation page with redirect to home

## 🗄️ Database Schema

### Users Table
```sql
- id (Primary Key)
- first_name
- middle_name
- last_name
- email (UNIQUE)
- student_id (UNIQUE)
- department
- password (hashed)
- has_voted (0 or 1)
- registration_date (timestamp)
```

### Votes Table
```sql
- vote_id (Primary Key)
- student_id (Foreign Key)
- president
- vice_president
- secretary
- timestamp
```

## 🔒 Security Features

1. **Password Hashing**: Uses PHP's `password_hash()` with PASSWORD_DEFAULT
2. **Prepared Statements**: All database queries use prepared statements to prevent SQL injection
3. **Session Management**: Uses PHP sessions for secure user authentication
4. **One-Vote Policy**: `has_voted` flag prevents double voting
5. **Input Validation**: All user inputs are validated and sanitized
6. **HTML Escaping**: Output is escaped with `htmlspecialchars()` to prevent XSS

## 📊 Setup Instructions

### 1. Database Setup

1. Open phpMyAdmin (http://localhost/phpmyadmin)
2. Copy and paste the contents of `database_setup.sql`
3. Execute the SQL to create the database and tables

Or via command line:
```bash
mysql -u root < database_setup.sql
```

### 2. Configuration

1. Edit `config.php` if your database credentials differ from:
   - Server: localhost
   - Username: root
   - Password: (empty)
   - Database: voting_system

### 3. Access the System

1. Place the project folder in `C:\xampp\htdocs`
2. Start Apache and MySQL from XAMPP Control Panel
3. Navigate to `http://localhost/Voting/index.html`

## 🎯 User Flow

```
index.html (Landing)
    ├─→ register.php (New User)
    │   └─→ login.php
    │       └─→ vote.php (First Time)
    │           └─→ vote_success.php
    │               └─→ logout.php
    │
    └─→ login.php (Returning User)
        ├─→ vote.php (If not voted)
        │   └─→ vote_success.php
        │
        └─→ already_voted.php (If already voted)
            └─→ logout.php
```

## 🎨 Styling

The system uses modern CSS with:
- Gradient backgrounds
- Responsive design (mobile-friendly)
- Smooth transitions and hover effects
- Clear visual hierarchy
- Color-coded alerts (success, danger, warning, info)

## 📱 Responsive Design

The system is fully responsive and works on:
- Desktop browsers
- Tablet devices
- Mobile phones

## ⚙️ Technologies Used

- **Frontend**: HTML5, CSS3, JavaScript (vanilla)
- **Backend**: PHP 7.4+
- **Database**: MySQL 5.7+
- **Server**: Apache (via XAMPP)

## 🔐 Default Test Credentials

To test the system, you can create a user through registration or uncomment the sample data in `database_setup.sql`:

```
Student ID: CS001
Password: password123
```

## 📝 Database Backup

```bash
mysqldump -u root voting_system > backup.sql
```

## 🐛 Troubleshooting

### "Connection failed" Error
- Ensure MySQL is running in XAMPP
- Check database credentials in `config.php`
- Verify database creation via phpMyAdmin

### "Student ID already exists"
- This is expected behavior - the unique constraint works
- Try registering with a different Student ID

### Password Mismatch Error
- Ensure both password fields match exactly
- Check for caps lock
- Password must be at least 6 characters

## 📄 License

This project is open-source and available for educational purposes.

## 👥 Support

For issues or questions, refer to individual file comments for detailed explanations.

---

**Last Updated**: February 10, 2026
**Version**: 1.0
