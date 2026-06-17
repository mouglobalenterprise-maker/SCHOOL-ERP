INSTALLATION & SETUP
Step-by-Step Installation
Step 1 Install XAMPP (Windows) or LAMP (Linux) on your computer or server.
Step 2 Copy all EduManage Pro files into your web root folder. Example: C:\xampp\htdocs\edumanage\ on
Windows, or /var/www/html/edumanage/ on Linux.
Step 3 Open phpMyAdmin (usually at http://localhost/phpmyadmin) and create a new database named
edumanage_pro.
Step 4 Import the file database_FIXED.sql into the edumanage_pro database using phpMyAdmin Import
tab.
Step 5 Open config/db.php and set your database credentials (DB_USER and DB_PASS).
Step 6 Open config/config.php and set BASE_URL to match your installation path. Example:
http://localhost/edumanage
Step 7 Visit http://localhost/edumanage/fix_passwords.php in your browser. This hashes all passwords.
The page will confirm success.
Step 8 DELETE fix_passwords.php from your server immediately after running it. This is important for
security.
Step 9 Go to http://localhost/edumanage/login.php and log in with admin / admin123. Change this password immediately after first login — these are setup-only credentials and must not be used in any live deployment.

■ Always delete fix_passwords.php after running it. Leaving it on your server is a security risk.
Folder Permissions
The following folders must be writable by your web server (chmod 755 on Linux):
• uploads/logos/
• uploads/photos/
• uploads/assignments/
• uploads/documents/
• exports/
