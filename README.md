# Water Billing System

A comprehensive water billing management system with M-Pesa payment integration built with PHP, MySQL, and Bootstrap.

## Features

### Core Features
- User Registration & Authentication
- Customer Account Management
- Water Bill Generation
- M-Pesa Payment Integration
- Accounting module with chart of accounts, journal entries, and trial balance
- SMS Notifications
- Admin Dashboard
- Payment History Tracking
- User Profile Management

### Security & Verification
- Two-step verification (2FA) via SMS or email on login
- Per-user 2FA settings configurable from the Profile page
- One-Time Password (OTP) auto-submission when code is fully entered

## Requirements
- PHP 7.4 or higher
- MySQL 5.7 or higher
- Web Server (Apache/Nginx)
- SSL Certificate (for production)
- M-Pesa Daraja API Credentials

## Quick Installation
1. Upload files to web server
2. Navigate to `yourdomain.com/install/install.php`
3. Follow installation wizard
4. Configure M-Pesa credentials in `.env`
5. Configure SMS credentials in `.env`
6. Configure email (SMTP) credentials in `.env`
7. Enable background jobs: `sudo bash scripts/install_background_jobs.sh`
8. Remove the `install/` directory after installation

### Background Jobs

Run this once after each new installation:

```bash
sudo bash scripts/install_background_jobs.sh
```

The script enables the system cron service and installs these jobs:

- SMS queue processing every minute.
- Email queue processing every minute, so invoice creation and payment processing do not wait for SMTP.
- Payment reminders daily at 8:00 AM: one day before a due date and once after a bill becomes overdue.

## Configuration

### Environment (.env)

Key settings used by this system include:

- Database:
	- `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- M-Pesa:
	- `MPESA_ENV`
	- `MPESA_SHORTCODE` or legacy `MPESA_SHORT_CODE`
	- `MPESA_CONSUMER_KEY`, `MPESA_CONSUMER_SECRET`
	- `MPESA_PASSKEY`
	- `MPESA_CALLBACK_URL`
	- `PAYMENT_LINK_SECRET`
- SMS / MobileSasa:
	- `SMS_API_TOKEN`, `SMS_SENDER_ID`
- Email (SMTP):
	- `EMAIL_MAILER` (e.g. `smtp`)
	- `EMAIL_HOST`, `EMAIL_PORT`
	- `EMAIL_USERNAME`, `EMAIL_PASSWORD`
	- `EMAIL_SCHEME` / `EMAIL_ENCRYPTION` (e.g. `ssl` or `tls`)
	- `EMAIL_FROM_ADDRESS`, `EMAIL_FROM_NAME`

The app will automatically load `.env` via lightweight helpers in the `config` classes. The installer also prepares default M-Pesa and payment-link values when `.env` is writable.

### Two-step Verification (2FA)

- Users can enable/disable 2FA from the **Profile** page and choose a preferred method: SMS (phone) or email.
- On login, if 2FA is enabled for the account:
	- The user first enters their identifier and password.
	- A 6-digit verification code is sent via the selected channel.
	- The login form switches to a dedicated "Enter verification code" step.
	- The code is auto-submitted once all 6 digits are entered, or the user can click **Verify and Login**.
- If the code expires, the user remains in a 2FA session and can request a new code (subject to cooldown) without re-entering credentials.
- Resend behavior:
	- Users can resend the code via phone or email (depending on which contacts are configured).
	- A short cooldown is enforced between resend attempts; during this time, the UI shows a countdown and disables resend links.

## Default Admin Credentials
- Name: System Administrator
- Phone: 254717996492
- Email: admin@bremac.co.ke
- Password: admin123

## Support
For support and documentation, visit the project repository.

## License
MIT License
