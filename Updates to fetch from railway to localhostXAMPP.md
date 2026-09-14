# Database Sync Guide: Railway Cloud to Localhost XAMPP

This document contains the instructions and exact command to pull the latest production database from **Railway MySQL** into your **Local XAMPP MySQL** (`sdsf_faculty_portal`).

---

## ⚡ Instant 1-Line Sync Command

Open **Windows PowerShell** or **Command Prompt** and run:

```cmd
mysqldump -h gondola.proxy.rlwy.net -u root -pGPytSfJeALfRdUMOzJJUMwuIrdBqCDcu --port 23635 --protocol=TCP railway | mysql -u root sdsf_faculty_portal
```

---

## 📋 How It Works

1. **`mysqldump -h gondola.proxy.rlwy.net ... railway`**: Connects to your live Railway cloud MySQL database and exports all current tables, courses, admins, faculty, and semester tags.
2. **`|` (Pipe operator)**: Streams the data directly across your internet connection into your local MySQL without saving any intermediate files to disk.
3. **`mysql -u root sdsf_faculty_portal`**: Receives the streamed database and updates your local XAMPP database (`sdsf_faculty_portal`) in ~5 seconds.

---

## 💡 Important Notes

> [!NOTE]
> When you run the command, you will see:
> `mysqldump: [Warning] Using a password on the command line interface can be insecure.`
>
> **This is NOT an error.** It is a standard MySQL security notice informing you that the password was supplied directly in the command. Once that warning appears and the command finishes, your local database is completely updated.

---

## 🛠️ Prerequisites

Before running the command:
1. Ensure **XAMPP Control Panel** has **MySQL** running (Green / Port 3306).
2. Your computer must have an active internet connection to communicate with Railway.

---

## 🔄 Push from Localhost XAMPP to Railway Cloud (Reverse Sync)

If you made changes locally (such as dropping rows from `admin`, adding courses, or testing updates) and want to push your local database back to **Railway**:

### ⚡ Instant 1-Line Push Command

Open **Windows PowerShell** or **Command Prompt** and run:

```cmd
mysqldump -u root sdsf_faculty_portal | mysql -h gondola.proxy.rlwy.net -u root -pGPytSfJeALfRdUMOzJJUMwuIrdBqCDcu --port 23635 --protocol=TCP railway
```

### 📋 Recommended Safe Workflow: Pull &rarr; Edit &rarr; Push

1. **Step 1 — Pull latest from Railway** (ensures you don't lose any live lecture/course data):
   ```cmd
   mysqldump -h gondola.proxy.rlwy.net -u root -pGPytSfJeALfRdUMOzJJUMwuIrdBqCDcu --port 23635 --protocol=TCP railway | mysql -u root sdsf_faculty_portal
   ```
2. **Step 2 — Make your edits locally**:
   - Open **phpMyAdmin** (`http://localhost/phpmyadmin`) or MySQL CLI.
   - Drop the admin row, edit subjects, or test whatever you need.
3. **Step 3 — Push back to Railway**:
   ```cmd
   mysqldump -u root sdsf_faculty_portal | mysql -h gondola.proxy.rlwy.net -u root -pGPytSfJeALfRdUMOzJJUMwuIrdBqCDcu --port 23635 --protocol=TCP railway
   ```

---

### 🎯 Fast Alternative: Delete a Specific Row on Railway Directly

If you only want to delete an admin row on Railway without replacing the entire database:

```cmd
mysql -h gondola.proxy.rlwy.net -u root -pGPytSfJeALfRdUMOzJJUMwuIrdBqCDcu --port 23635 --protocol=TCP railway -e "DELETE FROM admin WHERE username='username_here';"
```
*(Replace `username_here` with the exact admin username, or use `WHERE id=...`)*
