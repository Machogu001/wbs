# Water Billing System

A comprehensive water billing management system with M-Pesa payment integration built with PHP, MySQL, and Bootstrap.

## Features

### Core Features
- User Registration & Authentication
- Customer Account Management
- Water Bill Generation
- M-Pesa Payment Integration
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
4. Configure M-Pesa credentials in `config/mpesa_config.php`
5. Configure SMS credentials in `config/sms_config.php` and `.env`
6. Configure email (SMTP) credentials in `.env`

## Configuration

### Environment (.env)

Key settings used by this system include:

- Database:
	- `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- SMS / MobileSasa:
	- `SMS_API_TOKEN`, `SMS_SENDER_ID`
- Email (SMTP):
	- `EMAIL_MAILER` (e.g. `smtp`)
	- `EMAIL_HOST`, `EMAIL_PORT`
	- `EMAIL_USERNAME`, `EMAIL_PASSWORD`
	- `EMAIL_SCHEME` / `EMAIL_ENCRYPTION` (e.g. `ssl` or `tls`)
	- `EMAIL_FROM_ADDRESS`, `EMAIL_FROM_NAME`

The app will automatically load `.env` via lightweight helpers in the `config` classes.

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
- Phone: 254700000001
- Password: admin123

## Support
For support and documentation, visit the project repository.

## License
MIT License
