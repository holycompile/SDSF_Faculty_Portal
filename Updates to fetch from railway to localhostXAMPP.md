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

## 🔄 Optional: Push from Localhost XAMPP to Railway

If you ever make changes locally and want to overwrite Railway with your local database:

```cmd
mysqldump -u root sdsf_faculty_portal | mysql -h gondola.proxy.rlwy.net -u root -pGPytSfJeALfRdUMOzJJUMwuIrdBqCDcu --port 23635 --protocol=TCP railway
```
