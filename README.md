# IT Students Hub

A PHP and MySQL web portal for IT students, styled as a retro "Frutiger Aero" browser/OS window. It brings together study materials, a GPA calculator, mini games, and a chat feature in one place.


<img width="1915" height="889" alt="image" src="https://github.com/user-attachments/assets/443bbef3-4d78-48cf-ad03-384e04044946" />


## Features

- **Home** — dynamic welcome content pulled from the database
- **Materials** — browse and view course materials
- **GPA calculator** — calculate GPA based on courses and grades
- **Games** — a set of built-in mini games
- **Chat** — messaging between users
- **Profile** — user profile page with account management
- **Admin panel** (`admin.php`) — manage accounts and site content
- Retro "OS window" UI theme (Frutiger Aero style) used consistently across pages

## Tech Stack

- PHP (PDO + MySQL)
- MySQL / MariaDB
- HTML, CSS, JavaScript
- Google Fonts (Varela Round)

## Running It Locally

This project needs a local PHP + MySQL server, such as **XAMPP**, **WAMP**, or **MAMP**.

1. **Install a local server stack** if you don't have one — [XAMPP](https://www.apachefriends.org/) is a simple option.
2. **Copy the project folder** into your server's web root:
   - XAMPP: `htdocs/ITWebsite`
3. **Create the database:**
   - Open phpMyAdmin (or the MySQL CLI) and create a database named `it_website`.
   - Import `database/it_website.sql` into it.
4. **Check the database credentials** in `db.php` — by default it expects:
   - Host: `127.0.0.1`, Port: `3306`, User: `root`, Password: *(empty)*
   - Update these if your local MySQL setup differs.
5. **Start Apache and MySQL** from your server stack's control panel.
6. **Open the site** in your browser:
   - `http://localhost/ITWebsite/index.php`

## Notes

- Styling lives in `assets/css/style.css`; page-specific logic lives under `pages/`.
- If MySQL isn't running or the database is missing, the site will show a clear connection error instead of failing silently.
